<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="$headerUrl">
@if (filled($companyLogo))
<img src="{{ $companyLogo }}" class="logo" alt="{{ $companyName }}" style="max-height: 52px; height: auto; width: auto; max-width: 220px; border: 0; margin: 0 auto;">
@else
{{ $companyName }}
@endif
</x-mail::header>
</x-slot:header>

{{-- Body --}}
# {{ $greeting }}

{{ $intro }}

{{ $taskReminder }}

@if (filled($actionUrl))
<table class="action" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 28px auto; padding: 0; text-align: center; width: 100%;">
<tr>
<td align="center" style="padding: 0;">
<a href="{{ $actionUrl }}" target="_blank" rel="noopener" style="display: inline-block; padding: 12px 24px; border: 0; border-radius: 6px; background-color: #18181b; color: #ffffff !important; font-size: 14px; font-weight: 600; line-height: 1.25; text-decoration: none;">{{ $actionText }}</a>
</td>
</tr>
</table>
@endif

{{ __('remote-attendance::app.mail.salutation') }}<br>
{{ $companyName }}

{{-- Subcopy --}}
@if (filled($actionUrl))
<x-slot:subcopy>
<x-mail::subcopy>
{{ __('remote-attendance::app.mail.subcopy', ['action' => $actionText]) }} <span class="break-all">[{{ $actionUrl }}]({{ $actionUrl }})</span>
</x-mail::subcopy>
</x-slot:subcopy>
@endif

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ $companyName }}. {{ __('All rights reserved.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
