<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Human / bot / unknown classification across the tracking tables, plus
 * first-class analytics sessions.
 *
 * Before this, every visitor was assumed human and the admin showed three
 * different "online" numbers. Now every visitor, session and event carries a
 * traffic_type so business metrics can be filtered to humans and bot traffic
 * can be reported on its own. No backfill: a visitor is reclassified on its
 * next ping, and rows that never ping again age out with retention.
 *
 * Column-guarded per column: staging has run partial migrations before.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('visitors')) {
            Schema::table('visitors', function (Blueprint $t) {
                $add = function (string $col, Closure $def): void {
                    if (!Schema::hasColumn('visitors', $col)) {
                        $def();
                    }
                };
                $add('traffic_type', fn () => $t->string('traffic_type', 8)->default('unknown'));
                $add('bot_name', fn () => $t->string('bot_name', 64)->nullable());
                $add('bot_type', fn () => $t->string('bot_type', 24)->nullable());
                $add('country_code', fn () => $t->string('country_code', 2)->nullable());
                $add('state', fn () => $t->string('state', 80)->nullable());
                // The city the shopper is BROWSING (storefront city picker) — the
                // business-relevant one; `city` stays the approximate geo city.
                $add('shopping_city', fn () => $t->string('shopping_city', 120)->nullable());
                foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $utm) {
                    $add($utm, fn () => $t->string($utm, 120)->nullable()); // first touch, written once
                }
                $add('engaged', fn () => $t->boolean('engaged')->default(false));
            });
            if (!self::hasIndex('visitors', 'visitors_traffic_type_last_seen_index')) {
                Schema::table('visitors', fn (Blueprint $t) => $t->index(['traffic_type', 'last_seen'], 'visitors_traffic_type_last_seen_index'));
            }
        }

        if (!Schema::hasTable('analytics_sessions')) {
            Schema::create('analytics_sessions', function (Blueprint $t) {
                $t->id();
                // NOT unique: when a stale session id keeps arriving (old cached
                // bundle, a crawler) the server starts a NEW row under the same id.
                $t->string('session_id', 64)->index();
                $t->string('visitor_id', 64)->index();   // device/browser/geo live on visitors
                $t->unsignedBigInteger('user_id')->nullable()->index();
                $t->string('traffic_type', 8)->default('unknown');
                $t->string('bot_name', 64)->nullable();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('last_seen_at')->nullable()->index();
                $t->string('landing_page', 512)->nullable();
                $t->string('exit_page', 512)->nullable();
                $t->string('referrer', 512)->nullable();
                foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $utm) {
                    $t->string($utm, 120)->nullable();      // this session's touch
                }
                $t->unsignedInteger('page_views')->default(0);
                $t->boolean('engaged')->default(false);
                $t->index(['traffic_type', 'started_at']);
            });
        }

        if (Schema::hasTable('analytics_events')) {
            if (!Schema::hasColumn('analytics_events', 'traffic_type')) {
                Schema::table('analytics_events', fn (Blueprint $t) => $t->string('traffic_type', 8)->default('unknown'));
            }
            if (!self::hasIndex('analytics_events', 'analytics_events_traffic_type_type_created_at_index')) {
                Schema::table('analytics_events', fn (Blueprint $t) => $t->index(['traffic_type', 'type', 'created_at'], 'analytics_events_traffic_type_type_created_at_index'));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_sessions');
        if (Schema::hasTable('analytics_events') && Schema::hasColumn('analytics_events', 'traffic_type')) {
            Schema::table('analytics_events', function (Blueprint $t) {
                $t->dropIndex('analytics_events_traffic_type_type_created_at_index');
                $t->dropColumn('traffic_type');
            });
        }
        if (Schema::hasTable('visitors') && Schema::hasColumn('visitors', 'traffic_type')) {
            Schema::table('visitors', function (Blueprint $t) {
                $t->dropIndex('visitors_traffic_type_last_seen_index');
                $t->dropColumn(['traffic_type', 'bot_name', 'bot_type', 'country_code', 'state', 'shopping_city',
                    'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'engaged']);
            });
        }
    }

    /**
     * Schema::hasIndex() only arrived in Laravel 11 and this is 10.x — ask the driver
     * (lifted from 2026_09_22_000000_add_variant_master_columns_to_attribute_values).
     */
    private static function hasIndex(string $table, string $index): bool
    {
        try {
            $connection = Schema::getConnection();
            if ($connection->getDriverName() === 'sqlite') {
                foreach ($connection->select("PRAGMA index_list(\"{$table}\")") as $row) {
                    if (($row->name ?? null) === $index) {
                        return true;
                    }
                }
                return false;
            }
            return (bool) $connection->selectOne(
                'SELECT 1 AS found FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
                [$table, $index]
            );
        } catch (\Throwable) {
            return true; // unknown driver: skip the DDL rather than throw at it blindly
        }
    }
};
