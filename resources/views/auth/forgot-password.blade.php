@extends('layouts.app')

@section('title', 'Forgot your password')

@section('content')
<h1>Reset your password</h1>
@if (session('status'))
<x-state label="After pressing the button">
<h2>Check your email</h2>
<p>If {{ old('email') }} has an account, we've emailed it a link to choose a new password. The link works for 60 minutes.</p>
<p class="muted">Nothing yet? Check your spam or junk folder. You can ask again in a minute.</p>
</x-state>
<a href="{{ route('login') }}">Back to log in</a>
@else
<p>Enter your email address and we'll send you a link to choose a new password.</p>
<p class="muted">Never set a password? You don't need one. <a href="{{ route('login') }}">Log in with an email link</a> instead.</p>
<form method="POST" action="{{ route('password.email') }}">
@csrf
<x-field name="email" type="email" label="Email address" autocomplete="email" :value="old('email')" />
<button type="submit" class="btn btn-primary">Email me a reset link</button>
</form>
@endif
@endsection
