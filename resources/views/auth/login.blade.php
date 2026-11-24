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
@php $intended = (string) parse_url((string) session('url.intended'), PHP_URL_PATH); @endphp
@if (preg_match('#^/shifts/\d+/take$#', $intended))
<p role="status">Log in to take this shift. Once you're in, we'll take you straight back to it.</p>
@elseif (preg_match('#^/changes/[0-9A-Za-z]+$#', $intended))
<p role="status">Log in to answer about your shift. Once you're in, we'll take you straight back to it.</p>
@endif
@if (session('status'))
<p role="status">{{ session('status') }}</p>
@endif
<p>Enter your email address and we'll send you a link to log in. You don't need a password.</p>
<form method="POST" action="{{ route('login-link.request') }}" id="login-form">
@csrf
<x-field name="email" type="email" label="Email address" autocomplete="email" :value="old('email')" />
<button type="submit" class="btn btn-primary" id="send-link">Email me a link</button>
<p class="divider">or</p>
<div class="panel">
<h2>Log in with a password</h2>
<p class="muted">Anyone can add a password from their profile. Area leads, coordinators and admins always use one.</p>
<x-field name="password" type="password" label="Password" autocomplete="current-password" />
<label class="check"><input type="checkbox" name="remember" value="1"> Keep me logged in on this device</label>
<button type="submit" class="btn" id="password-login" formaction="{{ route('login') }}">Log in with my password</button>
<a href="{{ route('password.request') }}">Forgot your password?</a>
</div>
</form>
@endsection

@push('scripts')
<script>
(() => {
    // Both buttons submit this one form, so the browser sends every field. When it's "Email me a link", leave a typed password out of the request. Without JavaScript the link request ignores it anyway.
    const form = document.getElementById('login-form');
    form.addEventListener('submit', (event) => {
        if (event.submitter && event.submitter.id === 'send-link') {
            form.elements.password.value = '';
            form.elements.remember.checked = false;
        }
    });

    // Pressing Enter submits with the form's first button, "Email me a link". In the password box, Enter means "Log in with my password".
    form.elements.password.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && ! event.isComposing) {
            event.preventDefault();
            const passwordLogin = document.getElementById('password-login');
            form.requestSubmit ? form.requestSubmit(passwordLogin) : passwordLogin.click();
        }
    });
})();
</script>
@endpush
@endif
