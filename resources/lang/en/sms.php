<?php
// Customer-facing copy rides the WhatsApp utility template as ONE variable, so
// every string must read as a complete, clean sentence on its own. Keep the
// :PLACEHOLDER tokens exactly as-is — OrderSmsTrait/SmsTrait substitute them.
return [
    // Owner alerts. One line, one WhatsApp template variable — the gateway
    // flattens newlines because Meta rejects them in body parameters.
    'owner' => [
        'customerSignup' => [
            'message' => 'New signup on PlantAtHome: :NAME (:CONTACT).',
            'subject' => 'New customer signed up',
        ],
    ],
    'order' => [
        'cancelOrder'         => [
            'admin'      => [
                'message' => 'Order :ORDER_TRACKING_NUMBER has been cancelled.',
                'subject' => 'Order has been cancelled',
            ],
            'customer'   => [
                'message' => 'Your order *:ORDER_TRACKING_NUMBER* has been cancelled. If this was unexpected, please contact our support team.',
                'subject' => 'Your order has been cancelled',
            ],
            'storeOwner' => [
                'message' => 'Dear shop owner, order :ORDER_TRACKING_NUMBER has been cancelled.',
                'subject' => 'Order has been cancelled',
            ],
        ],
        'orderCreated'        => [
            'admin'      => [
                'message' => 'New order :ORDER_TRACKING_NUMBER has been placed by :customer_name.',
                'subject' => 'New order has been placed',
            ],
            'customer'   => [
                'message' => 'Thank you for your order! Order *:ORDER_TRACKING_NUMBER* has been placed successfully. We will notify you as it progresses.',
                'subject' => 'Your order has been placed successfully',
            ],
            'storeOwner' => [
                'message' => 'Dear shop owner, new order :ORDER_TRACKING_NUMBER has been placed by :customer_name.',
                'subject' => 'New order has been placed',
            ],
        ],
        'deliverOrder'        => [
            'admin'      => [
                'message' => 'Order :ORDER_TRACKING_NUMBER has been delivered successfully.',
                'subject' => 'Order has been delivered',
            ],
            'customer'   => [
                'message' => 'Your order *:ORDER_TRACKING_NUMBER* has been delivered. We hope your plants thrive — happy growing!',
                'subject' => 'Your order has been delivered successfully',
            ],
            'storeOwner' => [
                'message' => 'Dear shop owner, order :ORDER_TRACKING_NUMBER has been delivered successfully.',
                'subject' => 'Order has been delivered',
            ],

        ],
        'statusChangeOrder'   => [
            'admin'      => [
                'message' => 'Order :ORDER_TRACKING_NUMBER status changed to :order_status.',
                'subject' => 'Order status has been changed',
            ],
            'customer'   => [
                'message' => 'Update on your order *:ORDER_TRACKING_NUMBER* — status: *:order_status*.',
                'subject' => 'Your order status has been changed',
            ],
            'storeOwner' => [
                'message' => 'Dear shop owner, order :ORDER_TRACKING_NUMBER status changed to :order_status.',
                'subject' => 'Order status has been changed',
            ],
        ],
        'paymentSuccessOrder' => [
            'admin'      => [
                'message' => 'Payment received for order :ORDER_TRACKING_NUMBER.',
                'subject' => 'Order payment has been successful',
            ],
            'customer'   => [
                'message' => 'Payment received! Your payment for order *:ORDER_TRACKING_NUMBER* was successful.',
                'subject' => 'Your order payment has been successful',
            ],
            'storeOwner' => [
                'message' => 'Dear shop owner, payment has been received for order :ORDER_TRACKING_NUMBER.',
                'subject' => 'Order payment has been successful',
            ],
        ],
        'paymentFailedOrder'  => [
            'admin'      => [
                'message' => 'Payment failed for order :ORDER_TRACKING_NUMBER.',
                'subject' => 'Order payment has failed',
            ],
            'customer'   => [
                'message' => 'Payment for your order *:ORDER_TRACKING_NUMBER* could not be processed. Please try again or use a different payment method.',
                'subject' => 'Your order payment has failed',
            ],
            'storeOwner' => [
                'message' => 'Dear shop owner, payment failed for order :ORDER_TRACKING_NUMBER.',
                'subject' => 'Order payment has failed',
            ],
        ],
        'refundRequested'     => [
            'admin'    => [
                'message' => 'A refund has been requested for order :ORDER_TRACKING_NUMBER.',
                'subject' => 'Refund requested',
            ],
            'customer' => [
                'message' => 'Your refund request for order *:ORDER_TRACKING_NUMBER* has been submitted. We will update you once it is reviewed.',
                'subject' => 'Your refund request has been submitted successfully',
            ],
        ],
        'refundStatusChange' => [
            'admin'    => [
                'message' => 'Refund status for order :ORDER_TRACKING_NUMBER changed to :refund_status.',
                'subject' => 'Refund status has been changed',
            ],
            'customer' => [
                'message' => 'Update on your refund for order *:ORDER_TRACKING_NUMBER* — status: *:refund_status*.',
                'subject' => 'Your refund status has been changed',
            ],
        ],
    ]
];
