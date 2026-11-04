{{-- Screen 26: invite someone. --}}
@extends('layouts.app')

@section('title', 'Invite someone')

@section('content')
<nav aria-label="Breadcrumb" class="muted"><a href="{{ route('people.index') }}">People</a> / Invite someone</nav>
<h1>Invite someone</h1>
<p>We'll email them a link to join. It works once, for 7 days.</p>
<form method="POST" action="{{ route('people.invite.store') }}">
@csrf
<x-field name="name" label="Their name" hint="First name is fine." :value="old('name')" autocomplete="off" required />
<x-field name="email" type="email" label="Their email address" :value="old('email')" autocomplete="off" required />
<div class="field">
<label for="starts_as">They start as</label>
<select name="starts_as" id="starts_as" @error('starts_as') aria-invalid="true" aria-describedby="starts_as-error" @enderror>
@foreach ($options as $value => $label)
<option value="{{ $value }}" @selected(old('starts_as', 'volunteer') === $value)>{{ $label }}</option>
@endforeach
</select>
@error('starts_as')<span class="field-error" id="starts_as-error">{{ $message }}</span>@enderror
</div>
<p class="muted">Coordinators and admins are added from the person's page after they join.</p>
<button type="submit" class="btn btn-primary">Send the invitation</button>
<a href="{{ route('people.index') }}" class="btn">Cancel</a>
</form>
@endsection
