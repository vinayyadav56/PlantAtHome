@component('mail::message')
# Please Confirm Your Location

Hello {{ $recipientName }},

We need your precise location to complete your PlantAtHome profile.

Sharing your location helps us:

✔ Find nearby nurseries

✔ Calculate delivery accurately

✔ Improve delivery speed

✔ Ensure successful order fulfilment

Click the button below to securely share your location.

@component('mail::button', ['url' => $captureUrl, 'color' => 'success'])
Share My Location
@endcomponent

This link expires in {{ $expiryHours }} hours and can be used only once.

If the button doesn't work, copy this link into your browser:
{{ $captureUrl }}

Thanks,<br>
{{ config('app.name') }}

<img src="{{ $openPixelUrl }}" width="1" height="1" alt="" style="display:none;">
@endcomponent
