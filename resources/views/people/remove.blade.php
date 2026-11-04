{{-- Removing someone, after they asked to be removed. Typing their first name makes sure the right person goes, because it can't be undone. --}}
@extends('layouts.app')

@section('title', 'Remove '.$firstName)

@section('content')
<nav aria-label="Breadcrumb" class="muted"><a href="{{ route('people.index') }}">People</a> / <a href="{{ route('people.show', $person) }}">{{ $firstName }}</a> / Remove</nav>
<h1>Remove {{ $person->name }}</h1>
<p>Removing someone deletes their name and email address from SSVS. Shifts they worked stay counted, under Removed person, and so do their entries in the change log. This can't be undone.</p>

@error('remove')
<p class="field-error" role="alert">{{ $message }}</p>
@else
@if ($refusal)
<p class="field-error" role="alert">{{ $refusal }}</p>
@endif
@enderror

<form method="POST" action="{{ route('people.remove.store', $person) }}">
@csrf
<x-field name="confirm" :label="'Type '.$firstName.' to confirm'" autocomplete="off" required />
<button type="submit" class="btn btn-primary">Remove {{ $firstName }} for good</button>
<a href="{{ route('people.show', $person) }}" class="btn">Cancel</a>
</form>
@endsection
