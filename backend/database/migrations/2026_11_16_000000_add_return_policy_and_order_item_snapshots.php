<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->boolean('is_returnable')->default(false);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('sale_unit_snapshot', 32)->nullable();
            $table->boolean('is_returnable_snapshot')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['sale_unit_snapshot', 'is_returnable_snapshot']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('is_returnable');
        });
    }
};
