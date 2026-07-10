<?php

namespace App\Modules\Notifications\Database\Seeders;

use App\Modules\Notifications\Infrastructure\Models\Template;
use Illuminate\Database\Seeder;

/** Default notification templates for the key domain events (idempotent). */
class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            ['event_name' => 'sales.order_placed', 'channel' => 'email', 'subject' => 'Your order is confirmed', 'body' => 'Thank you! Your order {{order_uuid}} has been placed.'],
            ['event_name' => 'sales.order_placed', 'channel' => 'whatsapp', 'subject' => null, 'body' => '🌿 Order {{order_uuid}} placed. We will notify you as it ships.'],
            ['event_name' => 'sales.order_refunded', 'channel' => 'email', 'subject' => 'Your refund is processed', 'body' => 'Your refund for order {{sub_order_uuid}} has been processed.'],
        ];

        foreach ($templates as $t) {
            Template::updateOrCreate(
                ['event_name' => $t['event_name'], 'channel' => $t['channel']],
                ['subject' => $t['subject'], 'body' => $t['body'], 'is_active' => true],
            );
        }
    }
}
