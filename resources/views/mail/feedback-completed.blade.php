<x-mail::message>
# Thanks for your feedback

Hi {{ $userName }},

We've reviewed your feedback about **{{ $reasonLabel }}** submitted on {{ $submittedAt->timezone(config('app.timezone'))->format('M j, Y') }} and marked it complete.

If we made changes based on your report, they should already be live or on the way. If you still see an issue, feel free to send another note from the feedback button in the app.

Thanks for helping us improve {{ config('app.name') }}.

Thanks,<br>
The {{ config('app.name') }} team
</x-mail::message>
