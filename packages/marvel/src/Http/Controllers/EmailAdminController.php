<?php

namespace Marvel\Http\Controllers;

use App\Modules\Marketing\Domain\VariableMapper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Services\EmailService;

/**
 * Settings → Notifications admin API: template CRUD (+ versions/restore/
 * duplicate/preview/test-send), the event registry, and the delivery log
 * (summary/filters/retry/export). SUPER_ADMIN-gated at the route group.
 */
class EmailAdminController extends CoreController
{
    public function __construct(protected EmailService $email)
    {
    }

    // ── Templates ───────────────────────────────────────────────────────────

    public function templates(Request $request)
    {
        $q = DB::table('email_templates')->orderBy('category')->orderBy('name');
        if ($request->filled('category')) {
            $q->where('category', $request->get('category'));
        }
        if ($request->filled('q')) {
            $term = '%' . $request->get('q') . '%';
            $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('slug', 'like', $term)->orWhere('subject', 'like', $term));
        }
        return ['data' => $q->get()];
    }

    public function storeTemplate(Request $request)
    {
        $data = $this->validateTemplate($request);
        $data['slug'] = $data['slug'] ?: Str::slug($data['name']);
        if (DB::table('email_templates')->where('slug', $data['slug'])->exists()) {
            return response()->json(['message' => 'A template with this slug already exists.'], 422);
        }
        $id = DB::table('email_templates')->insertGetId($data + [
            'version' => 1,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->snapshot($id, 1, $data, $request->user()?->id);
        return ['data' => DB::table('email_templates')->where('id', $id)->first()];
    }

    public function updateTemplate(Request $request, $id)
    {
        $row = DB::table('email_templates')->where('id', (int) $id)->first();
        if ($row === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $data = $this->validateTemplate($request, (int) $id);
        $version = (int) $row->version + 1;
        DB::table('email_templates')->where('id', $row->id)->update($data + [
            'version' => $version,
            'updated_by' => $request->user()?->id,
            'updated_at' => now(),
        ]);
        $this->snapshot((int) $row->id, $version, $data, $request->user()?->id);
        Cache::forget("email_template:{$row->id}");
        return ['data' => DB::table('email_templates')->where('id', $row->id)->first()];
    }

    public function deleteTemplate($id)
    {
        DB::table('email_events')->where('template_id', (int) $id)->update(['template_id' => null, 'updated_at' => now()]);
        DB::table('email_template_versions')->where('template_id', (int) $id)->delete();
        DB::table('email_templates')->where('id', (int) $id)->delete();
        Cache::forget("email_template:{$id}");
        return ['success' => true];
    }

    public function duplicateTemplate(Request $request, $id)
    {
        $row = (array) DB::table('email_templates')->where('id', (int) $id)->first();
        if (empty($row)) {
            return response()->json(['message' => 'Not found'], 404);
        }
        unset($row['id']);
        $row['slug'] = $row['slug'] . '-copy-' . Str::lower(Str::random(4));
        $row['name'] = $row['name'] . ' (copy)';
        $row['status'] = 'draft';
        $row['version'] = 1;
        $row['created_at'] = now();
        $row['updated_at'] = now();
        $newId = DB::table('email_templates')->insertGetId($row);
        return ['data' => DB::table('email_templates')->where('id', $newId)->first()];
    }

    public function versions($id)
    {
        return ['data' => DB::table('email_template_versions')->where('template_id', (int) $id)
            ->orderByDesc('version')->limit(50)->get()];
    }

    public function restoreVersion(Request $request, $id, $version)
    {
        $snap = DB::table('email_template_versions')->where('template_id', (int) $id)->where('version', (int) $version)->first();
        $row = DB::table('email_templates')->where('id', (int) $id)->first();
        if ($snap === null || $row === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $next = (int) $row->version + 1;
        $data = [
            'subject' => $snap->subject, 'html_body' => $snap->html_body,
            'text_body' => $snap->text_body, 'variables' => $snap->variables,
        ];
        DB::table('email_templates')->where('id', $row->id)->update($data + [
            'version' => $next, 'updated_by' => $request->user()?->id, 'updated_at' => now(),
        ]);
        $this->snapshot((int) $row->id, $next, $data, $request->user()?->id);
        Cache::forget("email_template:{$row->id}");
        return ['data' => DB::table('email_templates')->where('id', $row->id)->first()];
    }

    public function preview(Request $request, $id)
    {
        $row = DB::table('email_templates')->where('id', (int) $id)->first();
        if ($row === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $sample = (array) $request->get('sample_data', []);
        $rendered = $this->email->renderPreview($row->slug, $sample);
        return ['data' => $rendered];
    }

    public function testSend(Request $request, $id)
    {
        $data = $request->validate(['to' => 'required|email', 'sample_data' => 'nullable|array']);
        $row = DB::table('email_templates')->where('id', (int) $id)->first();
        if ($row === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return ['data' => $this->email->sendTest($row->slug, $data['to'], (array) ($data['sample_data'] ?? []))];
    }

    // ── Events ──────────────────────────────────────────────────────────────

    public function events()
    {
        $events = DB::table('email_events as e')
            ->leftJoin('email_templates as t', 't.id', '=', 'e.template_id')
            ->select('e.*', 't.name as template_name', 't.slug as template_slug')
            ->orderBy('e.module')->orderBy('e.name')->get();
        return ['data' => $events];
    }

    public function updateEvent(Request $request, $id)
    {
        $data = $request->validate([
            'template_id' => 'nullable|integer',
            'enabled' => 'sometimes|boolean',
            'queue' => 'sometimes|string|max:32',
            'tries' => 'sometimes|integer|min:1|max:5',
        ]);
        if (array_key_exists('template_id', $data) && $data['template_id'] !== null
            && !DB::table('email_templates')->where('id', $data['template_id'])->exists()) {
            return response()->json(['message' => 'Template not found'], 422);
        }
        DB::table('email_events')->where('id', (int) $id)->update($data + ['updated_at' => now()]);
        $row = DB::table('email_events')->where('id', (int) $id)->first();
        if ($row) {
            Cache::forget("email_event:{$row->event_key}");
        }
        return ['data' => $row];
    }

    // ── Logs ────────────────────────────────────────────────────────────────

    public function logs(Request $request)
    {
        $q = DB::table('email_logs')->orderByDesc('id');
        if ($request->filled('status')) {
            $q->where('status', $request->get('status'));
        }
        if ($request->filled('event')) {
            $q->where('event_key', $request->get('event'));
        }
        if ($request->filled('recipient')) {
            $q->where('recipient', 'like', '%' . $request->get('recipient') . '%');
        }
        if ($request->filled('from')) {
            $q->where('created_at', '>=', $request->get('from'));
        }
        if ($request->filled('to')) {
            $q->where('created_at', '<=', $request->get('to') . ' 23:59:59');
        }
        return $q->paginate(min(100, max(10, (int) ($request->get('limit') ?: 25))));
    }

    public function logsSummary()
    {
        return Cache::remember('email_logs_summary', 30, function () {
            $today = now()->startOfDay();
            $byStatus = DB::table('email_logs')->where('created_at', '>=', $today)
                ->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
            return ['data' => [
                'today' => [
                    'total' => (int) $byStatus->sum(),
                    'sent' => (int) ($byStatus['sent'] ?? 0) + (int) ($byStatus['delivered'] ?? 0) + (int) ($byStatus['opened'] ?? 0) + (int) ($byStatus['clicked'] ?? 0),
                    'delivered' => (int) ($byStatus['delivered'] ?? 0) + (int) ($byStatus['opened'] ?? 0) + (int) ($byStatus['clicked'] ?? 0),
                    'opened' => (int) ($byStatus['opened'] ?? 0) + (int) ($byStatus['clicked'] ?? 0),
                    'failed' => (int) ($byStatus['failed'] ?? 0) + (int) ($byStatus['bounced'] ?? 0) + (int) ($byStatus['spam'] ?? 0),
                    'queued' => (int) ($byStatus['queued'] ?? 0),
                    'skipped' => (int) ($byStatus['skipped'] ?? 0),
                ],
                'top_templates_7d' => DB::table('email_logs')->where('created_at', '>=', now()->subDays(7))
                    ->whereNotNull('template_slug')
                    ->selectRaw('template_slug, COUNT(*) c')->groupBy('template_slug')->orderByDesc('c')->limit(5)->get(),
                'top_failures_7d' => DB::table('email_logs')->where('created_at', '>=', now()->subDays(7))
                    ->whereIn('status', ['failed', 'bounced', 'spam'])
                    ->selectRaw('event_key, COUNT(*) c')->groupBy('event_key')->orderByDesc('c')->limit(5)->get(),
            ]];
        });
    }

    public function retryLog(Request $request)
    {
        $ids = (array) $request->validate(['ids' => 'required|array|max:100'])['ids'];
        $ok = 0;
        foreach ($ids as $id) {
            if ($this->email->retry((int) $id)) {
                $ok++;
            }
        }
        return ['data' => ['retried' => $ok, 'requested' => count($ids)]];
    }

    public function exportLogs(Request $request)
    {
        $rows = DB::table('email_logs')->orderByDesc('id')->limit(10000)->get();
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'created_at', 'event_key', 'template', 'recipient', 'subject', 'status', 'provider', 'attempts', 'error']);
            foreach ($rows as $r) {
                fputcsv($out, [$r->id, $r->created_at, $r->event_key, $r->template_slug, $r->recipient, $r->subject, $r->status, $r->provider, $r->attempts, $r->error]);
            }
            fclose($out);
        }, 'email-logs-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv']);
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function validateTemplate(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'slug' => 'nullable|string|max:96',
            'category' => 'required|string|max:32',
            'subject' => 'required|string|max:500',
            'preview_text' => 'nullable|string|max:191',
            'html_body' => 'required|string',
            'text_body' => 'nullable|string',
            'status' => 'sometimes|in:active,draft',
        ]);
        // Auto-detect declared variables from the content; the UI shows them.
        $data['variables'] = json_encode(VariableMapper::extract(
            (string) $data['subject'], (string) $data['html_body'], (string) ($data['text_body'] ?? '')
        ));
        return $data;
    }

    private function snapshot(int $templateId, int $version, array $data, ?int $userId): void
    {
        DB::table('email_template_versions')->insert([
            'template_id' => $templateId,
            'version' => $version,
            'subject' => (string) ($data['subject'] ?? ''),
            'html_body' => (string) ($data['html_body'] ?? ''),
            'text_body' => $data['text_body'] ?? null,
            'variables' => $data['variables'] ?? null,
            'saved_by' => $userId,
            'created_at' => now(),
        ]);
    }
}
