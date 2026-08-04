<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds the email engine: branded HTML templates for the CORE transactional
 * flows (P1 scope — auth, orders, payments, refunds, vendor) + the full event
 * registry. Events whose code path doesn't exist yet are seeded wired=false,
 * disabled — the admin sees an honest roadmap, not a fictional switchboard.
 *
 * Idempotent: existing slugs/event_keys are left untouched (admin edits win).
 */
class EmailEngineSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('email_templates') || !Schema::hasTable('email_events')) {
            return;
        }

        $templateIds = [];
        foreach ($this->templates() as $t) {
            $existing = DB::table('email_templates')->where('slug', $t['slug'])->first();
            if ($existing !== null) {
                $templateIds[$t['slug']] = (int) $existing->id;
                continue;
            }
            $id = DB::table('email_templates')->insertGetId([
                'slug' => $t['slug'],
                'name' => $t['name'],
                'category' => $t['category'],
                'subject' => $t['subject'],
                'preview_text' => $t['preview'] ?? null,
                'html_body' => $this->layout($t['heading'], $t['body'], $t['cta'] ?? null),
                'text_body' => $t['text'] ?? null,
                'variables' => json_encode($t['vars']),
                'status' => 'active',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('email_template_versions')->insert([
                'template_id' => $id,
                'version' => 1,
                'subject' => $t['subject'],
                'html_body' => $this->layout($t['heading'], $t['body'], $t['cta'] ?? null),
                'text_body' => $t['text'] ?? null,
                'variables' => json_encode($t['vars']),
                'created_at' => now(),
            ]);
            $templateIds[$t['slug']] = (int) $id;
        }

        foreach ($this->events() as $e) {
            if (DB::table('email_events')->where('event_key', $e['key'])->exists()) {
                continue;
            }
            DB::table('email_events')->insert([
                'event_key' => $e['key'],
                'name' => $e['name'],
                'module' => $e['module'],
                'description' => $e['desc'] ?? null,
                'trigger_point' => $e['trigger'] ?? null,
                'template_id' => isset($e['template']) ? ($templateIds[$e['template']] ?? null) : null,
                'enabled' => $e['enabled'] ?? true,
                'queue' => 'default',
                'tries' => 5,
                'wired' => $e['wired'] ?? true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Branded responsive shell: green header, content card, company footer. */
    private function layout(string $heading, string $body, ?array $cta): string
    {
        $button = '';
        if ($cta !== null) {
            $button = <<<HTML
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px auto 8px;"><tr><td style="border-radius:10px;background:#2E5E2A;">
              <a href="{$cta['url']}" style="display:inline-block;padding:13px 32px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:10px;">{$cta['label']}</a>
            </td></tr></table>
            HTML;
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <meta name="color-scheme" content="light dark"><title>{{company_name}}</title></head>
        <body style="margin:0;padding:0;background:#F4F1EA;font-family:Arial,Helvetica,sans-serif;">
          <div style="display:none;max-height:0;overflow:hidden;">{{preview_text}}</div>
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F1EA;padding:24px 12px;"><tr><td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
              <tr><td style="background:#2E5E2A;border-radius:16px 16px 0 0;padding:22px 32px;text-align:center;">
                <span style="font-size:20px;font-weight:bold;color:#ffffff;letter-spacing:0.5px;">{{company_name}}</span>
              </td></tr>
              <tr><td style="background:#ffffff;padding:36px 32px;border-radius:0 0 16px 16px;">
                <h1 style="margin:0 0 16px;font-size:22px;color:#16301A;">{$heading}</h1>
                <div style="font-size:15px;line-height:1.65;color:#3d3d3d;">{$body}</div>
                {$button}
              </td></tr>
              <tr><td style="padding:24px 16px;text-align:center;font-size:12px;color:#908A7E;line-height:1.7;">
                {{company_name}} · {{company_address}}<br>
                <a href="mailto:{{company_email}}" style="color:#2E5E2A;">{{company_email}}</a> · {{company_phone}}<br>
                © {{current_year}} {{company_name}}. All rights reserved.
              </td></tr>
            </table>
          </td></tr></table>
        </body></html>
        HTML;
    }

    private function templates(): array
    {
        return [
            ['slug' => 'auth-otp', 'name' => 'Email OTP', 'category' => 'authentication',
             'subject' => 'Your {{company_name}} verification code', 'preview' => 'Code expires in 10 minutes',
             'heading' => 'Your verification code',
             'body' => '<p>Use this code to continue. It expires in 10 minutes.</p><p style="font-size:32px;font-weight:bold;letter-spacing:8px;color:#2E5E2A;text-align:center;margin:24px 0;">{{otp}}</p><p>If you didn\'t request this, you can safely ignore this email.</p>',
             'text' => 'Your {{company_name}} verification code is {{otp}}. It expires in 10 minutes.',
             'vars' => ['otp']],

            ['slug' => 'auth-forgot-password', 'name' => 'Password reset', 'category' => 'authentication',
             'subject' => 'Reset your {{company_name}} password', 'preview' => 'Use this code to choose a new password',
             'heading' => 'Reset your password',
             'body' => '<p>We received a request to reset your password. Use this code on the reset page:</p><p style="font-size:22px;font-weight:bold;letter-spacing:2px;color:#2E5E2A;text-align:center;margin:24px 0;word-break:break-all;">{{reset_token}}</p><p>If you didn\'t ask for this, ignore this email — your password stays unchanged.</p>',
             'cta' => ['label' => 'Open reset page', 'url' => '{{login_url}}'],
             'text' => 'Your {{company_name}} password reset code: {{reset_token}}',
             'vars' => ['reset_token']],

            ['slug' => 'vendor-credentials', 'name' => 'Vendor account credentials', 'category' => 'vendor',
             'subject' => 'Your {{company_name}} vendor account is ready', 'preview' => 'Sign in and set up your shop',
             'heading' => 'Welcome aboard, {{vendor_name}}',
             'body' => '<p>Your vendor account for <strong>{{shop_name}}</strong> is ready.</p><p><strong>Email:</strong> {{vendor_email}}<br><strong>Temporary password:</strong> {{temp_password}}</p><p>Please sign in and change your password right away.</p>',
             'cta' => ['label' => 'Sign in', 'url' => '{{login_url}}'],
             'vars' => ['vendor_name', 'vendor_email', 'shop_name', 'temp_password']],

            ['slug' => 'vendor-kyc-deadline', 'name' => 'Vendor KYC deadline reminder', 'category' => 'vendor',
             'subject' => 'Action needed: documents due by {{due_date}}', 'preview' => 'Your vendor account needs its KYC documents',
             'heading' => 'Documents due soon',
             'body' => '<p>Your vendor account for <strong>{{shop_name}}</strong> is missing: <strong>{{missing}}</strong>.</p><p>Please upload them by <strong>{{due_date}}</strong>. If they are not on file by then, your account will be placed on hold and your inventory will stop being offered to customers until the documents arrive.</p>',
             'cta' => ['label' => 'Upload documents', 'url' => '{{login_url}}'],
             'vars' => ['shop_name', 'due_date', 'missing']],

            ['slug' => 'contact-admin', 'name' => 'Contact form submission', 'category' => 'admin',
             'subject' => 'Contact form: {{subject_line}}', 'preview' => 'New message from the storefront',
             'heading' => 'New contact message',
             'body' => '<p><strong>From:</strong> {{sender_name}} ({{sender_email}})</p><p style="background:#F4F1EA;border-radius:10px;padding:16px;">{{message}}</p>',
             'vars' => ['sender_name', 'sender_email', 'subject_line', 'message']],

            ['slug' => 'order-placed-customer', 'name' => 'Order confirmation (customer)', 'category' => 'orders',
             'subject' => 'Order {{order_number}} confirmed 🌱', 'preview' => 'Thanks for shopping with us',
             'heading' => 'Thanks for your order, {{customer_name}}!',
             'body' => '<p>We\'ve received order <strong>{{order_number}}</strong> and are getting it ready.</p><p><strong>Order total:</strong> {{order_total}}<br><strong>Payment:</strong> {{payment_status}}<br><strong>Delivery to:</strong> {{delivery_city}}</p><p>We\'ll email you as it moves.</p>',
             'cta' => ['label' => 'Track your order', 'url' => '{{tracking_link}}'],
             'text' => 'Order {{order_number}} confirmed. Total {{order_total}}. Track: {{tracking_link}}',
             'vars' => ['customer_name', 'order_number', 'order_total', 'payment_status', 'delivery_city', 'tracking_link']],

            ['slug' => 'order-placed-admin', 'name' => 'New order (admin/vendor)', 'category' => 'orders',
             'subject' => 'New order {{order_number}} — {{order_total}}', 'preview' => 'A new order needs processing',
             'heading' => 'New order received',
             'body' => '<p>Order <strong>{{order_number}}</strong> was just placed.</p><p><strong>Customer:</strong> {{customer_name}}<br><strong>Total:</strong> {{order_total}}<br><strong>Payment:</strong> {{payment_status}}<br><strong>City:</strong> {{delivery_city}}</p>',
             'cta' => ['label' => 'Open order', 'url' => '{{order_admin_url}}'],
             'vars' => ['customer_name', 'order_number', 'order_total', 'payment_status', 'delivery_city', 'order_admin_url']],

            ['slug' => 'order-status-changed', 'name' => 'Order status update', 'category' => 'orders',
             'subject' => 'Order {{order_number}} is now {{order_status}}', 'preview' => 'Your order moved a step closer',
             'heading' => 'Your order is {{order_status}}',
             'body' => '<p>Hi {{customer_name}},</p><p>Order <strong>{{order_number}}</strong> is now <strong>{{order_status}}</strong>.</p>',
             'cta' => ['label' => 'Track your order', 'url' => '{{tracking_link}}'],
             'vars' => ['customer_name', 'order_number', 'order_status', 'tracking_link']],

            ['slug' => 'order-cancelled', 'name' => 'Order cancelled', 'category' => 'orders',
             'subject' => 'Order {{order_number}} has been cancelled', 'preview' => 'Details inside',
             'heading' => 'Order cancelled',
             'body' => '<p>Hi {{customer_name}},</p><p>Order <strong>{{order_number}}</strong> has been cancelled. If you paid online, the refund follows automatically — you\'ll get a separate email.</p>',
             'cta' => ['label' => 'View order', 'url' => '{{tracking_link}}'],
             'vars' => ['customer_name', 'order_number', 'tracking_link']],

            ['slug' => 'order-delivered', 'name' => 'Order delivered', 'category' => 'orders',
             'subject' => 'Order {{order_number}} was delivered 🎉', 'preview' => 'Happy growing!',
             'heading' => 'Delivered! Happy growing 🌿',
             'body' => '<p>Hi {{customer_name}},</p><p>Order <strong>{{order_number}}</strong> has been delivered. We\'d love to hear how the plants landed — a review helps other plant parents.</p>',
             'cta' => ['label' => 'Leave a review', 'url' => '{{tracking_link}}'],
             'vars' => ['customer_name', 'order_number', 'tracking_link']],

            ['slug' => 'payment-success', 'name' => 'Payment received', 'category' => 'payments',
             'subject' => 'Payment received for order {{order_number}}', 'preview' => 'Your payment went through',
             'heading' => 'Payment received',
             'body' => '<p>Hi {{customer_name}},</p><p>We received <strong>{{order_total}}</strong> for order <strong>{{order_number}}</strong>. You\'re all set.</p>',
             'cta' => ['label' => 'View order', 'url' => '{{tracking_link}}'],
             'vars' => ['customer_name', 'order_number', 'order_total', 'tracking_link']],

            ['slug' => 'payment-failed', 'name' => 'Payment failed', 'category' => 'payments',
             'subject' => 'Payment issue with order {{order_number}}', 'preview' => 'No money was taken',
             'heading' => 'That payment didn\'t go through',
             'body' => '<p>Hi {{customer_name}},</p><p>The payment for order <strong>{{order_number}}</strong> failed. No money was taken. You can retry from your order page.</p>',
             'cta' => ['label' => 'Retry payment', 'url' => '{{tracking_link}}'],
             'vars' => ['customer_name', 'order_number', 'tracking_link']],

            ['slug' => 'refund-requested', 'name' => 'Refund requested', 'category' => 'refunds',
             'subject' => 'Refund request for order {{order_number}}', 'preview' => 'We\'re on it',
             'heading' => 'Refund request received',
             'body' => '<p>Hi {{customer_name}},</p><p>Your refund request for order <strong>{{order_number}}</strong> is in. We\'ll review it and keep you posted.</p>',
             'cta' => ['label' => 'View status', 'url' => '{{tracking_link}}'],
             'vars' => ['customer_name', 'order_number', 'tracking_link']],

            ['slug' => 'refund-updated', 'name' => 'Refund status update', 'category' => 'refunds',
             'subject' => 'Refund update for order {{order_number}}: {{refund_status}}', 'preview' => 'Your refund moved',
             'heading' => 'Refund {{refund_status}}',
             'body' => '<p>Hi {{customer_name}},</p><p>The refund for order <strong>{{order_number}}</strong> is now <strong>{{refund_status}}</strong>.</p>',
             'cta' => ['label' => 'View status', 'url' => '{{tracking_link}}'],
             'vars' => ['customer_name', 'order_number', 'refund_status', 'tracking_link']],
        ];
    }

    private function events(): array
    {
        $wired = fn ($key, $name, $module, $template, $trigger) =>
            ['key' => $key, 'name' => $name, 'module' => $module, 'template' => $template, 'trigger' => $trigger];
        $roadmap = fn ($key, $name, $module, $desc) =>
            ['key' => $key, 'name' => $name, 'module' => $module, 'desc' => $desc, 'wired' => false, 'enabled' => false];

        return [
            // ── Wired (code path calls EmailService today) ──────────────────
            $wired('auth.otp', 'Email OTP code', 'authentication', 'auth-otp', 'ContactController::sendEmailOtp'),
            $wired('auth.forgot_password', 'Password reset link', 'authentication', 'auth-forgot-password', 'UserRepository::sendResetEmail'),
            $wired('vendor.credentials', 'Vendor account credentials', 'vendor', 'vendor-credentials', 'ShopRepository / VendorController / NurseryService'),
            $wired('vendor.kyc_deadline', 'Vendor KYC deadline reminder', 'vendor', 'vendor-kyc-deadline', 'SweepKycDeadlinesCommand'),
            $wired('admin.contact_form', 'Contact form submission', 'admin', 'contact-admin', 'UserController::contactAdmin'),
            $wired('order.placed.customer', 'Order confirmation → customer', 'orders', 'order-placed-customer', 'SendOrderCreationNotification'),
            $wired('order.placed.admin', 'New order → admins', 'orders', 'order-placed-admin', 'SendOrderCreationNotification'),
            $wired('order.placed.vendor', 'New order → vendor', 'orders', 'order-placed-admin', 'SendOrderReceivedNotification'),
            $wired('order.status_changed.customer', 'Order status update → customer', 'orders', 'order-status-changed', 'SendOrderStatusChangedNotification'),
            $wired('order.cancelled.customer', 'Order cancelled → customer', 'orders', 'order-cancelled', 'SendOrderCancelledNotification'),
            $wired('order.delivered.customer', 'Order delivered → customer', 'orders', 'order-delivered', 'SendOrderDeliveredNotification'),
            $wired('payment.success.customer', 'Payment received → customer', 'payments', 'payment-success', 'SendPaymentSuccessNotification'),
            $wired('payment.failed.customer', 'Payment failed → customer', 'payments', 'payment-failed', 'SendPaymentFailedNotification'),
            $wired('refund.requested.customer', 'Refund requested → customer', 'refunds', 'refund-requested', 'SendRefundRequestedNotification'),
            $wired('refund.updated.customer', 'Refund update → customer', 'refunds', 'refund-updated', 'SendRefundUpdateNotification'),

            // ── Legacy-wired (still on blade Mailables; migrate in P2) ──────
            ['key' => 'auth.verify_email', 'name' => 'Email verification link', 'module' => 'authentication',
             'trigger' => 'SendEmailVerificationNotification', 'desc' => 'Legacy Laravel VerifyEmail notification (P2 migration)'],
            ['key' => 'review.created.vendor', 'name' => 'New review → vendor', 'module' => 'reviews', 'trigger' => 'SendReviewNotification', 'desc' => 'Legacy blade (P2)'],
            ['key' => 'question.answered.customer', 'name' => 'Question answered → customer', 'module' => 'reviews', 'trigger' => 'SendQuestionAnsweredNotification', 'desc' => 'Legacy blade (P2)'],
            ['key' => 'location.capture', 'name' => 'Location capture link', 'module' => 'customer', 'trigger' => 'LocationCaptureService', 'desc' => 'Legacy blade (P2)'],

            // ── Roadmap (no code path yet — honest, disabled) ───────────────
            $roadmap('auth.welcome', 'Welcome email after signup', 'authentication', 'No welcome email exists today'),
            $roadmap('auth.password_changed', 'Password changed notice', 'security', 'No trigger on password change'),
            $roadmap('auth.new_device_login', 'Login from a new device', 'security', 'No device fingerprinting exists'),
            $roadmap('cart.abandoned', 'Abandoned cart reminder', 'marketing', 'Needs a scheduled sweep over analytics/cart data'),
            $roadmap('wishlist.reminder', 'Wishlist reminder', 'marketing', 'No scheduled trigger'),
            $roadmap('inventory.low_stock.admin', 'Low stock alert → admin', 'inventory', 'Data exists (inventory feed); no email trigger'),
            $roadmap('review.request', 'Post-delivery review request', 'reviews', 'Could hook order.delivered + delay'),
            $roadmap('vendor.payout.processed', 'Vendor payout processed', 'vendor', 'Withdraw approval has no email today'),
            $roadmap('report.daily.admin', 'Daily business report → admin', 'admin', 'Executive data exists; no scheduled digest'),
            $roadmap('delivery.failed.customer', 'Delivery failed notice', 'delivery', 'Shipment failure state has no email'),
        ];
    }
}
