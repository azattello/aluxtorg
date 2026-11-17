<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('meta_title', 160)->nullable();
            $table->string('meta_description', 320)->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('meta_title', 160)->nullable();
            $table->string('meta_description', 320)->nullable();
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->string('format', 16)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
        });

        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->string('placement', 32)->default('home_hero');
            $table->string('cloudinary_public_id')->unique();
            $table->text('secure_url');
            $table->string('alt_text', 255);
            $table->string('format', 16)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('destination_url', 2048)->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['placement', 'is_active', 'sort_order']);
            $table->index(['starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');

        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn(['format', 'width', 'height', 'bytes']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['meta_title', 'meta_description']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['meta_title', 'meta_description']);
        });
    }
};
