<?php

namespace Marvel\Services\Legal;

use Illuminate\Support\Str;
use Marvel\Database\Models\Legal\LegalAudit;
use Marvel\Database\Models\Legal\LegalCategory;
use Marvel\Database\Models\Legal\LegalDocument;
use Marvel\Database\Models\Legal\LegalDocumentType;
use Marvel\Database\Models\Legal\LegalDocumentVersion;
use Marvel\Database\Models\Legal\LegalSettings;
use Marvel\Database\Models\Settings;
use Marvel\Enums\LegalDocumentStatus as Status;
use Marvel\Exceptions\MarvelException;

class LegalDocumentService
{
    public function __construct(private readonly LegalWorkflow $workflow)
    {
    }

    /** Slugs whose published HTML is mirrored into settings.options.legalPages
     *  so the storefront's existing /privacy, /terms, /data-deletion pages pick
     *  the governed content up with zero storefront changes. */
    private const STOREFRONT_SYNC = [
        'privacy-policy' => 'privacy',
        'terms-of-service' => 'terms',
        'data-deletion-instructions' => 'dataDeletion',
    ];

    public function create(array $attrs, $user): LegalDocument
    {
        $type = LegalDocumentType::findOrFail($attrs['type_id']);
        $category = isset($attrs['category_id']) ? LegalCategory::find($attrs['category_id']) : null;

        $document = LegalDocument::create([
            'title' => $attrs['title'],
            'slug' => $this->uniqueSlug($attrs['title']),
            'document_code' => $attrs['document_code'] ?? $this->generateCode($type, $category),
            'type_id' => $type->id,
            'category_id' => $category?->id,
            'department' => $attrs['department'] ?? null,
            'owner_id' => $attrs['owner_id'] ?? $user->id,
            'status' => Status::DRAFT,
            'visibility' => $attrs['visibility'] ?? 'internal',
            'legal_review_required' => (bool) ($attrs['legal_review_required'] ?? false),
            'effective_date' => $attrs['effective_date'] ?? null,
            'review_frequency' => $attrs['review_frequency'] ?? null,
            'review_interval_days' => $attrs['review_interval_days'] ?? null,
            'next_review_date' => $attrs['next_review_date'] ?? null,
            'tags' => $attrs['tags'] ?? null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        // Drafts start at 0.1; the first publish promotes to 1.0 (document-
        // control convention — a 1.x number means "approved and issued").
        $document->versions()->create([
            'title' => $attrs['title'],
            'version_major' => 0,
            'version_minor' => 1,
            'content_json' => $attrs['content_json'] ?? null,
            'content_html' => $this->sanitize($attrs['content_html'] ?? null),
            'created_by' => $user->id,
        ]);

        return $document->fresh(['versions', 'type', 'category']);
    }

    /** Autosave/edit a version's content — only while the version is editable. */
    public function updateVersion(LegalDocumentVersion $version, array $attrs, $user): LegalDocumentVersion
    {
        if (! in_array($version->status, Status::EDITABLE, true)) {
            throw new MarvelException(
                "Version {$version->versionLabel()} is {$version->status} and can no longer be edited. Create a new version instead."
            );
        }
        $version->fill(array_filter([
            'title' => $attrs['title'] ?? null,
            'content_json' => $attrs['content_json'] ?? null,
            'change_summary' => $attrs['change_summary'] ?? null,
            'attachments' => $attrs['attachments'] ?? null,
        ], fn ($v) => $v !== null));
        if (array_key_exists('content_html', $attrs)) {
            $version->content_html = $this->sanitize($attrs['content_html']);
        }
        $version->save();

        if (isset($attrs['title'])) {
            $version->document->update(['title' => $attrs['title'], 'updated_by' => $user->id]);
        }

        return $version;
    }

    /**
     * Fork the next version. A published document is never edited in place —
     * this is the only path to change it (minor by default, major on request).
     */
    public function newVersion(LegalDocument $document, $user, bool $major = false): LegalDocumentVersion
    {
        if ($document->workingVersion()) {
            throw new MarvelException('A draft version is already in progress for this document.');
        }
        $latest = $document->versions()->first();
        $base = $document->currentVersion ?? $latest;

        // refresh(): status is workflow-guarded, so the in-memory model must
        // pick the 'draft' column default up from the DB.
        return $document->versions()->create([
            'title' => $document->title,
            'version_major' => $major ? $latest->version_major + 1 : $latest->version_major,
            'version_minor' => $major ? 0 : $latest->version_minor + 1,
            'content_json' => $base?->content_json,
            'content_html' => $base?->content_html,
            'attachments' => $base?->attachments,
            'created_by' => $user->id,
        ])->refresh();
    }

    public function applyTransition(string $transition, LegalDocumentVersion $version, $user, ?string $comment = null): LegalDocumentVersion
    {
        if ($transition === 'publish') {
            $this->guardPublication($version);
            // A 0.x draft becomes 1.0 on its first issue.
            if ((int) $version->version_major === 0) {
                $version->forceFill([
                    'version_major' => 1,
                    'version_minor' => 0,
                ])->save();
            }
        }

        $version = $this->workflow->apply($transition, $version, $user, $comment);

        if ($transition === 'publish') {
            $this->bumpReviewSchedule($version->document);
            $this->syncStorefrontLegalPages($version->document, $version);
        }

        return $version;
    }

    /**
     * A public document must not carry operational numbers that management has
     * not signed off. Seeded variable values are drafting conveniences; this is
     * what stops one becoming a customer-facing promise by accident.
     */
    private function guardPublication(LegalDocumentVersion $version): void
    {
        $document = $version->document;
        if (! $document || $document->visibility !== 'public') {
            return; // internal documents may publish with draft values
        }
        $outstanding = VariableResolver::outstanding($version->content_html);
        $blocking = array_merge($outstanding['unapproved'], $outstanding['unknown']);
        if ($blocking !== []) {
            throw new MarvelException(
                'This public document uses operational values that are not approved yet: '
                . implode(', ', $blocking)
                . '. Approve them under Legal → Variables before publishing.'
            );
        }
    }

    /** Restore an old version's content as a fresh draft (never mutates it). */
    public function restoreAsDraft(LegalDocumentVersion $source, $user): LegalDocumentVersion
    {
        $draft = $this->newVersion($source->document, $user);
        $draft->fill([
            'content_json' => $source->content_json,
            'change_summary' => "Restored from version {$source->versionLabel()}",
        ]);
        $draft->content_html = $source->content_html;
        $draft->save();
        LegalAudit::record('version_restored', $source->document_id, $draft->id, ['source' => $source->versionLabel()]);

        return $draft;
    }

    private function bumpReviewSchedule(LegalDocument $document): void
    {
        $days = match ($document->review_frequency) {
            'monthly' => 30,
            'quarterly' => 91,
            'half_yearly' => 182,
            'annually' => 365,
            'custom' => $document->review_interval_days,
            default => null,
        };
        if ($days) {
            $document->forceFill([
                'last_reviewed_at' => now()->toDateString(),
                'next_review_date' => now()->addDays($days)->toDateString(),
            ])->save();
        }
    }

    private function syncStorefrontLegalPages(LegalDocument $document, LegalDocumentVersion $version): void
    {
        $key = self::STOREFRONT_SYNC[$document->slug] ?? null;
        if ($key === null || $document->visibility !== 'public') {
            return;
        }
        try {
            $settings = Settings::query()->where('language', DEFAULT_LANGUAGE)->first();
            if (! $settings) {
                return;
            }
            $options = $settings->options;
            $options['legalPages'] = array_merge($options['legalPages'] ?? [], [
                $key => [
                    'title' => $document->title,
                    'updatedAt' => now()->format('F j, Y'),
                    // Public context: only approved values are substituted.
                    'body' => VariableResolver::resolve($version->content_html, VariableResolver::PUBLIC_CONTEXT),
                ],
            ]);
            $settings->options = $options;
            $settings->save();
            LegalAudit::record('storefront_synced', $document->id, $version->id, ['legalPages_key' => $key]);
        } catch (\Throwable $e) {
            // The publish itself must not fail on the mirror write.
            LegalAudit::record('storefront_sync_failed', $document->id, $version->id, ['error' => $e->getMessage()]);
        }
    }

    private function uniqueSlug(string $base): string
    {
        $slug = Str::slug($base) ?: 'document';
        $candidate = $slug;
        $n = 1;
        while (LegalDocument::withTrashed()->where('slug', $candidate)->exists()) {
            $candidate = $slug . '-' . (++$n);
        }

        return $candidate;
    }

    private function generateCode(LegalDocumentType $type, ?LegalCategory $category): string
    {
        $format = LegalSettings::current()->numbering_format ?: 'PAH-{TYPE}-{CAT}-{SEQ}';
        $cat = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $category->name ?? 'GEN'), 0, 4)) ?: 'GEN';
        $seq = 1;
        do {
            $code = str_replace(
                ['{TYPE}', '{CAT}', '{SEQ}'],
                [$type->code_prefix, $cat, str_pad((string) $seq, 3, '0', STR_PAD_LEFT)],
                $format
            );
            $seq++;
        } while (LegalDocument::withTrashed()->where('document_code', $code)->exists());

