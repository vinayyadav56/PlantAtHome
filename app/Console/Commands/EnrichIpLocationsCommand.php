<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Resolve country/city/ISP for recently seen log IPs, in batch, on a schedule —
 * NEVER in the request path. The logs drawer joins ip_locations for display.
 *
 * Provider: ipwho.is — HTTPS, no key, no composer dependency, ~10k lookups/mo
 * free. Chosen over ip-api.com after the latter proved unreachable from this
 * network during live testing. Per-IP GETs are fine at this cadence: only
 * DISTINCT previously-unseen IPs are looked up, a handful per sweep. Swapping
 * provider (GeoLite2, a paid API) means replacing resolveIps() and nothing
 * else — everything around it is provider-agnostic by design.
 *
 * Private/reserved ranges are recorded as 'local' without a lookup so proxied
 * container traffic (100.64/10 etc.) never wastes batch quota.
 */
class EnrichIpLocationsCommand extends Command
{
    protected $signature = 'logs:enrich-ips {--window=20 : Minutes of request_logs to scan for new IPs}';
    protected $description = 'Batch-resolve geo/ISP for recently logged IPs into ip_locations';

    private const MAX_PER_SWEEP = 50; // lookup budget per run — the rest catch the next sweep

    public function handle(): int
    {
        if (!Schema::hasTable('ip_locations') || !Schema::hasTable('request_logs')) {
            return self::SUCCESS;
        }

        $window = max(1, (int) $this->option('window'));
        $ips = DB::table('request_logs')
            ->where('created_at', '>=', now()->subMinutes($window))
            ->whereNotNull('ip')
            ->distinct()
            ->pluck('ip')
            ->reject(fn ($ip) => DB::table('ip_locations')->where('ip', $ip)->exists())
            ->values();

        if ($ips->isEmpty()) {
            return self::SUCCESS;
        }

        // Private/reserved/CGNAT addresses: record and skip — no quota spent.
        // PHP's NO_PRIV_RANGE does NOT cover 100.64.0.0/10 (CGNAT), which is
        // exactly what container platforms front requests with — live testing
        // showed those classified as public and burning lookups on failures.
        $isLocal = function (string $ip): bool {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return true;
            }
            if (str_starts_with($ip, '100.')) {
                $second = (int) explode('.', $ip)[1];
                return $second >= 64 && $second <= 127; // 100.64.0.0/10
            }
            return false;
        };
        [$local, $public] = $ips->partition($isLocal);
        foreach ($local as $ip) {
            DB::table('ip_locations')->insertOrIgnore([
                'ip' => $ip, 'country' => 'local', 'looked_up_at' => now(),
            ]);
        }

        $resolved = 0;
        foreach ($this->resolveIps($public->take(self::MAX_PER_SWEEP)->values()->all()) as $row) {
            DB::table('ip_locations')->updateOrInsert(['ip' => $row['ip']], $row + ['looked_up_at' => now()]);
            $resolved++;
        }

        $this->info("resolved {$resolved} of {$public->count()} public IPs ({$local->count()} local)");
        return self::SUCCESS;
    }

    /**
     * The ONLY provider-specific function. Swapping to GeoLite2/another API
     * means replacing this body and nothing else.
     *
     * @param string[] $ips
     * @return array<int, array{ip:string,country_code:?string,country:?string,region:?string,city:?string,isp:?string}>
     */
    private function resolveIps(array $ips): array
    {
        $out = [];
        foreach ($ips as $ip) {
            try {
                $r = Http::timeout(6)
                    ->get('https://ipwho.is/' . $ip, ['fields' => 'ip,success,country_code,country,region,city,connection'])
                    ->json();
                if (empty($r['success'])) {
                    continue; // unresolved IPs retry on the next sweep
                }
                $out[] = [
                    'ip' => (string) ($r['ip'] ?? $ip),
                    'country_code' => isset($r['country_code']) ? mb_substr($r['country_code'], 0, 2) : null,
                    'country' => isset($r['country']) ? mb_substr($r['country'], 0, 64) : null,
                    'region' => isset($r['region']) ? mb_substr($r['region'], 0, 64) : null,
                    'city' => isset($r['city']) ? mb_substr($r['city'], 0, 64) : null,
                    'isp' => isset($r['connection']['isp']) ? mb_substr($r['connection']['isp'], 0, 191) : null,
                ];
            } catch (\Throwable) {
                // this IP retries next sweep
            }
        }
        return $out;
    }
}
