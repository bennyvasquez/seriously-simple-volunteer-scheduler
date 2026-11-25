<?php

namespace App\Http\Controllers\Calendar;

use App\Actions\Scheduling\CreateArea;
use App\Actions\Scheduling\UpdateArea;
use App\Enums\AreaColor;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Organization;
use App\Rules\NoWebAddress;
use App\Support\Input;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Adding and editing areas from the calendar sidebar. The route's can:viewAny middleware lets area leads reach the calendar, and the policy refuses them here: only coordinators and admins add or edit areas. The edit page also lists the area's weekly slots that haven't ended, with links to edit each one and add a regular, so a slot with no shifts on the calendar yet can still be reached.
 */
class AreaController extends Controller
{
    use KeepsAreaFilter;

    public function create(Request $request): View
    {
        Gate::forUser($request->user())->authorize('create', Area::class);

        return view('calendar.area', [
            'area' => null,
            'slots' => collect(),
            'colors' => AreaColor::cases(),
            'areaQuery' => $this->areaQuery($request),
            'backDate' => Organization::current()->today()->toDateString(),
        ]);
    }

    public function store(Request $request, CreateArea $createArea): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('create', Area::class);

        $request->validate(['name' => ['required', 'string', 'max:100', new NoWebAddress]], [
            'name.required' => 'Give the area a name.',
            'name.max' => 'Keep the name to 100 characters or fewer.',
            'name.'.NoWebAddress::class => NoWebAddress::MESSAGE,
            'name.*' => 'Give the area a name.',
        ]);

        $area = $createArea->handle($request->user(), Input::string($request, 'name'));

        return redirect()->route('calendar.week', $this->areaQuery($request))->with('status', "Added {$area->name}.");
    }

    public function edit(Request $request, Area $area): View
    {
        Gate::forUser($request->user())->authorize('update', $area);

        $today = Organization::current()->today()->toDateString();

        return view('calendar.area', [
            'area' => $area,
            // Slots that haven't ended, so a slot with no shifts on the calendar yet can still be found.
            'slots' => $area->weeklySlots()
                ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
                ->orderBy('weekday')->orderBy('start_time')->orderBy('id')->get(),
            'today' => $today,
            'colors' => AreaColor::cases(),
            'areaQuery' => $this->areaQuery($request),
            'backDate' => Organization::current()->today()->toDateString(),
        ]);
    }

    public function update(Request $request, Area $area, UpdateArea $updateArea): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('update', $area);

        $request->validate([
            'name' => ['required', 'string', 'max:100', new NoWebAddress],
            'color' => ['required', Rule::enum(AreaColor::class)],
            'lead_name' => ['nullable', 'string', 'max:40', new NoWebAddress],
        ], [
            'name.required' => 'Give the area a name.',
            'name.max' => 'Keep the name to 100 characters or fewer.',
            'name.'.NoWebAddress::class => NoWebAddress::MESSAGE,
            'name.*' => 'Give the area a name.',
            'color.*' => 'Pick one of the colors listed.',
            'lead_name.'.NoWebAddress::class => NoWebAddress::MESSAGE,
            'lead_name.*' => 'Keep it to 40 characters or fewer.',
        ]);

        $area = $updateArea->handle($request->user(), $area, Input::string($request, 'name'), AreaColor::from(Input::string($request, 'color')), $request->input('lead_name'));

        return redirect()->route('calendar.week', $this->areaQuery($request))->with('status', "Saved {$area->name}.");
    }
}
