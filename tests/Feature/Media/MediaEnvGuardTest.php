<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\MediaItemVersion;
use Marvel\Exceptions\MarvelBadRequestException;

/**
 * The environment guard: staging (media.env here) may physically delete ONLY
 * objects it created and only pre-publish, while pointer flips (publish/
 * rollback/retire) are legal on any origin_env because they touch no object.
 * The S3-fake existence checks are the real assertion — a denied delete must
 * leave every byte where it was.
 */
final class MediaEnvGuardTest extends MediaTestCase
{
    public function test_delete_draft_is_own_env_and_object_mutable_only(): void
    {
        // Allowed: staging-origin draft/processing/rejected from staging.
        foreach ([MediaItemVersion::DRAFT, MediaItemVersion::PROCESSING, MediaItemVersion::REJECTED] as $status) {
            $v = $this->makeVersion($status, 'staging');
            $itemId = $v->media_item_id;
            $keys = $v->allKeys();

            $this->svc()->deleteDraft($v);

            foreach ($keys as $k) {
                Storage::disk('s3')->assertMissing($k);
            }
            $this->assertDatabaseMissing('media_item_versions', ['id' => $v->id]);
            // The empty shell (no versions, no attachments) goes with it.
            $this->assertDatabaseMissing('media_items', ['id' => $itemId]);
        }

        // Denied: wrong origin_env, or a status past the object-mutable window.
        $denied = [
            'prod-origin draft from staging' => $this->makeVersion(MediaItemVersion::DRAFT, 'production'),
            'prod-origin rejected from staging' => $this->makeVersion(MediaItemVersion::REJECTED, 'production'),
            'prod-origin live from staging' => $this->makeVersion(MediaItemVersion::LIVE, 'production'),
            'own-env live is object-immutable' => $this->makeVersion(MediaItemVersion::LIVE, 'staging'),
            'own-env retired is object-immutable' => $this->makeVersion(MediaItemVersion::RETIRED, 'staging'),
        ];
        foreach ($denied as $why => $v) {
            try {
                $this->svc()->deleteDraft($v);
                $this->fail("deleteDraft must refuse: {$why}");
            } catch (MarvelBadRequestException $e) {
            }
            foreach ($v->allKeys() as $k) {
                Storage::disk('s3')->assertExists($k);
            }
            $this->assertDatabaseHas('media_item_versions', ['id' => $v->id]);
        }
    }

    public function test_pointer_flips_are_legal_on_any_origin_env(): void
    {
        // A prod-origin approved version, operated on from staging.
        $v1 = $this->makeVersion(MediaItemVersion::APPROVED, 'production');
        $item = $v1->item;

        $this->svc()->publish($v1);
        $this->assertSame(MediaItemVersion::LIVE, $v1->refresh()->status);
        $this->assertSame($v1->id, $item->refresh()->live_version_id);

        // Staging v2 publish retires the prod-origin v1 (pointer flip, no delete).
        $v2 = $this->makeVersion(MediaItemVersion::APPROVED, 'staging', [], $item, 2);
        $this->svc()->publish($v2);
        $this->assertSame(MediaItemVersion::RETIRED, $v1->refresh()->status);

        // Rollback TO the prod-origin retired version.
        $this->svc()->rollback($item->refresh(), $v1);
        $this->assertSame(MediaItemVersion::LIVE, $v1->refresh()->status);
        $this->assertSame(MediaItemVersion::RETIRED, $v2->refresh()->status);

        // Retire clears the pointer, still without S3 traffic.
        $this->svc()->retire($item);
        $this->assertNull($item->refresh()->live_version_id);
        $this->assertSame(MediaItemVersion::RETIRED, $v1->refresh()->status);

        foreach (array_merge($v1->allKeys(), $v2->allKeys()) as $key) {
            Storage::disk('s3')->assertExists($key);
        }
    }
}
