<?php

namespace Marvel\Services\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\MediaAttachment;
use Marvel\Database\Models\MediaItem;
use Marvel\Database\Models\MediaItemVersion;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductImage;
use Marvel\Exceptions\MarvelBadRequestException;
use Marvel\Jobs\ProcessMediaVersionJob;

/**
 * The single door for media writes. Invariants enforced here, nowhere else:
 *
 *  - An S3 key is written ONCE. A changed image is a NEW version; put() to an
 *    existing key throws. Publish/retire/rollback are DB pointer flips that
 *    touch zero objects — that's what makes LIVE shareable across
 *    environments and CloudFront caching safe at `immutable`.
 *  - Objects may only be mutated/deleted by the environment that created them
 *    (version.origin_env === config('media.env')) and only while the version
 *    is draft/processing/rejected. Staging can therefore never damage a prod
 *    object even before IAM says so.
 *  - Ordering lives on media_attachments.position — reorder() creates no
 *    versions.
 *  - Never pass 'visibility' to S3 puts: the bucket has ACLs disabled
 *    (Bucket-owner-enforced) and rejects public-read ACLs.
 */
class MediaService
{
    public function __construct(private MediaUrlService $urls)
    {
    }

    /** Create a new item with its v1 draft and queue variant processing. */
    public function upload(UploadedFile $file, array $attrs = [], ?int $byUserId = null): MediaItem
    {
        $item = MediaItem::create([
            'entity_hint' => $attrs['entity_hint'] ?? 'misc',
            'created_by' => $byUserId,
            'meta' => array_filter([
                'alt' => $attrs['alt'] ?? null,
                // Whitelist: 'adoption' is the migration command's rollback
                // marker and must be unreachable from the API.
                'source' => in_array($attrs['source'] ?? null, ['upload', 'manual', 'ai', 'import'], true)
                    ? $attrs['source']
                    : 'upload',
                'attribution' => $attrs['attribution'] ?? null,
            ]),
        ]);
        $this->storeVersionFile($item, 1, $file, $byUserId);

        return $item->refresh();
    }

    /** New immutable version on an existing item (v{n+1}, draft). */
    public function createVersion(MediaItem $item, UploadedFile $file, ?int $byUserId = null): MediaItemVersion
    {
        return $this->storeVersionFile($item, $item->nextVersionNumber(), $file, $byUserId);
    }

    /** Job body: generate variants, draft/processing → ready. */
    public function process(MediaItemVersion $version): void
    {
        if (!in_array($version->status, [MediaItemVersion::DRAFT, MediaItemVersion::PROCESSING], true)) {
            return; // replayed job after a state change — idempotent no-op
        }
        if ($version->status === MediaItemVersion::DRAFT) {
            $this->transition($version, MediaItemVersion::PROCESSING);
        }

        $variants = app(MediaVariantGenerator::class)->generate($version);
        $version->forceFill(['variants' => $variants])->save();
        $this->transition($version, MediaItemVersion::READY);
    }

    public function approve(MediaItemVersion $version, int $byUserId): MediaItemVersion
    {
        $this->transition($version, MediaItemVersion::APPROVED, [
            'approved_by' => $byUserId,
            'approved_at' => now(),
        ]);

        return $version;
    }

    public function reject(MediaItemVersion $version, int $byUserId, ?string $reason = null): MediaItemVersion
    {
        $this->transition($version, MediaItemVersion::REJECTED, [
            'rejection_reason' => $reason ? mb_substr($reason, 0, 500) : null,
        ]);

        return $version;
    }

    /**
     * Pointer flip: current live → retired, target → live. No S3 traffic.
     * Also accepts a READY version when the caller has publish rights —
     * approve+publish is one admin gesture in the UI.
     */
    public function publish(MediaItemVersion $version, ?int $byUserId = null): MediaItemVersion
    {
        if ($version->status === MediaItemVersion::READY && $byUserId !== null) {
            $this->approve($version, $byUserId);
            $version->refresh();
        }

        return DB::transaction(function () use ($version) {
            $item = $version->item()->lockForUpdate()->first();
            $current = $item->live_version_id
                ? MediaItemVersion::query()->lockForUpdate()->find($item->live_version_id)
                : null;
            if ($current && $current->id !== $version->id) {
                $this->transition($current, MediaItemVersion::RETIRED, ['retired_at' => now()]);
            }
            $this->transition($version, MediaItemVersion::LIVE, ['published_at' => now()]);
            $item->forceFill(['live_version_id' => $version->id])->save();
            $this->denormalize($item);

            return $version->refresh();
        });
    }

