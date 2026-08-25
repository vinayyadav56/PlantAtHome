<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Database\Models\MediaAttachment;
use Marvel\Database\Models\MediaItem;
use Marvel\Database\Models\MediaItemVersion;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Exceptions\MarvelBadRequestException;
use Marvel\Http\Requests\MediaUploadRequest;
use Marvel\Services\Media\MediaService;
use Marvel\Services\Media\MediaUrlService;

/**
 * HTTP surface of the centralized media system. Thin by design: every rule
 * (state machine, env guards, S3 immutability) lives in MediaService — this
 * only resolves route params to models and shapes responses.
 */
class MediaController extends CoreController
{
    public function __construct(
        private MediaService $service,
        private MediaUrlService $urls
    ) {
    }

    /** POST /media — upload a new item (v1 draft, variants queued). */
    public function store(MediaUploadRequest $request)
    {
        $item = $this->service->upload(
            $request->file('file'),
            $request->only(['entity_hint', 'alt', 'attribution', 'source']),
            auth()->id()
        );

        return $this->itemPayload($item);
    }

    /** GET /media/{uuid} — item with all versions + live pointer. */
    public function show($uuid)
    {
        return $this->itemPayload($this->item($uuid));
    }

    /** POST /media/{uuid}/versions — new immutable version (draft). */
    public function storeVersion($uuid, MediaUploadRequest $request)
    {
        $version = $this->service->createVersion($this->item($uuid), $request->file('file'), auth()->id());

        return $this->versionPayload($version);
    }

    /** DELETE /media/{uuid}/versions/{version} — physically delete a draft/rejected version. */
    public function destroyVersion($uuid, $versionNumber)
    {
        $this->service->deleteDraft($this->version($uuid, $versionNumber));

        return ['success' => true];
    }

    /** POST /media/{uuid}/versions/{version}/approve */
    public function approve($uuid, $versionNumber)
    {
        return $this->versionPayload($this->service->approve($this->version($uuid, $versionNumber), auth()->id()));
    }

    /** POST /media/{uuid}/versions/{version}/reject — body { reason? } */
    public function reject($uuid, $versionNumber, Request $request)
    {
        return $this->versionPayload(
            $this->service->reject($this->version($uuid, $versionNumber), auth()->id(), $request->input('reason'))
        );
    }

    /** POST /media/{uuid}/versions/{version}/publish — approve+publish in one gesture when READY. */
    public function publish($uuid, $versionNumber)
    {
        return $this->versionPayload($this->service->publish($this->version($uuid, $versionNumber), auth()->id()));
    }

    /** POST /media/{uuid}/rollback/{version} — flip live back to a retired version. */
    public function rollbackTo($uuid, $versionNumber)
    {
        $item = $this->item($uuid);

        return $this->versionPayload($this->service->rollback($item, $this->version($uuid, $versionNumber)));
    }

    /** POST /media/{uuid}/retire — un-publish the item entirely. */
    public function retireItem($uuid)
    {
        $item = $this->item($uuid);
        $this->service->retire($item);

        return $this->itemPayload($item->refresh());
    }

    /** POST /media/attach — bind an item to an entity with a role. */
    public function attach(Request $request)
    {
        $request->validate([
            'media_uuid'  => ['required', 'string'],
            'entity_type' => ['required', 'in:product'],
            'entity_id'   => ['required', 'integer'],
            'role'        => ['required', 'in:main,gallery'],
            'position'    => ['nullable', 'integer'],
        ]);
        $attachment = $this->service->attach(
            $this->item($request->input('media_uuid')),
            $this->entity($request->input('entity_type'), (int) $request->input('entity_id')),
            $request->input('role'),
            $request->filled('position') ? (int) $request->input('position') : null
        );

        return $attachment;
    }

    /** POST /media/detach — remove the binding (role omitted = every role). */
    public function detach(Request $request)
    {
        $request->validate([
            'media_uuid'  => ['required', 'string'],
            'entity_type' => ['required', 'in:product'],
            'entity_id'   => ['required', 'integer'],
            'role'        => ['nullable', 'in:main,gallery'],
        ]);
        $this->service->detach(
            $this->item($request->input('media_uuid')),
            $this->entity($request->input('entity_type'), (int) $request->input('entity_id')),
            $request->input('role')
        );

        return ['success' => true];
    }

    /** PATCH /media/reorder — body { entity_type, entity_id, role, media_item_ids: [...] } */
    public function reorder(Request $request)
    {
        $request->validate([
            'entity_type'      => ['required', 'in:product'],
            'entity_id'        => ['required', 'integer'],
            'role'             => ['required', 'in:main,gallery'],
            'media_item_ids'   => ['required', 'array'],
            'media_item_ids.*' => ['integer'],
        ]);
        $this->service->reorder(
            $this->entity($request->input('entity_type'), (int) $request->input('entity_id')),
            $request->input('role'),
            $request->input('media_item_ids')
        );

        return ['success' => true];
    }

