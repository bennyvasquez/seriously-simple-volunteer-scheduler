{{-- Screen 27: one person. Area leads see Roles and Login only; coordinators and admins also get the controls and the changes about the person. --}}
@extends('layouts.app')

@section('title', $person->name)
@section('main-class', 'person-page')

@section('content')
<nav aria-label="Breadcrumb" class="muted"><a href="{{ route('people.index') }}">People</a> / {{ $firstName }}</nav>

@if (session('status'))
<p role="status" class="cal-status">{{ session('status') }}</p>
@endif
@foreach (['invite', 'role'] as $key)
@error($key)<p class="field-error" role="alert">{{ $message }}</p>@enderror
@endforeach

<div>
<h1>{{ $person->name }}@unless ($person->isActive()) <span class="tag">Turned off</span>@endunless</h1>
<div class="muted">{{ $person->email }}. @if ($joined)Joined {{ $joined }}.@else{{ $status->label() }}.@endif</div>
</div>

<div class="person-columns">
<div class="person-column">
<section class="panel" aria-labelledby="roles-heading">
<h2 id="roles-heading">Roles</h2>
<div class="row"><span>Volunteer</span><span class="muted">Everyone has this</span></div>
@foreach ($heldRoles as $held)
<div class="row">
<span>{{ $held->role->value === 'area_lead' ? ($held->area ? $held->area->leadTitle().': '.$held->area->name : 'Area lead') : ucfirst($held->role->value) }}</span>
@if ($canManage && auth()->user()->canGrant($held->role))
<form method="POST" action="{{ route('people.roles.destroy', $person) }}">
@csrf
<input type="hidden" name="role" value="{{ $held->role->value }}">
@if ($held->area_id)<input type="hidden" name="area" value="{{ $held->area_id }}">@endif
<button type="submit" class="btn">Remove</button>
</form>
@endif
</div>
@endforeach
@if ($canManage && $addable !== [])
<form method="POST" action="{{ route('people.roles.store', $person) }}" class="person-form">
@csrf
<label class="field">Add a role<select name="role">
@foreach ($addable as $value => $label)
<option value="{{ $value }}">{{ $label }}</option>
@endforeach
</select></label>
<button type="submit" class="btn">Add</button>
</form>
<p class="muted">Area leads and up need a password and an authenticator app. {{ $firstName }} will be asked to set them up the next time they log in.</p>
@endif
</section>

<section class="panel" aria-labelledby="login-heading">
<h2 id="login-heading">Login</h2>
<div class="row"><span>Invitation</span><span class="muted">{{ $status->label() }}@if ($joined) {{ $joined }}@endif</span></div>
<div class="row"><span>Last login</span><span class="muted">{{ $lastLogin }}</span></div>
<div class="row"><span>Last seen</span><span class="muted">{{ $lastSeen }}</span></div>
<div class="row"><span>Logs in with</span><span class="muted">{{ $logsInWith }}</span></div>
@if ($isAdminViewer)
<a href="{{ route('people.security', $person) }}" class="muted">Login and security</a>
@endif
@if ($canManage && $person->isActive() && $status !== \App\Enums\InvitationStatus::Accepted)
<form method="POST" action="{{ route('people.invite.send', $person) }}">
@csrf
@if ($status === \App\Enums\InvitationStatus::NotInvited)
<button type="submit" class="btn btn-primary">Invite {{ $firstName }}</button>
@else
<p class="muted">Sending a new one makes the old link stop working.</p>
<button type="submit" class="btn">Send a new invitation</button>
@endif
</form>
@endif
</section>

@if ($canManage && $person->isActive())
<section class="panel" aria-labelledby="turn-off-heading">
<h2 id="turn-off-heading">Turn off this account</h2>
<p class="muted">{{ $firstName }} is logged out everywhere and can't log in. Their history stays. Turning off doesn't take them off future shifts yet, so check the calendar for their shifts.</p>
<form method="POST" action="{{ route('people.turn-off', $person) }}">
@csrf
<button type="submit" class="btn">Turn off {{ $firstName }}'s account</button>
</form>
</section>
@endif
</div>

@if ($entries !== null)
<section class="panel person-column" aria-labelledby="changes-heading">
<div class="person-panel-head">
<h2 id="changes-heading">Changes about {{ $firstName }}</h2>
<a href="{{ $changeLogUrl }}" class="muted">See all in the change log</a>
</div>
@forelse ($entries as $entry)
<div class="entry">
<span>{{ $entry['sentence'] }}</span>
@if ($entry['changes'])<span class="muted">{{ $entry['changes'] }}</span>@endif
<span class="muted">{{ $entry['by'] }}, {{ $entry['at'] }}</span>
</div>
@empty
<p class="muted">Nothing has changed yet.</p>
@endforelse
@if ($changesPager->hasMorePages())<a class="btn" href="{{ $changesPager->nextPageUrl() }}" rel="next">Show older changes</a>@endif
@if ($changesPager->previousPageUrl())<a class="btn" href="{{ $changesPager->previousPageUrl() }}" rel="prev">Show newer changes</a>@endif
</section>
@endif
</div>
@endsection
