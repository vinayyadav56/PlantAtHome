<?php

namespace Marvel\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Marvel\Database\Models\CarePlanSetting;
use Marvel\Events\OrderDelivered;
use Marvel\Services\CarePlanService;

/**
 * When a plant order is delivered, kick off an AI care plan + reminder schedule for each plant
 * in it (the headline post-purchase experience). Queued + idempotent (one plan per user+product).
 * No-op unless the feature is enabled and auto_on_delivery is on — so it never spends until the
 * admin turns it on with ANTHROPIC credits configured.
 */
class GenerateCarePlanOnDelivery implements ShouldQueue
{
    // Bound the per-order spend regardless of basket size.
    private const MAX_PLANTS_PER_ORDER = 8;

    public function handle(OrderDelivered $event): void
    {
        $order = $event->order;
        if (!$order || !$order->customer_id) {
            return;
        }

        $setting = CarePlanSetting::first();
        if (!$setting || !$setting->enabled || !$setting->auto_on_delivery) {
            return;
        }

        $region = $this->regionFromOrder($order);
        $language = $order->language ?: 'en';
        $service = new CarePlanService();

        $count = 0;
        foreach ($order->products as $product) {
            // Heuristic: only real plants carry a scientific name in our catalog (pots/tools/produce don't).
            $scientific = $product->scientific_name ?? null;
            if (empty($scientific)) {
                continue;
            }
            if ($count >= self::MAX_PLANTS_PER_ORDER) {
                break;
            }
            $count++;

            $service->generateForPlant([
                'user_id'         => $order->customer_id,
                'order_id'        => $order->id,
                'product_id'      => $product->id,
                'plant_name'      => $product->name,
                'scientific_name' => $scientific,
                'image_url'       => optional($product->image)->original ?? (is_array($product->image ?? null) ? ($product->image['original'] ?? null) : null),
                'region'          => $region,
                'language'        => $language,
                'source'          => 'delivery',
            ]);
        }
    }

    private function regionFromOrder($order): ?string
    {
        $addr = $order->shipping_address ?? null;
        if (!$addr) {
            return null;
        }
        $a = is_array($addr) ? $addr : (array) $addr;
        return trim(implode(', ', array_filter([$a['city'] ?? null, $a['state'] ?? null]))) ?: null;
    }
}
