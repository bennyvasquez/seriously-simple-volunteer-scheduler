@extends('layouts.app')

@php
    // Retry-After is in seconds when the limit sets it. Round up so we never say a wait that's too short.
    $retryAfter = $exception->getHeaders()['Retry-After'] ?? null;
    $minutes = is_numeric($retryAfter) ? max(1, (int) ceil($retryAfter / 60)) : null;
    $wait = match (true) {
        $minutes === null => 'Wait a few minutes',
        $minutes === 1 => 'Wait a minute',
        default => "Wait {$minutes} minutes",
    };
@endphp

@section('title', 'Too many tries')

@section('content')
<h1>Too many tries</h1>
<p>{{ $wait }}, then try again.</p>
<p class="muted">We limit how often this can be tried, so nobody can guess their way into an account.</p>
@endsection
