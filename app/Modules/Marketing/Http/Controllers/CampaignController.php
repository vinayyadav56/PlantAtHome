<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Http\Controllers;

use App\Modules\Marketing\Application\CampaignService;
use App\Modules\Marketing\Application\DeliveryService;
use App\Modules\Marketing\Http\Concerns\ResolvesMarketingModels;
use App\Modules\Marketing\Http\Requests\RetryCampaignRequest;
use App\Modules\Marketing\Http\Requests\SaveCampaignRequest;
use App\Modules\Marketing\Http\Resources\CampaignResource;
use App\Modules\Marketing\Http\Resources\CampaignRunResource;
use App\Modules\Marketing\Infrastructure\Models\Campaign;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CampaignController extends ApiController
{
    use ResolvesMarketingModels;

    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly DeliveryService $delivery,
    ) {
    }

    /** GET marketing/campaigns?status=&q= */
    public function index(Request $request): JsonResponse
    {
        // audienceVersion is column-constrained: its `snapshot` longText can be
        // many MB, so never SELECT * it just to render uuid + version.
        $query = Campaign::query()->with(['audience', 'audienceVersion:id,uuid,version', 'templates.template']);
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($q = $request->query('q')) {
            $query->where('name', 'like', '%'.$q.'%');
        }
        $query->orderByDesc('id');

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            fn (Campaign $c) => CampaignResource::make($c),
        );
    }

    public function store(SaveCampaignRequest $request): JsonResponse
    {
        $campaign = $this->campaigns->create($request->validated(), $request->user()?->uuid);

        return $this->created(CampaignResource::make($this->loaded($campaign)));
    }

    public function show(string $campaign): JsonResponse
    {
        // Bound the runs load at the query (the relation is unbounded); the
        // resource only shows the latest 10 anyway.
        $model = $this->loaded($this->campaignOrFail($campaign))
            ->load(['runs' => fn ($q) => $q->limit(10)]);

        return $this->ok(CampaignResource::make($model, withDetails: true));
    }

    public function update(SaveCampaignRequest $request, string $campaign): JsonResponse
    {
        $model = $this->campaigns->update($this->campaignOrFail($campaign), $request->validated(), $request->user()?->uuid);

        return $this->ok(CampaignResource::make($this->loaded($model)));
    }

    /** POST marketing/campaigns/{campaign}/send — create a run + enqueue. */
    public function send(Request $request, string $campaign): JsonResponse
    {
        $run = $this->campaigns->launch($this->campaignOrFail($campaign), $request->user()?->uuid, 'manual');

        return $this->created(CampaignRunResource::make($run->load('campaign')));
    }

    /** POST marketing/campaigns/{campaign}/retry — Retry Center. */
    public function retry(RetryCampaignRequest $request, string $campaign): JsonResponse
    {
        $data = $request->validated();
        $run = $this->delivery->retry(
            $this->campaignOrFail($campaign),
            $data['scope'],
            $data['uuids'] ?? [],
            $request->user()?->uuid,
        );

        return $this->created(CampaignRunResource::make($run->load('campaign')));
    }

    public function pause(string $campaign): JsonResponse
    {
        return $this->ok(CampaignResource::make($this->loaded($this->campaigns->pause($this->campaignOrFail($campaign)))));
    }

    public function resume(string $campaign): JsonResponse
    {
        return $this->ok(CampaignResource::make($this->loaded($this->campaigns->resume($this->campaignOrFail($campaign)))));
    }

    /** GET marketing/campaigns/{campaign}/runs */
    public function runs(Request $request, string $campaign): JsonResponse
    {
        $model = $this->campaignOrFail($campaign);
        $paginator = $model->runs()->with('campaign')->paginate(min((int) $request->query('per_page', 20), 100));

        return $this->paginated($paginator, fn ($r) => CampaignRunResource::make($r));
    }

    public function destroy(string $campaign): JsonResponse
    {
        $this->campaignOrFail($campaign)->delete();

        return $this->ok(['deleted' => true]);
    }

    private function loaded(Campaign $campaign): Campaign
    {
        return $campaign->load(['audience', 'audienceVersion:id,uuid,version', 'templates.template']);
    }
}
