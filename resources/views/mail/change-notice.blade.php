<x-mail::message>
{{ $greeting }}

{{ $intro }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
Does the new time still work for you?

<x-mail::button :url="$stillWorksUrl">
Still works for me
</x-mail::button>

<x-mail::button :url="$cantUrl" color="error">
I can't make the new time
</x-mail::button>

You'll log in first if you aren't already.@if ($leadChecks) If you don't answer, your shift lead will check with you before the shift.@endif

@foreach ($titleLines as $line)
{{ $line }}

@endforeach
</x-mail::message>
