{{-- Screen 10, moving someone back to shadowing, as a page of its own rather than the wireframe's dialog, so it works without JavaScript. The keep or release choice shows only when they have upcoming regular shifts or a regular slot. A refusal (they were given a role meanwhile, say) shows above the form. --}}
@extends('layouts.app')

@section('title', 'Move '.$firstName.' back to shadowing')

@section('content')
<nav aria-label="Breadcrumb" class="muted"><a href="{{ route('people.index') }}">People</a> / <a href="{{ route('people.show', $person) }}">{{ $firstName }}</a> / Move back to shadowing</nav>
<h1>Move {{ $firstName }} back to shadowing?</h1>
<p class="muted">{{ $firstName }} won't be able to take regular shifts until a coordinator clears them again. They can still sign up for shadow spots.</p>

@error('move_back')<p class="field-error" id="move-back-error" role="alert">{{ $message }}</p>@enderror
<form method="POST" action="{{ route('people.move-back.store', $person) }}">
@csrf
@if ($hasChoice)
<fieldset class="choices options" @error('existing') aria-describedby="existing-error" @enderror>
<legend>{{ $legend }}</legend>
@error('existing')<span class="field-error" id="existing-error" role="alert">{{ $message }}</span>@enderror
<label class="opt"><input type="radio" name="existing" value="keep" @checked(old('existing') === 'keep')><span><strong>Keep them</strong><br><span class="muted">{{ $keepText }}</span></span></label>
<label class="opt"><input type="radio" name="existing" value="release" @checked(old('existing') === 'release')><span><strong>Release them</strong>@if ($releasesShifts)<br><span class="muted">They go out as cover requests right away, with the usual reminders.</span>@endif @if ($releaseRegularText)<br><span class="muted">{{ $releaseRegularText }}</span>@endif</span></label>
</fieldset>
@endif

<div class="field">
<label for="note">Note for the change log (optional)</label>
<textarea name="note" id="note" rows="2" maxlength="500" aria-describedby="note-hint">{{ old('note') }}</textarea>
<span class="field-hint" id="note-hint">Only coordinators and admins see it. Up to 500 characters.</span>
</div>

<button type="submit" class="btn btn-primary">Move back to shadowing</button>
<a href="{{ route('people.show', $person) }}" class="btn">Cancel</a>
</form>
@endsection