        return $code;
    }

    /**
     * Server-side HTML sanitation. Storage is the trust boundary: this content
     * is rendered in the admin AND, for public policies, mirrored onto the
     * storefront, so a stored payload would reach customers.
     *
     * HTMLPurifier (already a dependency) parses the document rather than
     * pattern-matching it — regex sanitizers are defeated by malformed nesting,
     * entity-encoded `javascript:`, and attribute-position tricks like
     * `<svg/onload=…>`. The allowlist matches exactly what the Tiptap editor
     * can produce; anything else is dropped rather than escaped.
     */
    private function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        $cachePath = storage_path('app/htmlpurifier');
        if (! is_dir($cachePath)) {
            @mkdir($cachePath, 0775, true);
        }

        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set(
            'HTML.Allowed',
            'h2,h3,h4,p,br,hr,strong,em,u,s,blockquote,'
            . 'ul,ol,li,'
            . 'table,thead,tbody,tr,th[colspan|rowspan],td[colspan|rowspan],'
            . 'a[href|title|target|rel]'
        );
        // Only these schemes may appear in href — javascript:/data: are dropped.
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('HTML.TargetBlank', true); // adds rel=noopener on target=_blank
        $config->set('AutoFormat.RemoveEmpty', false); // empty template sections are intentional
        $config->set('Cache.SerializerPath', $cachePath);
        // Tiptap checklists carry their state in data-* attributes, which
        // HTMLPurifier only permits via an explicit definition.
        $config->set('HTML.DefinitionID', 'plantathome-legal');
        $config->set('HTML.DefinitionRev', 1);
        if ($def = $config->maybeGetRawHTMLDefinition()) {
            $def->addAttribute('li', 'data-checked', 'Enum#true,false');
            $def->addAttribute('ul', 'data-type', 'Enum#taskList');
            $def->addAttribute('li', 'data-type', 'Enum#taskItem');
        }

        return (new \HTMLPurifier($config))->purify($html);
    }
}
