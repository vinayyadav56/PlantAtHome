<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The 18 Airtel DLT SMS templates, seeded into the existing notification
 * template engine (email_templates, channel = sms).
 *
 * Rules baked in:
 *  - bodies use the engine's {{semanticVar}} convention — Airtel's {#var#} is
 *    never stored; the ORDER of the variables array IS the DLT variable order
 *  - no URLs anywhere (Airtel DLT rejects unvalidatable domains) — bodies and
 *    sample values are plain text only
 *  - dlt_template_id starts NULL: the real Airtel id is entered by the admin
 *    after approval; it is never generated and never equals template_code
 *  - status starts 'draft' — production sends require status 'active' plus a
 *    configured MSG91 Flow ID (provider_template_id)
 *  - idempotent + non-destructive: a row whose template_code already exists is
 *    left completely alone (admin-configured values survive re-runs)
 */
class SmsTemplateSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('email_templates') || ! Schema::hasColumn('email_templates', 'template_code')) {
            return;
        }

        $created = 0;
        foreach ($this->templates() as $t) {
            if (DB::table('email_templates')->where('template_code', $t['code'])->exists()) {
                continue;
            }
            $slug = strtolower(str_replace('_', '-', $t['code']));
            $id = DB::table('email_templates')->insertGetId([
                'slug' => $slug,
                'name' => $t['name'],
                'category' => $t['category'],
                'channel' => 'sms',
                'template_code' => $t['code'],
                'subject' => $t['name'],   // NOT NULL column; unused for SMS
                'html_body' => '',         // NOT NULL column; unused for SMS
                'text_body' => $t['body'],
                'variables' => json_encode($t['vars']),
                'status' => 'draft',
                'dlt_template_id' => null,
                'provider_template_id' => null,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if (Schema::hasTable('email_template_versions')) {
                DB::table('email_template_versions')->insert([
                    'template_id' => $id,
                    'version' => 1,
                    'subject' => $t['name'],
                    'html_body' => '',
                    'text_body' => $t['body'],
                    'variables' => json_encode($t['vars']),
                    'created_at' => now(),
                ]);
            }
            $created++;
        }
        $this->command?->info("SMS DLT templates: {$created} created, " . (18 - $created) . ' already present.');
    }

    /** @return array<int, array{code:string,name:string,category:string,body:string,vars:array}> */
    private function templates(): array
    {
        $v = fn (string $name, string $type, string $sample, string $desc) => [
            'name' => $name, 'type' => $type, 'sample' => $sample, 'description' => $desc,
        ];
        $orderId = $v('orderId', 'alphanumeric', 'PAH-20260911-10452', 'Unique PlantAtHome order identification number.');

        return [
            [
                'code' => 'PlantAtHome_Login_OTP', 'name' => 'Login OTP', 'category' => 'authentication',
                'body' => 'PlantAtHome verification code is {{otp}}. This code is valid for {{otpValidityMinutes}} minutes. Do not share this code with anyone.',
                'vars' => [
                    $v('otp', 'numeric', '123456', 'One-time password sent to the customer for login or mobile number verification.'),
                    $v('otpValidityMinutes', 'numeric', '10', 'Validity period of the OTP in minutes.'),
                ],
            ],
            [
                'code' => 'PlantAtHome_Password_Reset_OTP', 'name' => 'Password Reset OTP', 'category' => 'authentication',
                'body' => 'PlantAtHome password reset code is {{otp}}. This code is valid for {{otpValidityMinutes}} minutes. If you did not request this, please ignore this message.',
                'vars' => [
                    $v('otp', 'numeric', '123456', 'One-time password sent to the customer to reset their PlantAtHome account password.'),
                    $v('otpValidityMinutes', 'numeric', '10', 'Validity period of the password reset OTP in minutes.'),
                ],
            ],
            [
                'code' => 'PlantAtHome_Account_Created', 'name' => 'Account Created', 'category' => 'authentication',
                'body' => 'Welcome to PlantAtHome. Your account has been successfully created. You can now access your account using your registered mobile number.',
                'vars' => [],
            ],
            [
                'code' => 'PlantAtHome_Order_Confirmed', 'name' => 'Order Confirmed', 'category' => 'orders',
                'body' => 'PlantAtHome order {{orderId}} has been confirmed. Order value is Rs. {{orderAmount}}. We will notify you when your order is dispatched.',
                'vars' => [
                    $orderId,
                    $v('orderAmount', 'numeric', '1499', 'Total order value in Indian Rupees.'),
                ],
            ],
            [
                'code' => 'PlantAtHome_Payment_Success', 'name' => 'Payment Success', 'category' => 'payments',
                'body' => 'Payment of Rs. {{paymentAmount}} for PlantAtHome order {{orderId}} has been received successfully. Thank you for your order.',
                'vars' => [
                    $v('paymentAmount', 'numeric', '1499', 'Payment amount received from the customer in Indian Rupees.'),
                    $v('orderId', 'alphanumeric', 'PAH-20260911-10452', 'Unique PlantAtHome order identification number associated with the payment.'),
                ],
            ],
            [
                'code' => 'PlantAtHome_Payment_Failed', 'name' => 'Payment Failed', 'category' => 'payments',
                'body' => 'Payment for PlantAtHome order {{orderId}} could not be completed. Please retry the payment or choose another payment method.',
                'vars' => [
                    $v('orderId', 'alphanumeric', 'PAH-20260911-10452', 'Unique PlantAtHome order identification number for the failed payment.'),
                ],
            ],
            [
                'code' => 'PlantAtHome_Order_Dispatched', 'name' => 'Order Dispatched', 'category' => 'delivery',
                'body' => 'Your PlantAtHome order {{orderId}} has been dispatched. Expected delivery date is {{expectedDeliveryDate}}. We will notify you of further delivery updates.',
                'vars' => [
                    $orderId,
                    $v('expectedDeliveryDate', 'alphanumeric', '15 Sep 2026', "Expected delivery date of the customer's order."),
                ],
            ],
            [
                'code' => 'PlantAtHome_Out_For_Delivery', 'name' => 'Out For Delivery', 'category' => 'delivery',
                'body' => 'Your PlantAtHome order {{orderId}} is out for delivery today. Please keep your phone available for delivery updates.',
                'vars' => [$orderId],
            ],
            [
                'code' => 'PlantAtHome_Order_Delivered', 'name' => 'Order Delivered', 'category' => 'delivery',
                'body' => 'Your PlantAtHome order {{orderId}} has been delivered successfully. Thank you for shopping with PlantAtHome.',
                'vars' => [$orderId],
            ],
            [
                'code' => 'PlantAtHome_Order_Cancelled', 'name' => 'Order Cancelled', 'category' => 'orders',
                'body' => 'Your PlantAtHome order {{orderId}} has been cancelled. Refund of Rs. {{refundAmount}}, if applicable, will be processed as per the refund policy.',
                'vars' => [
                    $orderId,
                    $v('refundAmount', 'numeric', '1499', 'Refund amount applicable to the cancelled order in Indian Rupees.'),
                ],
            ],
            [
                'code' => 'PlantAtHome_Refund_Initiated', 'name' => 'Refund Initiated', 'category' => 'refunds',
                'body' => "Refund of Rs. {{refundAmount}} for PlantAtHome order {{orderId}} has been initiated. The amount will be credited according to your payment provider's processing time.",
                'vars' => [
                    $v('refundAmount', 'numeric', '1499', 'Refund amount initiated for the customer in Indian Rupees.'),
                    $v('orderId', 'alphanumeric', 'PAH-20260911-10452', 'Unique PlantAtHome order identification number associated with the refund.'),
                ],
            ],
            [
                'code' => 'PlantAtHome_Password_Changed', 'name' => 'Password Changed', 'category' => 'security',
                'body' => 'Your PlantAtHome account password was changed successfully. If you did not make this change, please secure your account immediately.',
                'vars' => [],
            ],
            [
                'code' => 'PlantAtHome_Security_Alert', 'name' => 'Security Alert', 'category' => 'security',
                'body' => 'Security alert: A {{securityActivity}} was detected on your PlantAtHome account on {{activityDateTime}}. If this was not you, please secure your account immediately.',
                'vars' => [
                    $v('securityActivity', 'alphanumeric', 'new login', "Security activity detected on the customer's PlantAtHome account."),
                    $v('activityDateTime', 'alphanumeric', '11 Sep 2026 19:30', 'Date and time when the security activity was detected.'),
                ],
            ],
            [
                'code' => 'PlantAtHome_Delivery_Attempt_Failed', 'name' => 'Delivery Attempt Failed', 'category' => 'delivery',
                'body' => 'Delivery of your PlantAtHome order {{orderId}} could not be completed. Our delivery partner may contact you for another delivery attempt.',
                'vars' => [$orderId],
            ],
            [
                'code' => 'PlantAtHome_Delivery_Rescheduled', 'name' => 'Delivery Rescheduled', 'category' => 'delivery',
                'body' => 'Delivery of your PlantAtHome order {{orderId}} has been rescheduled to {{newDeliveryDate}}. We will notify you of further delivery updates.',
                'vars' => [
                    $orderId,
                    $v('newDeliveryDate', 'alphanumeric', '18 Sep 2026', "New scheduled delivery date for the customer's order."),
                ],
            ],
            [
                'code' => 'PlantAtHome_Return_Requested', 'name' => 'Return Requested', 'category' => 'refunds',
                'body' => 'Your return request for PlantAtHome order {{orderId}} has been received. We will notify you after your request is reviewed.',
                'vars' => [
                    $v('orderId', 'alphanumeric', 'PAH-20260911-10452', 'Unique PlantAtHome order identification number associated with the return request.'),
                ],
            ],
            [
                'code' => 'PlantAtHome_Return_Approved', 'name' => 'Return Approved', 'category' => 'refunds',
                'body' => 'Your return request for PlantAtHome order {{orderId}} has been approved. Our team will provide further return instructions.',
                'vars' => [
                    $v('orderId', 'alphanumeric', 'PAH-20260911-10452', 'Unique PlantAtHome order identification number associated with the return request.'),
                ],
            ],
            [
                'code' => 'PlantAtHome_Replacement_Dispatched', 'name' => 'Replacement Dispatched', 'category' => 'delivery',
                'body' => 'Your replacement for PlantAtHome order {{orderId}} has been dispatched. Expected delivery date is {{expectedDeliveryDate}}.',
                'vars' => [
                    $v('orderId', 'alphanumeric', 'PAH-20260911-10452', 'Unique PlantAtHome order identification number associated with the replacement.'),
                    $v('expectedDeliveryDate', 'alphanumeric', '15 Sep 2026', 'Expected delivery date of the replacement order.'),
                ],
            ],
        ];
    }
}
