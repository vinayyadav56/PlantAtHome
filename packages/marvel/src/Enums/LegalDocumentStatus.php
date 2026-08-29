<?php

namespace Marvel\Enums;

/**
 * Lifecycle of a governed document/version. Transitions are validated ONLY by
 * Marvel\Services\Legal\LegalWorkflow — never scatter status checks elsewhere.
 */
final class LegalDocumentStatus
{
    public const DRAFT = 'draft';
    public const IN_REVIEW = 'in_review';
    public const REVISION_REQUIRED = 'revision_required';
    public const PENDING_APPROVAL = 'pending_approval';
    public const APPROVED = 'approved';
    public const PUBLISHED = 'published';
    public const SUPERSEDED = 'superseded';
    public const ARCHIVED = 'archived';

    public const ALL = [
        self::DRAFT,
        self::IN_REVIEW,
        self::REVISION_REQUIRED,
        self::PENDING_APPROVAL,
        self::APPROVED,
        self::PUBLISHED,
        self::SUPERSEDED,
        self::ARCHIVED,
    ];

    /** Statuses whose version content may still be edited. */
    public const EDITABLE = [self::DRAFT, self::REVISION_REQUIRED];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }
}
