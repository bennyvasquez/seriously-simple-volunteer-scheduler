<?php

namespace App\Http\Controllers;

use App\Actions\UpdateSettings;
use App\Http\Controllers\Calendar\KeepsAreaFilter;
use App\Models\Organization;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Organization settings (screen 38): name, time zone and weeks ahead. Admins only.
 */
class SettingsController extends Controller
{
    use KeepsAreaFilter;

    public function edit(Request $request): View
    {
        Gate::forUser($request->user())->authorize('manage-settings');

        $organization = Organization::current();

        return view('settings', [
            'organization' => $organization,
            'timezones' => DateTimeZone::listIdentifiers(),
            'areaQuery' => $this->areaQuery($request),
            'backDate' => $organization->today()->toDateString(),
        ]);
    }

    public function update(Request $request, UpdateSettings $updateSettings): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('manage-settings');

        $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'weeks_ahead' => ['required', 'integer', 'between:1,26'],
        ], [
            'name.required' => 'Give the organization a name.',
            'name.max' => 'Keep the name to 150 characters or fewer.',
            'name.*' => 'Give the organization a name.',
            'timezone.*' => 'Pick a time zone from the list.',
            'weeks_ahead.*' => 'Choose a number of weeks from 1 to 26.',
        ]);

        $updateSettings->handle($request->user(), (string) $request->input('name'), (string) $request->input('timezone'), (int) $request->input('weeks_ahead'));

        return redirect()->route('settings', $this->areaQuery($request))->with('status', 'Saved the settings.');
    }
}
