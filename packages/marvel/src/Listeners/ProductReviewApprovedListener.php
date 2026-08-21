<?php

namespace Marvel\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Marvel\Events\ProductReviewApproved;
use Marvel\Notifications\ProductApprovedNotification;

class ProductReviewApprovedListener implements ShouldQueue
{   
    /**
     * Notify the vendor who PROPOSED the plant.
     *
     * Not $event->product->shop->owner: since the single-master-catalog cutover every product
     * is owned by the master shop, so that expression notifies the admin about the admin's own
     * decision and the proposing vendor hears nothing. The proposer is on proposed_by_shop_id.
     */
    public function handle(ProductReviewApproved $event)
    {
        $vendor = optional($event->product->proposedByShop)->owner
            // Fall back to the owning shop for pre-cutover products, which have no proposer.
            ?? optional($event->product->shop)->owner;
        if (!$vendor) {
            return;
        }
        $vendor->notify(new ProductApprovedNotification($event->product));
    }
}
