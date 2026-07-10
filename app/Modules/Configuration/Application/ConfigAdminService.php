<?php

namespace App\Modules\Configuration\Application;

use App\Modules\Catalog\Infrastructure\Models\Product as CatalogProduct;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant as CatalogVariant;
use App\Modules\Configuration\Domain\Events\ConfigurationChanged;
use App\Modules\Configuration\Domain\SelectType;
use App\Modules\Configuration\Infrastructure\Models\ConfigGroup;
use App\Modules\Configuration\Infrastructure\Models\ConfigOption;
use App\Modules\Configuration\Infrastructure\Models\NurseryEnablement;
use App\Modules\Configuration\Infrastructure\Models\OptionCompatibility;
use App\Modules\Configuration\Infrastructure\Models\ProductAssignment;
use App\Shared\Application\DomainActionException;
use App\Shared\Events\EventPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Configuration write use-cases (admin-managed groups/options/assignments/
 * compatibility, and per-nursery enablement). Every write emits
 * ConfigurationChanged through the outbox and bumps the resolution cache version
 * so rendered configurations recompute.
 */
class ConfigAdminService
{
    public function __construct(
        private readonly EventPublisher $events,
        private readonly ConfigurationService $config,
    ) {
    }

    public function createGroup(array $data, ?string $actorUuid = null): ConfigGroup
    {
        return $this->write(function () use ($data, $actorUuid) {
            $group = ConfigGroup::create([
                'code'        => $data['code'],
                'name'        => $data['name'],
                'select_type' => $data['select_type'] ?? SelectType::SINGLE,
                'is_required' => (bool) ($data['is_required'] ?? false),
                'sort'        => $data['sort'] ?? 0,
                'created_by'  => $actorUuid,
                'updated_by'  => $actorUuid,
            ]);

            return [$group, new ConfigurationChanged('group', $group->uuid)];
        });
    }

    public function createOption(ConfigGroup $group, array $data, ?string $actorUuid = null): ConfigOption
    {
        return $this->write(function () use ($group, $data, $actorUuid) {
            $option = $group->options()->create([
                'sku'          => $data['sku'] ?? $this->generateSku($group),
                'name'         => $data['name'],
                'weight_grams' => $data['weight_grams'] ?? null,
                'base_price'   => $data['base_price'] ?? 0,
                'currency'     => $data['currency'] ?? 'INR',
                'status'       => $data['status'] ?? 'active',
                'sort'         => $data['sort'] ?? 0,
                'created_by'   => $actorUuid,
                'updated_by'   => $actorUuid,
            ]);

            return [$option, new ConfigurationChanged('option', $option->uuid)];
        });
    }

    /** Attach (or update) a group on a product. */
    public function assignGroup(CatalogProduct $product, ConfigGroup $group, array $data): ProductAssignment
    {
        return $this->write(function () use ($product, $group, $data) {
            $defaultOptionId = null;
            if (! empty($data['default_option_uuid'])) {
                $default = ConfigOption::where('uuid', $data['default_option_uuid'])->where('group_id', $group->id)->first();
                if (! $default) {
                    throw DomainActionException::unprocessable('default_option_uuid must be an option of this group.', 'BAD_DEFAULT_OPTION', 'default_option_uuid');
                }
                $defaultOptionId = $default->id;
            }

            $assignment = ProductAssignment::updateOrCreate(
                ['product_id' => $product->id, 'group_id' => $group->id],
                [
                    'is_required_override' => $data['is_required_override'] ?? null,
                    'default_option_id'    => $defaultOptionId,
                    'sort'                 => $data['sort'] ?? 0,
                ],
            );

            return [$assignment, new ConfigurationChanged('assignment', $group->uuid)];
        });
    }

    /**
     * Replace an option's compatibility rules.
     *
     * @param  array<int,array{variant_uuid?:string,size_code?:string,is_compatible?:bool}>  $rules
     */
    public function setCompatibility(ConfigOption $option, array $rules): void
    {
        $this->write(function () use ($option, $rules) {
            $option->compatibility()->delete();

            foreach ($rules as $rule) {
                $variantId = null;
                if (! empty($rule['variant_uuid'])) {
                    $variant = CatalogVariant::where('uuid', $rule['variant_uuid'])->first();
                    if (! $variant) {
                        throw DomainActionException::unprocessable('Unknown variant in compatibility rule.', 'UNKNOWN_VARIANT', 'variant_uuid');
                    }
                    $variantId = $variant->id;
                }

                if ($variantId === null && empty($rule['size_code'])) {
                    throw DomainActionException::unprocessable('Each compatibility rule needs a variant_uuid or a size_code.', 'INCOMPLETE_RULE');
                }

                OptionCompatibility::create([
                    'option_id'     => $option->id,
                    'variant_id'    => $variantId,
                    'size_code'     => $rule['size_code'] ?? null,
                    'is_compatible' => array_key_exists('is_compatible', $rule) ? (bool) $rule['is_compatible'] : true,
                ]);
            }

            return [null, new ConfigurationChanged('compatibility', $option->uuid)];
        });
    }

    /** Set whether a nursery offers an option. */
    public function setEnablement(string $nurseryId, ConfigOption $option, bool $isEnabled): NurseryEnablement
    {
        return $this->write(function () use ($nurseryId, $option, $isEnabled) {
            $row = NurseryEnablement::updateOrCreate(
                ['nursery_id' => $nurseryId, 'option_id' => $option->id],
                ['is_enabled' => $isEnabled],
            );

            return [$row, new ConfigurationChanged('enablement', $option->uuid, $nurseryId)];
        });
    }

    /**
     * Run a write in a transaction, publish its ConfigurationChanged event, and
     * invalidate the resolution cache.
     *
     * @param  callable():array{0:mixed,1:ConfigurationChanged}  $work
     */
    private function write(callable $work): mixed
    {
        $result = DB::transaction(function () use ($work) {
            [$value, $event] = $work();
            $this->events->publish($event);

            return $value;
        });

        $this->config->invalidate();

        return $result;
    }

    private function generateSku(ConfigGroup $group): string
    {
        $base = 'OPT-'.strtoupper(Str::slug($group->code, ''));
        do {
            $candidate = substr($base, 0, 16).'-'.strtoupper(Str::random(5));
        } while (ConfigOption::withTrashed()->where('sku', $candidate)->exists());

        return $candidate;
    }
}
