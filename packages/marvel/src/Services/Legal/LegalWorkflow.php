<?php

namespace Marvel\Services\Legal;

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Legal\LegalAudit;
use Marvel\Database\Models\Legal\LegalDocument;
use Marvel\Database\Models\Legal\LegalDocumentApproval;
use Marvel\Database\Models\Legal\LegalDocumentVersion;
use Marvel\Enums\LegalDocumentStatus as Status;
use Marvel\Exceptions\MarvelException;

/**
 * The ONLY authority on status transitions (spec Part 24). Every transition
 * names the permission that may perform it; controllers pass the acting user
 * and never mutate status themselves. Publishing supersedes the previously
 * published version inside one transaction — a published version is never
 * silently overwritten.
 */
class LegalWorkflow
{
    /** transition => [from-statuses, to-status, required permission, comment required] */
    private const TRANSITIONS = [
        'submit-review' => [[Status::DRAFT, Status::REVISION_REQUIRED], Status::IN_REVIEW, 'legal.edit', false],
        'request-revision' => [[Status::IN_REVIEW, Status::PENDING_APPROVAL], Status::REVISION_REQUIRED, 'legal.review', true],
        'approve-review' => [[Status::IN_REVIEW], Status::PENDING_APPROVAL, 'legal.review', false],
        'reject' => [[Status::IN_REVIEW, Status::PENDING_APPROVAL], Status::REVISION_REQUIRED, 'legal.review', true],
        'approve' => [[Status::PENDING_APPROVAL], Status::APPROVED, 'legal.approve', false],
        'publish' => [[Status::APPROVED], Status::PUBLISHED, 'legal.publish', false],
        'archive' => [[Status::PUBLISHED, Status::DRAFT, Status::APPROVED], Status::ARCHIVED, 'legal.archive', false],
        'restore' => [[Status::ARCHIVED], Status::DRAFT, 'legal.archive', false],
    ];

    public static function transitions(): array
    {
        return self::TRANSITIONS;
    }

    public function apply(string $transition, LegalDocumentVersion $version, $user, ?string $comment = null): LegalDocumentVersion
    {
        $spec = self::TRANSITIONS[$transition] ?? null;
        if ($spec === null) {
            throw new MarvelException("Unknown transition: {$transition}");
        }
        [$fromStatuses, $to, $permission, $commentRequired] = $spec;

        if (! $user || (! $user->hasPermissionTo($permission) && ! $user->hasRole('super_admin'))) {
            throw new MarvelException('NOT_AUTHORIZED');
        }
        if (! in_array($version->status, $fromStatuses, true)) {
            throw new MarvelException(
                "Transition '{$transition}' is not allowed from status '{$version->status}'."
            );
        }
        if ($commentRequired && trim((string) $comment) === '') {
            throw new MarvelException('A comment is required for this action.');
        }

        return DB::transaction(function () use ($transition, $version, $user, $comment, $to) {
            $from = $version->status;
            $now = now();

            $stamps = match ($to) {
                Status::IN_REVIEW => ['submitted_at' => $now],
                Status::PENDING_APPROVAL => ['reviewed_by' => $user->id, 'reviewed_at' => $now],
                Status::APPROVED => ['approved_by' => $user->id, 'approved_at' => $now],
                Status::PUBLISHED => ['published_at' => $now],
                default => [],
            };
            $version->forceFill(array_merge(['status' => $to], $stamps))->save();

            $document = $version->document()->lockForUpdate()->first();

            if ($to === Status::PUBLISHED) {
                // Supersede the previously published version (pointer flip).
                if ($document->current_version_id && $document->current_version_id !== $version->id) {
                    LegalDocumentVersion::whereKey($document->current_version_id)
                        ->update(['status' => Status::SUPERSEDED]);
                }
                $document->forceFill([
                    'current_version_id' => $version->id,
                    'status' => Status::PUBLISHED,
                    'published_at' => $now,
                    'archived_at' => null,
                ])->save();
            } elseif ($to === Status::ARCHIVED) {
                $document->forceFill(['status' => Status::ARCHIVED, 'archived_at' => $now])->save();
            } else {
                // Document status mirrors its working version while unpublished;
                // a published document with a new draft in flight stays PUBLISHED.
                if ($document->status !== Status::PUBLISHED) {
                    $document->forceFill(['status' => $to])->save();
                }
            }

            if (in_array($transition, ['approve', 'reject'], true)) {
                LegalDocumentApproval::create([
                    'version_id' => $version->id,
                    'approver_id' => $user->id,
                    'status' => $transition === 'approve' ? 'approved' : 'rejected',
                    'comment' => $comment,
                    'requested_at' => $version->reviewed_at,
                    'acted_at' => $now,
                ]);
            }

            LegalAudit::record($transition, $document->id, $version->id, array_filter([
                'from' => $from,
                'to' => $to,
                'comment' => $comment,
            ]));

            return $version->fresh();
        });
    }
}
