<?php

namespace Marvel\Services\Legal;

use Marvel\Database\Models\Legal\LegalVariable;

/**
 * Resolves {{variable}} placeholders in document content.
 *
 * Two contexts, deliberately different:
 *
 *   ADMIN   — every variable is substituted so drafters see the working text,
 *             but UNAPPROVED values are wrapped in a marker span so it is
 *             obvious on screen that the number is not yet a decision.
 *
 *   PUBLIC  — only APPROVED values are substituted. An unapproved placeholder
 *             is replaced with neutral wording ("as per the currently approved
 *             configuration") rather than a raw {{key}} — the spec forbids
 *             exposing unresolved internals — and the publish guard should have
 *             stopped the document reaching here in the first place.
 */
class VariableResolver
{
    public const ADMIN = 'admin';
    public const PUBLIC_CONTEXT = 'public';

    private const PATTERN = '/\{\{\s*([a-z0-9_]+)\s*\}\}/i';

    /** @var array<string, LegalVariable>|null */
    private static ?array $cache = null;

    public static function flushCache(): void
    {
        self::$cache = null;
    }

    /** @return array<string, LegalVariable> */
    private static function all(): array
    {
        if (self::$cache === null) {
            try {
                self::$cache = LegalVariable::all()->keyBy('key')->all();
            } catch (\Throwable) {
                self::$cache = [];
            }
        }

        return self::$cache;
    }

    /** Every {{key}} used in the content, in order of first appearance. */
    public static function used(?string $html): array
    {
        if (! $html) {
            return [];
        }
        preg_match_all(self::PATTERN, $html, $m);

        return array_values(array_unique(array_map('strtolower', $m[1] ?? [])));
    }

    /**
     * Variables used by the content that are not approved (or do not exist).
     * This is what the publish guard and the "management decisions" report read.
     *
     * @return array{unapproved: string[], unknown: string[]}
     */
    public static function outstanding(?string $html): array
    {
        $vars = self::all();
        $unapproved = [];
        $unknown = [];
        foreach (self::used($html) as $key) {
            if (! isset($vars[$key])) {
                $unknown[] = $key;
            } elseif (! $vars[$key]->is_approved) {
                $unapproved[] = $key;
            }
        }

        return ['unapproved' => $unapproved, 'unknown' => $unknown];
    }

    public static function resolve(?string $html, string $context = self::ADMIN): ?string
    {
        if (! $html || ! str_contains($html, '{{')) {
            return $html;
        }
        $vars = self::all();

        return preg_replace_callback(self::PATTERN, function (array $m) use ($vars, $context) {
            $key = strtolower($m[1]);
            $variable = $vars[$key] ?? null;

            // Unknown key: never echo the raw placeholder outward.
            if (! $variable || ($variable->value === null || $variable->value === '')) {
                return $context === self::PUBLIC_CONTEXT
                    ? 'as per the currently approved configuration'
                    : '<span class="legal-var legal-var--missing" title="No value set for ' . e($key) . '">[' . e($key) . ' — not set]</span>';
            }

            if ($variable->is_approved) {
                return e((string) $variable->value);
            }

            return $context === self::PUBLIC_CONTEXT
                ? 'as per the currently approved configuration'
                : '<span class="legal-var legal-var--pending" title="Draft default — pending management approval">'
                    . e((string) $variable->value) . '</span>';
        }, $html);
    }
}
