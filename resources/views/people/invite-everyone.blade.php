{{-- Invite everyone who is active and not invited yet. --}}
@extends('layouts.app')

@section('title', 'Invite everyone')

@section('content')
<nav aria-label="Breadcrumb" class="muted"><a href="{{ route('people.index') }}">People</a> / Invite everyone</nav>
@if ($count === 0)
<h1>Nobody is waiting to be invited</h1>
<a href="{{ route('people.index') }}" class="btn">Back to people</a>
@else
<h1>Invite {{ $count }} {{ $count === 1 ? 'person' : 'people' }}?</h1>
<p>Each gets an email with a link to join. It works once, for 7 days.</p>
<form method="POST" action="{{ route('people.invite-everyone.store') }}">
@csrf
<button type="submit" class="btn btn-primary">Send {{ $count }} {{ $count === 1 ? 'invitation' : 'invitations' }}</button>
<a href="{{ route('people.index') }}" class="btn">Cancel</a>
</form>
@endif
@endsection
