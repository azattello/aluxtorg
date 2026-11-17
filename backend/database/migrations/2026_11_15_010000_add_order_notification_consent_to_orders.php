<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestampTz('customer_notifications_opted_in_at')->nullable()->after('customer_email');
            $table->timestampTz('customer_notifications_opted_out_at')->nullable()->after('customer_notifications_opted_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'customer_notifications_opted_in_at',
                'customer_notifications_opted_out_at',
            ]);
        });
    }
};
