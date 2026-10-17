@extends('layouts.app')

@if (session('login_link_sent'))
@section('title', 'Check your email')

@section('content')
<h1>Check your email</h1>
<p>If {{ old('email') ?: 'that address' }} has an account, we've emailed it a link to log in. The link works once, for the next 15 minutes.</p>
<p>Open the email on any device and press the link. You can close this page.</p>
<div class="panel">
<h2>Didn't get it?</h2>
<p class="muted">It can take a minute or two to arrive. Check your spam or junk folder, and make sure you typed the address you signed up with.</p>
<form method="POST" action="{{ route('login-link.request') }}">
@csrf
<input type="hidden" name="email" value="{{ old('email') }}">
<button type="submit" class="btn">Send another link</button>
</form>
</div>
<p class="muted">Still stuck? Ask a coordinator to check your address.</p>
<a class="btn" href="{{ route('login') }}">Back to log in</a>
@endsection
@else
@section('title', 'Log in')

@section('content')
<h1>Log in</h1>
@if (session('status'))
<p role="status">{{ session('status') }}</p>
@endif
<p>Enter your email address and we'll send you a link to log in. You don't need a password.</p>
<form method="POST" action="{{ route('login-link.request') }}">
@csrf
<x-field name="email" type="email" label="Email address" autocomplete="email" :value="old('email')" />
<button type="submit" class="btn btn-primary">Email me a link</button>
<p class="muted">or</p>
<div class="panel">
<h2>Log in with a password</h2>
<p class="muted">Anyone can add a password from their profile. Area leads, coordinators and admins always use one.</p>
<x-field name="password" type="password" label="Password" autocomplete="current-password" />
<label class="check"><input type="checkbox" name="remember" value="1"> Keep me logged in on this device</label>
<button type="submit" class="btn" formaction="{{ route('login') }}">Log in with my password</button>
<a href="{{ route('password.request') }}">Forgot your password?</a>
</div>
</form>
@endsection
@endif
