{{-- Screens 24 and 25: the people list. The table and the phone cards are both in the page; the CSS shows one. --}}
@extends('layouts.app')

@section('title', 'People')
@section('main-class', 'people-page')

@section('content')
<nav aria-label="Breadcrumb" class="muted"><a href="{{ route('calendar.week') }}">Calendar</a> / People</nav>
<div class="people-head">
<div>
<h1>People</h1>
<p class="muted">{{ $summary }}</p>
@if (! $isCoordinator && $ledAreaNames !== [])
<p class="muted">People who work in {{ implode(' and ', $ledAreaNames) }}, and you.</p>
@endif
</div>
@if ($canInvite)
<div class="people-actions">
<a href="{{ route('people.invite') }}" class="btn btn-primary">Invite someone</a>
@if ($notInvitedCount > 0)
<a href="{{ route('people.invite-everyone') }}" class="btn">Invite everyone not invited yet ({{ $notInvitedCount }} {{ $notInvitedCount === 1 ? 'person' : 'people' }})</a>
@endif
</div>
@endif
</div>

@if (session('status'))
<p role="status" class="cal-status">{{ session('status') }}</p>
@endif

<form method="GET" action="{{ route('people.index') }}" role="search" class="people-filters">
<label class="field">{{ $isCoordinator ? 'Search by name or email' : 'Search by name' }}<input type="search" name="q" value="{{ $filters['q'] }}"></label>
<label class="field">Role<select name="role">
<option value="">Everyone</option>
<option value="volunteer" @selected($filters['role'] === 'volunteer')>Volunteers</option>
<option value="area_lead" @selected($filters['role'] === 'area_lead')>Area leads</option>
<option value="coordinator" @selected($filters['role'] === 'coordinator')>Coordinators</option>
<option value="admin" @selected($filters['role'] === 'admin')>Admins</option>
</select></label>
<label class="field">Area<select name="area">
<option value="">All areas</option>
@foreach ($areas as $area)
<option value="{{ $area->id }}" @selected($filters['area'] === $area->id)>{{ $area->name }}</option>
@endforeach
</select></label>
<label class="field">Invitation<select name="invitation">
<option value="">Any</option>
@foreach ($invitationStatuses as $status)
<option value="{{ $status->value }}" @selected($filters['invitation'] === $status->value)>{{ $status->label() }}</option>
@endforeach
</select></label>
<label class="field">Show<select name="show">
<option value="active" @selected($filters['show'] === 'active')>Active people</option>
<option value="off" @selected($filters['show'] === 'off')>Turned off accounts</option>
<option value="all" @selected($filters['show'] === 'all')>Everyone</option>
</select></label>
<button type="submit" class="btn">Filter</button>
</form>

@if ($rows->isEmpty())
<p>Nobody matches. Try fewer filters.</p>
@else
<div class="people-table-wrap">
<table class="people-table">
<thead>
<tr><th scope="col">Name</th><th scope="col">Roles</th><th scope="col">Invitation</th><th scope="col">Last seen</th><th scope="col">Last login</th></tr>
</thead>
<tbody>
@foreach ($rows as $row)
<tr>
<td><strong><a href="{{ route('people.show', $row['person']) }}">{{ $row['person']->name }}</a></strong>@unless ($row['person']->isActive()) <span class="tag">Turned off</span>@endunless<div class="muted">{{ $row['person']->email }}</div></td>
<td>{{ $row['roles'] === [] ? 'Volunteer' : implode(', ', $row['roles']) }}</td>
<td>@if (in_array($row['status']->value, ['waiting', 'ran_out'], true))<span class="tag">{{ $row['status']->label() }}</span>@if ($row['sent'])<div class="muted">Sent {{ $row['sent'] }}</div>@endif @else{{ $row['status']->label() }}@endif</td>
<td>{{ $row['seen'] }}</td>
<td>{{ $row['login'] }}</td>
</tr>
@endforeach
</tbody>
</table>
</div>

<div class="people-cards">
@foreach ($rows as $row)
<div class="card">
<div class="card-top"><strong><a href="{{ route('people.show', $row['person']) }}">{{ $row['person']->name }}</a>@unless ($row['person']->isActive()) <span class="tag">Turned off</span>@endunless</strong><span class="muted">{{ $row['status']->value !== 'accepted' ? ($row['status']->value === 'waiting' ? 'Invitation waiting' : $row['status']->label()) : ($row['seen'] === 'Never' ? 'Never seen' : 'Seen '.lcfirst($row['seen'])) }}</span></div>
<div>{{ $row['roles'] === [] ? 'Volunteer' : implode(', ', $row['roles']) }}</div>
<div class="muted">{{ $row['person']->email }}</div>
<div class="muted">@if ($row['sent'] && in_array($row['status']->value, ['waiting', 'ran_out'], true))Sent {{ $row['sent'] }}@else{{ $row['status']->label() }}@endif</div>
</div>
@endforeach
</div>

<p class="muted">Last seen is the last time someone opened SSVS. People who stay logged in on their phone may rarely log in, so last seen is the better sign of who's active.</p>
@endif

@if ($people->hasPages())
<nav class="people-pager" aria-label="Pages">
@if ($people->previousPageUrl())<a class="btn" href="{{ $people->previousPageUrl() }}" rel="prev">Previous</a>@endif
@if ($people->hasMorePages())<a class="btn" href="{{ $people->nextPageUrl() }}" rel="next">Next</a>@endif
</nav>
@endif
@endsection
