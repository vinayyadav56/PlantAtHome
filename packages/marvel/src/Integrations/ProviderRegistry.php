<?php

namespace Marvel\Integrations;

/**
 * The catalogue of every third-party provider PlantAtHome integrates.
 *
 * Adding a provider is ONE entry here. It then appears in the admin with a working form, gets
 * encrypted storage, connection testing and health tracking, with no migration and no new
 * controller — which is the entire point of the module.
 *
 * Field names are the stable snake_case keys used end to end: the same string is the form input
 * name, the array key in the encrypted bag, and (for delivery partners) the key the Go
 * shipping-service reads out of the synced credential bag. Renaming one is a breaking change.
 */
class ProviderRegistry
{
    /** @var array<string,ProviderDefinition>|null */
    private static ?array $cache = null;

    /** @return array<string,ProviderDefinition> keyed by slug */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $defs = [
            // ── Delivery ────────────────────────────────────────────────────────────────────
            // These three sync to the Go shipping-service, which is where the partner HTTP calls
            // actually happen. The monolith stores and pushes; it never calls a courier directly.
            new ProviderDefinition(
                slug: 'porter',
                displayName: 'Porter',
                category: ProviderDefinition::CATEGORY_DELIVERY,
                credentialFields: [
                    ['name' => 'api_key', 'label' => 'API Key', 'required' => true, 'help' => 'Porter x-api-key for this environment.'],
                    ['name' => 'webhook_token', 'label' => 'Webhook Token', 'help' => 'Shared token Porter sends back on status callbacks. Without it, webhooks are rejected and status advances only via polling.'],
                ],
                configFields: [
                    ['name' => 'base_url', 'label' => 'Base URL', 'type' => 'select', 'required' => true,
                        'options' => [
                            ['value' => 'https://pfe-apigw-uat.porter.in', 'label' => 'UAT — pfe-apigw-uat.porter.in'],
                            ['value' => 'https://pfe-apigw.porter.in', 'label' => 'Production — pfe-apigw.porter.in'],
                        ],
                        'help' => 'Porter UAT is IP-allowlisted at the edge: an un-allowlisted source gets a 403 on every path, identical with or without a valid key.'],
                    ['name' => 'vehicle_type', 'label' => 'Preferred Vehicle', 'placeholder' => '2 Wheeler', 'help' => 'Blank picks the cheapest quoted vehicle.'],
                    ['name' => 'cod_supported', 'label' => 'Supports COD', 'type' => 'switch',
                        'help' => 'OFF by default. Porter\'s documented create_order carries no amount-to-collect field, so a COD order routed here reaches a rider who collects nothing. Turn on only after Porter confirms the mechanism.'],
                ],
                priority: 10,
                syncsToShipping: true,
                blurb: 'On-demand 2W/3W/truck, same-city lane.',
            ),
            new ProviderDefinition(
                slug: 'borzo',
                displayName: 'Borzo',
                category: ProviderDefinition::CATEGORY_DELIVERY,
                credentialFields: [
                    ['name' => 'token', 'label' => 'API Token', 'required' => true],
                    ['name' => 'callback_token', 'label' => 'Callback Token', 'help' => 'Used to verify inbound status callbacks (HMAC over the body).'],
                ],
                configFields: [
                    ['name' => 'base_url', 'label' => 'Base URL', 'required' => true, 'placeholder' => 'https://robotapitest-in.borzodelivery.com/api/business/1.8'],
                    ['name' => 'vehicle_type_id', 'label' => 'Vehicle Type ID', 'type' => 'number', 'placeholder' => '8'],
                    ['name' => 'matter', 'label' => 'Shipment Matter', 'placeholder' => 'Plants & garden supplies'],
                ],
                priority: 20,
                syncsToShipping: true,
                blurb: 'Same-city courier with COD collection and multi-point drops.',
            ),
            new ProviderDefinition(
                slug: 'shiprocket',
                displayName: 'Shiprocket',
                category: ProviderDefinition::CATEGORY_DELIVERY,
                credentialFields: [
                    ['name' => 'email', 'label' => 'Account Email', 'type' => 'text', 'help' => 'Exchanged for a token. Not needed if a permanent API token is set.'],
                    ['name' => 'password', 'label' => 'Account Password'],
                    ['name' => 'api_token', 'label' => 'Permanent API Token', 'help' => 'Preferred: avoids storing the account password.'],
                    ['name' => 'webhook_token', 'label' => 'Webhook Token'],
                ],
                configFields: [
                    ['name' => 'base_url', 'label' => 'Base URL', 'required' => true, 'placeholder' => 'https://apiv2.shiprocket.in'],
                ],
                priority: 30,
                syncsToShipping: true,
                blurb: 'Nationwide courier aggregation (the courier lane, not same-city).',
            ),
            new ProviderDefinition(
                slug: 'shipping_service',
                displayName: 'Shipping Service',
                category: ProviderDefinition::CATEGORY_DELIVERY,
                credentialFields: [
                    ['name' => 'api_key', 'label' => 'Service API Key', 'required' => true, 'help' => 'X-Api-Key this monolith sends to the Go shipping-service.'],
                    ['name' => 'callback_key', 'label' => 'Callback Key', 'help' => 'X-Api-Key the shipping-service must present on its callbacks to us.'],
                    ['name' => 'sync_key', 'label' => 'Credential Sync Key', 'help' => '32-byte hex/base64. Seals credential pushes to the shipping service. Must equal its INTEGRATION_SYNC_KEY.'],
                ],
                configFields: [
                    ['name' => 'url', 'label' => 'Service URL', 'required' => true, 'placeholder' => 'https://xxxx.ap-south-1.awsapprunner.com'],
                    ['name' => 'timeout', 'label' => 'Timeout (seconds)', 'type' => 'number', 'placeholder' => '20'],
                ],
                priority: 1,
                blurb: 'The Go microservice that performs all courier API calls.',
            ),

            // ── Payment ─────────────────────────────────────────────────────────────────────
            new ProviderDefinition(
                slug: 'razorpay',
                displayName: 'Razorpay',
                category: ProviderDefinition::CATEGORY_PAYMENT,
                credentialFields: [
                    ['name' => 'key_secret', 'label' => 'Key Secret', 'required' => true],
                    ['name' => 'webhook_secret', 'label' => 'Webhook Secret', 'help' => 'Verifies X-Razorpay-Signature on inbound payment webhooks.'],
                ],
                configFields: [
                    // The key ID is PUBLIC by design — the browser and the mobile app need it to open
                    // the payment sheet, and it is already served from /api/settings.
                    ['name' => 'key_id', 'label' => 'Key ID', 'required' => true, 'help' => 'Public. rzp_live_… for real payments; rzp_test_… only ever accepts test cards.'],
                ],
                priority: 10,
                blurb: 'Cards, UPI and netbanking.',
            ),
            new ProviderDefinition(
                slug: 'cashfree',
                displayName: 'Cashfree',
                category: ProviderDefinition::CATEGORY_PAYMENT,
                credentialFields: [
                    ['name' => 'client_secret', 'label' => 'Client Secret', 'required' => true],
                    ['name' => 'webhook_secret', 'label' => 'Webhook Secret'],
                ],
                configFields: [
                    ['name' => 'client_id', 'label' => 'Client ID', 'required' => true],
                    ['name' => 'base_url', 'label' => 'Base URL', 'placeholder' => 'https://api.cashfree.com'],
                ],
                priority: 20,
            ),
            new ProviderDefinition(
                slug: 'phonepe',
                displayName: 'PhonePe',
                category: ProviderDefinition::CATEGORY_PAYMENT,
                credentialFields: [
                    ['name' => 'salt_key', 'label' => 'Salt Key', 'required' => true],
                ],
                configFields: [
                    ['name' => 'merchant_id', 'label' => 'Merchant ID', 'required' => true],
                    ['name' => 'salt_index', 'label' => 'Salt Index', 'type' => 'number', 'placeholder' => '1'],
                ],
                priority: 30,
            ),
            new ProviderDefinition(
                slug: 'stripe',
                displayName: 'Stripe',
                category: ProviderDefinition::CATEGORY_PAYMENT,
                credentialFields: [
                    ['name' => 'secret_key', 'label' => 'Secret Key', 'required' => true],
                    ['name' => 'webhook_secret', 'label' => 'Webhook Signing Secret'],
                ],
                configFields: [
                    ['name' => 'publishable_key', 'label' => 'Publishable Key', 'help' => 'Public by design.'],
                ],
                priority: 40,
            ),

            // ── Communication ───────────────────────────────────────────────────────────────
            new ProviderDefinition(
                slug: 'msg91',
                displayName: 'MSG91',
                category: ProviderDefinition::CATEGORY_COMMUNICATION,
                credentialFields: [
                    ['name' => 'auth_key', 'label' => 'Auth Key', 'required' => true],
                ],
                configFields: [
                    ['name' => 'sender', 'label' => 'Sender ID', 'placeholder' => 'PLNTAT'],
                    ['name' => 'template_id', 'label' => 'OTP Template ID'],
                    ['name' => 'flow_id', 'label' => 'Flow ID'],
                ],
                priority: 10,
                blurb: 'SMS and OTP delivery.',
            ),
            new ProviderDefinition(
                slug: 'twilio',
                displayName: 'Twilio',
                category: ProviderDefinition::CATEGORY_COMMUNICATION,
                credentialFields: [
                    ['name' => 'auth_token', 'label' => 'Auth Token', 'required' => true],
                ],
                configFields: [
                    ['name' => 'account_sid', 'label' => 'Account SID', 'required' => true],
                    ['name' => 'from_number', 'label' => 'From Number', 'placeholder' => '+1…'],
                    ['name' => 'verification_sid', 'label' => 'Verify Service SID'],
                ],
                priority: 20,
            ),
            new ProviderDefinition(
                slug: 'whatsapp',
                displayName: 'WhatsApp Business',
                category: ProviderDefinition::CATEGORY_COMMUNICATION,
                credentialFields: [
                    ['name' => 'access_token', 'label' => 'Access Token', 'required' => true],
                ],
                configFields: [
                    ['name' => 'phone_number_id', 'label' => 'Phone Number ID', 'required' => true],
                    ['name' => 'api_version', 'label' => 'Graph API Version', 'placeholder' => 'v19.0'],
                    ['name' => 'otp_template', 'label' => 'OTP Template Name'],
                ],
                priority: 30,
            ),
            new ProviderDefinition(
                slug: 'sendgrid',
                displayName: 'SendGrid',
                category: ProviderDefinition::CATEGORY_COMMUNICATION,
                credentialFields: [
                    ['name' => 'api_key', 'label' => 'API Key', 'required' => true],
                ],
                configFields: [
                    ['name' => 'from_email', 'label' => 'From Address', 'placeholder' => 'hello@plantathome.in'],
                    ['name' => 'from_name', 'label' => 'From Name', 'placeholder' => 'PlantAtHome'],
                ],
                priority: 40,
                blurb: 'Transactional email over HTTPS (Railway blackholes SMTP).',
            ),
            new ProviderDefinition(
                slug: 'aws_ses',
                displayName: 'AWS SES',
                category: ProviderDefinition::CATEGORY_COMMUNICATION,
                credentialFields: [
                    ['name' => 'secret_access_key', 'label' => 'Secret Access Key', 'required' => true],
                ],
                configFields: [
                    ['name' => 'access_key_id', 'label' => 'Access Key ID', 'required' => true],
                    ['name' => 'region', 'label' => 'Region', 'placeholder' => 'ap-south-1'],
                ],
                priority: 50,
            ),

            // ── Maps ────────────────────────────────────────────────────────────────────────
            new ProviderDefinition(
                slug: 'google_maps',
                displayName: 'Google Maps',
                category: ProviderDefinition::CATEGORY_MAPS,
                credentialFields: [
                    ['name' => 'server_key', 'label' => 'Server API Key', 'required' => true, 'help' => 'Server-side key for geocoding and distance. Restrict it by IP.'],
                ],
                configFields: [
                    ['name' => 'browser_key', 'label' => 'Browser API Key', 'help' => 'Public by design; restrict by HTTP referrer.'],
                ],
                priority: 10,
            ),
            new ProviderDefinition(
                slug: 'mapbox',
                displayName: 'Mapbox',
                category: ProviderDefinition::CATEGORY_MAPS,
                credentialFields: [
                    ['name' => 'secret_token', 'label' => 'Secret Token', 'required' => true],
                ],
                configFields: [
                    ['name' => 'public_token', 'label' => 'Public Token'],
                ],
                priority: 20,
            ),

            // ── Storage ─────────────────────────────────────────────────────────────────────
            new ProviderDefinition(
                slug: 'aws_s3',
                displayName: 'AWS S3',
                category: ProviderDefinition::CATEGORY_STORAGE,
                credentialFields: [
                    ['name' => 'secret_access_key', 'label' => 'Secret Access Key', 'required' => true],
                ],
                configFields: [
                    ['name' => 'access_key_id', 'label' => 'Access Key ID', 'required' => true],
                    ['name' => 'region', 'label' => 'Region', 'placeholder' => 'ap-south-1'],
                    ['name' => 'bucket', 'label' => 'Bucket', 'required' => true],
                    ['name' => 'url', 'label' => 'Public URL / CDN base'],
                ],
                priority: 10,
            ),
            new ProviderDefinition(
                slug: 'cloudinary',
                displayName: 'Cloudinary',
                category: ProviderDefinition::CATEGORY_STORAGE,
                credentialFields: [
                    ['name' => 'api_secret', 'label' => 'API Secret', 'required' => true],
                ],
                configFields: [
                    ['name' => 'cloud_name', 'label' => 'Cloud Name', 'required' => true],
                    ['name' => 'api_key', 'label' => 'API Key', 'required' => true],
                ],
                priority: 20,
            ),

            // ── AI ──────────────────────────────────────────────────────────────────────────
            new ProviderDefinition(
                slug: 'openai',
                displayName: 'OpenAI',
                category: ProviderDefinition::CATEGORY_AI,
                credentialFields: [
                    ['name' => 'api_key', 'label' => 'API Key', 'required' => true],
                ],
                configFields: [
                    ['name' => 'text_model', 'label' => 'Text Model', 'placeholder' => 'gpt-4o-mini'],
                    ['name' => 'image_model', 'label' => 'Image Model', 'placeholder' => 'gpt-image-1'],
                    ['name' => 'monthly_budget_inr', 'label' => 'Monthly Budget (₹)', 'type' => 'number'],
                ],
                priority: 10,
            ),
            new ProviderDefinition(
                slug: 'anthropic',
                displayName: 'Anthropic',
                category: ProviderDefinition::CATEGORY_AI,
                credentialFields: [
                    ['name' => 'api_key', 'label' => 'API Key', 'required' => true],
                ],
                configFields: [
                    ['name' => 'model', 'label' => 'Model', 'placeholder' => 'claude-opus-5'],
                    ['name' => 'monthly_budget_inr', 'label' => 'Monthly Budget (₹)', 'type' => 'number'],
                ],
                priority: 20,
                blurb: 'Powers the plant doctor, care plans and the Ask-AI chat.',
            ),
            new ProviderDefinition(
                slug: 'google_gemini',
                displayName: 'Google Gemini',
                category: ProviderDefinition::CATEGORY_AI,
                credentialFields: [
                    ['name' => 'api_key', 'label' => 'API Key', 'required' => true],
                ],
                configFields: [
                    ['name' => 'model', 'label' => 'Model', 'placeholder' => 'gemini-1.5-pro'],
                ],
                priority: 30,
            ),
            new ProviderDefinition(
                slug: 'replicate',
                displayName: 'Replicate',
                category: ProviderDefinition::CATEGORY_AI,
                credentialFields: [
                    ['name' => 'api_token', 'label' => 'API Token', 'required' => true],
                ],
                configFields: [
                    ['name' => 'model', 'label' => 'Model'],
                    ['name' => 'base_url', 'label' => 'Base URL', 'placeholder' => 'https://api.replicate.com'],
                    ['name' => 'owner', 'label' => 'Owner'],
                ],
                priority: 40,
            ),

            // ── First-party AI microservices ────────────────────────────────────────────────
            //
            // These are OUR services (chatbot, plant-doctor, care-plan), not vendors — but they
            // are reached over HTTP with a shared key exactly like a vendor, and that key is a
            // secret. Each currently lives in its own table with `service_api_key` stored as a
            // PLAINTEXT varchar; folding them in here is what gets those secrets encrypted.
            //
            // Their `enabled` flag is load-bearing beyond this module: three PUBLIC endpoints
            // (GET ai-chat/settings, care-plans/settings, plant-doctor/settings) report it to the
            // storefront so a feature hides itself when its backend is off. Those endpoints must
            // keep returning ONLY `enabled` (+ max_prompts) — never a URL, never a key.
            new ProviderDefinition(
                slug: 'ai_chat',
                displayName: 'Ask AI Chat Service',
                category: ProviderDefinition::CATEGORY_AI,
                credentialFields: [
                    ['name' => 'service_api_key', 'label' => 'Service API Key', 'required' => true],
                ],
                configFields: [
                    ['name' => 'service_url', 'label' => 'Service URL', 'placeholder' => 'https://chatbot-service…up.railway.app'],
                    ['name' => 'openai_model', 'label' => 'Model'],
                    ['name' => 'monthly_budget_inr', 'label' => 'Monthly Budget (₹)', 'type' => 'number'],
                    ['name' => 'max_prompts', 'label' => 'Max Prompts / Conversation', 'type' => 'number'],
                    ['name' => 'daily_user_cap', 'label' => 'Daily Cap / User', 'type' => 'number'],
                ],
                priority: 50,
                blurb: 'Per-plant chat on the product page. Disabled hides the button.',
            ),
            new ProviderDefinition(
                slug: 'plant_doctor',
                displayName: 'Plant Doctor Service',
                category: ProviderDefinition::CATEGORY_AI,
                credentialFields: [
                    ['name' => 'service_api_key', 'label' => 'Service API Key', 'required' => true],
                ],
                configFields: [
                    ['name' => 'service_url', 'label' => 'Service URL'],
                    ['name' => 'openai_model', 'label' => 'Model'],
                    ['name' => 'monthly_budget_inr', 'label' => 'Monthly Budget (₹)', 'type' => 'number'],
                    ['name' => 'plant_id_enabled', 'label' => 'Plant identification', 'type' => 'boolean'],
                ],
                priority: 51,
                blurb: 'Photo → diagnosis. Powers /plant-doctor and the app health check.',
            ),
            new ProviderDefinition(
                slug: 'care_plan',
                displayName: 'Care Plan Service',
                category: ProviderDefinition::CATEGORY_AI,
                credentialFields: [
                    ['name' => 'service_api_key', 'label' => 'Service API Key', 'required' => true],
                ],
                configFields: [
                    ['name' => 'service_url', 'label' => 'Service URL'],
                    ['name' => 'model', 'label' => 'Model'],
                    ['name' => 'monthly_budget_inr', 'label' => 'Monthly Budget (₹)', 'type' => 'number'],
                    ['name' => 'auto_on_delivery', 'label' => 'Generate on delivery', 'type' => 'boolean'],
                ],
                priority: 52,
                blurb: 'Post-delivery AI care plan + reminder schedule.',
            ),

            // ── Translation ─────────────────────────────────────────────────────────────────
            // One row per provider, mirroring translation_provider_configs. `is_active` there
            // picks which one the TranslationManager actually uses; here that is `priority`
            // plus `enabled`, so the lowest-priority enabled provider wins.
            new ProviderDefinition(
                slug: 'translation_azure',
                displayName: 'Azure Translator',
                category: ProviderDefinition::CATEGORY_TRANSLATION,
                credentialFields: [
                    ['name' => 'key', 'label' => 'Subscription Key', 'required' => true],
                ],
                configFields: [
                    ['name' => 'region', 'label' => 'Region', 'placeholder' => 'centralindia'],
                    ['name' => 'endpoint', 'label' => 'Endpoint', 'placeholder' => 'https://api.cognitive.microsofttranslator.com'],
                ],
                priority: 10,
            ),
            new ProviderDefinition(
                slug: 'translation_deepl',
                displayName: 'DeepL',
                category: ProviderDefinition::CATEGORY_TRANSLATION,
                credentialFields: [
                    ['name' => 'api_key', 'label' => 'API Key', 'required' => true],
                ],
                configFields: [
                    ['name' => 'endpoint', 'label' => 'Endpoint', 'placeholder' => 'https://api-free.deepl.com'],
                ],
                priority: 20,
            ),

            // ── Analytics ───────────────────────────────────────────────────────────────────
            new ProviderDefinition(
                slug: 'google_analytics',
                displayName: 'Google Analytics',
                category: ProviderDefinition::CATEGORY_ANALYTICS,
                credentialFields: [
                    ['name' => 'api_secret', 'label' => 'Measurement Protocol API Secret'],
                ],
                configFields: [
                    ['name' => 'measurement_id', 'label' => 'Measurement ID', 'placeholder' => 'G-XXXXXXX', 'help' => 'Public by design.'],
                ],
                priority: 10,
            ),
            new ProviderDefinition(
                slug: 'meta_pixel',
                displayName: 'Meta Pixel',
                category: ProviderDefinition::CATEGORY_ANALYTICS,
                credentialFields: [
                    ['name' => 'access_token', 'label' => 'Conversions API Token'],
                ],
                configFields: [
                    ['name' => 'pixel_id', 'label' => 'Pixel ID', 'help' => 'Public by design.'],
                ],
                priority: 20,
            ),

            // ── Notification ────────────────────────────────────────────────────────────────
            new ProviderDefinition(
                slug: 'fcm',
                displayName: 'Firebase Cloud Messaging',
                category: ProviderDefinition::CATEGORY_NOTIFICATION,
                credentialFields: [
                    ['name' => 'service_account_json', 'label' => 'Service Account JSON', 'type' => 'textarea', 'required' => true],
                ],
                configFields: [
                    ['name' => 'project_id', 'label' => 'Project ID', 'required' => true],
                ],
                priority: 10,
            ),
            new ProviderDefinition(
                slug: 'onesignal',
                displayName: 'OneSignal',
                category: ProviderDefinition::CATEGORY_NOTIFICATION,
                credentialFields: [
                    ['name' => 'rest_api_key', 'label' => 'REST API Key', 'required' => true],
                ],
                configFields: [
                    ['name' => 'app_id', 'label' => 'App ID', 'required' => true],
                ],
                priority: 20,
            ),
        ];

