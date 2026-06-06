<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Settings;

/**
 * Force the Pickbazar license "trust" flag ON so logins never get blocked by
 * the upstream redq.io license re-check (which was returning "The credentials
 * was wrong" on production). Sets settings.options.app_settings.trust = true
 * and writes the encrypted shop.config.json license file.
 *
 *   php artisan plantathome:ensure-trust
 *
 * Idempotent and safe to re-run on every deploy. The real guarantee is the
 * MARVEL_FORCE_TRUST env override in UserRepository::checkIfApplicationIsValid();
 * this command keeps the persisted DB flag consistent as a belt-and-suspenders.
 */
class EnsureTrustCommand extends Command
{
    protected $signature = 'plantathome:ensure-trust';

    protected $description = 'Force the license trust flag ON so logins are never blocked by the remote license check.';

    public function handle(): int
    {
        // verify() sets trust=true + writes storage/app/shop/shop.config.json (no external HTTP call).
        $verification = new MarvelVerification();
        $verification->verify('plantathome-trust');

        $settings = Settings::getData();
        if (!$settings) {
            $this->warn('No settings record found — trust DB flag not written (env MARVEL_FORCE_TRUST still applies).');
            return self::SUCCESS;
        }

        $options = $settings->options ?? [];
        $options['app_settings'] = [
            'trust'              => true,
            'last_checking_time' => now()->toISOString(),
        ];
        $settings->update(['options' => $options]);

        Cache::flush();

        $this->info('License trust flag set to true (settings.app_settings.trust + shop.config.json).');

        return self::SUCCESS;
    }
}