    /** Roll the live pointer back to a retired version. No upload, no copy. */
    public function rollback(MediaItem $item, MediaItemVersion $target): MediaItemVersion
    {
        if ($target->media_item_id !== $item->id) {
            throw new MarvelBadRequestException('Version does not belong to this media item.');
        }
        if ($target->status !== MediaItemVersion::RETIRED || $target->purged_at) {
            throw new MarvelBadRequestException('Only a retired, un-purged version can be rolled back to.');
        }

        return DB::transaction(function () use ($item, $target) {
            $item = MediaItem::query()->lockForUpdate()->find($item->id);
            if ($item->live_version_id) {
                $current = MediaItemVersion::query()->lockForUpdate()->find($item->live_version_id);
                if ($current && $current->id !== $target->id) {
                    $this->transition($current, MediaItemVersion::RETIRED, ['retired_at' => now()]);
                }
            }
            $this->transition($target, MediaItemVersion::LIVE, ['published_at' => now(), 'retired_at' => null]);
            $item->forceFill(['live_version_id' => $target->id])->save();
            $this->denormalize($item);

            return $target->refresh();
        });
    }

    /** Un-publish an item entirely (live → retired, no live version). */
    public function retire(MediaItem $item): void
    {
        DB::transaction(function () use ($item) {
            $item = MediaItem::query()->lockForUpdate()->find($item->id);
            if (!$item->live_version_id) {
                return;
            }
            $current = MediaItemVersion::query()->lockForUpdate()->find($item->live_version_id);
            if ($current) {
                $this->transition($current, MediaItemVersion::RETIRED, ['retired_at' => now()]);
            }
            $item->forceFill(['live_version_id' => null])->save();
            $this->denormalize($item);
        });
    }

