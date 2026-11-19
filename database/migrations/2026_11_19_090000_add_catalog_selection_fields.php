<?php

use App\Models\Catalog\Product\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_actions', function (Blueprint $table) {
            $table->unsignedBigInteger('filter_manufacturer_id')->nullable();
            $table->string('filter_supplier', 50)->nullable();
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dateTime('first_published_at')->nullable()->index();
        });
        // Preserve imported dates. A migration timestamp is not a publication date.
        DB::table('products')->select('id', 'payload', 'code')->where('is_active', true)->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $legacy = data_get(json_decode($row->payload ?: '{}', true), 'opencart');
                if (is_array($legacy) && ($date = Product::legacyPublicationDate($legacy))) {
                    DB::table('products')->where('id', $row->id)->whereNull('first_published_at')->update(['first_published_at' => $date]);
                }
            }
        });
        Schema::table('orders', fn (Blueprint $table) => $table->string('erp_cost_centre', 50)->nullable());
        DB::table('orders')->where('customer_type', 'B2C')->where('cost_centre', 'Web prodaja')->update(['erp_cost_centre' => 'web']);
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('erp_cost_centre'));
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['first_published_at']);
        });
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('first_published_at'));
        Schema::table('catalog_actions', fn (Blueprint $table) => $table->dropColumn(['filter_manufacturer_id', 'filter_supplier']));
    }
};
