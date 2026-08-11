<?php

namespace Marvel\Traits;

trait Helper
{
    /**
     * Format billing, shipping address
     *
     * @param array $address
     * @return string
     */
    public function formatAddress($address)
    {
        if (!$address) {
            return null;
        }

        // Snapshots vary: some nest under 'address', many predate the
        // canonical shape — null-coalesce every key (unguarded concat emitted
        // PHP warnings from OrderExport on any partial address).
        $a = is_array($address['address'] ?? null) ? $address['address'] : (array) $address;
        $zip  = $a['zip'] ?? $a['pincode'] ?? $a['postal_code'] ?? '';
        $line = trim(($a['house_no'] ?? '') . ' ' . ($a['street_address'] ?? ''), ' ,');

        return $line . ', ' . $zip . '-' . ($a['city'] ?? '') . ', ' . ($a['state'] ?? '') . ', ' . ($a['country'] ?? '');
    }
}
