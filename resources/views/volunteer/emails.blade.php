@extends('layouts.app')

@section('title', 'Emails')
@section('header', 'Emails')

@section('content')
<h1>Emails</h1>
@if ($status)
<p role="status">{{ $status }}</p>
@endif
<p><a href="{{ route('home') }}">Back to home</a></p>

<form method="POST" action="{{ route('account.emails') }}">
@csrf
<section class="panel">
<h2>Cover requests</h2>
<label class="check"><input type="checkbox" name="cover_request_emails" value="1" @checked($user->cover_request_emails)><span>Email me when someone can't make a shift I could take<br><span class="muted">Usually one or two emails each time a shift needs covering.</span></span></label>
</section>
<section class="panel">
<h2>Reminders</h2>
<label class="check"><input type="checkbox" name="reminder_emails" value="1" @checked($user->reminder_emails)><span>Remind me before my shifts<br><span class="muted">One email before each shift you're on.</span></span></label>
</section>
<button type="submit" class="btn btn-primary">Save</button>
</form>

<section class="panel">
<h2>Always sent</h2>
<p>Emails about your own shifts: when you're put on one, when one changes or is cancelled, and when someone covers for you.</p>
</section>
@endsection
