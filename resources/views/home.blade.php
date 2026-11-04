@extends('layouts.app')

@section('title', 'Home')

@section('content')
<h1>Hi {{ $user->firstName() }}</h1>
<p>Your shifts will show here soon.</p>
@if ($user->canViewCalendar())
<a class="btn" href="{{ route('calendar.week') }}">Calendar</a>
@endif
@can('viewAny', App\Models\User::class)
<a class="btn" href="{{ route('people.index') }}">People</a>
@endcan
@if ($user->isCoordinator())
<a class="btn" href="{{ url('/change-log') }}">Change log</a>
@endif
<a class="btn" href="{{ route('account.show') }}">Login and security</a>
<x-log-out />
@endsection
