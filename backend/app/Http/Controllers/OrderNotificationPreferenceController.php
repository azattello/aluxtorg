<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OrderNotificationPreferenceController extends Controller
{
    public function show(Order $order): View
    {
        return view('order-notifications.unsubscribe', [
            'orderNumber' => $order->number,
            'unsubscribed' => false,
        ]);
    }

    public function unsubscribe(Order $order): View
    {
        DB::transaction(function () use ($order): void {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($lockedOrder->customer_notifications_opted_in_at && ! $lockedOrder->customer_notifications_opted_out_at) {
                $lockedOrder->update(['customer_notifications_opted_out_at' => now()]);
                AuditLog::query()->create([
                    'actor_user_id' => null,
                    'event_key' => 'customer.order_notifications_unsubscribed',
                    'subject_type' => Order::class,
                    'subject_id' => $lockedOrder->id,
                    'metadata' => [],
                ]);
            }
        });

        return view('order-notifications.unsubscribe', [
            'orderNumber' => $order->number,
            'unsubscribed' => true,
        ]);
    }
}
