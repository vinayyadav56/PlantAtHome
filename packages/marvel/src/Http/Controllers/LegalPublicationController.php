<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Database\Models\Legal\LegalAudit;
use Marvel\Database\Models\Legal\LegalDocument;
use Marvel\Database\Models\Legal\LegalSettings;
use Marvel\Enums\LegalDocumentStatus as Status;

/**
 * Phase 6 — publication and export.
 *
 * The public endpoints are deliberately narrow: they serve ONLY the live
 * version of a PUBLISHED, visibility=public document. Drafts, internal
 * documents and superseded versions are unreachable here regardless of what
 * id or slug is supplied.
 */
class LegalPublicationController extends CoreController
{
    /** Base query for anything a logged-out visitor may see. */
    private function publicQuery()
    {
        return LegalDocument::query()
            ->where('status', Status::PUBLISHED)
            ->where('visibility', 'public')
            ->whereNotNull('current_version_id');
    }

    /** GET /legal/public/policies — index for the storefront. */
    public function index()
    {
        return $this->publicQuery()
            ->with('category:id,name,slug')
            ->orderBy('title')
            ->get()
            ->map(fn (LegalDocument $d) => [
                'slug' => $d->slug,
                'title' => $d->title,
                'category' => $d->category?->name,
                'effective_date' => $d->effective_date?->toDateString(),
                'updated_at' => $d->published_at?->toDateString(),
            ]);
    }

    /** GET /legal/public/policies/{slug} — one policy, live version only. */
    public function show(string $slug)
    {
        $document = $this->publicQuery()->where('slug', $slug)->with('currentVersion')->first();
        if (! $document) {
            return response()->json(['message' => 'Policy not found.'], 404);
        }
        $version = $document->currentVersion;

        return [
            'slug' => $document->slug,
            'title' => $document->title,
            'document_code' => $document->document_code,
            'version' => $version->versionLabel(),
            'effective_date' => $document->effective_date?->toDateString(),
            'updated_at' => ($version->published_at ?? $document->published_at)?->toDateString(),
            'content_html' => $version->content_html,
            'toc' => $this->tableOfContents($version->content_html),
        ];
    }

    /** Headings → a clickable table of contents (ids injected client-side). */
    private function tableOfContents(?string $html): array
    {
        if (! $html) {
            return [];
        }
        preg_match_all('/<h([23])[^>]*>(.*?)<\/h\1>/i', $html, $matches, PREG_SET_ORDER);

        return array_values(array_filter(array_map(function ($m) {
            $text = trim(html_entity_decode(strip_tags($m[2])));

            return $text === '' ? null : [
                'level' => (int) $m[1],
                'text' => $text,
                'id' => \Illuminate\Support\Str::slug($text),
            ];
        }, $matches)));
    }

    /* ── export (authenticated, permission-gated) ─────────────────── */

    /** GET /legal/documents/{uuid}/export?format=pdf|html */
    public function export(Request $request, string $uuid)
    {
        $document = LegalDocument::where('uuid', $uuid)->with(['currentVersion', 'type', 'category'])->firstOrFail();
        $version = $document->currentVersion ?? $document->versions()->first();
        if (! $version) {
            return response()->json(['message' => 'This document has no content to export.'], 422);
        }

        $format = $request->query('format', 'pdf');
        $html = $this->renderForExport($document, $version);
        LegalAudit::record('exported', $document->id, $version->id, ['format' => $format]);

        $filename = $document->document_code . '-v' . $version->versionLabel();

        if ($format === 'html') {
            return response($html, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}.html\"",
            ]);
        }

        // .doc: Word opens styled HTML natively — a real .docx would need a new
        // dependency for markup that Word renders from HTML anyway.
        if ($format === 'doc') {
            return response($html, 200, [
                'Content-Type' => 'application/msword',
                'Content-Disposition' => "attachment; filename=\"{$filename}.doc\"",
            ]);
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4');

        return $pdf->download($filename . '.pdf');
    }

    private function renderForExport(LegalDocument $document, $version): string
    {
        $settings = LegalSettings::current();
        $footer = e($settings->export_footer ?: 'PlantAtHome | Silvestrix Green LLP — Controlled Document');
        $disclaimer = $settings->legal_disclaimer;

        $classification = match ($document->visibility) {
            'public' => 'PUBLIC POLICY',
            'restricted' => 'CONFIDENTIAL',
            default => 'INTERNAL USE ONLY',
        };
        $reviewBanner = $document->status !== Status::PUBLISHED
            ? '<div class="banner">DRAFT — NOT APPROVED FOR CIRCULATION</div>'
            : '';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#1a1a1a;line-height:1.55}'
            . 'h1{font-size:20px;margin:0 0 4px}h2{font-size:14px;margin:18px 0 6px;border-bottom:1px solid #ddd;padding-bottom:3px}'
            . 'h3{font-size:12px;margin:12px 0 4px}p{margin:0 0 8px}ul,ol{margin:0 0 8px 18px}'
            . 'table{width:100%;border-collapse:collapse;margin:10px 0}th,td{border:1px solid #bbb;padding:5px 7px;text-align:left;font-size:10px}'
            . 'th{background:#f2f2f2}blockquote{margin:10px 0;padding:8px 12px;background:#fff8e1;border-left:3px solid #f0b429}'
            . '.meta{border:1px solid #ddd;padding:8px 10px;margin:0 0 16px;font-size:10px;background:#fafafa}'
            . '.meta td{border:none;padding:2px 6px 2px 0;font-size:10px}'
            . '.banner{background:#fdecea;border:1px solid #f5c6cb;color:#a4262c;padding:6px 10px;font-weight:bold;margin:0 0 12px;font-size:11px}'
            . '.classification{float:right;font-size:9px;letter-spacing:.08em;color:#666}'
            . '.footer{margin-top:22px;padding-top:8px;border-top:1px solid #ddd;font-size:9px;color:#666}'
            . '</style></head><body>'
            . '<div class="classification">' . $classification . '</div>'
            . '<h1>' . e($document->title) . '</h1>'
            . $reviewBanner
            . '<table class="meta"><tr><td><strong>Document code</strong></td><td>' . e($document->document_code) . '</td>'
            . '<td><strong>Version</strong></td><td>' . e($version->versionLabel()) . '</td></tr>'
            . '<tr><td><strong>Status</strong></td><td>' . e(ucfirst(str_replace('_', ' ', $document->status))) . '</td>'
            . '<td><strong>Effective</strong></td><td>' . e($document->effective_date?->toDateString() ?? '—') . '</td></tr>'
            . '<tr><td><strong>Category</strong></td><td>' . e($document->category?->name ?? '—') . '</td>'
            . '<td><strong>Next review</strong></td><td>' . e($document->next_review_date?->toDateString() ?? '—') . '</td></tr></table>'
            . ($version->content_html ?? '')
            . ($disclaimer ? '<div class="footer">' . e($disclaimer) . '</div>' : '')
            . '<div class="footer">' . $footer . ' · Version ' . e($version->versionLabel())
            . ' · Generated ' . now()->format('d M Y') . '</div>'
            . '</body></html>';
    }
}
