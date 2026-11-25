{{-- Screens 28, 29 and 64: the change log. The same markup serves the wide screen and the phone; the CSS stacks the time above the sentence. The Email filter shows once the Emails kind is picked (or an email is), so it works without JavaScript after pressing Show changes. An email edit shows the text before and after, escaped, exactly as it was typed. --}}
@extends('layouts.app')

@section('title', 'Change log')
@section('main-class', 'log-page')

@section('content')
<nav aria-label="Breadcrumb" class="muted"><a href="{{ route('calendar.week') }}">Calendar</a> / Change log</nav>
<div>
<h1>Change log</h1>
<p class="muted">Every change to accounts, roles, the schedule and the wording of emails, newest first. Nothing here can be edited.</p>
</div>

<form method="GET" action="{{ route('change-log.index') }}" class="log-filters">
<label class="field">Made by<select name="by">
<option value="">Anyone</option>
<option value="system" @selected($filters['by'] === 'system')>The system</option>
@foreach ($makers as $maker)
<option value="{{ $maker->id }}" @selected($filters['by'] === (string) $maker->id)>{{ $maker->name }}</option>
@endforeach
</select></label>
<label class="field">About<input type="search" name="about" value="{{ $filters['about'] }}"></label>
<label class="field">Kind of change<select name="kind">
<option value="">Everything</option>
@foreach ($kinds as $key => $label)
<option value="{{ $key }}" @selected($filters['kind'] === $key)>{{ $label }}</option>
@endforeach
</select></label>
@if ($filters['kind'] === 'emails' || $filters['email'] !== '')
<label class="field">Email<select name="email">
<option value="">Every email we send</option>
@foreach ($emails as $key => $title)
<option value="{{ $key }}" @selected($filters['email'] === $key)>{{ $title }}</option>
@endforeach
</select></label>
@endif
<label class="field">From<input type="date" name="from" value="{{ $filters['from'] }}"></label>
<label class="field">To<input type="date" name="to" value="{{ $filters['to'] }}"></label>
<button type="submit" class="btn btn-primary">Show changes</button>
<a href="{{ route('change-log.index') }}" class="btn">Clear</a>
</form>

<p class="muted" role="status">{{ $summary }}</p>

@if ($days->isEmpty())
<p>No changes match.</p>
@else
<div class="log-list">
@foreach ($days as $entries)
<h2 class="log-day">{{ $entries->first()['heading'] }}</h2>
@foreach ($entries as $entry)
<div class="log-entry">
<div class="log-when">{{ $entry['time'] }}<br>by @if ($entry['actor']['id'])<a href="{{ route('people.show', $entry['actor']['id']) }}">{{ $entry['actor']['label'] }}</a>@else{{ $entry['actor']['label'] }}@endif</div>
<div>{{ $entry['sentence'] }}@if ($entry['changes'])<div class="log-change">{{ $entry['changes'] }}</div>@endif
@if ($entry['text'])
<div class="log-change log-text"><p><b>Before:</b> <span class="log-old">{{ $entry['text']['before'] }}</span></p><p><b>After:</b> <span class="log-new">{{ $entry['text']['after'] }}</span></p></div>
@endif
</div>
</div>
@endforeach
@endforeach
</div>
<div class="log-pager">
@if ($page->previousPageUrl())<a class="btn" href="{{ $page->previousPageUrl() }}" rel="prev">Newer changes</a>@endif
@if ($page->hasMorePages())<a class="btn" href="{{ $page->nextPageUrl() }}" rel="next">Older changes</a>@endif
<span class="muted">Showing {{ $page->firstItem() }} to {{ $page->lastItem() }} of {{ $page->total() }}</span>
</div>
@endif

<p class="muted">Changes made by SSVS itself, such as generating shifts, show as "by the system". Someone whose account was removed shows as "Removed person", and their contact details are blanked.</p>
@endsection
