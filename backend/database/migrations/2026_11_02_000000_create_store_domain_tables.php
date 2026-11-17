<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_published')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['is_published', 'category_id', 'sort_order']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('sku')->unique();
            $table->string('name');
            $table->string('sale_unit', 32)->default('piece');
            $table->jsonb('attributes')->default('{}');
            $table->unsignedBigInteger('price_kzt');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['product_id', 'is_active', 'sort_order']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cloudinary_public_id')->unique();
            $table->text('secure_url');
            $table->string('alt_text')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['product_id', 'sort_order']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number')->unique();
            $table->string('order_status', 32)->default('new');
            $table->string('payment_status', 32)->default('unpaid');
            $table->string('fulfillment_method', 16);
            $table->string('customer_name');
            $table->string('customer_phone', 32);
            $table->string('customer_email')->nullable();
            $table->string('delivery_city')->nullable();
            $table->string('delivery_street')->nullable();
            $table->string('delivery_building')->nullable();
            $table->string('delivery_apartment')->nullable();
            $table->text('delivery_instructions')->nullable();
            $table->unsignedBigInteger('subtotal_kzt');
            $table->unsignedBigInteger('delivery_fee_kzt')->nullable();
            $table->unsignedBigInteger('total_kzt')->nullable();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->timestampsTz();

            $table->index(['order_status', 'created_at']);
            $table->index('customer_phone');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name_snapshot');
            $table->string('variant_name_snapshot')->nullable();
            $table->string('sku_snapshot');
            $table->jsonb('variant_attributes_snapshot')->nullable();
            $table->unsignedBigInteger('unit_price_kzt');
            $table->integer('quantity');
            $table->unsignedBigInteger('line_total_kzt');
            $table->timestampsTz();

            $table->index('product_variant_id');
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
        });

        Schema::create('payment_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->unsignedBigInteger('amount_kzt')->nullable();
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_key', 100);
            $table->nullableMorphs('subject');
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['actor_user_id', 'created_at']);
            $table->index(['event_key', 'created_at']);
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->integer('on_hand')->default(0);
            $table->integer('reserved')->default(0);
            $table->timestampsTz();

            $table->unique(['warehouse_id', 'product_variant_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('correction_of_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->string('type', 16);
            $table->integer('quantity_delta');
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['warehouse_id', 'product_variant_id', 'created_at']);
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('quantity');
            $table->string('status', 16)->default('active');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->unique(['order_item_id', 'warehouse_id']);
            $table->index(['warehouse_id', 'product_variant_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $checks = [
                'ALTER TABLE product_variants ADD CONSTRAINT product_variants_price_kzt_nonnegative CHECK (price_kzt >= 0)',
                "ALTER TABLE orders ADD CONSTRAINT orders_status_valid CHECK (order_status IN ('new', 'confirmed', 'preparing', 'ready_for_pickup', 'shipped', 'completed', 'cancelled'))",
                "ALTER TABLE orders ADD CONSTRAINT orders_payment_status_valid CHECK (payment_status IN ('unpaid', 'paid', 'partially_refunded', 'refunded'))",
                "ALTER TABLE orders ADD CONSTRAINT orders_fulfillment_method_valid CHECK (fulfillment_method IN ('delivery', 'pickup'))",
                "ALTER TABLE orders ADD CONSTRAINT orders_delivery_address_required CHECK (fulfillment_method <> 'delivery' OR (delivery_city IS NOT NULL AND delivery_street IS NOT NULL AND delivery_building IS NOT NULL))",
                "ALTER TABLE orders ADD CONSTRAINT orders_pickup_fee_required CHECK (fulfillment_method <> 'pickup' OR (delivery_fee_kzt IS NOT NULL AND delivery_fee_kzt = 0 AND total_kzt IS NOT NULL))",
                'ALTER TABLE orders ADD CONSTRAINT orders_amounts_nonnegative CHECK (subtotal_kzt >= 0 AND (delivery_fee_kzt IS NULL OR delivery_fee_kzt >= 0) AND (total_kzt IS NULL OR total_kzt >= 0))',
                'ALTER TABLE orders ADD CONSTRAINT orders_total_matches_fee CHECK ((delivery_fee_kzt IS NULL AND total_kzt IS NULL) OR (delivery_fee_kzt IS NOT NULL AND total_kzt = subtotal_kzt + delivery_fee_kzt))',
                'ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_positive CHECK (quantity > 0)',
                'ALTER TABLE order_items ADD CONSTRAINT order_items_amounts_nonnegative CHECK (unit_price_kzt >= 0 AND line_total_kzt >= 0)',
                'ALTER TABLE order_items ADD CONSTRAINT order_items_total_matches_quantity CHECK (line_total_kzt = unit_price_kzt * quantity)',
                "ALTER TABLE order_status_history ADD CONSTRAINT order_status_history_to_valid CHECK (to_status IN ('new', 'confirmed', 'preparing', 'ready_for_pickup', 'shipped', 'completed', 'cancelled'))",
                "ALTER TABLE order_status_history ADD CONSTRAINT order_status_history_from_valid CHECK (from_status IS NULL OR from_status IN ('new', 'confirmed', 'preparing', 'ready_for_pickup', 'shipped', 'completed', 'cancelled'))",
                "ALTER TABLE payment_status_history ADD CONSTRAINT payment_status_history_to_valid CHECK (to_status IN ('unpaid', 'paid', 'partially_refunded', 'refunded'))",
                "ALTER TABLE payment_status_history ADD CONSTRAINT payment_status_history_from_valid CHECK (from_status IS NULL OR from_status IN ('unpaid', 'paid', 'partially_refunded', 'refunded'))",
                'ALTER TABLE payment_status_history ADD CONSTRAINT payment_status_history_amount_positive CHECK (amount_kzt IS NULL OR amount_kzt > 0)',
                'ALTER TABLE stock_balances ADD CONSTRAINT stock_balances_quantities_valid CHECK (on_hand >= 0 AND reserved >= 0 AND reserved <= on_hand)',
                "ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_valid CHECK (type IN ('receipt', 'dispatch', 'adjustment', 'correction'))",
                'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_quantity_nonzero CHECK (quantity_delta <> 0)',
                "ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_correction_reference_valid CHECK ((type = 'correction' AND correction_of_movement_id IS NOT NULL) OR (type <> 'correction' AND correction_of_movement_id IS NULL))",
                'ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_quantity_positive CHECK (quantity > 0)',
                "ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_status_valid CHECK (status IN ('active', 'released', 'consumed'))",
                "ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_resolution_valid CHECK ((status = 'active' AND resolved_at IS NULL AND resolved_by IS NULL) OR (status <> 'active' AND resolved_at IS NOT NULL))",
            ];

            foreach ($checks as $check) {
                DB::statement($check);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('payment_status_history');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
