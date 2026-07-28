<?php

namespace Marvel\Integrations;

use Marvel\Database\Models\CourierPartnerConfig;
use Throwable;

/**
 * Where each provider's credentials lived BEFORE this module existed.
 *
 * This is the reason the Integration module can ship without a flag day: `integration_providers`
 * can be completely empty and every feature keeps working, because IntegrationService falls through
 * to here and then to config()/env(). Providers are migrated one at a time, and a bad migration is
 * a config rollback rather than an outage.
 *
 * Every lookup is wrapped defensively — a legacy table may not exist on an environment where its
 * migration has not run, and a missing table must read as "not configured", never as a 500.
 *
 * As providers are absorbed, entries here become dead and can be deleted. Until then they are
 * load-bearing.
 */
class LegacyBridge
{
    /** Secret values by (slug, field). */
    public function secret(string $slug, string $field): string
    {
        return (string) match ($slug) {
            'shipping_service' => $this->shippingService($field),
            'razorpay' => match ($field) {
                'key_secret'     => config('shop.razorpay.key_secret'),
                'webhook_secret' => config('shop.razorpay.webhook_secret'),
                default          => '',
            },
            'stripe' => match ($field) {
                'secret_key'     => config('shop.stripe.stripe_api_secret') ?? env('STRIPE_API_SECRET'),
                'webhook_secret' => env('STRIPE_WEBHOOK_SECRET_KEY'),
                default          => '',
            },
            'msg91' => match ($field) {
                'auth_key' => config('services.msg91.auth_key') ?? env('MSG91_AUTH_KEY'),
                default    => '',
            },
            'twilio' => match ($field) {
                'auth_token' => config('services.twilio.auth_token') ?? env('TWILIO_AUTH_TOKEN'),
                default      => '',
            },
            'whatsapp' => match ($field) {
                'access_token' => config('services.whatsapp.access_token') ?? env('WHATSAPP_ACCESS_TOKEN'),
                default        => '',
            },
            'sendgrid' => match ($field) {
                'api_key' => env('SENDGRID_API_KEY'),
                default   => '',
            },
            'aws_ses', 'aws_s3' => match ($field) {
                'secret_access_key' => config('filesystems.disks.s3.secret') ?? env('AWS_SECRET_ACCESS_KEY'),
                default             => '',
            },
            'google_maps' => match ($field) {
                'server_key' => config('location.google_maps_server_key') ?? env('GOOGLE_MAPS_SERVER_KEY'),
                default      => '',
            },
            'openai' => match ($field) {
                // NOTE the capital K — the existing config key really is `secret_Key`.
                'api_key' => config('shop.openai.secret_Key') ?? env('OPENAI_SECRET_KEY'),
                default   => '',
            },
            'anthropic' => match ($field) {
                'api_key' => env('ANTHROPIC_API_KEY'),
                default   => '',
            },
            'google_gemini' => match ($field) {
                'api_key' => env('GEMINI_API_KEY'),
                default   => '',
            },
            'replicate' => match ($field) {
                'api_token' => config('services.replicate.token') ?? env('REPLICATE_API_TOKEN'),
                default     => '',
            },
            default => '',
        };
    }

    /** Non-secret values by (slug, field). */
    public function config(string $slug, string $field): mixed
    {
        return match ($slug) {
            'shipping_service' => match ($field) {
                'url'     => config('services.shipping_service.url'),
                'timeout' => config('services.shipping_service.timeout'),
                default   => null,
            },
            'razorpay' => match ($field) {
                'key_id' => config('shop.razorpay.key_id'),
                default  => null,
            },
            'stripe' => match ($field) {
                'publishable_key' => config('shop.stripe.stripe_api_key') ?? env('STRIPE_API_KEY'),
                default           => null,
            },
            'msg91' => match ($field) {
                'sender'      => config('services.msg91.sender'),
                'template_id' => config('services.msg91.template_id'),
                'flow_id'     => config('services.msg91.flow_id'),
                default       => null,
            },
            'twilio' => match ($field) {
                'account_sid'      => config('services.twilio.account_sid'),
                'from_number'      => config('services.twilio.from_number'),
                'verification_sid' => config('services.twilio.verification_sid'),
                default            => null,
            },
            'whatsapp' => match ($field) {
                'phone_number_id' => config('services.whatsapp.phone_number_id'),
                'api_version'     => config('services.whatsapp.api_version'),
                'otp_template'    => config('services.whatsapp.otp_template'),
                default           => null,
            },
            'aws_s3' => match ($field) {
                'access_key_id' => config('filesystems.disks.s3.key'),
                'region'        => config('filesystems.disks.s3.region'),
                'bucket'        => config('filesystems.disks.s3.bucket'),
                'url'           => config('filesystems.disks.s3.url'),
                default         => null,
            },
            'aws_ses' => match ($field) {
                'access_key_id' => config('services.ses.key') ?? env('AWS_ACCESS_KEY_ID'),
                'region'        => config('services.ses.region') ?? env('AWS_DEFAULT_REGION'),
                default         => null,
            },
            'openai' => match ($field) {
                'text_model'  => env('OPENAI_TEXT_MODEL'),
                'image_model' => env('OPENAI_IMAGE_MODEL'),
                default       => null,
            },
            'anthropic' => match ($field) {
                'model' => env('CLAUDE_TRANSLATE_MODEL'),
                default => null,
            },
            'replicate' => match ($field) {
                'model'    => config('services.replicate.model'),
                'base_url' => config('services.replicate.base_url'),
                'owner'    => config('services.replicate.owner'),
                default    => null,
            },
            // The delivery partners' own credentials have never lived in the monolith: since the
            // in-process courier stack was retired, they exist only as env vars inside the Go
            // shipping-service. There is deliberately nothing to bridge.
            'porter', 'borzo', 'shiprocket' => $this->partnerBaseUrlDefault($slug, $field),
            default => null,
        };
    }

    /** Sensible endpoint defaults so a fresh partner form is not blank. */
    private function partnerBaseUrlDefault(string $slug, string $field): ?string
    {
        if ($field !== 'base_url') {
            return null;
        }

        return match ($slug) {
            'porter'     => 'https://pfe-apigw-uat.porter.in',
            'borzo'      => 'https://robotapitest-in.borzodelivery.com/api/business/1.8',
            'shiprocket' => 'https://apiv2.shiprocket.in',
            default      => null,
        };
    }

    /**
     * The shipping-service link is the one provider that already had an encrypted admin-managed
     * home (courier_partner_configs), so read it before falling back to config().
     */
    private function shippingService(string $field): string
    {
        try {
            $row = CourierPartnerConfig::query()->where('partner_code', 'shipping_service')->first();
            $creds = (array) ($row?->credentials ?? []);
            if (trim((string) ($creds[$field] ?? '')) !== '') {
                return (string) $creds[$field];
            }
        } catch (Throwable) {
            // Table absent (pre-migration environment) — fall through to config().
        }

        return (string) match ($field) {
            'api_key'      => config('services.shipping_service.api_key'),
            'callback_key' => config('services.shipping_service.callback_key'),
            'sync_key'     => config('services.shipping_service.sync_key') ?? env('INTEGRATION_SYNC_KEY'),
            default        => '',
        };
    }
}
