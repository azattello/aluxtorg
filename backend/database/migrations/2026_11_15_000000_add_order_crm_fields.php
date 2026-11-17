<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('assigned_to_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->index(['assigned_to_id', 'order_status']);
        });

        Schema::create('order_internal_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_internal_notes');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['assigned_to_id', 'order_status']);
            $table->dropConstrainedForeignId('assigned_to_id');
        });
    }
};
