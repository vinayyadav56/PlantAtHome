<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Marvel\Enums\Permission;
use Marvel\Services\TestDataCleanup\BaselineSeedService;
use Marvel\Services\TestDataCleanup\CleanupRunner;
use Marvel\Services\TestDataCleanup\MiscCleaner;
use Marvel\Services\TestDataCleanup\OrdersCleaner;
use Marvel\Services\TestDataCleanup\PricesCleaner;
use Marvel\Services\TestDataCleanup\UsersCleaner;
use Marvel\Services\TestDataCleanup\VendorInventoryCleaner;
use Marvel\Services\TestDataCleanup\VendorsCleaner;

/**
 * Test Data Cleanup. The controller only authorises, validates and delegates — every deletion
 * rule and safety guarantee lives in the services, so no endpoint can bypass them.
 */
class TestDataCleanupController extends Controller
{
    private const MODULES = [
        'orders'           => OrdersCleaner::class,
        'users'            => UsersCleaner::class,
        'vendors'          => VendorsCleaner::class,
        'vendor_inventory' => VendorInventoryCleaner::class,
        'prices'           => PricesCleaner::class,
        'misc'             => MiscCleaner::class,
    ];

    private function assertAllowed(Request $request): void
    {
        $user = $request->user();
        if (!$user || !($user->hasPermissionTo(Permission::SUPER_ADMIN) || $user->can('settings.testdata.cleanup'))) {
            abort(403, 'Test data cleanup requires an administrator.');
        }
    }

    private function cleaner(string $module)
    {
        $class = self::MODULES[$module] ?? abort(404, "Unknown cleanup module '{$module}'.");
        return new $class();
    }

    /** GET test-data/modules — module cards + live counts + environment posture. */
    public function index(Request $request)
    {
        $this->assertAllowed($request);
        $runner = new CleanupRunner();

        $modules = [];
        foreach (array_keys(self::MODULES) as $key) {
            $c = $this->cleaner($key);
            $modules[] = [
                'key' => $c->key(), 'label' => $c->label(),
                'description' => $c->description(), 'stats' => $c->stats(),
            ];
        }

        return response()->json([
            'environment'      => $runner->environment(),
            'is_production'    => $runner->isProduction(),
            'confirm_phrase'   => $runner->isProduction() ? CleanupRunner::CONFIRM_PHRASE : null,
            'launch_guard'     => $runner->launchGuard(),
            'protected_tables' => CleanupRunner::PROTECTED_TABLES,
            'modules'          => $modules,
            'seed_groups'      => collect(BaselineSeedService::GROUPS)
                ->map(fn ($g, $k) => ['key' => $k, 'label' => $g[0]])->values(),
        ]);
    }

    /** POST test-data/preview — exact impact, deletes nothing. */
    public function preview(Request $request)
    {
        $this->assertAllowed($request);
        $data = $request->validate([
            'module' => 'required|string',
            'scope'  => 'nullable|array',
        ]);
        return response()->json(
            (new CleanupRunner())->preview($this->cleaner($data['module']), $data['scope'] ?? [])
        );
    }

    /** POST test-data/execute — snapshot, delete, audit. */
    public function execute(Request $request)
    {
        $this->assertAllowed($request);
        $data = $request->validate([
            'module'                => 'required|string',
            'scope'                 => 'nullable|array',
            'confirm_phrase'        => 'nullable|string',
            'acknowledge'           => 'nullable|boolean',
            'override_launch_guard' => 'nullable|boolean',
            'backup_reference'      => 'nullable|string|max:191',
        ]);

        try {
            $result = (new CleanupRunner())->execute(
                $this->cleaner($data['module']),
                $data['scope'] ?? [],
                $data,
                $request->user(),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json($result);
    }

    /** GET test-data/runs — the audit trail. */
    public function runs(Request $request)
    {
        $this->assertAllowed($request);
        $rows = DB::table('test_data_cleanup_runs')
            ->select('id', 'module', 'mode', 'environment', 'table_counts', 'total_rows',
                     'actor_name', 'status', 'error', 'started_at', 'finished_at', 'backup_reference')
            ->orderByDesc('id')->limit(50)->get()
            ->map(function ($r) {
                $r->table_counts = json_decode($r->table_counts ?? '{}', true);
                return $r;
            });
        return response()->json(['data' => $rows]);
    }

    /** POST test-data/runs/{id}/restore — put a run's rows back from its snapshot. */
    public function restore(Request $request, int $id)
    {
        $this->assertAllowed($request);
        try {
            return response()->json((new CleanupRunner())->restore($id));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** POST test-data/seed-baseline — idempotent restore of reference data. */
    public function seedBaseline(Request $request)
    {
        $this->assertAllowed($request);
        $groups = $request->validate([
            'groups'   => 'required|array|min:1',
            'groups.*' => 'string',
        ])['groups'];
        return response()->json(['results' => (new BaselineSeedService())->run($groups)]);
    }
}
