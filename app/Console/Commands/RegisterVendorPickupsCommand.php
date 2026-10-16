<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Marvel\Services\Courier\CourierService;

/**
 * Registers at Shiprocket the pickup each partner nursery got from its own registration, once the
 * nursery is approved — so nobody registers nursery addresses separately. Touches nothing while
 * Shiprocket is off. See CourierService::registerVendorPickups.
 */
class RegisterVendorPickupsCommand extends Command
{
    protected $signature = 'courier:register-vendor-pickups';

    protected $description = 'Register approved vendors\' own pickup addresses at Shiprocket (waits while Shiprocket is off).';

    public function handle(CourierService $courier): int
    {
        $this->line(json_encode($courier->registerVendorPickups()));

        return self::SUCCESS;
    }
}
