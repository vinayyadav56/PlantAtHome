<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Http\Controllers;

use App\Modules\Marketing\Application\AudienceService;
use App\Modules\Marketing\Http\Concerns\ResolvesMarketingModels;
use App\Modules\Marketing\Http\Requests\PreviewAudienceRequest;
use App\Modules\Marketing\Http\Requests\SaveAudienceRequest;
use App\Modules\Marketing\Http\Resources\AudienceResource;
use App\Modules\Marketing\Http\Resources\AudienceVersionResource;
use App\Modules\Marketing\Infrastructure\Models\Audience;
use App\Modules\Marketing\Infrastructure\Models\AudienceVersion;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AudienceController extends ApiController
{
    use ResolvesMarketingModels;

    public function __construct(private readonly AudienceService $audiences)
    {
    }

    /** GET marketing/audiences */
    public function index(Request $request): JsonResponse
    {
        $query = Audience::query();
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($q = $request->query('q')) {
            $query->where('name', 'like', '%'.$q.'%');
        }
        $query->orderByDesc('id');

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            fn (Audience $a) => AudienceResource::make($a),
        );
    }

    /** POST marketing/audiences/preview — dry-run, never persists. */
    public function preview(PreviewAudienceRequest $request): JsonResponse
    {
        $preview = $this->audiences->preview($request->validated()['sql_query']);

        return $this->ok($preview->toArray());
    }

    /** POST marketing/audiences */
    public function store(SaveAudienceRequest $request): JsonResponse
    {
        $audience = $this->audiences->create($request->validated(), $request->user()?->uuid);

        return $this->created(AudienceResource::make($audience->load('versions'), withVersions: true));
    }

    /** GET marketing/audiences/{audience} */
    public function show(string $audience): JsonResponse
    {
        $model = $this->audienceOrFail($audience)->load('versions');

        return $this->ok(AudienceResource::make($model, withVersions: true));
    }

    /** PATCH marketing/audiences/{audience} */
    public function update(SaveAudienceRequest $request, string $audience): JsonResponse
    {
        $model = $this->audiences->update($this->audienceOrFail($audience), $request->validated(), $request->user()?->uuid);

        return $this->ok(AudienceResource::make($model->load('versions'), withVersions: true));
    }

    /** POST marketing/audiences/{audience}/refresh — freeze the next version. */
    public function refresh(Request $request, string $audience): JsonResponse
    {
        $model = $this->audiences->refresh($this->audienceOrFail($audience), $request->user()?->uuid);

        return $this->ok(AudienceResource::make($model->load('versions'), withVersions: true));
    }

    /** GET marketing/audiences/{audience}/versions/{version} — sample rows. */
    public function version(string $audience, string $version): JsonResponse
    {
        $model = $this->audienceOrFail($audience);
        $ver = AudienceVersion::where('audience_id', $model->id)->where('uuid', $version)->first();
        if (! $ver) {
            return $this->fail('AUDIENCE_VERSION_NOT_FOUND', 'That version does not exist.', 404);
        }

        return $this->ok(AudienceVersionResource::make($ver, withSample: true));
    }

    /** DELETE marketing/audiences/{audience} */
    public function destroy(string $audience): JsonResponse
    {
        $this->audienceOrFail($audience)->delete();

        return $this->ok(['deleted' => true]);
    }
}