    /**
     * Physically delete a draft/rejected version this environment created.
     * The ONLY user-facing path to an S3 delete.
     */
    public function deleteDraft(MediaItemVersion $version): void
    {
        $this->assertObjectMutable($version);
        foreach ($version->allKeys() as $key) {
            try {
                Storage::disk('s3')->delete($key);
            } catch (\Throwable $e) {
                Log::warning('media: draft object delete failed', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }
        $item = $version->item;
        $version->delete();
        // An item left with no versions and no attachments is an empty shell.
        if ($item && $item->versions()->count() === 0 && $item->attachments()->count() === 0) {
            $item->delete();
        }
    }

    public function attach(MediaItem $item, Model $entity, string $role = 'gallery', ?int $position = null): MediaAttachment
    {
        if ($position === null) {
            $position = (int) MediaAttachment::query()
                ->where('attachable_type', $entity->getMorphClass())
                ->where('attachable_id', $entity->getKey())
                ->where('role', $role)
                ->max('position') + 1;
        }
        $attachment = MediaAttachment::query()->firstOrCreate([
            'media_item_id' => $item->id,
            'attachable_type' => $entity->getMorphClass(),
            'attachable_id' => $entity->getKey(),
            'role' => $role,
        ], ['position' => $position]);
        $this->denormalizeEntity($entity);

        return $attachment;
    }

    public function detach(MediaItem $item, Model $entity, ?string $role = null): void
    {
        MediaAttachment::query()
            ->where('media_item_id', $item->id)
            ->where('attachable_type', $entity->getMorphClass())
            ->where('attachable_id', $entity->getKey())
            ->when($role, fn ($q) => $q->where('role', $role))
            ->delete();
        $this->denormalizeEntity($entity);
    }

    /** Reassign positions in the given order. Creates NO versions. */
    public function reorder(Model $entity, string $role, array $mediaItemIds): void
    {
        DB::transaction(function () use ($entity, $role, $mediaItemIds) {
            foreach (array_values($mediaItemIds) as $pos => $mediaItemId) {
                MediaAttachment::query()
                    ->where('attachable_type', $entity->getMorphClass())
                    ->where('attachable_id', $entity->getKey())
                    ->where('role', $role)
                    ->where('media_item_id', $mediaItemId)
                    ->update(['position' => $pos]);
            }
        });
        $this->denormalizeEntity($entity);
    }

    public function getLiveVersion(MediaItem $item): ?MediaItemVersion
    {
        return $item->liveVersion;
    }

    public function getVersions(MediaItem $item)
    {
        return $item->versions()->get();
    }

    // ── internals ──────────────────────────────────────────────────────────

    private function storeVersionFile(MediaItem $item, int $number, UploadedFile $file, ?int $byUserId): MediaItemVersion
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $key = $this->urls->storageKey($item, $number, "original.{$ext}");
        $this->putNew($key, $file->getContent(), $file->getMimeType());

        [$width, $height] = @getimagesize($file->getRealPath()) ?: [null, null];

        $version = new MediaItemVersion();
        $version->forceFill([
            'media_item_id' => $item->id,
            'version_number' => $number,
            'status' => MediaItemVersion::DRAFT,
            'origin_env' => config('media.env'),
            'original_key' => $key,
            'mime' => $file->getMimeType(),
            'width' => $width,
            'height' => $height,
            'size_bytes' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $byUserId,
        ])->save();

        ProcessMediaVersionJob::dispatch($version->id)->onQueue(config('media.queue'));

        return $version;
    }

    /**
     * Immutability at the write seam: refuse to PUT over any existing key.
     * Keys are immutable so CloudFront may cache them forever.
     */
    public function putNew(string $key, string $contents, ?string $mime = null): void
    {
        $disk = Storage::disk('s3');
        if ($disk->exists($key)) {
            throw new MarvelBadRequestException("Refusing to overwrite existing media object: {$key}");
        }
        $disk->put($key, $contents, [
            'CacheControl' => 'public, max-age=31536000, immutable',
        ] + ($mime ? ['ContentType' => $mime] : []));
    }

    /** Object mutations: own-env, pre-publish states only. */
    private function assertObjectMutable(MediaItemVersion $version): void
    {
        if ($version->origin_env !== config('media.env')) {
            throw new MarvelBadRequestException(
                "This environment ({$version->origin_env}-origin object) cannot modify or delete it from " . config('media.env') . '.'
            );
        }
        if (!in_array($version->status, MediaItemVersion::OBJECT_MUTABLE, true)) {
            throw new MarvelBadRequestException("A {$version->status} version's objects are immutable — create a new version instead.");
        }
    }

    private function transition(MediaItemVersion $version, string $to, array $extra = []): void
    {
        if (!$version->canTransitionTo($to)) {
            throw new MarvelBadRequestException("Illegal media version transition {$version->status} → {$to}.");
        }
        $version->forceFill(['status' => $to] + $extra)->save();
    }

    private function denormalize(MediaItem $item): void
    {
        foreach ($item->attachments()->get() as $attachment) {
            $entity = $attachment->attachable;
            if ($entity) {
                $this->denormalizeEntity($entity);
            }
        }
    }

    /**
     * Keep the legacy serving cache in the exact shape frontends read today.
     * Products: upsert product_images rows (media-backed ones only; legacy
     * rows are left alone) then rebuild products.image/gallery via the
     * existing syncImageColumns(). Other entity types keep their JSON columns
     * untouched until their adoption phase.
     */
    private function denormalizeEntity(Model $entity): void
    {
        if (!$entity instanceof Product) {
            return;
        }

        $attachments = MediaAttachment::query()
            ->where('attachable_type', $entity->getMorphClass())
            ->where('attachable_id', $entity->getKey())
            ->whereIn('role', ['main', 'gallery'])
            ->orderBy('position')
            ->with('mediaItem.liveVersion')
            ->get();

        $liveItemIds = [];
        foreach ($attachments as $attachment) {
            $item = $attachment->mediaItem;
            $live = $item?->liveVersion;
            if (!$live) {
                continue;
            }
            $liveItemIds[] = $item->id;
            // in_gallery/is_primary are the legacy admin's publish/hero flags —
            // preserved on existing rows (adopted hidden images must not be
            // republished); set only when the row is first created.
            $existing = ProductImage::query()
                ->where('product_id', $entity->getKey())
                ->where('media_item_id', $item->id)
                ->first();
            $values = [
                'url' => $this->urls->url($live, 'large') ?? $this->urls->url($live),
                'thumbnail_url' => $this->urls->url($live, 'thumbnail'),
                'alt' => $item->meta['alt'] ?? null,
                'sort_order' => $attachment->position,
                'source' => $item->meta['source'] ?? 'media',
            ];
            if ($existing) {
                $existing->update($values);
            } else {
                ProductImage::query()->create($values + [
                    'product_id' => $entity->getKey(),
                    'media_item_id' => $item->id,
                    'is_primary' => $attachment->role === 'main',
                    'in_gallery' => true,
                ]);
            }
        }
        // Media-backed rows whose item is no longer attached or live.
        ProductImage::query()
            ->where('product_id', $entity->getKey())
            ->whereNotNull('media_item_id')
            ->when($liveItemIds, fn ($q) => $q->whereNotIn('media_item_id', $liveItemIds))
            ->delete();

        $entity->refresh()->syncImageColumns();
    }
}
