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
{{-- The reset screens are for logged-out people, so this logs out first instead of linking straight there. --}}
<form method="POST" action="{{ route('account.forgot-password') }}">
@csrf
<button type="submit" class="link" aria-describedby="forgot-logs-out">Forgot your password?</button>
<p class="muted" id="forgot-logs-out">This logs you out so you can reset it by email.</p>
</form>
<p><a href="{{ route('account.show') }}">Back to login and security</a></p>
@endsection
