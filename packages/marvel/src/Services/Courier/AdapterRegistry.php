<?php

namespace Marvel\Services\Courier;

/**
 * Resolves shipping partner adapters by code and lists the ones wired this environment. A
 * partner is "available" only when its code is listed in COURIER_PROVIDER AND its credentials
 * are configured (so a half-set partner never enters routing). Adding a partner = add a class
 * implementing ShippingPartnerAdapter + a case here + its config block. Nothing else changes.
 */
class AdapterRegistry
{
    /** Adapter for one code, or null when that partner is not wired/credentialed. */
    public static function for(string $code): ?ShippingPartnerAdapter
    {
        return match ($code) {
            'borzo'      => config('services.borzo.enabled') && !empty(config('services.borzo.token')) ? new BorzoAdapter() : null,
            'shiprocket' => config('services.shiprocket.enabled')
                && (!empty(config('services.shiprocket.api_token')) || (!empty(config('services.shiprocket.email')) && !empty(config('services.shiprocket.password'))))
                ? new ShiprocketAdapter() : null,
            default      => null,
        };
    }

    /** All available adapters keyed by code (the routing candidate set). */
    public static function available(): array
    {
        $out = [];
        foreach (['borzo', 'shiprocket'] as $code) {
            if ($a = self::for($code)) {
                $out[$code] = $a;
            }
        }
        return $out;
    }

    /** Adapters that can serve a given mode (instant|same_city|courier) AND COD if required. */
    public static function candidatesForMode(string $mode, bool $cod): array
    {
        return array_filter(self::available(), function (ShippingPartnerAdapter $a) use ($mode, $cod) {
            $caps = $a->capabilities();
            $modeOk = in_array($mode, (array) ($caps['modes'] ?? []), true);
            $codOk = !$cod || !empty($caps['cod']);
            return $modeOk && $codOk;
        });
    }
}
