@extends('layouts.app')

{{-- Every screen of an email address change. $step: identity (no password, not confirmed yet), form, identity-link and confirm-link (the button pages emailed links open), changed, expired. --}}

@if ($step === 'identity')
@section('title', 'Confirm it\'s you')

@section('content')
<h1>Confirm it's you</h1>
@if (session('status'))
<p role="status">{{ session('status') }}</p>
@endif
<p>Before you change the address you log in with, we'll email a link to {{ $user->email }} to make sure it's you.</p>
<form method="POST" action="{{ route('account.email.identity.send') }}">
@csrf
<button type="submit" class="btn btn-primary">Email me a link</button>
</form>
<p><a href="{{ route('account.show') }}">Back to login and security</a></p>
@endsection
@elseif ($step === 'form')
@section('title', 'Change my email address')

@section('content')
<h1>Change my email address</h1>
<p>We'll email a link to the new address. Your current address, {{ $user->email }}, keeps working until you open that link.</p>
<form method="POST" action="{{ route('account.email.update') }}">
@csrf
<x-field name="email" type="email" label="New email address" autocomplete="email" :value="old('email')" required />
@if ($needsCode)
<x-field name="code" label="6-digit code from your app" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" required />
@endif
<button type="submit" class="btn btn-primary">Send the link</button>
</form>
<p><a href="{{ route('account.show') }}">Back to login and security</a></p>
@endsection
@elseif ($step === 'identity-link')
@section('title', 'Confirm it\'s you')

@section('content')
<h1>Confirm it's you</h1>
<p>Press the button to keep changing your email address.</p>
<form method="POST" action="{{ route('account.email.identity', request()->route('token')) }}">
@csrf
<button type="submit" class="btn btn-primary">It's me</button>
</form>
<p class="muted">We ask you to press a button because some email programs open links by themselves to check them for safety.</p>
@endsection
@elseif ($step === 'confirm-link')
@section('title', 'Confirm your new address')

@section('content')
<h1>Confirm your new address</h1>
<p>Press the button to start logging in with {{ $newEmail }}.</p>
<form method="POST" action="{{ route('account.email.confirm', request()->route('token')) }}">
@csrf
<button type="submit" class="btn btn-primary">Use this address</button>
</form>
<p class="muted">We ask you to press a button because some email programs open links by themselves to check them for safety.</p>
@endsection
@elseif ($step === 'changed')
@section('title', 'Your email address is changed')

@section('content')
<h1>Your email address is changed</h1>
<p role="status">Your email address is now {{ $newEmail }}. Use it next time you log in.</p>
<a class="btn btn-primary" href="{{ auth()->check() ? route('account.show') : route('login') }}">{{ auth()->check() ? 'Back to login and security' : 'Log in' }}</a>
@endsection
@else
@section('title', 'This link doesn\'t work anymore')

@section('content')
<div class="panel">
<h1>This link doesn't work anymore</h1>
<p>It may have been used already, or it ran out. If you asked for more than one, only the newest works. To try again, go to login and security and choose Change my email address.</p>
<a class="btn btn-primary" href="{{ auth()->check() ? route('account.show') : route('login') }}">{{ auth()->check() ? 'Go to login and security' : 'Log in' }}</a>
</div>
@endsection
@endif
