@extends('layouts.app')

@section('title', 'Home')

@section('content')
<h1>Hi {{ strtok($user->name, ' ') }}</h1>
<p>Your shifts will show here soon.</p>
@if ($user->canViewCalendar())
<a class="btn" href="{{ route('calendar.week') }}">Calendar</a>
@endif
<a class="btn" href="{{ route('account.show') }}">Login and security</a>
<x-log-out />
@endsection
