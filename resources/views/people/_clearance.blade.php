{{-- Screen 9 inside the person page: clearance status, shadow progress and history, and for coordinators the "Clear for regular shifts" form or, for someone cleared without a role, a link to "Move back to shadowing" (screen 10). $clearance comes from PersonController::clearance(). --}}
<section class="panel" aria-labelledby="clearance-heading">
<h2 id="clearance-heading">Shadowing and clearance</h2>
{{-- Above the form, so a refusal still shows when the form is gone (someone else cleared them first). --}}
@error('clear')<p class="field-error" id="clear-error" role="alert">{{ $message }}</p>@enderror
@if ($clearance['clearedSince'])
<p><strong>Cleared for regular shifts since {{ $clearance['clearedSince'] }}</strong></p>
@if ($clearance['canMoveBack'])
<p><a href="{{ route('people.move-back', $person) }}" class="btn">Move back to shadowing</a></p>
@endif
@else
<div class="progress">
<p><strong>{{ $clearance['ready'] ? 'Ready for review: '.$clearance['done'].' of '.$clearance['required'].' shadow shifts done' : 'Shadow shifts done: '.$clearance['done'].' of '.$clearance['required'] }}</strong></p>
<div class="progress-bar" aria-hidden="true">
@for ($i = 0; $i < $clearance['required']; $i++)
<span @class(['done' => $i < $clearance['done']])></span>
@endfor
</div>
<p class="muted">Required shadow shifts: {{ $clearance['required'] }}. Coordinators can change this in Settings.</p>
</div>
@endif

@if ($clearance['history']->isNotEmpty() || ! $clearance['clearedSince'])
<h3>Shadow history</h3>
@forelse ($clearance['history'] as $row)
<div class="row"><span>{{ $row['text'] }}</span> <span class="muted">{{ $row['status'] }}</span></div>
@empty
<p class="muted">No shadow shifts yet.</p>
@endforelse
@endif

@if ($clearance['canClear'])
<form method="POST" action="{{ route('people.clear', $person) }}" class="add-panel">
@csrf
<div class="field">
<label for="note">Note for the change log (optional)</label>
<textarea name="note" id="note" rows="2" maxlength="500" aria-describedby="note-hint{{ $errors->has('clear') ? ' clear-error' : '' }}">{{ old('note') }}</textarea>
<span class="field-hint" id="note-hint">Only coordinators and admins see it. Up to 500 characters.</span>
</div>
<button type="submit" class="btn btn-primary">Clear for regular shifts</button>
</form>
@endif
</section>
