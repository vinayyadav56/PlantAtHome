<?php

namespace Marvel\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Address;
use Marvel\Database\Repositories\SettingsRepository;
use Marvel\Events\Maintenance;
use Marvel\Exceptions\MarvelException;
use Illuminate\Support\Facades\Cache;
use Marvel\Http\Requests\SettingsRequest;
use Marvel\Traits\ApiResponseCache;
use Prettus\Validator\Exceptions\ValidatorException;

class SettingsController extends CoreController
{
    use ApiResponseCache;

    public $repository;

    public function __construct(SettingsRepository $repository)
    {
        $this->repository = $repository;
    }


    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Collection|Address[]
     */
    public function index(Request $request)
    {
        $language = $request->language ? $request->language : DEFAULT_LANGUAGE;

        // Bounded, NOT rememberForever. A forever entry meant one missed or
        // mistimed bust pinned the old settings (logo, banners, SEO) until the
        // next save — the storefront then served a stale logo indefinitely.
        // Ten minutes is long enough to absorb SSR traffic and short enough
        // that any bust we miss self-heals.
        $data = Cache::remember(
            'cached_settings_' . $language,
            now()->addMinutes(10),
            function () use ($request) {
                return $this->repository->getData($request->language);
            }
        );

        // Format maintenance start and until data
        $maintenanceStart = Carbon::parse($data['options']['maintenance']['start'])->format('F j, Y h:i A');
        $maintenanceUntil = Carbon::parse($data['options']['maintenance']['until'])->format('F j, Y h:i A');

        $formattedMaintenance = [
            "start" => $maintenanceStart,
            "until" => $maintenanceUntil,
        ];

        // Add formatted maintenance data to the existing data
        $data['maintenance'] = $formattedMaintenance;

        // Expose the PUBLIC Razorpay key id so storefront + mobile clients use the exact
        // key the server creates orders with (the secret stays server-side). Injected from
        // config on read (not cached/stored) so rotating the key never needs a client rebuild.
        $options = $data['options'] ?? [];
        $options['razorpayKeyId'] = config('shop.razorpay.key_id') ?: null;
        $data['options'] = $options;

        // Already server-cached above (rememberForever, busted on store).
        // For anonymous storefront reads also allow the Vercel edge to cache
        // it — every page (SSR) fetches settings. Admin (real Bearer) stays
        // uncached so the dashboard reflects edits immediately.
        if ($this->isPublicCacheable($request)) {
            // Short shared TTL (60s) so admin toggles (homepage banners, hero slides, design
            // system, …) reach the live storefront within ~1 min instead of the 5-min default.
            return response()->json($data)->header('Cache-Control', $this->cacheControl(60));
        }

        return $data;
    }

    // public function fetchSettings(Request $request)
    // {
    //     $language = $request->language ? $request->language : DEFAULT_LANGUAGE;
    //     return $this->repository->getData($language);
    // }

    /**
     * Store a newly created resource in storage.
     *
     * @param SettingsRequest $request
     * @return mixed
     * @throws ValidatorException
     */
    /**
     * Record changes to the financial config blocks (tax, vendor pricing).
     *
     * Settings is one JSON blob overwritten wholesale, so "who changed the GSTIN"
     * or "when did the margin move" had no answer at all — and those two blocks
     * decide what every customer is charged. Only the financial keys are diffed:
     * logging the whole blob on every homepage-banner save would bury them.
     */
    private function auditFinancialOptions(?array $before, ?array $after): void
    {
        foreach (['tax', 'vendorPricing'] as $key) {
            $was = $before[$key] ?? null;
            $now = $after[$key] ?? null;
            if ($was == $now) {
                continue;
            }
            \Marvel\Database\Models\Accounting\AccountingAuditLog::record(
                $key === 'tax' ? 'tax_settings' : 'pricing_settings',
                $key,
                'updated',
                is_array($was) ? $was : null,
                is_array($now) ? $now : null,
                'Settings'
            );
        }
    }

    public function store(SettingsRequest $request)
    {
        // Settings are SINGLE-ROW by design (the translation engine is
        // flag-gated off, and virtually every server read is a no-arg
        // Settings::getData() = the DEFAULT_LANGUAGE row). The admin sends
        // `language: <ui locale>`, and this method used to CREATE a fork row
        // for that locale — a row no server logic ever read again, so every
        // toggle saved from a non-default locale silently did nothing.
        // Always write the default-language row; forget the requested
        // locale's cache too (that's the row the admin re-reads).
        $requested = $request->language ? $request->language : DEFAULT_LANGUAGE;
        $language = DEFAULT_LANGUAGE;
        $request->merge([
            'options' => [
                ...$request->options,
                ...$this->repository->getApplicationSettings(),
                'server_info' => server_environment_info(),
            ]
        ]);

        $data = $this->repository->where('language', $language)->first();
        $before = $data ? (array) $data->options : null;

        if ($data) {
            $settings =  tap($data)->update($request->only(['options']));
        } else {
            $settings =  $this->repository->create(['options' => $request['options'], 'language' => $language]);
        }

        // Bust AFTER the write. It used to run BEFORE, so any GET landing in the
        // window between the forget and the update re-cached the PRE-update row —
        // and with the old rememberForever that pinned the stale logo until the
        // next save. SSR hits /settings on every render, so that window was hit
        // in practice.
        Cache::forget('cached_settings_' . $language);
        if ($requested !== $language) {
            Cache::forget('cached_settings_' . $requested);
        }
        event(new Maintenance($language));
        $this->auditFinancialOptions($before, (array) $request['options']);
        return $settings;
    }

    /**
     * Display the specified resource.
     *
     * @param $id
     * @return JsonResponse
     */
    public function show($id)
    {
        try {
            return $this->repository->first();
        } catch (\Exception $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param SettingsRequest $request
     * @param int $id
     * @return JsonResponse
     * @throws ValidatorException
     */
    public function update(SettingsRequest $request, $id)
    {
        $language = $request->language ? $request->language : DEFAULT_LANGUAGE;
        $settings = $this->repository->first();
        if (isset($settings->id)) {
            $before = (array) $settings->options;
            $updated = $this->repository->update($request->only(['options']), $settings->id);
            $this->auditFinancialOptions($before, (array) $request['options']);
            // Bust the forever-cached settings response so storefront + admin reflect the edit
            // immediately. store() already did this; update() previously did NOT — so admin
            // saves (homepage banners, hero slides, design system, …) read stale until restart.
            Cache::forget('cached_settings_' . $language);
            Cache::forget('cached_settings_' . DEFAULT_LANGUAGE);
            event(new Maintenance($language));
            return $updated;
        } else {
            return $this->repository->create(['options' => $request['options']]);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return array
     */
    public function destroy($id)
    {
        throw new MarvelException(ACTION_NOT_VALID);
    }
}
