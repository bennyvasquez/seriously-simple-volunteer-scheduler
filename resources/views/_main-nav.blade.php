{{-- The Home, Schedule and Me menu on every signed-in page. It's left out for anyone who can't use those pages yet: not signed in, turned off, or still setting up the password and two-factor that their role needs (the same test as EnsureAccountIsSecure). --}}
@php
$navUser = auth()->user();
$showNav = $navUser !== null
    && $navUser->isActive()
    && (! $navUser->requiresTwoFactor() || ($navUser->password !== null && $navUser->hasEnabledTwoFactorAuthentication()));
@endphp
@if ($showNav)
<nav aria-label="Main" class="main-nav">
<a href="{{ route('home') }}" @if (request()->routeIs('home')) aria-current="page" @endif>Home</a>
<a href="{{ route('calendar.week') }}" @if (request()->routeIs('calendar.*')) aria-current="page" @endif>Schedule</a>
<a href="{{ route('account.show') }}" @if (request()->routeIs('account.*')) aria-current="page" @endif>Me</a>
</nav>
@endif
