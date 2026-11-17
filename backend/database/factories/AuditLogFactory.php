<?php

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'actor_user_id' => null,
            'event_key' => 'catalog.product.created',
            'subject_type' => null,
            'subject_id' => null,
            'metadata' => [],
        ];
    }
}
