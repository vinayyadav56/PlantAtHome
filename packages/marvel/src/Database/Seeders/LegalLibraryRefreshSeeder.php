<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\Legal\LegalCategory;
use Marvel\Database\Models\Legal\LegalDocument;
use Marvel\Database\Models\Legal\LegalDocumentRelation;
use Marvel\Database\Models\Legal\LegalDocumentType;
use Marvel\Database\Models\Legal\LegalTemplate;
use Marvel\Database\Models\Legal\LegalVariable;
use Marvel\Enums\LegalDocumentStatus as Status;

/**
 * Part 2 — installs the structured policy library (12/13-section formats,
 * {{variable}} references instead of invented numbers) over the Part 1 seed.
 *
 * SAFETY: a document is only rewritten when it is still an untouched machine
 * seed — DRAFT status, never edited, no comments, no approvals. Anything a
 * human has worked on is left exactly as-is and reported as skipped.
 */
class LegalLibraryRefreshSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('legal_documents') || ! Schema::hasTable('legal_variables')) {
            $this->command?->warn('legal tables missing — run migrations first.');

            return;
        }

        $this->seedVariables();
        $types = LegalDocumentType::pluck('id', 'code_prefix');
        $categories = LegalCategory::pluck('id', 'slug');

        $path = __DIR__ . '/../../../data/legal-blueprints-v2.json';
        if (! is_file($path)) {
            $this->command?->warn('legal-blueprints-v2.json not found — variables seeded, documents skipped.');

            return;
        }
        $blueprints = json_decode((string) file_get_contents($path), true) ?: [];

        $created = $updated = $skipped = 0;
        $titleToId = [];

        foreach ($blueprints as $bp) {
            $title = trim($bp['title'] ?? '');
            if ($title === '') {
                continue;
            }
            $slug = Str::slug($title);
            $document = LegalDocument::where('slug', $slug)->orWhere('title', $title)->first();

            if ($document === null) {
                $document = $this->createDocument($bp, $slug, $types, $categories);
                $created++;
            } elseif ($this->isUntouchedSeed($document)) {
                $this->refreshDocument($document, $bp, $categories);
                $updated++;
            } else {
                $skipped++;
                $this->command?->line("  skipped (human-edited or beyond draft): {$title}");
            }
            $titleToId[$title] = $document->id;
        }

        $relations = $this->seedRelations($blueprints, $titleToId);
        $this->seedTemplates($types, $categories);

        $this->command?->info(
            "Policy library v2: {$created} created, {$updated} refreshed, {$skipped} skipped, "
            . "{$relations} relation(s). Total documents: " . LegalDocument::count()
        );
    }

    /** Only rewrite what no human has touched. */
    private function isUntouchedSeed(LegalDocument $document): bool
    {
        if ($document->status !== Status::DRAFT) {
            return false;
        }
        $versions = $document->versions()->get();
        if ($versions->count() !== 1) {
            return false;
        }
        $version = $versions->first();
        if ($version->status !== Status::DRAFT) {
            return false;
        }
        // An edit moves updated_at away from created_at.
        if ($version->created_at && $version->updated_at
            && abs($version->updated_at->diffInSeconds($version->created_at)) > 5) {
            return false;
        }

        return ! DB::table('legal_document_comments')->where('version_id', $version->id)->exists()
            && ! DB::table('legal_document_approvals')->where('version_id', $version->id)->exists();
    }

    private function createDocument(array $bp, string $slug, $types, $categories): LegalDocument
    {
        $prefix = $bp['type_prefix'] ?? 'POL';
        $document = LegalDocument::create([
            'title' => $bp['title'],
            'slug' => $slug,
            'document_code' => $this->nextCode($prefix, $bp['category_slug'] ?? ''),
            'type_id' => $types[$prefix] ?? $types['POL'],
            'category_id' => $categories[$bp['category_slug'] ?? ''] ?? null,
            'department' => $bp['department'] ?? null,
            'status' => Status::DRAFT,
            'visibility' => $bp['visibility'] ?? 'internal',
            'legal_review_required' => (bool) ($bp['legal_review_required'] ?? true),
            'review_frequency' => 'annually',
            'next_review_date' => now()->addYear()->toDateString(),
            'tags' => $bp['tags'] ?? null,
        ]);
        $document->versions()->create([
            'title' => $bp['title'],
            'version_major' => 0,
            'version_minor' => 1,
            'content_html' => $bp['html'],
            'change_summary' => 'Initial structured draft — legal review required.',
        ]);

        return $document;
    }

    private function refreshDocument(LegalDocument $document, array $bp, $categories): void
    {
        $document->forceFill([
            'visibility' => $bp['visibility'] ?? $document->visibility,
            'department' => $bp['department'] ?? $document->department,
            'legal_review_required' => (bool) ($bp['legal_review_required'] ?? true),
            'tags' => $bp['tags'] ?? $document->tags,
            'category_id' => $categories[$bp['category_slug'] ?? ''] ?? $document->category_id,
        ])->save();

        $version = $document->versions()->first();
        $version->forceFill([
            'content_html' => $bp['html'],
            'content_json' => null, // regenerated from HTML when first opened in the editor
            'version_major' => 0,
            'version_minor' => 1,
            'change_summary' => 'Restructured to the standard document format; operational values replaced with configurable references.',
        ])->save();
    }

    /** "Reference the authoritative document" links from each blueprint. */
    private function seedRelations(array $blueprints, array $titleToId): int
    {
        $made = 0;
        foreach ($blueprints as $bp) {
            $fromId = $titleToId[trim($bp['title'] ?? '')] ?? null;
            if (! $fromId) {
                continue;
            }
            foreach ($bp['related_titles'] ?? [] as $relatedTitle) {
                $toId = $titleToId[trim($relatedTitle)] ?? null;
                if (! $toId || $toId === $fromId) {
                    continue; // only link documents that actually exist
                }
                $exists = LegalDocumentRelation::where('document_id', $fromId)
                    ->where('related_document_id', $toId)->exists();
                if (! $exists) {
                    LegalDocumentRelation::create([
                        'document_id' => $fromId,
                        'related_document_id' => $toId,
                        'relation_type' => 'related',
                    ]);
                    $made++;
                }
            }
        }

        return $made;
    }

    private function seedVariables(): void
    {
        $path = __DIR__ . '/../../../data/legal-variables.json';
        if (! is_file($path)) {
            return;
        }
        foreach (json_decode((string) file_get_contents($path), true) ?: [] as $v) {
            // firstOrCreate: never overwrite a value management has already set.
            LegalVariable::firstOrCreate(['key' => $v['key']], [
                'label' => $v['label'],
                'value' => $v['value'] ?? null,
                'unit' => $v['unit'] ?? null,
                'category' => $v['category'] ?? 'general',
                'description' => $v['description'] ?? null,
                'is_approved' => false, // a seeded value is a draft, not a decision
            ]);
        }
    }

    private function seedTemplates($types, $categories): void
    {
        $templates = [
            ['name' => 'Incident Report', 'prefix' => 'NOT', 'sections' => [
                'Incident ID', 'Date', 'Reported By', 'Severity', 'Affected System', 'Description',
                'Impact', 'Immediate Action', 'Root Cause', 'Corrective Action', 'Status',
            ]],
            ['name' => 'Risk Assessment', 'prefix' => 'STD', 'sections' => [
                'Risk', 'Category', 'Probability', 'Impact', 'Score', 'Level', 'Owner',
                'Mitigation', 'Contingency',
            ]],
        ];
        foreach ($templates as $tpl) {
            if (LegalTemplate::where('name', $tpl['name'])->exists()) {
                continue;
            }
            $html = '<blockquote><p><strong>DRAFT — LEGAL REVIEW REQUIRED.</strong> Replace this notice once the document has been approved.</p></blockquote>';
            foreach ($tpl['sections'] as $section) {
                $html .= '<h2>' . $section . '</h2><p></p>';
            }
            LegalTemplate::create([
                'name' => $tpl['name'],
                'type_id' => $types[$tpl['prefix']] ?? null,
                'category_id' => $categories['templates-forms'] ?? null,
                'description' => 'Reusable skeleton — ' . count($tpl['sections']) . ' standard sections.',
                'content_html' => $html,
                'is_active' => true,
            ]);
        }
    }

    private function nextCode(string $prefix, string $categorySlug): string
    {
        $cat = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $categorySlug) ?: 'GEN', 0, 4));
        $seq = 1;
        do {
            $code = sprintf('PAH-%s-%s-%03d', $prefix, $cat, $seq);
            $seq++;
        } while (LegalDocument::withTrashed()->where('document_code', $code)->exists());

        return $code;
    }
}
