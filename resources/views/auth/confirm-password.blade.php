@extends('layouts.app')

@section('title', 'Confirm your password')

@section('content')
<h1>Confirm it's you</h1>
<p>Type your password to keep going. We ask for this before changes to how you log in.</p>
<form method="POST" action="{{ route('password.confirm') }}">
@csrf
<x-field name="password" type="password" label="Password" autocomplete="current-password" />
<button type="submit" class="btn btn-primary">Confirm</button>
</form>
@endsection
