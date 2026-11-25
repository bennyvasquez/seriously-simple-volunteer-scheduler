{{-- One line of an email in the editor (wireframe 62): its label, when it shows, the box, and for an edited line the default and a "Back to the default" button. That button is part of the main form and sends it to the reset route as a DELETE, so the other boxes keep what was typed. The hidden input carries the text as the page loaded it, so a save can tell whether someone else changed the line meanwhile. --}}
@php
$error = $errors->first('lines.'.$line['key']);
$describedBy = trim(($line['when'] ? $line['id'].'-when ' : '').($line['edited'] ? $line['id'].'-default ' : '').($error ? $line['id'].'-error' : ''));
@endphp
<div class="panel email-line @if ($line['edited']) email-line-edited @endif">
<div class="field">
<label for="{{ $line['id'] }}">{{ $line['label'] }}</label>
@if ($line['when'])<span class="field-hint" id="{{ $line['id'] }}-when">{{ $line['when'] }}</span>@endif
@if ($line['long'])
<textarea id="{{ $line['id'] }}" name="lines[{{ $line['key'] }}]" rows="{{ min(6, max(3, (int) ceil(mb_strlen($line['value']) / 35))) }}"@if ($describedBy) aria-describedby="{{ $describedBy }}"@endif @if ($error) aria-invalid="true"@endif>{{ $line['value'] }}</textarea>
@else
<input type="text" id="{{ $line['id'] }}" name="lines[{{ $line['key'] }}]" value="{{ $line['value'] }}"@if ($describedBy) aria-describedby="{{ $describedBy }}"@endif @if ($error) aria-invalid="true"@endif>
@endif
@if ($error)<span class="field-error" id="{{ $line['id'] }}-error">{{ $error }}</span>@endif
@if ($line['edited'])<span class="field-hint" id="{{ $line['id'] }}-default">Default: “{{ $line['default'] }}”</span>@endif
</div>
<input type="hidden" name="loaded[{{ $line['key'] }}]" value="{{ $line['loaded'] }}">
@if ($line['edited'])<button type="submit" formaction="{{ route('emails.reset', [$email->key, $line['key']]) }}" name="_method" value="DELETE" class="link">Back to the default<span class="vh"> for {{ $line['label'] }}</span></button>@endif
</div>
