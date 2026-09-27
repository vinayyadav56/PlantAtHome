@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@php
    // Brand header for EVERY markdown mailable (location capture, orders,
    // refunds…): the Settings logo, falling back to the site title, falling
    // back to the slot ("PlantAtHome API" — the APP_NAME default we're hiding).
    $__brandLogo = null;
    $__brandName = null;
    try {
        $__vars = app(\Marvel\Services\EmailService::class)->globalVars();
        $__brandLogo = $__vars['company_logo'] ?? null;
        $__brandName = $__vars['company_name'] ?? null;
    } catch (\Throwable $e) {
        // Settings unavailable (fresh install, tests) — text fallback below.
    }
@endphp
@if ($__brandLogo)
<img src="{{ $__brandLogo }}" class="logo" alt="{{ $__brandName ?: trim($slot) }}" style="max-height: 50px; width: auto;">
@else
{{ $__brandName ?: $slot }}
@endif
</a>
</td>
</tr>
