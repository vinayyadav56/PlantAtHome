@component('mail::message')
# Location Captured

**{{ $name }}** ({{ $req->type }}) has shared their precise location.

@component('mail::panel')
**Address:** {{ $req->formatted_address ?? '—' }}

**City:** {{ $req->city ?? '—' }} · **State:** {{ $req->state ?? '—' }} · **PIN:** {{ $req->postal_code ?? '—' }}

**Coordinates:** {{ $req->latitude }}, {{ $req->longitude }} (±{{ $req->accuracy ? round($req->accuracy) . ' m' : 'n/a' }})

**Captured at:** {{ optional($req->completed_at)->format('d M Y, h:i A') }}
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
