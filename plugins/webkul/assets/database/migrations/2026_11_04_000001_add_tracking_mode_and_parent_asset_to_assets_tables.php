<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_products', function (Blueprint $table) {
            $table->string('tracking_mode')->default('unit')->after('type')->index();
        });

        DB::table('asset_products')
            ->where('type', 'consumable')
            ->update(['tracking_mode' => 'quantity']);

        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('parent_asset_id')
                ->nullable()
                ->after('product_id')
                ->constrained('assets')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_asset_id');
        });

        Schema::table('asset_products', function (Blueprint $table) {
            $table->dropIndex(['tracking_mode']);
            $table->dropColumn('tracking_mode');
        });
    }
};
