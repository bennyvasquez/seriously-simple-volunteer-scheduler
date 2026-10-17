@extends('layouts.app')

@section('title', 'Secure your account')
@section('header', 'Secure your account')

@section('content')
<div class="steps">
<div class="step">Step {{ $step }} of 2</div>
<div class="progress" aria-hidden="true"><span class="on"></span><span @class(['on' => $step === 2])></span></div>
</div>
@if ($step === 1)
<h1>Choose a password</h1>
<p>{{ $roleSentence }}, you can see volunteers' email addresses and phone numbers. To keep those safe, your account needs a password and a second step when you log in. This takes about two minutes, and you only do it once.</p>
<form method="POST" action="{{ route('account.password.set') }}">
@csrf
<x-field name="password" type="password" label="New password" autocomplete="new-password" hint="At least 10 characters. A few unrelated words, like a short phrase, are easy to remember and hard to guess." />
<x-field name="password_confirmation" type="password" label="Type it again" autocomplete="new-password" />
<button type="submit" class="btn btn-primary">Save and continue</button>
</form>
<p class="muted">You'll see this until both steps are done. Your shifts and the schedule are waiting for you after that.</p>
@else
<h1>Connect an authenticator app</h1>
@if ($setupKey === null)
<p>An authenticator app on your phone shows a new 6-digit code every 30 seconds. You'll type that code after your password when you log in.</p>
<form method="POST" action="{{ route('two-factor.enable') }}">
@csrf
<button type="submit" class="btn btn-primary">Set up the app</button>
</form>
@else
@php $codeError = $errors->confirmTwoFactorAuthentication->first('code'); @endphp
<p>An authenticator app on your phone shows a new 6-digit code every 30 seconds. You'll type that code after your password when you log in.</p>
<ol class="steps-list">
<li>Open an authenticator app, such as Google Authenticator, Microsoft Authenticator or 1Password. Any of them works.</li>
<li>Add a new account in the app and scan this code.</li>
</ol>
<div class="qr" role="img" aria-label="QR code to scan">{!! $user->twoFactorQrCodeSvg() !!}</div>
<p class="muted centered">Can't scan it? Type this key into the app instead:<br><strong class="mono">{{ $setupKey }}</strong></p>
<form method="POST" action="{{ route('two-factor.confirm') }}">
@csrf
<div class="field">
<label for="code">3. Type the 6-digit code the app shows</label>
<input type="text" name="code" id="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456"@if ($codeError) aria-describedby="code-error" aria-invalid="true"@endif>
@if ($codeError)<span class="field-error" id="code-error">{{ $codeError }}</span>@endif
</div>
<button type="submit" class="btn btn-primary">Connect the app</button>
</form>
@endif
@endif
<x-log-out />
@endsection
