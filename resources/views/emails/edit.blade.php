{{-- Wireframes 62 and 63: one email, when it's sent, a box for each line, the words in braces it can use, and the preview. On a computer the preview sits beside the boxes; on a phone it's above them, and only after pressing Preview (public/css/app.css). Save sends the form as a PUT (the button carries the method), Preview posts the same form to the preview route, and each "Back to the default" button sends it to the reset route as a DELETE. The form's first button is a hidden Preview, so pressing Enter in a box previews rather than putting a line back. The preview comes after the boxes in the page, so keyboard and screen reader users meet the boxes first. --}}
@extends('layouts.app')

@section('title', $email->title.': Emails we send')
@section('main-class', 'email-page')

@section('content')
<nav aria-label="Breadcrumb" class="muted"><a href="{{ route('calendar.week') }}">Calendar</a> / <a href="{{ route('emails.index') }}">Emails we send</a> / {{ $email->title }}</nav>
<h1>{{ $email->title }}</h1>
@if (session('status'))
<p role="status" class="cal-status">{{ session('status') }}</p>
@endif
@if ($errors->has('lines'))
<p class="field-error" role="alert">{{ $errors->first('lines') }}</p>
@elseif ($errors->any() && ! $previewing)
<p class="field-error" role="alert">Nothing was saved. Check the lines marked below.</p>
@endif

<section class="panel email-when">
<h2>When it's sent</h2>
<p>{{ $email->when }}</p>
@if ($email->key === 'shift_reminder' && $isAdmin)<p><a href="{{ route('settings') }}">Change the timing in Settings</a></p>@endif
</section>

@if ($readOnly)
<section class="panel">
<h2>Its lines</h2>
<p>These lines can't be changed here yet. They're coming in a later update.</p>
<ul class="plain-list">
@foreach ($email->lines as $line)
<li><b>{{ $line['label'] }}</b>@if ($line['when'])<br><span class="muted">{{ $line['when'] }}</span>@endif</li>
@endforeach
</ul>
</section>
@else
<div class="email-layout">
<div class="email-lines">
<p class="muted">Each box is one line of the email. The line under each name says when that line shows: SSVS decides that, and you decide the words. @if ($sampleWord !== null)Words in braces, like <code class="var">{{ '{'.$sampleWord.'}' }}</code>, are filled in for each person.@endif</p>

<form method="POST" action="{{ route('emails.update', $email->key) }}" id="email-form" class="email-form">
@csrf
{{-- Enter in a box presses a form's first button; this one previews. Hidden from everyone, since the visible Preview button does the same. --}}
<button type="submit" formaction="{{ route('emails.preview', $email->key) }}" class="vh" tabindex="-1" aria-hidden="true">Show the preview</button>
@foreach ($lines as $line)
@include('emails._line', ['line' => $line])
@endforeach

@if ($insideLines !== [])
<h2 class="email-subhead">Used inside other lines</h2>
<p class="muted">These words fill in a word in braces in the lines above.</p>
@foreach ($insideLines as $line)
@include('emails._line', ['line' => $line])
@endforeach
@endif

@if ($words !== [])
<section class="panel">
<h2>Words you can use in this email</h2>
<p class="muted">Each line can use the words in braces its default has. These examples are what the preview fills in.</p>
<dl class="email-words">
@foreach ($words as $name => $value)
<div><dt><code class="var">{{ '{'.$name.'}' }}</code></dt><dd class="muted">{{ $value }}</dd></div>
@endforeach
</dl>
</section>
@endif

<div class="add-actions email-actions">
<button type="submit" formaction="{{ route('emails.preview', $email->key) }}" class="btn">Preview</button>
<button type="submit" name="_method" value="PUT" class="btn btn-primary">Save</button>
</div>
</form>
</div>

@include('emails._preview')
</div>
@endif
@endsection
