<?php

namespace Marvel\Traits;

use Illuminate\Support\Facades\App;
use Marvel\Database\Models\Order;
use Marvel\Enums\EventType;

trait OrderSmsTrait
{
    use SmsTrait;
    
    public function sendOrderCancelSms(Order $order): void
    {
        $language = $order->language;
        App::setLocale($language);
        $customerName = $this->getCustomerName($order);
        $smsArray = [
            'order'             => $order,
            'language'          => $order->language ?? DEFAULT_LANGUAGE,
            'smsEventName'      => EventType::ORDER_CANCELLED,
            'adminMessage'      => __('sms.order.cancelOrder.admin.message', ['ORDER_TRACKING_NUMBER' => $order->tracking_number, 'customer_name' => $customerName]),
            'customerMessage'   => __('sms.order.cancelOrder.customer.message', ['ORDER_TRACKING_NUMBER' => $order->tracking_number, 'customer_name' => $customerName]),
            'storeOwnerMessage' => __('sms.order.cancelOrder.storeOwner.message'),
            // Airtel DLT template (used when active + configured; blob is the fallback)
            'dlt' => ['code' => 'PlantAtHome_Order_Cancelled', 'vars' => [
                'orderId' => (string) $order->tracking_number,
                // Refundable only when money was actually captured (mirrors RefundController's rule)
                'refundAmount' => (string) ($order->payment_status === \Marvel\Enums\PaymentStatus::SUCCESS ? round((float) $order->paid_total) : 0),
            ]],
        ];
        $this->sendSmsOnOrderEvent($smsArray);
    }

    public function sendOrderCreationSms(Order $order): void
    {
        $language = $order->language;
        App::setLocale($language);
        $smsArray = [
            'order'             => $order,
            'language'          => $order->language ?? DEFAULT_LANGUAGE,
            'smsEventName'      => EventType::ORDER_CREATED,
            'adminMessage'      => __('sms.order.orderCreated.admin.message', ['ORDER_TRACKING_NUMBER' => $order->tracking_number]),
            'customerMessage'   => __('sms.order.orderCreated.customer.message', ['ORDER_TRACKING_NUMBER' => $order->tracking_number]),
            'storeOwnerMessage' => __('sms.order.orderCreated.storeOwner.message'),
            'dlt' => ['code' => 'PlantAtHome_Order_Confirmed', 'vars' => [
                'orderId' => (string) $order->tracking_number,
                'orderAmount' => (string) round((float) $order->total),
            ]],
        ];
        $this->sendSmsOnOrderEvent($smsArray);
    }

    public function sendPaymentDoneSuccessfullySms(Order $order): void
    {
        $language = $order->language;
        App::setLocale($language);
        $smsArray = [
            'order'             => $order,
            'language'          => $order->language ?? DEFAULT_LANGUAGE,
            'smsEventName'      => EventType::ORDER_PAYMENT_SUCCESS,
            'adminMessage'      => __('sms.order.paymentSuccessOrder.admin.message', ['ORDER_TRACKING_NUMBER' => $order->tracking_number]),
            'customerMessage'   => __('sms.order.paymentSuccessOrder.customer.message', ['ORDER_TRACKING_NUMBER' => $order->tracking_number]),
            'storeOwnerMessage' => __('sms.order.paymentSuccessOrder.storeOwner.message'),
            'dlt' => ['code' => 'PlantAtHome_Payment_Success', 'vars' => [
                'paymentAmount' => (string) round((float) $order->paid_total),
                'orderId' => (string) $order->tracking_number,
            ]],
        ];
        $this->sendSmsOnOrderEvent($smsArray);
    }

    public function sendOrderStatusChangeSms(Order $order): void
    {
        $language = $order->language;
        App::setLocale($language);
        $status = ucfirst(str_replace('-', ' ', $order->order_status));
        $smsArray = [
            'order'             => $order,
            'language'          => $order->language ?? DEFAULT_LANGUAGE,
            'smsEventName'      => EventType::ORDER_STATUS_CHANGED,
            'adminMessage'      => __('sms.order.statusChangeOrder.admin.message', [
                'ORDER_TRACKING_NUMBER' => $order->tracking_number,
                'order_status'          => $status
            ]),
            'customerMessage'   => __('sms.order.statusChangeOrder.customer.message', [
                'ORDER_TRACKING_NUMBER' => $order->tracking_number,
                'order_status'          => $status
            ]) . $this->courierTrackingSuffix($order),
            'storeOwnerMessage' => __('sms.order.statusChangeOrder.storeOwner.message', ['order_status' => $status]),
        ];
        // DLT templates for the courier milestones (all other statuses keep the
        // generic blob). 'delivered' arrives as order-completed via this same
        // event — the Marvel OrderDelivered event is never dispatched.
        $dltByStatus = [
            'order-at-local-facility' => 'PlantAtHome_Order_Dispatched',
            'order-out-for-delivery'  => 'PlantAtHome_Out_For_Delivery',
            'order-completed'         => 'PlantAtHome_Order_Delivered',
        ];
        if (isset($dltByStatus[$order->order_status])) {
            $vars = ['orderId' => (string) $order->tracking_number];
            if ($order->order_status === 'order-at-local-facility') {
                $eta = $order->delivery_time ?: null;
                $vars['expectedDeliveryDate'] = $eta ? (string) $eta : 'soon';
            }
            $smsArray['dlt'] = ['code' => $dltByStatus[$order->order_status], 'vars' => $vars];
        }
        $this->sendSmsOnOrderEvent($smsArray, false);
    }

