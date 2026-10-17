@extends('layouts.app')

@if ($link === null)
@section('title', 'This link doesn\'t work anymore')

@section('content')
<div class="panel">
<h1>This link doesn't work anymore</h1>
<p>Login links work once, for 15 minutes. If you asked for more than one, only the newest works. You can get a new one in a few seconds.</p>
<a class="btn btn-primary" href="{{ route('login') }}">Email me a new link</a>
<p class="muted">If this was an invitation that ran out, ask whoever invited you to send a new one.</p>
</div>
@endsection
@elseif ($link->purpose === \App\Enums\LoginLinkPurpose::Invitation)
@section('title', 'Welcome')

@section('content')
<h1>Welcome to {{ \App\Models\Organization::query()->value('name') ?? config('app.name') }}</h1>
<p>{{ $inviter ? $inviter.' invited you' : 'You\'ve been invited' }} to help with the makerspace's volunteer schedule. Here you'll see your shifts, swap ones you can't make and pick up open ones.</p>
<form method="POST" action="{{ route('login-link.consume', request()->route('token')) }}">
@csrf
<button type="submit" class="btn btn-primary">Get started</button>
</form>
<p class="muted">Next time, log in with your email address and we'll send you a link. You don't need a password, but you can add one from your profile if you'd rather use one.</p>
@endsection
@else
@section('title', 'Log in')

@section('content')
<h1>Log in to {{ \App\Models\Organization::query()->value('name') ?? config('app.name') }}</h1>
<p>Press the button to finish logging in.</p>
<form method="POST" action="{{ route('login-link.consume', request()->route('token')) }}">
@csrf
<label class="check"><input type="checkbox" name="remember" value="1"> Keep me logged in on this device</label>
<button type="submit" class="btn btn-primary">Log me in</button>
</form>
<p class="muted">We ask you to press a button because some email programs open links by themselves to check them for safety.</p>
@endsection
@endif
