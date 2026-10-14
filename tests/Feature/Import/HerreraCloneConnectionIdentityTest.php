<?php

namespace Tests\Feature\Import;

use App\Models\User\LegacyCredential;
use App\Services\Import\HerreraCutoverSafety;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HerreraCloneConnectionIdentityTest extends TestCase
{
    public function test_new_eloquent_credentials_save_only_to_the_named_clone_and_never_the_original(): void
    {
        $originalDefault = DB::getDefaultConnection();
        $originalName = 'herrera_identity_original';
        $cloneName = 'herrera_identity_clone';
        config(['database.connections.'.$originalName => [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]]);
        $original = DB::connection($originalName);
        config(['database.connections.'.$cloneName => HerreraCutoverSafety::isolatedConnectionConfig($original->getConfig())]);

        try {
            foreach ([$originalName, $cloneName] as $name) {
                Schema::connection($name)->create('users', function (Blueprint $table): void {
                    $table->id();
                    $table->string('password');
                });
                Schema::connection($name)->create('user_legacy_credentials', function (Blueprint $table): void {
                    $table->id();
                    $table->foreignId('user_id')->unique()->constrained('users');
                    $table->text('legacy_hash')->nullable();
                    $table->text('legacy_salt')->nullable();
                    $table->string('password_fingerprint');
                    $table->boolean('enabled');
                    $table->timestamp('retired_at')->nullable();
                    $table->timestamps();
                });
            }
            $original->table('users')->insert(['id' => 1, 'password' => 'original-password']);
            DB::connection($cloneName)->table('users')->insert(['id' => 1231, 'password' => 'clone-password']);
            DB::setDefaultConnection($cloneName);

            // The original failed with a foreign-key error: the SELECT used the
            // clone PDO, then Eloquent's new model retained the original name.
            $credential = LegacyCredential::query()->firstOrCreate(['user_id' => 1231], [
                'legacy_hash' => 'legacy-test', 'legacy_salt' => 'salt-test', 'enabled' => true,
            ]);

            $this->assertSame($cloneName, $credential->getConnectionName());
            $this->assertSame($cloneName, $credential->getConnection()->getName());
            $this->assertSame(1, DB::connection($cloneName)->table('user_legacy_credentials')->count());
            $this->assertSame(hash('sha256', 'clone-password'), $credential->password_fingerprint);
            $this->assertSame(0, $original->table('user_legacy_credentials')->count());
            $this->assertSame('original-password', $original->table('users')->where('id', 1)->value('password'));
        } finally {
            DB::setDefaultConnection($originalDefault);
            DB::purge($cloneName);
            DB::purge($originalName);
        }
    }
}