        $keyed = [];
        foreach ($defs as $def) {
            $keyed[$def->slug] = $def;
        }
        self::$cache = $keyed;

        return $keyed;
    }

    public static function find(string $slug): ?ProviderDefinition
    {
        return self::all()[$slug] ?? null;
    }

    public static function has(string $slug): bool
    {
        return isset(self::all()[$slug]);
    }

    /** @return array<string,ProviderDefinition> */
    public static function byCategory(string $category): array
    {
        return array_filter(self::all(), static fn (ProviderDefinition $d) => $d->category === $category);
    }

    /** Slugs whose credentials are pushed to the Go shipping-service. */
    public static function shippingSyncSlugs(): array
    {
        return array_keys(array_filter(self::all(), static fn (ProviderDefinition $d) => $d->syncsToShipping));
    }

    /** Every category in a stable display order. */
    public static function categories(): array
    {
        return [
            ProviderDefinition::CATEGORY_DELIVERY,
            ProviderDefinition::CATEGORY_PAYMENT,
            ProviderDefinition::CATEGORY_COMMUNICATION,
            ProviderDefinition::CATEGORY_AI,
            ProviderDefinition::CATEGORY_MAPS,
            ProviderDefinition::CATEGORY_STORAGE,
            ProviderDefinition::CATEGORY_ANALYTICS,
            ProviderDefinition::CATEGORY_NOTIFICATION,
            ProviderDefinition::CATEGORY_TRANSLATION,
        ];
    }

    /** Test-only: drop the memoized catalogue. */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
