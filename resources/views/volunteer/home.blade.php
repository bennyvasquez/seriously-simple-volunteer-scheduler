@extends('layouts.app')

@section('title', 'Home')

@section('content')
@if (session('status'))
<p role="status">{{ session('status') }}</p>
@endif
<h1>{{ trim('Hi '.$user->firstName()) }}</h1>

@foreach ($notices as $notice)
@include('volunteer._change-ask', ['notice' => $notice])
@endforeach

@if ($user->isCleared())
<section class="shift-section">
<h2>Your next shifts</h2>
@forelse ($next as $shift)
@include('volunteer._shift-card', ['shift' => $shift, 'action' => 'release', 'tag' => $shift->card_tag])
@empty
<p class="muted">You're not on any shifts in the next two weeks.</p>
@endforelse
<a class="btn" href="{{ route('my-shifts') }}">All my shifts</a>
</section>

@include('volunteer._open-shifts')
@else
@include('volunteer._shadow-home')
@endif

<section class="shift-section">
<h2>Your account</h2>
@can('viewAny', App\Models\User::class)
<a class="btn" href="{{ route('people.index') }}">People</a>
@endcan
@can('view-change-log')
<a class="btn" href="{{ route('change-log.index') }}">Change log</a>
@endcan
@can('manage-emails')
<a class="btn" href="{{ route('emails.index') }}">Emails we send</a>
@endcan
<a class="btn" href="{{ route('account.emails') }}">Emails</a>
<a class="btn" href="{{ route('account.show') }}">Login and security</a>
<x-log-out />
</section>
@endsection
