@component('mail::message')
# Reset your GovQueue password

Good day {{ $user->name }}. Use the button below to choose a new password for your
GovQueue account.

@component('mail::button', ['url' => $url, 'color' => 'primary'])
Choose a new password
@endcomponent

The link expires in {{ $minutes }} minutes and can only be used once.

If you did not ask to reset your password, you can ignore this message. Nothing
has changed and your current password still works.

@component('mail::footer')
This message was sent because someone requested a password reset for a GovQueue
account at {{ config('govqueue.office.lgu') }}. If this was not you, no action is
needed. We will never ask you to reply with your password.

{{ $url }}
@endcomponent
@endcomponent
