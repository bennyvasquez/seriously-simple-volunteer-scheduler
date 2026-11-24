<x-mail::message>
{{ $greeting }}

{{ $intro }}

{{ $others }}

@if ($shadows !== null)
{{ $shadows }}

@endif
@if ($stillWorksUrl !== null)
{{ $timeChanged }}

<x-mail::button :url="$stillWorksUrl">
{{ $stillWorksLabel }}
</x-mail::button>

<x-mail::button :url="$cantUrl" color="error">
{{ $cantLabel }}
</x-mail::button>

{{ $loginNote }}

@endif
@if ($releaseUrl !== null)
<x-mail::button :url="$releaseUrl" color="error">
{{ $releaseLabel }}
</x-mail::button>

@endif

{{ $turnOff }}
</x-mail::message>
