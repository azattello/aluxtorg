<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\PaymentStatusHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentStatusHistory>
 */
class PaymentStatusHistoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'actor_user_id' => null,
            'from_status' => null,
            'to_status' => 'unpaid',
            'amount_kzt' => null,
            'note' => null,
        ];
    }
}
