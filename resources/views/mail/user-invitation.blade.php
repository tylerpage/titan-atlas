<x-mail::message>
# You're invited to {{ $companyName }}

{{ $inviterName }} has invited you to access analytics dashboards for **{{ $companyName }}** on {{ config('app.name') }}.

Click the button below to set your password and join. This invitation expires {{ $expiresAt->timezone(config('app.timezone'))->format('M j, Y g:i A T') }}.

<x-mail::button :url="$acceptUrl">
Accept invitation
</x-mail::button>

If you did not expect this invitation, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
