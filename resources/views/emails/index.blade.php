{{-- Wireframe 61: every email SSVS sends, grouped by who gets it, with "Edited" on the ones the organization has changed. --}}
@extends('layouts.app')

@section('title', 'Emails we send')
@section('main-class', 'email-page')

@section('content')
<nav aria-label="Breadcrumb" class="muted"><a href="{{ route('calendar.week') }}">Calendar</a> / Emails we send</nav>
<h1>Emails we send</h1>
<p class="muted">Every email SSVS sends, grouped by who gets it. Pick one to see when it goes out and change its wording.</p>

@foreach ($groups as $group => $emails)
<section class="panel email-group">
<h2>{{ $group }}</h2>
<ul class="email-list">
@foreach ($emails as $item)
<li><a href="{{ route('emails.edit', $item['email']->key) }}" class="email-row"><span><b>{{ $item['email']->title }}</b><br><span class="muted">{{ $item['summary'] }}</span></span>@if ($item['edited'])<span class="tag tag-strong">Edited</span>@endif</a></li>
@endforeach
</ul>
</section>
@endforeach

<p class="muted">"Edited" marks the ones your organization has changed.</p>
@endsection
