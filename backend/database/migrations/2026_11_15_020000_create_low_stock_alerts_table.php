<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('low_stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->uuid('activation_key');
            $table->timestampTz('triggered_at')->useCurrent();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->unique(['warehouse_id', 'product_variant_id']);
            $table->index(['is_active', 'triggered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('low_stock_alerts');
    }
};
