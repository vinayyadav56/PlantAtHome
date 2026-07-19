<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Http\Controllers;

use App\Modules\Marketing\Application\DeliveryService;
use App\Modules\Marketing\Http\Concerns\ResolvesMarketingModels;
use App\Modules\Marketing\Http\Resources\DeliveryLogResource;
use App\Modules\Marketing\Http\Resources\NotificationResource;
use App\Modules\Marketing\Infrastructure\Models\Campaign;
use App\Modules\Marketing\Infrastructure\Models\CampaignRun;
use App\Modules\Marketing\Infrastructure\Models\MarketingNotification;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Delivery Tracking: the per-message log and its full status timeline. Filters
 * by campaign / run / status / channel so an operator can find failures fast.
 */
final class DeliveryLogController extends ApiController
{
    use ResolvesMarketingModels;

    public function __construct(private readonly DeliveryService $delivery)
    {
    }

    /** GET marketing/notifications?campaign=&run=&status=&channel=&q= */
    public function index(Request $request): JsonResponse
    {
        $query = MarketingNotification::query();

        if ($campaignUuid = $request->query('campaign')) {
            $id = Campaign::where('uuid', $campaignUuid)->value('id');
            $query->where('campaign_id', $id ?? -1);
        }
        if ($runUuid = $request->query('run')) {
            $id = CampaignRun::where('uuid', $runUuid)->value('id');
            $query->where('run_id', $id ?? -1);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($channel = $request->query('channel')) {
            $query->where('channel', $channel);
        }
        if ($q = $request->query('q')) {
            $query->where('recipient', 'like', '%'.$q.'%');
        }
        $query->orderByDesc('id');

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 25), 100)),
            fn (MarketingNotification $n) => NotificationResource::make($n),
        );
    }

    /** GET marketing/notifications/{notification} — full body + status timeline. */
    public function show(string $notification): JsonResponse
    {
        $model = $this->notificationOrFail($notification)->load(['logs' => fn ($q) => $q->orderBy('id')]);
        $data = NotificationResource::make($model, withBody: true);
        $data['logs'] = $model->logs->map(fn ($l) => DeliveryLogResource::make($l))->all();

        return $this->ok($data);
    }

    /**
     * POST marketing/notifications/{notification}/status — record a delivery/read
     * (the manual/admin equivalent of a provider webhook, for the demo flow).
     */
    public function status(Request $request, string $notification): JsonResponse
    {
        $validated = $request->validate([
            'status'   => 'required|in:delivered,read,failed,sent',
            'response' => 'nullable|array',
            'error'    => 'nullable|string|max:1000',
        ]);

        $model = $this->notificationOrFail($notification);
        $this->delivery->applyStatus($model, $validated['status'], $validated['response'] ?? [], $validated['error'] ?? null);

        return $this->ok(NotificationResource::make($model->fresh()));
    }
}
