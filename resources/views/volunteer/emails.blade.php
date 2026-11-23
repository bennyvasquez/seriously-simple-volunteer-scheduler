@extends('layouts.app')

@section('title', 'Emails')
@section('header', 'Emails')

@section('content')
@if ($status)
<p role="status">{{ $status }}</p>
@endif
<p><a href="{{ route('home') }}">Back to home</a></p>

<form class="panel" method="POST" action="{{ route('account.emails') }}">
@csrf
<h2>Cover requests</h2>
<label class="check"><input type="checkbox" name="cover_request_emails" value="1" @checked($user->cover_request_emails)><span>Email me when someone can't make a shift I could take<br><span class="muted">Usually one or two emails each time a shift needs covering.</span></span></label>
<button type="submit" class="btn btn-primary">Save</button>
</form>

<section class="panel">
<h2>Always sent</h2>
<p>Emails about your own shifts: when you're put on one, when one changes or is cancelled, and when someone covers for you.</p>
</section>
@endsection
