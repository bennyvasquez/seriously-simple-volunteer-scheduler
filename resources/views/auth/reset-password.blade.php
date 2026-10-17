@extends('layouts.app')

@section('title', 'Choose a new password')

@section('content')
<h1>Choose a new password</h1>
<form method="POST" action="{{ route('password.update') }}">
@csrf
<input type="hidden" name="token" value="{{ $request->route('token') }}">
<x-field name="email" type="email" label="Email address" autocomplete="email" :value="old('email', $request->query('email'))" readonly />
<x-field name="password" type="password" label="New password" autocomplete="new-password" hint="At least 10 characters. A few unrelated words, like a short phrase, are easy to remember and hard to guess." />
<x-field name="password_confirmation" type="password" label="Type it again" autocomplete="new-password" />
<button type="submit" class="btn btn-primary">Save my new password</button>
</form>
<div class="panel">
<h2>What happens next</h2>
<p class="muted">You'll log in again with your new password. We'll also email you to say your password changed, so you'd know if someone else did it.</p>
<p class="muted">Area leads, coordinators and admins: the next time you log in, use your authenticator app or a recovery code. An emailed code won't work until you have.</p>
</div>
@endsection
