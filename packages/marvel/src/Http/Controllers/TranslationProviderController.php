<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Database\Models\TranslationProviderConfig;
use Marvel\Translation\TranslationManager;

/**
 * Super-admin CRUD for translation provider configuration. API keys are stored
 * encrypted (model `encrypted:array` cast) and NEVER returned — reads mask them
 * to booleans (mirrors CourierConfigController). Setting a provider active is how
 * you switch the engine's backend — no deploy.
 */
class TranslationProviderController extends CoreController
{
    public function index(Request $request)
    {
        $manager = app(TranslationManager::class);
        $rows = TranslationProviderConfig::all()->keyBy('provider');

        $out = [];
        foreach ($manager->available() as $provider) {
            /** @var TranslationProviderConfig|null $row */
            $row = $rows->get($provider);
            $creds = $row ? (array) $row->credentials : [];
            $out[] = [
                'provider' => $provider,
                'enabled' => (bool) ($row->enabled ?? false),
                'is_active' => (bool) ($row->is_active ?? false),
                // Masked: only reveal WHICH secrets are set, never the values.
                'has_credentials' => !empty(array_filter($creds)),
                'credential_keys' => array_keys($creds),
                'settings' => $row->settings ?? [],
                'cost_per_million_chars' => config('translation.cost_per_million_chars.' . $provider),
            ];
        }
        return ['providers' => $out, 'default' => config('translation.default_provider', 'google')];
    }

    public function update(Request $request)
    {
        $request->validate([
            'provider' => 'required|string|in:' . implode(',', app(TranslationManager::class)->available()),
        ]);
        $provider = $request->get('provider');

        $row = TranslationProviderConfig::firstOrNew(['provider' => $provider]);

        if ($request->has('enabled')) {
            $row->enabled = (bool) $request->boolean('enabled');
        }
        if ($request->filled('credentials') && is_array($request->get('credentials'))) {
            // Merge so a partial update doesn't wipe other secrets.
            $existing = (array) $row->credentials;
            $incoming = array_filter($request->get('credentials'), fn($v) => $v !== null && $v !== '');
            $row->credentials = array_merge($existing, $incoming);
        }
        if ($request->has('settings')) {
            $row->settings = (array) $request->get('settings');
        }

        // Exactly one active provider.
        if ($request->boolean('is_active')) {
            TranslationProviderConfig::where('provider', '!=', $provider)->update(['is_active' => false]);
            $row->is_active = true;
            $row->enabled = true;
        } elseif ($request->has('is_active')) {
            $row->is_active = false;
        }

        $row->save();

        return $this->index($request);
    }
}
