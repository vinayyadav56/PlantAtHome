@component('mail::message')
# Hello {{ $ownerName }},

You have been set up as the owner of **{{ $shopName }}** on PlantAtHome.

Use the credentials below to log in to your vendor dashboard:

@component('mail::panel')
**Email:** {{ $email }}<br>
**Password:** {{ $password }}
@endcomponent

@component('mail::button', ['url' => $loginUrl])
Log in to your vendor dashboard
@endcomponent

For your security, please change this password after your first login — a
"Forgot password?" link is available on the login page.

If the button above does not work, copy and paste this URL into your browser:
{{ $loginUrl }}

Thanks,<br>
{{ config('app.name') }}
@endcomponent
