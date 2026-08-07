<?php

namespace Marvel\Services;

/**
 * One place that turns an Order into email template variables — every order/
 * payment/refund listener feeds templates through this, so variable names stay
 * consistent across the whole registry.
 */
class OrderEmailVars
{
    public static function from($order): array
    {
        $shopUrl = rtrim((string) (config('shop.shop_url') ?: ''), '/');
        $dashUrl = rtrim((string) (config('shop.dashboard_url') ?: ''), '/');
        $tracking = (string) ($order->tracking_number ?? '');
        $token = (string) ($order->tracking_token ?? '');

        $city = null;
        try {
            $a = is_string($order->shipping_address) ? json_decode($order->shipping_address, true) : (array) $order->shipping_address;
            $city = $a['city'] ?? ($a['address']['city'] ?? null);
        } catch (\Throwable) {
        }

        return [
            'customer_name' => (string) ($order->customer_name ?? optional($order->customer)->name ?? 'there'),
            'order_number' => $tracking,
            'order_total' => '₹' . number_format((float) ($order->paid_total ?? $order->total ?? 0), 2),
            'order_status' => ucwords(str_replace(['order-', '-'], ['', ' '], (string) ($order->order_status ?? ''))),
            'payment_status' => ucwords(str_replace(['payment-', '-'], ['', ' '], (string) ($order->payment_status ?? ''))),
            'delivery_city' => (string) ($city ?? ''),
            'tracking_link' => $shopUrl . '/orders/' . $tracking . ($token !== '' ? '?token=' . $token : ''),
            'order_admin_url' => $dashUrl . '/orders/' . ($order->id ?? ''),
        ];
    }
}
