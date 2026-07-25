<?php

use Illuminate\Database\Migrations\Migration;
use Marvel\Database\Models\Settings;

/**
 * Turn on the AI features (product description + category suggestions + bulk
 * content generation). The `ai` singleton resolves the provider from
 * options.defaultAi, and Marvel\Ai\Base gates every call on options.useAi — so
 * both must be set for the AI buttons/endpoints to work. Merge-safe: leaves the
 * rest of the settings blob untouched. Once live, admins can toggle useAi from
 * the settings UI (this only seeds the initial on-state).
 */
return new class extends Migration
{
    public function up(): void
    {
        $settings = Settings::first();
        if (!$settings) {
            return;
        }
        $options = (array) ($settings->options ?? []);
        $options['useAi'] = true;
        if (empty($options['defaultAi'])) {
            $options['defaultAi'] = 'openai';
        }
        $settings->options = $options;
        $settings->save();
    }

    public function down(): void
    {
        $settings = Settings::first();
        if (!$settings) {
            return;
        }
        $options = (array) ($settings->options ?? []);
        $options['useAi'] = false;
        $settings->options = $options;
        $settings->save();
    }
};
