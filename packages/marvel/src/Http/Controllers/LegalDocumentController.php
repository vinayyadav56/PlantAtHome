<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Database\Models\Legal\LegalDocument;
use Marvel\Database\Models\Legal\LegalDocumentComment;
use Marvel\Database\Models\Legal\LegalDocumentVersion;
use Marvel\Enums\LegalDocumentStatus as Status;
use Marvel\Exceptions\MarvelException;
use Marvel\Services\Legal\LegalDocumentService;

class LegalDocumentController extends CoreController
{
    public function __construct(private LegalDocumentService $service)
    {
    }

    public function index(Request $request)
    {
        $query = LegalDocument::query()
            ->with(['type', 'category', 'owner:id,name', 'currentVersion:id,version_major,version_minor'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('category_id'), fn ($q, $c) => $q->where('category_id', $c))
            ->when($request->query('type_id'), fn ($q, $t) => $q->where('type_id', $t))
            ->when($request->query('owner_id'), fn ($q, $o) => $q->where('owner_id', $o))
            ->when($request->query('visibility'), fn ($q, $v) => $q->where('visibility', $v))
            ->when($request->boolean('review_due'), fn ($q) => $q->whereNotNull('next_review_date')->whereDate('next_review_date', '<=', now()))
            ->when($request->query('search'), function ($q, $term) {
                $q->where(function ($qq) use ($term) {
                    $qq->where('title', 'like', "%{$term}%")
                        ->orWhere('document_code', 'like', "%{$term}%")
                        ->orWhere('slug', 'like', "%{$term}%");
                });
            });

        $orderBy = in_array($request->query('orderBy'), ['title', 'document_code', 'status', 'updated_at', 'next_review_date'], true)
            ? $request->query('orderBy') : 'updated_at';
        $query->orderBy($orderBy, $request->query('sortedBy') === 'asc' ? 'asc' : 'desc');

        return $query->paginate(min((int) $request->query('limit', 20), 100));
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'type_id' => 'required|integer|exists:legal_document_types,id',
            'category_id' => 'nullable|integer|exists:legal_categories,id',
            'visibility' => 'nullable|in:' . implode(',', LegalDocument::VISIBILITIES),
            'review_frequency' => 'nullable|in:' . implode(',', LegalDocument::REVIEW_FREQUENCIES),
            'review_interval_days' => 'nullable|integer|min:1|max:1825',
            'effective_date' => 'nullable|date',
            'department' => 'nullable|string|max:64',
            'owner_id' => 'nullable|integer|exists:users,id',
            'tags' => 'nullable|array',
            'content_json' => 'nullable|string',
            'content_html' => 'nullable|string',
            'legal_review_required' => 'nullable|boolean',
        ]);

        return $this->service->create($request->all(), $request->user());
    }

    public function show(string $uuid)
    {
        return LegalDocument::where('uuid', $uuid)
            ->with(['type', 'category', 'owner:id,name', 'versions.author:id,name', 'currentVersion'])
            ->firstOrFail();
    }

    public function update(Request $request, string $uuid)
    {
        $document = LegalDocument::where('uuid', $uuid)->firstOrFail();
        $request->validate([
            'category_id' => 'nullable|integer|exists:legal_categories,id',
            'visibility' => 'nullable|in:' . implode(',', LegalDocument::VISIBILITIES),
            'review_frequency' => 'nullable|in:' . implode(',', LegalDocument::REVIEW_FREQUENCIES),
            'review_interval_days' => 'nullable|integer|min:1|max:1825',
            'effective_date' => 'nullable|date',
            'next_review_date' => 'nullable|date',
            'department' => 'nullable|string|max:64',
            'owner_id' => 'nullable|integer|exists:users,id',
            'tags' => 'nullable|array',
            'legal_review_required' => 'nullable|boolean',
        ]);
        $document->fill($request->only([
            'category_id', 'visibility', 'review_frequency', 'review_interval_days',
            'effective_date', 'next_review_date', 'department', 'owner_id', 'tags', 'legal_review_required',
        ]));
        $document->updated_by = $request->user()->id;
        $document->save();

        return $document->fresh(['type', 'category', 'owner:id,name']);
    }

    public function destroy(Request $request, string $uuid)
    {
        $document = LegalDocument::where('uuid', $uuid)->firstOrFail();
        if ($document->status === Status::PUBLISHED) {
            throw new MarvelException('A published document must be archived before deletion.');
        }
        $document->delete();

        return ['success' => true];
    }

    /* ── versions ─────────────────────────────────────────────────── */

    public function versions(string $uuid)
    {
        $document = LegalDocument::where('uuid', $uuid)->firstOrFail();

        return $document->versions()->with('author:id,name')->get();
    }

    public function createVersion(Request $request, string $uuid)
    {
        $document = LegalDocument::where('uuid', $uuid)->firstOrFail();

        return $this->service->newVersion($document, $request->user(), $request->boolean('major'));
    }

    public function updateVersion(Request $request, string $versionUuid)
    {
        $version = LegalDocumentVersion::where('uuid', $versionUuid)->firstOrFail();
        $request->validate([
            'title' => 'nullable|string|max:255',
            'content_json' => 'nullable|string',
            'content_html' => 'nullable|string',
            'change_summary' => 'nullable|string|max:2000',
            'attachments' => 'nullable|array',
        ]);

        return $this->service->updateVersion($version, $request->all(), $request->user());
    }

    public function restoreVersion(Request $request, string $versionUuid)
    {
        $version = LegalDocumentVersion::where('uuid', $versionUuid)->firstOrFail();

        return $this->service->restoreAsDraft($version, $request->user());
    }

    /* ── workflow transitions ─────────────────────────────────────── */

    public function transition(Request $request, string $versionUuid, string $transition)
    {
        $version = LegalDocumentVersion::where('uuid', $versionUuid)->firstOrFail();

        return $this->service->applyTransition($transition, $version, $request->user(), $request->input('comment'));
    }

    /* ── comments ─────────────────────────────────────────────────── */

    public function comments(string $versionUuid)
    {
        $version = LegalDocumentVersion::where('uuid', $versionUuid)->firstOrFail();

        return $version->comments()->with(['user:id,name', 'replies.user:id,name'])
            ->whereNull('parent_id')->orderByDesc('id')->get();
    }

    public function addComment(Request $request, string $versionUuid)
    {
        $version = LegalDocumentVersion::where('uuid', $versionUuid)->firstOrFail();
        $request->validate([
            'body' => 'required|string|max:5000',
            'parent_id' => 'nullable|integer|exists:legal_document_comments,id',
            'selection' => 'nullable|array',
        ]);

        $comment = $version->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $request->input('body'),
            'parent_id' => $request->input('parent_id'),
            'selection' => $request->input('selection'),
        ]);
        \Marvel\Database\Models\Legal\LegalAudit::record('comment_created', $version->document_id, $version->id, ['comment_id' => $comment->id]);

        return $comment->load('user:id,name');
    }

    public function resolveComment(Request $request, int $commentId)
    {
        $comment = LegalDocumentComment::findOrFail($commentId);
        $comment->update([
            'status' => 'resolved',
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return $comment->fresh('user:id,name');
    }

    /* ── audits ───────────────────────────────────────────────────── */

    public function audits(string $uuid)
    {
        $document = LegalDocument::where('uuid', $uuid)->firstOrFail();

        return \Marvel\Database\Models\Legal\LegalAudit::where('document_id', $document->id)
            ->orderByDesc('id')->limit(100)->get();
    }
}
