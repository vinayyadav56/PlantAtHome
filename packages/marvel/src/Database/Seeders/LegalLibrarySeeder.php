<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\Legal\LegalCategory;
use Marvel\Database\Models\Legal\LegalDocument;
use Marvel\Database\Models\Legal\LegalDocumentType;
use Marvel\Database\Models\Legal\LegalTemplate;
use Marvel\Enums\LegalDocumentStatus as Status;

/**
 * Seeds the Legal & Operations library: taxonomy, reusable templates, the
 * policy blueprints (DRAFT — legal review required), and the three live public
 * pages as PUBLISHED v1.0 carrying exactly the HTML the storefront serves today.
 *
 * Idempotent: keyed on document slug / category slug / type prefix, so re-runs
 * add what is missing and never duplicate or overwrite edited content.
 */
class LegalLibrarySeeder extends Seeder
{
    private const TYPES = [
        ['name' => 'Policy', 'code_prefix' => 'POL', 'sort_order' => 1],
        ['name' => 'Standard Operating Procedure', 'code_prefix' => 'SOP', 'sort_order' => 2],
        ['name' => 'Agreement', 'code_prefix' => 'AGR', 'sort_order' => 3],
        ['name' => 'Manual', 'code_prefix' => 'MAN', 'sort_order' => 4],
        ['name' => 'Standard', 'code_prefix' => 'STD', 'sort_order' => 5],
        ['name' => 'Notice', 'code_prefix' => 'NOT', 'sort_order' => 6],
    ];

    private const CATEGORIES = [
        ['slug' => 'customer-policies', 'name' => 'Customer Policies', 'sort_order' => 1],
        ['slug' => 'vendor-policies', 'name' => 'Vendor Policies', 'sort_order' => 2],
        ['slug' => 'logistics-policies', 'name' => 'Logistics Policies', 'sort_order' => 3],
        ['slug' => 'employee-policies', 'name' => 'Employee Policies', 'sort_order' => 4],
        ['slug' => 'technology-policies', 'name' => 'Technology Policies', 'sort_order' => 5],
        ['slug' => 'financial-controls', 'name' => 'Financial Controls', 'sort_order' => 6],
        ['slug' => 'compliance-audit', 'name' => 'Compliance & Audit', 'sort_order' => 7],
        ['slug' => 'risk-management', 'name' => 'Risk Management', 'sort_order' => 8],
        ['slug' => 'templates-forms', 'name' => 'Templates & Forms', 'sort_order' => 9],
    ];

    public function run(): void
    {
        if (! Schema::hasTable('legal_documents')) {
            $this->command?->warn('legal_* tables are missing — run migrations first.');

            return;
        }

        foreach (self::TYPES as $type) {
            LegalDocumentType::firstOrCreate(['code_prefix' => $type['code_prefix']], $type + ['is_active' => true]);
        }
        foreach (self::CATEGORIES as $cat) {
            LegalCategory::firstOrCreate(['slug' => $cat['slug']], $cat + ['is_active' => true]);
        }

        $types = LegalDocumentType::pluck('id', 'code_prefix');
        $categories = LegalCategory::pluck('id', 'slug');

        $path = __DIR__ . '/../../../data/legal-blueprints.json';
        if (! is_file($path)) {
            $this->command?->warn('legal-blueprints.json not found — taxonomy seeded, documents skipped.');

            return;
        }
        $data = json_decode((string) file_get_contents($path), true) ?: [];

        $created = 0;
        foreach ($data['drafts'] ?? [] as $bp) {
            $created += $this->seedDocument($bp, $types, $categories, Status::DRAFT) ? 1 : 0;
        }
        foreach ($data['published'] ?? [] as $bp) {
            $created += $this->seedDocument($bp, $types, $categories, Status::PUBLISHED) ? 1 : 0;
        }

        $this->seedTemplates($types, $categories);
        $this->seedOperations();

        $this->command?->info("Legal library: {$created} document(s) created, "
            . LegalDocument::count() . ' total.');
    }

