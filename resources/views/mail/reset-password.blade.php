<x-mail::message>
# Reset your password

Hi {{ $userName }},

We received a request to reset the password for your {{ config('app.name') }} account.

<x-mail::button :url="$resetUrl">
Reset password
</x-mail::button>

This link expires in {{ $expireMinutes }} minutes. If you did not request a password reset, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
