<?php

namespace Marvel\Services\Tax;

use Marvel\Database\Models\Settings;
use Marvel\Database\Models\State;

/**
 * The single source of the BUSINESS's tax identity — GSTIN, legal name, origin
 * (registration) state, pricing preference, invoice numbering and delivery-tax
 * treatment. Read from settings.options.tax, with sensible fallbacks to the
 * existing company address (contactDetails.location). Never duplicate GSTIN or
 * the origin state anywhere else — resolve them here.
 */
class BusinessTaxConfig
{
    private array $tax;
    private array $contact;

    public function __construct(?array $options = null)
    {
        if ($options === null) {
            $options = (array) (Settings::getData()->options ?? []);
        }
        $this->tax = (array) ($options['tax'] ?? []);
        $this->contact = (array) ($options['contactDetails'] ?? []);
    }

    /**
     * CA flag (spec §17): when ON, a product whose HSN/GST the CA has not verified is treated as
     * 0% / non-taxable instead of its configured rate. Default OFF — existing products are all
     * unverified, so ON would silently drop recorded GST until the CA review completes.
     */
    public function enforceTaxVerified(): bool
    {
        return (bool) ($this->tax['enforce_tax_verified'] ?? false);
    }

    /** Store-wide default: are configured product prices inclusive of GST? Default TRUE. */
    public function pricesIncludeTax(): bool
    {
        return (bool) ($this->tax['prices_include_tax'] ?? true);
    }

    public function gstin(): ?string
    {
        return $this->str($this->tax['gstin'] ?? null);
    }

    public function legalName(): ?string
    {
        return $this->str($this->tax['legal_name'] ?? null);
    }

    /** The registration (origin) state name — falls back to the company address state. */
    public function registrationState(): ?string
    {
        return $this->str($this->tax['registration_state'] ?? null)
            ?? $this->str(data_get($this->contact, 'location.state'));
    }

    /** GST 2-letter state code for the origin state (from config, GSTIN, or the states table). */
    public function registrationStateCode(): ?string
    {
        $explicit = $this->str($this->tax['registration_state_code'] ?? null);
        if ($explicit) {
            return strtoupper($explicit);
        }
        // GSTIN's first two chars are the numeric state code; but we key place-of-supply
        // off the alpha code, so resolve from the states table by name.
        $state = $this->registrationState();
        if ($state) {
            $code = State::whereRaw('LOWER(name) = ?', [strtolower(trim($state))])->value('code');
            if ($code) {
                return strtoupper($code);
            }
        }
        return null;
    }

    public function invoicePrefix(): string
    {
        return $this->str($this->tax['invoice_prefix'] ?? null) ?? 'INV';
    }

    /**
     * Delivery/freight GST treatment: follow_principal | separate | exempt.
     * Default 'follow_principal' (freight taxed at the goods' weighted rate).
     */
    public function deliveryTaxTreatment(): string
    {
        $t = strtolower($this->str($this->tax['delivery_tax_treatment'] ?? null) ?? 'follow_principal');
        return in_array($t, ['follow_principal', 'separate', 'exempt'], true) ? $t : 'follow_principal';
    }

    /** Flat GST rate for delivery when treatment = 'separate'. Default 18. */
    public function deliveryGstRate(): float
    {
        return (float) ($this->tax['delivery_gst_rate'] ?? 18);
    }

    private function str($v): ?string
    {
        $v = is_string($v) ? trim($v) : $v;
        return ($v === null || $v === '') ? null : (string) $v;
    }
}
