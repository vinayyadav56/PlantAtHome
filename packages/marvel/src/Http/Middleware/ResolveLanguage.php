<?php

namespace Marvel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Marvel\Translation\TranslationContext;

/**
 * Resolves the request language for the translation overlay.
 *
 * Precedence (backward-compatible):
 *   1. explicit ?language= query param (the existing shop contract) — unchanged.
 *   2. q-weighted Accept-Language header, matched to the supported set.
 *   3. default language.
 *
 * It merges `language` back into the request so every existing
 * `$request->language ?? DEFAULT_LANGUAGE` keeps working, and primes the
 * request-scoped TranslationContext that drives the read-overlay.
 */
class ResolveLanguage
{
    public function handle(Request $request, Closure $next)
    {
        $default = config('translation.default_language', 'en');
        $supported = config('translation.languages', [$default]);

        $lang = $request->query('language') ?: $request->input('language');

        if (!$lang) {
            $lang = $this->fromAcceptLanguage($request->header('Accept-Language'), $supported);
        }

        if (!in_array($lang, $supported, true)) {
            $lang = $default;
        }

        // Back-compat: controllers read $request->language.
        $request->merge(['language' => $lang]);

        // Drive the overlay + Laravel locale (for translated emails/validation).
        app(TranslationContext::class)->setLanguage($lang);
        app()->setLocale($lang);

        return $next($request);
    }

    /** Pick the best supported language from a q-weighted Accept-Language header. */
    protected function fromAcceptLanguage(?string $header, array $supported): ?string
    {
        if (!$header) {
            return null;
        }
        $ranked = [];
        foreach (explode(',', $header) as $part) {
            $bits = explode(';q=', trim($part));
            $tag = strtolower(trim($bits[0]));
            $q = isset($bits[1]) ? (float) $bits[1] : 1.0;
            if ($tag === '' || $tag === '*') {
                continue;
            }
            $primary = explode('-', $tag)[0]; // hi-IN -> hi
            $ranked[$primary] = max($ranked[$primary] ?? 0, $q);
        }
        arsort($ranked);
        foreach (array_keys($ranked) as $tag) {
            if (in_array($tag, $supported, true)) {
                return $tag;
            }
        }
        return null;
    }
}
