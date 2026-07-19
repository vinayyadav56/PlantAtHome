<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Http\Concerns;

use App\Modules\Marketing\Infrastructure\Models\Audience;
use App\Modules\Marketing\Infrastructure\Models\Campaign;
use App\Modules\Marketing\Infrastructure\Models\MarketingNotification;
use App\Modules\Marketing\Infrastructure\Models\Template;
use App\Shared\Application\DomainActionException;

/** Resolve wire uuids to models, or fail with a clean 404 envelope. */
trait ResolvesMarketingModels
{
    protected function audienceOrFail(string $uuid): Audience
    {
        return Audience::where('uuid', $uuid)->first()
            ?? throw DomainActionException::notFound('Audience not found.', 'AUDIENCE_NOT_FOUND');
    }

    protected function templateOrFail(string $uuid): Template
    {
        return Template::where('uuid', $uuid)->first()
            ?? throw DomainActionException::notFound('Template not found.', 'TEMPLATE_NOT_FOUND');
    }

    protected function campaignOrFail(string $uuid): Campaign
    {
        return Campaign::where('uuid', $uuid)->first()
            ?? throw DomainActionException::notFound('Campaign not found.', 'CAMPAIGN_NOT_FOUND');
    }

    protected function notificationOrFail(string $uuid): MarketingNotification
    {
        return MarketingNotification::where('uuid', $uuid)->first()
            ?? throw DomainActionException::notFound('Notification not found.', 'NOTIFICATION_NOT_FOUND');
    }
}
