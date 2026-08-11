<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Settings are single-row by design, but SettingsController::store used to
 * CREATE a per-locale fork row whenever an admin saved from a non-default UI
 * locale — a row almost no server code reads (reads are no-arg
 * Settings::getData() = the default-language row). Delete the forks so an
 * admin's view (their locale row, via fallback) matches what the server
 * actually consumes. Cache::forget per deleted language is REQUIRED: the
 * settings index caches with rememberForever, so a deleted fork would keep
 * serving from cache indefinitely.
 */
return new class extends Migration
{
    public function up(): void
    {
        $default = defined('DEFAULT_LANGUAGE') ? DEFAULT_LANGUAGE : 'en';

        $hasDefault = DB::table('settings')->where('language', $default)->exists();
        if (!$hasDefault) {
            return; // never delete the only row
        }

        $forks = DB::table('settings')->where('language', '!=', $default)->pluck('language');
        DB::table('settings')->where('language', '!=', $default)->delete();
        foreach ($forks as $lang) {
            Cache::forget('cached_settings_' . $lang);
        }
        Cache::forget('cached_settings_' . $default);
    }

    public function down(): void
    {
        // Deleted fork rows are not recoverable (and by design should not be).
    }
};
