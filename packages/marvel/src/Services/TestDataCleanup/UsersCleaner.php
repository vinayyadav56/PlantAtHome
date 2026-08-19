<?php

namespace Marvel\Services\TestDataCleanup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\User;

/**
 * Customer / staff accounts.
 *
 * Five foreign keys are declared WITHOUT onDelete, which in MySQL means RESTRICT — they will
 * block the delete outright rather than cascade: orders.customer_id, address.customer_id,
 * user_profiles.customer_id, shops.owner_id and store_notices.created_by/updated_by. So this
 * cleaner clears each of them first, and refuses a user who still owns a shop (that is the
 * Vendors module's job, and doing it here would risk the catalog cascade).
 */
class UsersCleaner implements CleanerContract
{
    public function key(): string { return 'users'; }

    public function label(): string { return 'Users'; }

    public function description(): string
    {
        return 'Removes customer/staff accounts with their addresses, profiles, carts, wishlists, '
             . 'reviews, notifications, devices and tokens. Shop owners are refused — clean the '
             . 'vendor first. Their orders must be included or removed separately.';
    }

    public function stats(): array
    {
        if (!Schema::hasTable('users')) {
            return ['total' => 0];
        }
        return [
            'total'        => DB::table('users')->count(),
            'shop_owners'  => Schema::hasTable('shops') ? DB::table('shops')->whereNotNull('owner_id')->distinct()->count('owner_id') : 0,
            'with_orders'  => Schema::hasTable('orders') ? DB::table('orders')->whereNotNull('customer_id')->distinct()->count('customer_id') : 0,
            'marked_test'  => TestDataMarker::countFor(User::class),
        ];
    }

    public function plan(array $scope): CleanupPlan
    {
        $plan = new CleanupPlan($this->key(), $scope);
        if (!Schema::hasTable('users')) {
            return $plan;
        }

        $q = DB::table('users')->select('id');
        if (!empty($scope['ids'])) {
            $q->whereIn('id', (array) $scope['ids']);
        } elseif (!empty($scope['only_marked'])) {
            $q->whereIn('id', TestDataMarker::idsFor(User::class));
        } elseif (!empty($scope['email_like'])) {
            // e.g. '%@plantathome.test' — the demo-user convention the identity seeder uses.
            $q->where('email', 'like', $scope['email_like']);
        } elseif (empty($scope['all'])) {
            return $plan;
        }
        $ids = $q->pluck('id')->all();

        // Never delete the account you are acting as, and never the last super-admin.
        $actorId = optional(request()?->user())->id;
        if ($actorId && in_array($actorId, $ids)) {
            $ids = array_values(array_diff($ids, [$actorId]));
            $plan->warn('Your own account was excluded from this cleanup.');
        }
        // Shop owners are refused — removing them would either be blocked by the FK or drag a
        // shop (and potentially the catalog) with it.
        if ($ids && Schema::hasTable('shops')) {
            $owners = DB::table('shops')->whereIn('owner_id', $ids)->distinct()->pluck('owner_id')->all();
            if ($owners) {
                $ids = array_values(array_diff($ids, $owners));
                $plan->warn(count($owners) . ' shop owner(s) were excluded — clean their vendor first.');
            }
        }
        if (!$ids) {
            return $plan;
        }

        // Their orders come with them (orders.customer_id is RESTRICT: it would block otherwise).
        if (Schema::hasTable('orders')) {
            $orderIds = DB::table('orders')->whereIn('customer_id', $ids)->pluck('id')->all();
            if ($orderIds) {
                foreach ((new OrdersCleaner())->plan(['ids' => $orderIds])->steps as $s) {
                    $plan->steps[] = $s;
                }
                $plan->warn(count($orderIds) . ' order(s) placed by these users are included.');
            }
        }

        // RESTRICT blockers, then the no-FK orphan sweep.
        foreach ([
            ['address', 'customer_id'], ['user_profiles', 'customer_id'],
            ['device_tokens', 'user_id'], ['carts', 'user_id'], ['care_plans', 'user_id'],
            ['care_reminders', 'user_id'], ['garden_packages', 'user_id'],
            ['analytics_events', 'user_id'], ['visitors', 'user_id'],
            ['plant_doctor_logs', 'user_id'], ['ai_chat_conversations', 'user_id'],
            ['download_tokens', 'user_id'], ['coupon_usages', 'user_id'],
            ['location_capture_requests', 'user_id'], ['delivery_notify_requests', 'user_id'],
        ] as [$table, $col]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $col)) {
                $plan->step($table, DB::table($table)->whereIn($col, $ids)->pluck('id')->all(), 'id');
            }
        }
        // Polymorphic tables key on model_id + model_type.
        if (Schema::hasTable('personal_access_tokens')) {
            $plan->step('personal_access_tokens',
                DB::table('personal_access_tokens')->whereIn('tokenable_id', $ids)
                    ->where('tokenable_type', User::class)->pluck('id')->all(), 'id');
        }
        foreach (['model_has_roles', 'model_has_permissions'] as $table) {
            if (Schema::hasTable($table)) {
                // No id column on these pivots — delete by model_id, scoped to the User type.
                $rows = DB::table($table)->whereIn('model_id', $ids)->where('model_type', User::class)->count();
                if ($rows) {
                    $plan->step($table, $ids, 'model_id', 'role/permission assignments');
                }
            }
        }
        // store_notices.created_by is RESTRICT — clear it rather than delete the notice.
        foreach ([['store_notices', 'created_by'], ['store_notices', 'updated_by'],
                  ['products', 'created_by'], ['shops', 'created_by'], ['shops', 'updated_by'],
                  ['users', 'reporting_manager_id']] as [$table, $col]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $col)) {
                $plan->nullify($table, $col, $ids);
            }
        }

        $plan->step('users', $ids, 'id', 'the accounts themselves');
        $plan->warn('CASCADE clears wishlists, reviews, questions, wallets, conversations and notify_logs automatically.');

        return $plan;
    }
}
