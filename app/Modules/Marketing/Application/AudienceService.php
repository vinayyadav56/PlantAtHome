<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Application;

use App\Modules\Marketing\Application\DTO\AudiencePreview;
use App\Modules\Marketing\Application\Sql\AudienceQueryRunner;
use App\Modules\Marketing\Infrastructure\Models\Audience;
use App\Modules\Marketing\Infrastructure\Models\AudienceVersion;
use App\Shared\Application\DomainActionException;
use Illuminate\Database\ConnectionInterface;

/**
 * Audience Builder use-cases. Preview never persists; saving/refreshing freezes
 * an immutable snapshot version (V1, V2 …) that campaigns pin to. All SQL is
 * validated + capped + timed by the AudienceQueryRunner before it ever runs.
 */
final class AudienceService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AudienceQueryRunner $runner,
    ) {
    }

    /** Dry-run a query for the preview pane — validates + executes, no writes. */
    public function preview(string $sql): AudiencePreview
    {
        return $this->runner->preview($sql);
    }

    /**
     * Create an audience and freeze its first snapshot (V1) in one transaction.
     * The snapshot run also validates the SQL, so a bad query never creates a row.
     */
    public function create(array $data, ?string $actorUuid): Audience
    {
        $snapshot = $this->runner->snapshot($data['sql_query']);

        return $this->db->transaction(function () use ($data, $actorUuid, $snapshot) {
            $audience = Audience::create([
                'name'              => $data['name'],
                'description'       => $data['description'] ?? null,
                'sql_query'         => $data['sql_query'],
                'status'            => 'ready',
                'current_version'   => 0,
                'last_result_count' => $snapshot->totalCount,
                'last_execution_ms' => $snapshot->executionMs,
                'columns'           => $snapshot->columns,
                'created_by_uuid'   => $actorUuid,
            ]);

            $this->freezeVersion($audience, $snapshot, $actorUuid);

            return $audience->fresh();
        });
    }

    /**
     * Edit the definition. Changing the SQL does NOT auto-resnapshot — the
     * operator refreshes explicitly so a running campaign's pinned version is
     * never surprised.
     */
    public function update(Audience $audience, array $data, ?string $actorUuid = null): Audience
    {
        return $this->db->transaction(function () use ($audience, $data) {
            $audience->fill(array_filter([
                'name'        => $data['name'] ?? null,
                'description' => $data['description'] ?? null,
                'sql_query'   => $data['sql_query'] ?? null,
                'status'      => $data['status'] ?? null,
            ], fn ($v) => $v !== null))->save();

            return $audience->fresh();
        });
    }

    /** Re-run the current SQL and freeze the next version (V+1). */
    public function refresh(Audience $audience, ?string $actorUuid = null): Audience
    {
        $snapshot = $this->runner->snapshot($audience->sql_query);

        return $this->db->transaction(function () use ($audience, $snapshot, $actorUuid) {
            $this->freezeVersion($audience, $snapshot, $actorUuid);

            $audience->update([
                'status'            => 'ready',
                'last_result_count' => $snapshot->totalCount,
                'last_execution_ms' => $snapshot->executionMs,
                'columns'           => $snapshot->columns,
            ]);

            return $audience->fresh();
        });
    }

    /** Persist a new immutable version and bump the audience's current_version. */
    private function freezeVersion(Audience $audience, AudiencePreview $snapshot, ?string $actorUuid): AudienceVersion
    {
        $nextVersion = (int) $audience->current_version + 1;
        $previewRows = (int) config('marketing.audience.preview_rows', 100);

        $version = AudienceVersion::create([
            'audience_id'     => $audience->id,
            'version'         => $nextVersion,
            'sql_query'       => $audience->sql_query,
            'snapshot'        => json_encode($snapshot->rows),
            'sample'          => array_slice($snapshot->rows, 0, $previewRows),
            'columns'         => $snapshot->columns,
            'result_count'    => $snapshot->totalCount,
            'execution_ms'    => $snapshot->executionMs,
            'truncated'       => $snapshot->truncated,
            'created_by_uuid' => $actorUuid,
        ]);

        $audience->current_version = $nextVersion;
        $audience->save();

        return $version;
    }

    /** The pinned version a campaign should use: explicit id, else the latest. */
    public function resolveVersion(Audience $audience, ?string $versionUuid = null): AudienceVersion
    {
        if ($versionUuid) {
            $version = AudienceVersion::where('audience_id', $audience->id)->where('uuid', $versionUuid)->first();
            if (! $version) {
                throw DomainActionException::notFound('That audience version does not exist.', 'AUDIENCE_VERSION_NOT_FOUND');
            }

            return $version;
        }

        $latest = AudienceVersion::where('audience_id', $audience->id)
            ->orderByDesc('version')
            ->first();

        if (! $latest) {
            throw DomainActionException::unprocessable(
                'This audience has no saved snapshot yet — refresh it first.',
                'AUDIENCE_NO_SNAPSHOT',
            );
        }

        return $latest;
    }
}
