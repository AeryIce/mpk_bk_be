@component('mail::message')
# Your Magic Link

Hi, **{{ $email }}**.

Click the button below to {{ $purpose === 'reset' ? 'reset your password' : 'complete your sign-in' }}.

@component('mail::button', ['url' => $url])
Open Magic Link
@endcomponent

If the button doesn’t work, copy and paste this URL into your browser:

{{ $url }}

Thanks,<br>
{{ config('app.name') }}
@endcomponent
