<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Database\Models\Legal\LegalAudit;
use Marvel\Database\Models\Legal\LegalCategory;
use Marvel\Database\Models\Legal\LegalDocument;
use Marvel\Database\Models\Legal\LegalDocumentType;
use Marvel\Database\Models\Legal\LegalSettings;
use Marvel\Database\Models\Legal\LegalTemplate;
use Marvel\Enums\LegalDocumentStatus as Status;

/** Overview dashboard, taxonomy (categories/types), templates, settings. */
class LegalGovernanceController extends CoreController
{
    public function overview()
    {
        $counts = LegalDocument::query()
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $reminderDays = LegalSettings::current()->review_reminder_days;

        return [
            'totals' => [
                'all' => $counts->sum(),
                'published' => (int) ($counts[Status::PUBLISHED] ?? 0),
                'draft' => (int) ($counts[Status::DRAFT] ?? 0),
                'in_review' => (int) ($counts[Status::IN_REVIEW] ?? 0),
                'pending_approval' => (int) ($counts[Status::PENDING_APPROVAL] ?? 0),
                'revision_required' => (int) ($counts[Status::REVISION_REQUIRED] ?? 0),
                'archived' => (int) ($counts[Status::ARCHIVED] ?? 0),
                'review_due' => LegalDocument::whereNotNull('next_review_date')
                    ->whereDate('next_review_date', '<=', now()->addDays($reminderDays))->count(),
                'overdue' => LegalDocument::whereNotNull('next_review_date')
                    ->whereDate('next_review_date', '<', now())->count(),
            ],
            'attention' => [
                'pending_approval' => LegalDocument::where('status', Status::PENDING_APPROVAL)
                    ->select('uuid', 'title', 'document_code', 'updated_at')->limit(8)->get(),
                'in_review' => LegalDocument::where('status', Status::IN_REVIEW)
                    ->select('uuid', 'title', 'document_code', 'updated_at')->limit(8)->get(),
                'review_due' => LegalDocument::whereNotNull('next_review_date')
                    ->whereDate('next_review_date', '<=', now()->addDays($reminderDays))
                    ->orderBy('next_review_date')
                    ->select('uuid', 'title', 'document_code', 'next_review_date')->limit(8)->get(),
            ],
            'recent_activity' => LegalAudit::orderByDesc('id')->limit(15)->get(),
        ];
    }

    /* ── categories ───────────────────────────────────────────────── */

    public function categories()
    {
        return LegalCategory::withCount('documents')
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    public function storeCategory(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:120',
            'parent_id' => 'nullable|integer|exists:legal_categories,id',
            'description' => 'nullable|string|max:1000',
            'sort_order' => 'nullable|integer',
        ]);
        $slug = \Illuminate\Support\Str::slug($request->input('name'));
        $n = 1;
        $candidate = $slug;
        while (LegalCategory::withTrashed()->where('slug', $candidate)->exists()) {
            $candidate = $slug . '-' . (++$n);
        }

        return LegalCategory::create($request->only(['name', 'parent_id', 'description', 'sort_order']) + ['slug' => $candidate]);
    }

    public function updateCategory(Request $request, string $uuid)
    {
        $category = LegalCategory::where('uuid', $uuid)->firstOrFail();
        $request->validate([
            'name' => 'sometimes|string|max:120',
            'parent_id' => 'nullable|integer|exists:legal_categories,id',
            'description' => 'nullable|string|max:1000',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);
        $category->update($request->only(['name', 'parent_id', 'description', 'sort_order', 'is_active']));

        return $category;
    }

    public function destroyCategory(string $uuid)
    {
        $category = LegalCategory::where('uuid', $uuid)->firstOrFail();
        if ($category->documents()->exists()) {
            throw new \Marvel\Exceptions\MarvelException('Category still has documents; move them first.');
        }
        $category->delete();

        return ['success' => true];
    }

    /* ── types / templates / settings ─────────────────────────────── */

    public function types()
    {
        return LegalDocumentType::orderBy('sort_order')->get();
    }

    public function templates()
    {
        return LegalTemplate::where('is_active', true)->orderBy('name')->get();
    }

    public function template(string $uuid)
    {
        return LegalTemplate::where('uuid', $uuid)->firstOrFail();
    }

    public function settings()
    {
        return LegalSettings::current();
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'numbering_format' => 'sometimes|string|max:100',
            'review_reminder_days' => 'sometimes|integer|min:1|max:365',
            'export_footer' => 'nullable|string|max:255',
            'legal_disclaimer' => 'nullable|string|max:2000',
        ]);
        $settings = LegalSettings::current();
        $settings->update($request->only(['numbering_format', 'review_reminder_days', 'export_footer', 'legal_disclaimer']));
        LegalAudit::record('settings_updated', null, null, $request->only(['numbering_format', 'review_reminder_days']));

        return $settings;
    }

    /** Grouped library view: categories with their documents (light columns). */
    public function library()
    {
        return LegalCategory::whereNull('parent_id')->where('is_active', true)
            ->orderBy('sort_order')
            ->with(['children' => fn ($q) => $q->where('is_active', true)])
            ->get()
            ->map(function (LegalCategory $cat) {
                $ids = array_merge([$cat->id], $cat->children->pluck('id')->all());

                return [
                    'uuid' => $cat->uuid,
                    'name' => $cat->name,
                    'documents' => LegalDocument::whereIn('category_id', $ids)
                        ->select('uuid', 'title', 'document_code', 'status', 'visibility', 'updated_at', 'category_id')
                        ->orderBy('title')->get(),
                ];
            });
    }
}
