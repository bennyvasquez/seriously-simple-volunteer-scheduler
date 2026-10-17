@extends('layouts.app')

@section('title', $person->name.': login and security')
@section('header', 'Login and security')

@php
    $when = fn ($time) => $time ? $time->setTimezone($timezone)->format('M j, g:ia') : 'Never';
@endphp

@section('content')
@if (session('status'))
<p role="status">{{ session('status') }}</p>
@endif

<section class="panel">
<h1>{{ $person->name }}</h1>
<div class="muted">{{ $person->email }}@if ($roles). {{ implode(', ', $roles) }}@endif</div>
</section>

<section class="panel">
<h2>Login and security</h2>
<div class="row"><span>Last login</span><span>{{ $when($person->last_login_at) }}</span></div>
<div class="row"><span>Last seen</span><span>{{ $when($person->last_seen_at) }}</span></div>
<div class="row"><span>Password</span><span>{{ $person->password !== null ? 'Set' : 'Not set' }}</span></div>
<div class="row"><span>Authenticator app</span><span>{{ $person->hasEnabledTwoFactorAuthentication() ? 'Connected' : 'Not connected' }}</span></div>
</section>

@if ($person->hasEnabledTwoFactorAuthentication())
@if ($isSelf)
<p class="muted">Ask another admin to reset your own two-factor.</p>
@else
<section class="panel">
<h2>Reset {{ $firstName }}'s two-factor?</h2>
<p>Use this when {{ $firstName }} has lost both their phone and their recovery codes.</p>
<ul>
<li>Their authenticator app and recovery codes stop working right away.</li>
<li>They're logged out everywhere.</li>
<li>Next time they log in with their password, they set up a new app and get new codes.</li>
<li>We email {{ $firstName }} to say it happened, and it goes in the change log.</li>
</ul>
<form method="POST" action="{{ route('people.two-factor.reset', $person) }}">
@csrf
<div class="field">
<label class="check" for="identity_checked">
<input type="checkbox" name="identity_checked" id="identity_checked" value="1" @if ($errors->has('identity_checked')) aria-invalid="true" aria-describedby="identity_checked-error"@endif>
<span>I've checked it's really {{ $firstName }} asking, in person or by phone, not only by email or chat.</span>
</label>
@if ($errors->has('identity_checked'))<span class="field-error" id="identity_checked-error">{{ $errors->first('identity_checked') }}</span>@endif
</div>
<button type="submit" class="btn btn-primary">Reset two-factor</button>
</form>
<a class="btn" href="{{ route('home') }}">Cancel</a>
</section>
@endif
@endif
@endsection
