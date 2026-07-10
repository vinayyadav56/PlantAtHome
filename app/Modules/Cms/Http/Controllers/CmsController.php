<?php

namespace App\Modules\Cms\Http\Controllers;

use App\Modules\Cms\Infrastructure\Models\Banner;
use App\Modules\Cms\Infrastructure\Models\Page;
use App\Shared\Application\DomainActionException;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** City-targetable CMS: pages + banners. Reads public, writes admin. */
final class CmsController extends ApiController
{
    /** GET /api/v1/cms/pages/{slug}?city= — city-specific overrides the global page. */
    public function page(Request $request, string $slug): JsonResponse
    {
        $city = $request->query('city');
        $page = Page::where('slug', $slug)->where('status', 'published')
            ->where(fn ($q) => $q->where('city_uuid', $city)->orWhereNull('city_uuid'))
            ->orderByRaw('city_uuid is null asc') // city-specific first
            ->first();

        if (! $page) {
            throw DomainActionException::notFound('Page not found.', 'PAGE_NOT_FOUND');
        }

        return $this->ok(['slug' => $page->slug, 'title' => $page->title, 'body' => $page->body, 'city_uuid' => $page->city_uuid]);
    }

    /** GET /api/v1/cms/banners?position=&city= — city banners + global fallbacks. */
    public function banners(Request $request): JsonResponse
    {
        $city = $request->query('city');
        $banners = Banner::where('is_active', true)
            ->when($request->query('position'), fn ($q, $p) => $q->where('position', $p))
            ->where(fn ($q) => $q->where('city_uuid', $city)->orWhereNull('city_uuid'))
            ->orderByRaw('city_uuid is null asc')
            ->orderBy('sort')
            ->get();

        return $this->ok($banners->map(fn (Banner $b) => [
            'uuid' => $b->uuid, 'title' => $b->title, 'image_url' => $b->image_url,
            'link' => $b->link, 'position' => $b->position, 'city_uuid' => $b->city_uuid,
        ])->all());
    }

    /** POST /api/v1/cms/pages (admin) */
    public function storePage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug'      => ['required', 'string', 'max:255'],
            'title'     => ['required', 'string', 'max:255'],
            'body'      => ['nullable', 'string'],
            'city_uuid' => ['nullable', 'uuid'],
            'status'    => ['nullable', 'in:published,draft'],
        ]);
        $page = Page::updateOrCreate(
            ['slug' => $data['slug'], 'city_uuid' => $data['city_uuid'] ?? null],
            ['title' => $data['title'], 'body' => $data['body'] ?? null, 'status' => $data['status'] ?? 'published', 'updated_by' => $request->user()?->uuid],
        );

        return $this->created(['uuid' => $page->uuid, 'slug' => $page->slug]);
    }

    /** POST /api/v1/cms/banners (admin) */
    public function storeBanner(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'     => ['nullable', 'string', 'max:255'],
            'image_url' => ['required', 'string', 'max:2048'],
            'link'      => ['nullable', 'string', 'max:2048'],
            'position'  => ['nullable', 'string', 'max:64'],
            'city_uuid' => ['nullable', 'uuid'],
            'sort'      => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $banner = Banner::create($data + ['position' => $data['position'] ?? 'home']);

        return $this->created(['uuid' => $banner->uuid]);
    }
}
