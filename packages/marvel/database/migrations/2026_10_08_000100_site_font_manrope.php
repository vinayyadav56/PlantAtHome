<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Manrope for everything (owner, 2026-10-07: "use these fonts everywhere on the website for
 * heading and non-heading" → chose "Everything in Manrope").
 *
 * Typography is admin data (Settings → Design System): `options.typography =
 * { fontFamily, headingFontFamily }`. The body font was "Inter"; headings already resolve to
 * Manrope through the design-system pairing (headingFontFamily null). This sets the body font
 * to Manrope on every settings row and keeps headingFontFamily as it is — both keys written,
 * because an admin save replaces the whole object. The old object is snapshotted into
 * pah_settings_typography_backup for down(), and the settings response cache is forgotten so
 * the storefront's design-system applier picks Manrope up at once. The admin keeps the
 * control: the next Design System save wins.
 *
 * Decoded to objects (not arrays) so empty `{}` maps elsewhere in the options JSON stay
 * objects when re-encoded.
 */
return new class extends Migration {
    private const BACKUP = 'pah_settings_typography_backup';
    private const FONT = 'Manrope';

    public function up(): void
    {
        if (!Schema::hasTable('settings')) {
            return;
        }
        if (!Schema::hasTable(self::BACKUP)) {
            Schema::create(self::BACKUP, function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('settings_id');
                $t->longText('old_typography')->nullable(); // JSON; null = the key was absent
                $t->timestamp('created_at')->nullable();
            });
        }

        try {
            DB::transaction(function () {
                foreach (DB::table('settings')->get(['id', 'options']) as $row) {
                    $options = json_decode((string) $row->options);
                    if (!is_object($options)) {
                        continue;
                    }
                    $old = $options->typography ?? null;
                    if (is_object($old) && ($old->fontFamily ?? null) === self::FONT) {
                        continue; // already Manrope
                    }
                    DB::table(self::BACKUP)->insert([
                        'settings_id'    => $row->id,
                        'old_typography' => $old === null ? null : json_encode($old),
                        'created_at'     => now(),
                    ]);
                    $options->typography = (object) [
                        'fontFamily'        => self::FONT,
                        'headingFontFamily' => is_object($old) ? ($old->headingFontFamily ?? null) : null,
                    ];
                    DB::table('settings')->where('id', $row->id)->update([
                        'options'    => json_encode($options),
                        'updated_at' => now(),
                    ]);
                }
            });
        } catch (\Throwable $e) {
            // Never a reason to fail a deploy; the transaction left nothing half-done.
            Log::error('site_font_manrope failed: ' . $e->getMessage());
            echo "WARN site_font_manrope failed: {$e->getMessage()}\n";
        }

        $this->forgetSettingsCache();
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::BACKUP)) {
            return;
        }
        DB::transaction(function () {
            foreach (DB::table(self::BACKUP)->orderByDesc('id')->get() as $b) {
                $row = DB::table('settings')->where('id', $b->settings_id)->first(['id', 'options']);
                $options = $row ? json_decode((string) $row->options) : null;
                if (!is_object($options)) {
                    continue;
                }
                if ($b->old_typography === null) {
                    unset($options->typography);
                } else {
                    $options->typography = json_decode($b->old_typography);
                }
                DB::table('settings')->where('id', $b->settings_id)->update(['options' => json_encode($options)]);
            }
        });
        Schema::drop(self::BACKUP);
        $this->forgetSettingsCache();
    }

    /** SettingsController::index caches per language under this key. */
    private function forgetSettingsCache(): void
    {
        try {
            $languages = Schema::hasColumn('settings', 'language')
                ? DB::table('settings')->pluck('language')->filter()->all()
                : [];
            $languages[] = defined('DEFAULT_LANGUAGE') ? DEFAULT_LANGUAGE : 'en';
            foreach (array_unique($languages) as $lang) {
                Cache::forget('cached_settings_' . $lang);
            }
        } catch (\Throwable $e) {
            // the 10-minute cache expires on its own
        }
    }
};
