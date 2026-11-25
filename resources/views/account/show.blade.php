@extends('layouts.app')

@section('title', 'Login and security')
@section('header', 'Login and security')

@section('content')
<h1>Login and security</h1>
@if ($status)
<p role="status">{{ $status }}</p>
@endif
<p><a href="{{ route('home') }}">Back to home</a></p>

<section class="panel">
<h2>How you log in</h2>
<div>{{ $hasPassword ? 'With your password, or with a link we email to' : 'With a link we email to' }} {{ $user->email }}</div>
<a class="btn" href="{{ route('account.email.edit') }}">Change my email address</a>
<div class="muted">We'll ask you to confirm it's you, then email a link to the new address. Your current address keeps working until you open that link.</div>
</section>

@if (! $hasPassword)
<section class="panel">
<h2>Add a password (optional)</h2>
<p class="muted">If you'd rather type a password than wait for an email, add one here. You can still use email links whenever you like.</p>
@if ($canAddPassword)
<form method="POST" action="{{ route('account.password.set') }}">
@csrf
<x-field name="password" type="password" label="New password" autocomplete="new-password" hint="At least 10 characters." />
<x-field name="password_confirmation" type="password" label="Type it again" autocomplete="new-password" />
<button type="submit" class="btn btn-primary">Save password</button>
</form>
@else
@error('password')
<p class="field-error" role="alert">{{ $message }}</p>
@enderror
<p>To keep your account safe, we only add a password within {{ \App\Http\Controllers\Auth\LoginLinkController::RECENT_MINUTES }} minutes of you signing in with an emailed link. Log out, ask for a new link on the login page, then come back to Login and security after you open it.</p>
<form method="POST" action="{{ route('logout') }}">
@csrf
<button type="submit" class="btn">Log out to get a new link</button>
</form>
@endif
</section>
@else
<section class="panel">
<h2>{{ $isStaff ? 'Password and second step' : 'Change my password' }}</h2>
@if ($isStaff)
<div>Password: set. Authenticator app: connected.</div>
<h3>Change my password</h3>
@endif
<form method="POST" action="{{ route('user-password.update') }}">
@csrf
@method('PUT')
<x-field name="current_password" type="password" label="Current password" autocomplete="current-password" bag="updatePassword" />
<x-field name="password" type="password" label="New password" autocomplete="new-password" hint="At least 10 characters." bag="updatePassword" />
<x-field name="password_confirmation" type="password" label="Type it again" autocomplete="new-password" bag="updatePassword" />
<button type="submit" class="btn btn-primary">Change my password</button>
</form>
@if ($isStaff)
<form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}">
@csrf
<button type="submit" class="btn">Make new recovery codes</button>
</form>
<p class="muted">New codes replace your old ones, which stop working right away.</p>
@endif
</section>
@endif

<section class="panel">
<h2>Emails</h2>
<div class="muted">Choose whether you get an email when someone can't make a shift you could take.</div>
<a class="btn" href="{{ route('account.emails') }}">Emails</a>
</section>

<section class="panel">
<h2>Signed in somewhere you shouldn't be?</h2>
<p class="muted">This logs you out on every phone and computer, including this one.</p>
<form method="POST" action="{{ route('account.sign-out-everywhere') }}">
@csrf
<button type="submit" class="btn">Sign out everywhere</button>
</form>
</section>

<x-log-out />
@endsection
