<?php

namespace Marvel\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\CourierPartnerConfig;
use Marvel\Database\Models\Settings;
use Marvel\Enums\Permission;

/**
 * Super-admin courier configuration. Reads/writes the courier master switch + default package
 * (settings.options.courier — public-safe) and the per-partner config + ENCRYPTED credentials
 * (courier_partner_configs). SECRETS ARE NEVER RETURNED: index() exposes only a boolean
 * "is this field set" per secret; update() only writes a secret field when the admin actually
 * sends a non-empty value (so saving without re-typing secrets keeps them intact).
 */
class CourierConfigController extends CoreController
{
    /** Encrypted secret fields per partner — never serialized back to the client. */
    private array $secretFields = [
        'shiprocket'       => ['email', 'password', 'api_token', 'webhook_token'],
        'borzo'            => ['token', 'callback_token'],
        'shipping_service' => ['url', 'api_key', 'callback_key'],
    ];

    /** Non-secret, admin-editable settings per partner (safe to return). */
    private array $nonSecretFields = [
        'shiprocket'       => ['base_url'],
        'borzo'            => ['base_url', 'vehicle_type_id', 'matter'],
        'shipping_service' => ['timeout'],
    ];

    public function index(Request $request)
    {
        $this->assertAdmin($request);

        $options = (array) (Settings::getData()->options ?? []);
        $courier = (array) ($options['courier'] ?? []);
        $rows    = CourierPartnerConfig::all()->keyBy('partner_code');

        $partners = [];
        foreach (array_keys($this->secretFields) as $code) {
            $row      = $rows->get($code);
            $creds    = $row ? (array) $row->credentials : [];
            $settings = $row ? (array) $row->settings : [];

            // Per secret field: is it set anywhere (DB or env)? boolean only — never the value.
            $credsSet = [];
            foreach ($this->secretFields[$code] as $f) {
                $credsSet[$f] = !empty($creds[$f]) || !empty(config("services.$code.$f"));
            }

            $partners[$code] = [
                'code'            => $code,
                'enabled'         => $row ? (bool) $row->enabled : (bool) config("services.$code.enabled"),
                'settings'        => $this->resolvedNonSecrets($code, $settings),
                'credentials_set' => $credsSet,
                'configured'      => $this->isConfigured($code, $creds),
            ];
        }

        return [
            'enabled'         => (bool) ($courier['enabled'] ?? false),
            'default_package' => $this->sanitizePackage((array) ($courier['default_package'] ?? [])),
            'partners'        => $partners,
        ];
    }

    public function update(Request $request)
    {
        $this->assertAdmin($request);
        $language = $request->language ?: DEFAULT_LANGUAGE;

        // (1) Master switch + default package → settings.options.courier (no secrets here).
        $settings = Settings::where('language', $language)->first();
        if ($settings) {
            $opts = (array) $settings->options;
            $opts['courier'] = [
                'enabled'         => $request->boolean('enabled'),
                'default_package' => $this->sanitizePackage((array) $request->input('default_package', [])),
            ];
            $settings->update(['options' => $opts]);
            Cache::forget('cached_settings_' . $language);
        }

        // (2) Per-partner: enable flag + non-secret settings + credentials (only fields provided).
        foreach ((array) $request->input('partners', []) as $code => $payload) {
            if (!isset($this->secretFields[$code]) || !is_array($payload)) {
                continue;
            }
            $row = CourierPartnerConfig::firstOrNew(['partner_code' => $code]);
            $row->enabled = (bool) ($payload['enabled'] ?? $row->enabled ?? false);

            // Merge non-secret settings (new keys override, untouched keys preserved).
            $newSettings = Arr::only((array) ($payload['settings'] ?? []), $this->nonSecretFields[$code]);
            $row->settings = $newSettings + (array) ($row->settings ?? []);

            // Merge credentials: only overwrite a secret the admin actually sent (non-empty).
            $creds = (array) ($row->credentials ?? []);
            foreach ($this->secretFields[$code] as $f) {
                $val = $payload['credentials'][$f] ?? null;
                if ($val !== null && $val !== '') {
                    $creds[$f] = is_string($val) ? trim($val) : $val;
                }
            }
            $row->credentials = $creds;
            $row->save();
        }

        return $this->index($request);
    }

    private function assertAdmin(Request $request): void
    {
        $user = $request->user();
        if (!$user || !$user->hasPermissionTo(Permission::SUPER_ADMIN)) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
    }

    /** Non-secret settings resolved DB-first then env (safe to return). */
    private function resolvedNonSecrets(string $code, array $settings): array
    {
        $out = [];
        foreach ($this->nonSecretFields[$code] as $f) {
            $out[$f] = $settings[$f] ?? config("services.$code.$f");
        }
        return $out;
    }

    /** Is the partner usable (required creds present in DB or env)? Never exposes the values. */
    private function isConfigured(string $code, array $creds): bool
    {
        $get = fn (string $f) => !empty($creds[$f] ?? null) || !empty(config("services.$code.$f"));
        switch ($code) {
            case 'shiprocket':
                return $get('api_token') || ($get('email') && $get('password'));
            case 'borzo':
                return $get('token');
            case 'shipping_service':
                return $get('url') && $get('api_key');
        }
        return false;
    }

    private function sanitizePackage(array $pkg): array
    {
        return [
            'weight'  => (int) ($pkg['weight'] ?? 500),
            'length'  => (float) ($pkg['length'] ?? 20),
            'breadth' => (float) ($pkg['breadth'] ?? 15),
            'height'  => (float) ($pkg['height'] ?? 15),
        ];
    }
}