    /** Risk + compliance registers (Phase 5). Idempotent on title. */
    private function seedOperations(): void
    {
        if (! Schema::hasTable('legal_risk_items')) {
            return;
        }
        $path = __DIR__ . '/../../../data/legal-operations-seed.json';
        if (! is_file($path)) {
            return;
        }
        $data = json_decode((string) file_get_contents($path), true) ?: [];

        $risks = 0;
        foreach ($data['risks'] ?? [] as $r) {
            if (\Marvel\Database\Models\Legal\LegalRiskItem::withTrashed()->where('title', $r['title'])->exists()) {
                continue;
            }
            \Marvel\Database\Models\Legal\LegalRiskItem::create([
                'risk_code' => $this->nextOpsCode(\Marvel\Database\Models\Legal\LegalRiskItem::class, 'risk_code', 'RSK'),
                'title' => $r['title'],
                'category' => $r['category'],
                'description' => $r['description'] ?? null,
                'probability' => $r['probability'] ?? 3,
                'impact' => $r['impact'] ?? 3,
                'mitigation_plan' => $r['mitigation_plan'] ?? null,
                'contingency_plan' => $r['contingency_plan'] ?? null,
                'department' => $r['department'] ?? null,
                'status' => 'open',
                'review_date' => now()->addQuarter()->toDateString(),
            ]);
            $risks++;
        }

        $items = 0;
        foreach ($data['compliance'] ?? [] as $c) {
            if (\Marvel\Database\Models\Legal\LegalComplianceItem::withTrashed()->where('title', $c['title'])->exists()) {
                continue;
            }
            \Marvel\Database\Models\Legal\LegalComplianceItem::create([
                'item_code' => $this->nextOpsCode(\Marvel\Database\Models\Legal\LegalComplianceItem::class, 'item_code', 'CMP'),
                'title' => $c['title'],
                'compliance_area' => $c['compliance_area'],
                'requirement' => $c['requirement'] ?? null,
                'evidence' => $c['evidence'] ?? null,
                'applicable_department' => $c['applicable_department'] ?? null,
                'status' => $c['status'] ?? 'under_review',
                'review_frequency' => $c['review_frequency'] ?? 'annually',
                'next_review_date' => now()->addMonths(3)->toDateString(),
            ]);
            $items++;
        }

        if ($risks || $items) {
            $this->command?->info("Operations registers: {$risks} risk(s), {$items} compliance item(s) created.");
        }
    }

    private function nextOpsCode(string $model, string $column, string $prefix): string
    {
        $n = 1;
        do {
            $code = sprintf('%s-%03d', $prefix, $n);
            $n++;
        } while ($model::withTrashed()->where($column, $code)->exists());

        return $code;
    }

    /** @return bool true when a document was created (false = already present) */
    private function seedDocument(array $bp, $types, $categories, string $status): bool
    {
        $slug = $bp['slug'] ?? Str::slug($bp['title']);
        if (LegalDocument::withTrashed()->where('slug', $slug)->exists()) {
            return false;
        }

        $typeId = $types[$bp['type_prefix'] ?? 'POL'] ?? $types['POL'];
        $categoryId = $categories[$bp['category_slug'] ?? 'customer-policies'] ?? null;
        $isPublished = $status === Status::PUBLISHED;

        $document = LegalDocument::create([
            'title' => $bp['title'],
            'slug' => $slug,
            'document_code' => $this->nextCode($bp['type_prefix'] ?? 'POL', $bp['category_slug'] ?? ''),
            'type_id' => $typeId,
            'category_id' => $categoryId,
            'department' => $bp['department'] ?? null,
            'status' => $status,
            'visibility' => $bp['visibility'] ?? 'internal',
            // Every seeded legal blueprint carries the review flag: none of this
            // content has been through counsel.
            'legal_review_required' => ! $isPublished,
            'review_frequency' => 'annually',
            'next_review_date' => now()->addYear()->toDateString(),
            'published_at' => $isPublished ? now() : null,
        ]);

        $version = $document->versions()->create([
            'title' => $bp['title'],
            'version_major' => 1,
            'version_minor' => 0,
            'content_html' => $bp['html'],
            'change_summary' => $isPublished
                ? 'Imported from the live storefront copy.'
                : 'Initial blueprint draft — legal review required.',
        ]);

        if ($isPublished) {
            $version->forceFill(['status' => Status::PUBLISHED, 'published_at' => now()])->save();
            $document->forceFill(['current_version_id' => $version->id])->save();
        }

        return true;
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

    private function seedTemplates($types, $categories): void
    {
        $templates = [
            ['name' => 'Standard Policy', 'prefix' => 'POL', 'sections' => [
                'Document Control', 'Purpose', 'Scope', 'Definitions', 'Policy Statement',
                'Responsibilities', 'Procedures', 'Exceptions', 'Compliance', 'Violations',
                'Related Documents', 'Revision History', 'Approval',
            ]],
            ['name' => 'Standard Operating Procedure', 'prefix' => 'SOP', 'sections' => [
                'Purpose', 'Scope', 'Definitions', 'Roles and Responsibilities', 'Prerequisites',
                'Step-by-Step Procedure', 'SLA', 'Escalation Process', 'Quality Checks',
                'Records', 'Exceptions', 'Revision History',
            ]],
            ['name' => 'Agreement', 'prefix' => 'AGR', 'sections' => [
                'Parties', 'Definitions', 'Scope', 'Obligations', 'Payment Terms', 'Confidentiality',
                'Intellectual Property', 'Liability', 'Termination', 'Dispute Resolution',
                'Governing Law', 'Signatures',
            ]],
            ['name' => 'Policy Matrix', 'prefix' => 'STD', 'sections' => [
                'Purpose', 'Scope', 'Matrix', 'Notes', 'Review Cadence', 'Revision History',
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
}
