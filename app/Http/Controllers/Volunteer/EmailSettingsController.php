<?php

namespace App\Http\Controllers\Volunteer;

use App\Actions\Accounts\UpdateEmailSettings;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The Emails page (decision 5): the one email setting a volunteer can change. */
class EmailSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        return view('volunteer.emails', ['user' => $request->user(), 'status' => session('status')]);
    }

    public function update(Request $request, UpdateEmailSettings $updateEmailSettings): RedirectResponse
    {
        // A checkbox that isn't ticked isn't sent, so a missing value means off.
        $updateEmailSettings->handle($request->user(), $request->boolean('cover_request_emails'));

        return redirect()->route('account.emails')->with('status', 'Saved.');
    }
}
