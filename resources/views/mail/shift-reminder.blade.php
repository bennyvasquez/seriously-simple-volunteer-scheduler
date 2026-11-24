<x-mail::message>
{{ $greeting }}

{{ $intro }}

{{ $others }}

@if ($shadows !== null)
{{ $shadows }}

@endif
@if ($stillWorksUrl !== null)
The time of this shift changed, and you haven't told us yet whether the new time works.

<x-mail::button :url="$stillWorksUrl">
Still works for me
</x-mail::button>

<x-mail::button :url="$cantUrl" color="error">
I can't make the new time
</x-mail::button>

You'll log in first if you aren't already.

@endif
<x-mail::button :url="$releaseUrl" color="error">
Can't make it
</x-mail::button>

You can turn these reminders off on the [Emails page]({{ $emailsUrl }}).
</x-mail::message>
