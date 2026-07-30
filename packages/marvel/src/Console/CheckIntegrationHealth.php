<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Marvel\Database\Models\IntegrationProvider;
use Marvel\Integrations\ConnectionTester;
use Marvel\Integrations\IntegrationLog;
use Throwable;

/**
 * Sweep every enabled provider and record its health.
 *
 * Without this, `health_status` only changes when someone opens the admin and clicks Test
 * Connection — so an expired key reads as "connected" until a customer hits the failure. The point
 * of the sweep is to find that out before they do.
 *
 * Only ENABLED providers are probed. A disabled provider is not broken, and several of these probes
 * cost money per call (Porter's get_quote is billed), so sweeping everything would turn an
 * observability feature into a recurring invoice.
 */
class CheckIntegrationHealth extends Command
{
    protected $signature = 'integrations:health
                            {--provider= : Check a single provider slug instead of all enabled ones}
                            {--prune-days=90 : Delete integration_logs entries older than this}';

    protected $description = 'Probe enabled third-party integrations and record their health';

    public function handle(ConnectionTester $tester): int
    {
        $query = IntegrationProvider::query()
            ->where('environment', (string) (config('integrations.environment') ?: 'production'));

        if ($slug = $this->option('provider')) {
            $query->where('provider_slug', $slug);
        } else {
            $query->where('enabled', true);
        }

        $providers = $query->orderBy('priority')->get();
        if ($providers->isEmpty()) {
            $this->info('No providers to check.');

            return self::SUCCESS;
        }

        $tester = $tester->asScheduledCheck();
        $failed = 0;

        foreach ($providers as $provider) {
            try {
                $result = $tester->test($provider->provider_slug);
                $ok = (bool) ($result['ok'] ?? false);
                $failed += $ok ? 0 : 1;

                $this->line(sprintf(
                    '%-22s %s  %s',
                    $provider->provider_slug,
                    $ok ? '<info>ok</info>' : '<comment>' . ($result['status'] ?? 'failed') . '</comment>',
                    $ok ? '' : (string) ($result['message'] ?? '')
                ));
            } catch (Throwable $e) {
                // One provider throwing must not abandon the rest of the sweep.
                $failed++;
                $this->error($provider->provider_slug . ': ' . $e->getMessage());
            }
        }

        $pruned = IntegrationLog::prune((int) $this->option('prune-days'));
        if ($pruned > 0) {
            $this->line("pruned {$pruned} old log entries");
        }

        // A non-zero exit would make the scheduler treat a genuinely-unhealthy provider as a broken
        // JOB and alert on the wrong thing. The health is recorded; that is the output that matters.
        $this->info(sprintf('Checked %d provider(s), %d unhealthy.', $providers->count(), $failed));

        return self::SUCCESS;
    }
}
