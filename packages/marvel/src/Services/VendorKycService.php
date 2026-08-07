<?php

namespace Marvel\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Shop;

/**
 * The vendor KYC clock.
 *
 * Vendors register WITHOUT documents on purpose — blocking onboarding on
 * paperwork just loses vendors, and the API has always allowed it. The trade is
 * that the gap cannot stay open forever: a vendor gets a window to supply the
 * core documents, and when it lapses the account goes on hold.
 *
 * Everything about that policy lives here so the rule is stated once: the
 * required document set, whether a shop still owes anything, when its deadline
 * falls, and what "on hold" means.
 */
class VendorKycService
{
    /** Documents a self-serve vendor must have on file to go live. */
    public const REQUIRED_DOCUMENTS = [
        'gstCertificate' => 'GST Certificate',
        'pan'            => 'PAN card',
        'cheque'         => 'Cancelled cheque',
    ];

    /** Which of the required documents this shop is still missing (labels). */
    public function missingDocuments(Shop $shop): array
    {
        $documents = data_get($shop->settings, 'documents', []);
        $missing = [];
        foreach (self::REQUIRED_DOCUMENTS as $key => $label) {
            if (empty(data_get($documents, $key))) {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    /** Days a new vendor gets to supply documents. */
    public function windowDays(): int
    {
        return (int) config('shop.kyc.window_days', 15);
    }

    /** Days before the deadline that the warning email goes out. */
    public function warnBeforeDays(): int
    {
        return (int) config('shop.kyc.warn_before_days', 3);
    }

    /**
     * Start, extend or clear a shop's deadline to match its documents.
     *
     * Called after any write that could change the document set, so the clock
     * always reflects reality: complete paperwork clears the deadline (and any
     * hold it caused), incomplete paperwork starts one if it isn't already
     * running. An existing deadline is never silently moved — only the extend
     * endpoint does that, deliberately.
     */
    public function syncDeadline(Shop $shop): void
    {
        if (!Schema::hasColumn('shops', 'documents_due_at')) {
            return; // migration not applied yet — never break a shop save over this
        }

        if (empty($this->missingDocuments($shop))) {
            // Paperwork is complete. Clear the clock, and release a hold that
            // only existed because of it.
            $shop->documents_due_at = null;
            $shop->kyc_reminded_at = null;
            if ($shop->approval_status === Shop::STATUS_ON_HOLD) {
                $this->release($shop, 'KYC documents received');
            }
            return;
        }

        if ($shop->documents_due_at === null && $shop->approval_status !== Shop::STATUS_ON_HOLD) {
            $shop->documents_due_at = Carbon::now()->addDays($this->windowDays());
        }
    }

    /**
     * Put a vendor on hold. This is NOT "rejected" — the vendor has simply run
     * out of time and can be released the moment the documents arrive.
     *
     * `is_active` alone would not be enough: it gates the vendor dashboard but
     * NOT the selling path, so a held vendor's stock would keep being sold and
     * assigned. AvailabilityService reads `approval_status`, hence the recompute
     * here — without it the city-availability projection keeps serving this
     * vendor until the nightly rebuild.
     */
    public function hold(Shop $shop, string $reason): void
    {
        $shop->approval_status = Shop::STATUS_ON_HOLD;
        $shop->is_active = false;
        $shop->hold_reason = $reason;
        $shop->save();

        $this->refreshAvailability($shop);
    }

    /** Lift a hold and put the vendor back to whatever it was before. */
    public function release(Shop $shop, string $reason = ''): void
    {
        // Back to approved only if it had already been approved once (approved_at
        // is the record of that); otherwise it returns to the pending queue.
        $shop->approval_status = $shop->approved_at ? 'approved' : 'pending';
        $shop->is_active = (bool) $shop->approved_at;
        $shop->hold_reason = $reason ?: null;
        $shop->save();

        $this->refreshAvailability($shop);
    }

    /**
     * Rebuild city availability for this vendor's catalogue.
     *
     * Never allowed to break the caller: a hold that fails to recompute is a
     * stale projection (bad), but a hold that throws leaves the vendor in an
     * unknown state (worse).
     */
    private function refreshAvailability(Shop $shop): void
    {
        try {
            app(AvailabilityService::class)->recomputeForShop((int) $shop->id);
        } catch (\Throwable $e) {
            Log::warning('[vendor-kyc] availability recompute failed', [
                'shop_id' => $shop->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
