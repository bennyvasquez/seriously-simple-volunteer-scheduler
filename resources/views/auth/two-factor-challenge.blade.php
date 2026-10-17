@extends('layouts.app')

@if ($mode === 'recovery')
@section('title', 'Use a recovery code')

@section('content')
<h1>Use a recovery code</h1>
<p>Type one of the recovery codes you saved when you set up your second step. Each code works once.</p>
<form method="POST" action="{{ route('two-factor.login.store') }}">
@csrf
<x-field name="recovery_code" label="Recovery code" autocomplete="one-time-code" />
<button type="submit" class="btn btn-primary">Log in</button>
</form>
<a href="{{ route('two-factor.login') }}">Use my authenticator app instead</a>
<a class="muted" href="{{ route('login') }}">Cancel and go back to log in</a>
@endsection
@elseif ($mode === 'email')
@section('title', 'Emailed code')

@section('content')
<h1>Check your email for a code</h1>
<p>We emailed a 6-digit code to {{ $pendingUser->email }}. It works for 10 minutes.</p>
<form method="POST" action="{{ route('two-factor.email.verify') }}">
@csrf
<x-field name="code" label="6-digit code from the email" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" />
<button type="submit" class="btn btn-primary">Log in</button>
<button type="submit" class="btn" formaction="{{ route('two-factor.email.send') }}" formnovalidate>Send a new code</button>
</form>
<a href="{{ route('two-factor.login') }}">Use my authenticator app instead</a>
@endsection
@else
@section('title', 'Enter your code')

@section('content')
<h1>Enter your code</h1>
@if ($errors->has('email_code'))
<p role="alert">{{ $errors->first('email_code') }}</p>
@endif
<p>Open the authenticator app on your phone and type the 6-digit code it shows for {{ \App\Models\Organization::query()->value('name') ?? config('app.name') }}.</p>
<form method="POST" action="{{ route('two-factor.login.store') }}">
@csrf
<x-field name="code" label="6-digit code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" />
<button type="submit" class="btn btn-primary">Log in</button>
</form>
@if ($justReset)
<div class="panel">
<h2>Use your app or a recovery code this time</h2>
<p>You just reset your password through your email, so an emailed code can't be your second step. Once you've logged in with your app or a recovery code, emailed codes work again.</p>
<a class="btn" href="{{ route('two-factor.login', ['recovery' => 1]) }}">Use a recovery code</a>
</div>
@else
<div class="panel">
<h2>Don't have your phone?</h2>
<a class="btn" href="{{ route('two-factor.login', ['recovery' => 1]) }}">Use a recovery code</a>
@if ($canEmailCode)
<form method="POST" action="{{ route('two-factor.email.send') }}">
@csrf
<button type="submit" class="btn">Email me a code instead</button>
</form>
@endif
<p class="muted">Lost your phone and your recovery codes? Ask an admin to help you get back in.</p>
</div>
@endif
<a class="muted" href="{{ route('login') }}">Cancel and go back to log in</a>
@endsection
@endif
