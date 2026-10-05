@component('mail::message')
# {{ $title }}

{{ $intro }}

@component('mail::table')
| | |
|:--|:--|
@foreach ($rows as $label => $value)
| **{{ $label }}** | {{ $value }} |
@endforeach
@endcomponent

@if ($actionUrl)
@component('mail::button', ['url' => $actionUrl, 'color' => 'primary'])
{{ $actionLabel }}
@endcomponent
@endif

{{ $footnote }}

{{ $office['lgu'] }}, {{ $office['province'] }}

@component('mail::footer')
This message was sent because you have a GovQueue account with the {{ $office['lgu'] }}. If you no longer wish to receive reminders for an appointment, cancel the appointment from your account.

@if ($actionUrl)
{{ $actionUrl }}
@endif
@endcomponent
@endcomponent