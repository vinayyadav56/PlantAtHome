<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Http\Controllers;

use App\Modules\Marketing\Application\TemplateService;
use App\Modules\Marketing\Http\Concerns\ResolvesMarketingModels;
use App\Modules\Marketing\Http\Requests\SaveTemplateRequest;
use App\Modules\Marketing\Http\Resources\TemplateResource;
use App\Modules\Marketing\Infrastructure\Models\Template;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TemplateController extends ApiController
{
    use ResolvesMarketingModels;

    public function __construct(private readonly TemplateService $templates)
    {
    }

    /** GET marketing/templates?channel=&status=&q= */
    public function index(Request $request): JsonResponse
    {
        $query = Template::query();
        if ($channel = $request->query('channel')) {
            $query->where('channel', $channel);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($q = $request->query('q')) {
            $query->where('name', 'like', '%'.$q.'%');
        }
        $query->orderByDesc('id');

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            fn (Template $t) => TemplateResource::make($t),
        );
    }

    public function store(SaveTemplateRequest $request): JsonResponse
    {
        $template = $this->templates->create($request->validated(), $request->user()?->uuid);

        return $this->created(TemplateResource::make($template));
    }

    public function show(string $template): JsonResponse
    {
        return $this->ok(TemplateResource::make($this->templateOrFail($template)));
    }

    public function update(SaveTemplateRequest $request, string $template): JsonResponse
    {
        $model = $this->templates->update($this->templateOrFail($template), $request->validated(), $request->user()?->uuid);

        return $this->ok(TemplateResource::make($model));
    }

    public function destroy(string $template): JsonResponse
    {
        $this->templateOrFail($template)->delete();

        return $this->ok(['deleted' => true]);
    }
}
