{{-- Wireframe 63: the preview of one version of the email, with the "Show it as" choice. The radios and the button belong to the main form (the form attribute), so choosing a version and pressing the button previews it with whatever is typed in the boxes, with no JavaScript. Each block's HTML comes from App\Emails\EmailPreview, which escapes it the way the real email does. --}}
<section class="panel email-preview @unless ($previewing) email-preview-idle @endunless" aria-labelledby="email-preview-heading">
<h2 id="email-preview-heading">Preview</h2>
@if (count($email->versions) > 1)
<fieldset class="choices">
<legend class="muted">Show it as it would reach:</legend>
@foreach (array_keys($email->versions) as $name)
<label class="check"><input type="radio" name="version" value="{{ $name }}" form="email-form" @checked($name === $version)>{{ $name }}</label>
@endforeach
</fieldset>
<button type="submit" form="email-form" formaction="{{ route('emails.preview', $email->key) }}" class="btn email-version-button">Show this version</button>
@endif
@if ($preview)
<div class="mail" role="group" aria-label="Email preview">
@if ($preview['subject'] !== null)<p class="mail-subject"><span class="vh">Subject: </span>{{ $preview['subject'] }}</p>@endif
@foreach ($preview['blocks'] as $block)
@if ($block['kind'] === 'greeting')
<p class="mail-greeting">{!! $block['html'] !!}</p>
@elseif ($block['kind'] === 'button')
<p><span class="mail-button">{!! $block['html'] !!}</span></p>
@else
<div class="mail-line">{!! $block['html'] !!}</div>
@endif
@endforeach
</div>
<p class="muted">{{ $previewing ? 'This preview uses your unsaved changes and example people. Nothing is sent.' : 'This preview uses the saved wording and example people. Nothing is sent.' }}</p>
@else
<p>Fix the lines marked below to see the preview.</p>
@endif
</section>
