{{-- Removing someone, after they asked to be removed. Typing their first name makes sure the right person goes, because it can't be undone. When they're on future shifts or are a regular, the page says those go first. If something lands on their schedule while removing them, the refusal shows above the form so they can try again. --}}
@extends('layouts.app')

@section('title', 'Remove '.$firstName)

@section('content')
<nav aria-label="Breadcrumb" class="muted"><a href="{{ route('people.index') }}">People</a> / <a href="{{ route('people.show', $person) }}">{{ $firstName }}</a> / Remove</nav>
<h1>Remove {{ $person->name }}</h1>
<p>Removing someone deletes their name, email address and membership ID from SSVS. Shifts they worked stay counted, under Removed person, and so do their entries in the change log. This can't be undone.</p>

@if ($releases['shifts'] > 0)
<p>This removes them from their future shifts first.</p>
@elseif ($releases['regulars'] > 0)
<p>This ends their time as a regular first.</p>
@endif

@error('remove')<p class="field-error" role="alert">{{ $message }}</p>@enderror
<form method="POST" action="{{ route('people.remove.store', $person) }}">
@csrf
<x-field name="confirm" :label="'Type '.$firstName.' to confirm'" autocomplete="off" required />
<button type="submit" class="btn btn-primary">Remove {{ $firstName }} for good</button>
<a href="{{ route('people.show', $person) }}" class="btn">Cancel</a>
</form>
@endsection