    /** GET /media/for-entity/{type}/{id} — attachments + live/pending versions per item. */
    public function forEntity($type, $id)
    {
        $entity = $this->entity($type, (int) $id);

        return MediaAttachment::query()
            ->where('attachable_type', $entity->getMorphClass())
            ->where('attachable_id', $entity->getKey())
            ->orderBy('role')
            ->orderBy('position')
            ->with('mediaItem.versions', 'mediaItem.liveVersion')
            ->get()
            ->map(function (MediaAttachment $attachment) {
                $item = $attachment->mediaItem;
                $live = $item?->liveVersion;
                $pending = $item
                    ? $item->versions->whereIn('status', [
                        MediaItemVersion::DRAFT,
                        MediaItemVersion::PROCESSING,
                        MediaItemVersion::READY,
                    ])->values()
                    : collect();

                return [
                    'media_item_id'    => $item?->id,
                    'uuid'             => $item?->uuid,
                    'entity_hint'      => $item?->entity_hint,
                    'meta'             => $item?->meta,
                    'role'             => $attachment->role,
                    'position'         => $attachment->position,
                    'live_version'     => $live ? $this->versionPayload($live) : null,
                    'pending_versions' => $pending->map(fn ($v) => $this->versionPayload($v))->values(),
                ];
            })
            ->values();
    }

    /** GET /media/pending-approvals — paginated READY versions for the approval queue. */
    public function pendingApprovals(Request $request)
    {
        $limit = (int) $request->input('limit', 20) ?: 20;

        return MediaItemVersion::query()
            ->where('status', MediaItemVersion::READY)
            ->with('item.attachments.attachable')
            ->orderBy('created_at')
            ->paginate($limit)
            ->through(function (MediaItemVersion $version) {
                $item = $version->item;

                return $this->versionPayload($version) + [
                    'item' => $item ? [
                        'id'          => $item->id,
                        'uuid'        => $item->uuid,
                        'entity_hint' => $item->entity_hint,
                        'meta'        => $item->meta,
                    ] : null,
                    'entities' => $item
                        ? $item->attachments->map(fn (MediaAttachment $a) => [
                            'type' => $a->attachable instanceof Product ? 'product' : $a->attachable_type,
                            'id'   => $a->attachable_id,
                            'name' => $a->attachable->name ?? null,
                            'role' => $a->role,
                        ])->values()
                        : collect(),
                ];
            });
    }

    // ── internals ──────────────────────────────────────────────────────────

    private function item(string $uuid): MediaItem
    {
        return MediaItem::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function version(string $uuid, $versionNumber): MediaItemVersion
    {
        return $this->item($uuid)->versions()->where('version_number', (int) $versionNumber)->firstOrFail();
    }

    private function entity(string $type, int $id): Product
    {
        if ($type !== 'product') {
            throw new MarvelBadRequestException("Unsupported media entity type: {$type}");
        }

        return Product::findOrFail($id);
    }

    private function itemPayload(MediaItem $item): array
    {
        $item->loadMissing('versions', 'liveVersion');
        $uploaders = User::query()
            ->whereIn('id', $item->versions->pluck('uploaded_by')->filter()->unique())
            ->pluck('name', 'id');

        return [
            'id'              => $item->id,
            'uuid'            => $item->uuid,
            'entity_hint'     => $item->entity_hint,
            'origin_env'      => $item->origin_env,
            'meta'            => $item->meta,
            'live_version_id' => $item->live_version_id,
            'payload'         => $this->urls->attachmentPayload($item),
            'versions'        => $item->versions
                ->map(fn ($v) => $this->versionPayload($v, $uploaders[$v->uploaded_by] ?? null))
                ->values(),
            'created_at'      => $item->created_at,
            'updated_at'      => $item->updated_at,
        ];
    }

    private function versionPayload(MediaItemVersion $version, ?string $uploaderName = null): array
    {
        $urls = ['original' => $this->urls->url($version)];
        foreach (array_keys((array) $version->variants) as $variant) {
            $urls[$variant] = $this->urls->url($version, $variant);
        }

        return [
            'id'               => $version->id,
            'version_number'   => $version->version_number,
            'status'           => $version->status,
            'urls'             => $urls,
            'uploaded_by'      => $version->uploaded_by,
            'uploader'         => $uploaderName,
            'rejection_reason' => $version->rejection_reason,
            'created_at'       => $version->created_at,
            'updated_at'       => $version->updated_at,
        ];
    }
}
