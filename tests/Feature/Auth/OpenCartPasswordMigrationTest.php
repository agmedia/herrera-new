<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\User\LegacyCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OpenCartPasswordMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('legacyAlgorithms')]
    public function test_original_password_is_upgraded_and_legacy_secret_retired(string $algorithm): void
    {
        [$user, $legacy] = $this->customer($algorithm);
        $this->assertNotSame($legacy->legacy_hash, DB::table('user_legacy_credentials')->value('legacy_hash'));
        $this->assertArrayNotHasKey('legacy_hash', $legacy->toArray());
        $this->assertArrayNotHasKey('legacy_salt', $legacy->toArray());
        $this->assertFalse(Auth::attempt(['email' => $user->email, 'password' => 'Wrong-password']));
        $this->assertFalse($legacy->fresh()->retired_at !== null);

        $this->post(route('front.auth.login.store'), [
            'email' => $user->email, 'password' => 'Original-password123!',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('Original-password123!', $user->fresh()->password));
        $this->assertNotNull($legacy->fresh()->retired_at);
        $this->assertNull($legacy->fresh()->legacy_hash);
        $this->assertNull($legacy->fresh()->legacy_salt);

        Auth::logout();
        $this->assertTrue(Auth::attempt(['email' => $user->email, 'password' => 'Original-password123!']));
    }

    public static function legacyAlgorithms(): array
    {
        return [['salted_sha1'], ['md5']];
    }

    public function test_a_password_reset_prevents_old_password_fallback(): void
    {
        [$user, $legacy] = $this->customer('salted_sha1');
        $user->update(['password' => 'New-password123!']);
        $this->assertFalse(Auth::attempt(['email' => $user->email, 'password' => 'Original-password123!']));
        $this->assertNotNull($legacy->fresh()->retired_at);
        $this->assertTrue(Auth::attempt(['email' => $user->email, 'password' => 'New-password123!']));
    }

    public function test_disabled_original_customer_cannot_use_legacy_password(): void
    {
        [$user, $legacy] = $this->customer('salted_sha1');
        $legacy->update(['enabled' => false]);
        $this->assertFalse(Auth::attempt(['email' => $user->email, 'password' => 'Original-password123!']));
        $this->assertGuest();
    }

    private function customer(string $algorithm): array
    {
        $user = User::factory()->create();
        $salt = 'TestLegacySalt';
        $plain = 'Original-password123!';
        $legacy = LegacyCredential::query()->create([
            'user_id' => $user->id, 'enabled' => true,
            'legacy_hash' => $algorithm === 'md5' ? md5($plain) : sha1($salt.sha1($salt.sha1($plain))),
            'legacy_salt' => $salt,
        ]);

        return [$user, $legacy];
    }
}