    public function sendOrderDeliveredSms($order): void
    {
        $language = $order->language;
        App::setLocale($language);
        $smsArray = [
            'order'             => $order,
            'language'          => $order->language ?? DEFAULT_LANGUAGE,
            'smsEventName'      => EventType::ORDER_DELIVERED,
            'adminMessage'      => __('sms.order.deliverOrder.admin.message', ['ORDER_TRACKING_NUMBER' => $order->tracking_number]),
            'customerMessage'   => __('sms.order.deliverOrder.customer.message', ['ORDER_TRACKING_NUMBER' => $order->tracking_number]),
            'storeOwnerMessage' => __('sms.order.deliverOrder.storeOwner.message'),
            'dlt' => ['code' => 'PlantAtHome_Order_Delivered', 'vars' => [
                'orderId' => (string) $order->tracking_number,
            ]],
        ];
        $this->sendSmsOnOrderEvent($smsArray, false);
    }

    /**
     * Courier tracking line for customer status messages — " Track: {courier}
     * {awb} {url}", parts included only when present; empty string when the
     * order has no live-booked shipment yet. Shipments hang off the PARENT
     * order, which is exactly what the customer branch sends to.
     */
    protected function courierTrackingSuffix($order): string
    {
        try {
            $shipment = $order->shipments()
                ->where(function ($q) {
                    $q->whereNotNull('awb_number')->orWhereNotNull('tracking_url');
                })
                ->latest('id')
                ->first();
        } catch (\Throwable $e) {
            return '';
        }
        if (!$shipment) {
            return '';
        }
        $parts = array_filter([
            $shipment->courier_name,
            $shipment->awb_number,
            $shipment->tracking_url,
        ]);

        return $parts ? ' Track: ' . implode(' ', $parts) : '';
    }


    public function sendPaymentFailedSms($order): void
    {
        $language = $order->language;
        App::setLocale($language);
        $smsArray = [
            'order'             => $order,
            'language'          => $order->language ?? DEFAULT_LANGUAGE,
            'smsEventName'      => EventType::ORDER_PAYMENT_FAILED,
            'adminMessage'      => __('sms.order.paymentFailedOrder.admin.message', ['ORDER_TRACKING_NUMBER' => $order->tracking_number]),
            'customerMessage'   => __('sms.order.paymentFailedOrder.customer.message', ['ORDER_TRACKING_NUMBER' => $order->tracking_number]),
            'storeOwnerMessage' => __('sms.order.paymentFailedOrder.storeOwner.message'),
            'dlt' => ['code' => 'PlantAtHome_Payment_Failed', 'vars' => [
                'orderId' => (string) $order->tracking_number,
            ]],
        ];
        $this->sendSmsOnOrderEvent($smsArray, false);
    }
    protected function getCustomerName($order)
    {
        $customerName = $order->customer;
        if (!$customerName) {
            $customerName = "Guest Customer";
        } else {
            $customerName = $order->customer->name;
        }
        return $customerName;
    }
    public function sendRefundRequestedSms($refund): void
    {
        $order = $refund->order;
        $language = $order->language;
        App::setLocale($language);
        $smsArray = [
            'order'             => $order,
            'language'          => $order->language ?? DEFAULT_LANGUAGE,
            'smsEventName'      => EventType::ORDER_REFUND,
            'adminMessage'      => __('sms.order.refundRequested.admin.message', ['ORDER_TRACKING_NUMBER' => $order->tracking_number]),
            'customerMessage'   => __('sms.order.refundRequested.customer.message', ['ORDER_TRACKING_NUMBER' => $order->tracking_number]),
            // A refund request IS the customer's return request here (no separate RMA flow).
            'dlt' => ['code' => 'PlantAtHome_Return_Requested', 'vars' => [
                'orderId' => (string) $order->tracking_number,
            ]],
        ];
        $this->sendSmsOnRefund($smsArray);
    }
    public function sendRefundUpdateSms($refund): void
    {
        $order = $refund->order;
        $language = $order->language;
        App::setLocale($language);
        $smsArray = [
            'order'             => $order,
            'language'          => $order->language ?? DEFAULT_LANGUAGE,
            'smsEventName'      => EventType::ORDER_REFUND,
            // Replacement keys are bare names — a ':' prefix here made the
            // :refund_status token never substitute.
            'adminMessage'      => __('sms.order.refundUpdated.admin.message', ['ORDER_TRACKING_NUMBER' => $order->tracking_number, 'refund_status' => $refund->status]),
            'customerMessage'   => __('sms.order.refundUpdated.customer.message', ['ORDER_TRACKING_NUMBER' => $order->tracking_number, 'refund_status' => $refund->status]),
        ];
        if ($refund->status === 'approved') {
            $smsArray['dlt'] = ['code' => 'PlantAtHome_Refund_Initiated', 'vars' => [
                'refundAmount' => (string) round((float) $refund->amount),
                'orderId' => (string) $order->tracking_number,
            ]];
        }
        $this->sendSmsOnRefund($smsArray);
    }
}
