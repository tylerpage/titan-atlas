<x-mail::message>
# Your feedback is complete

Hi {{ $userName }},

We've finished work on your feedback and wanted to let you know.

<x-mail::panel>
**{{ $reasonLabel }}** · submitted {{ $submittedAt->timezone(config('app.timezone'))->format('M j, Y') }}

{{ $feedbackMessage }}
</x-mail::panel>

If we made changes based on your report, they should already be live or on the way. If you still see an issue, feel free to send another note from the feedback button in the app.

Thanks for helping us improve {{ config('app.name') }}.

Thanks,<br>
The {{ config('app.name') }} team
</x-mail::message>
