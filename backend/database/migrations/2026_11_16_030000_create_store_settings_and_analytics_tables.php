<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('phone', 32)->nullable();
            $table->string('whatsapp', 128)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('address')->nullable();
            $table->string('working_hours')->nullable();
            $table->string('instagram_url', 2048)->nullable();
            $table->string('telegram_url', 2048)->nullable();
            $table->timestampsTz();
        });

        DB::table('store_settings')->insert([
            'id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('storefront_daily_views', function (Blueprint $table): void {
            $table->id();
            $table->date('view_date');
            $table->string('page_type', 16);
            $table->string('resource_key', 191);
            $table->unsignedBigInteger('views')->default(0);
            $table->timestampsTz();

            $table->unique(['view_date', 'page_type', 'resource_key']);
            $table->index(['view_date', 'page_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_daily_views');
        Schema::dropIfExists('store_settings');
    }
};
