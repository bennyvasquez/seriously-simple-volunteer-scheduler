<x-mail::message>
{{ $greeting }}

{{ $intro }}

@foreach ($lines as $line)
{{ $line }}

@endforeach
{{ $question }}

<x-mail::button :url="$stillWorksUrl">
{{ $stillWorksLabel }}
</x-mail::button>

<x-mail::button :url="$cantUrl" color="error">
{{ $cantLabel }}
</x-mail::button>

{{ $loginNote }}
@foreach ($titleLines as $line)
{{ $line }}

@endforeach
</x-mail::message>
