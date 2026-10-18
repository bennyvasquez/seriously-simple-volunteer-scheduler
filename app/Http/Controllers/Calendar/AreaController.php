<?php

namespace App\Http\Controllers\Calendar;

use App\Actions\Scheduling\CreateArea;
use App\Actions\Scheduling\UpdateArea;
use App\Enums\AreaColor;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Adding and editing areas from the calendar sidebar. The route's can:viewAny middleware lets area leads reach the calendar, and the policy refuses them here: only coordinators and admins add or edit areas.
 */
class AreaController extends Controller
{
    use KeepsAreaFilter;

    public function create(Request $request): View
    {
        Gate::forUser($request->user())->authorize('create', Area::class);

        return view('calendar.area', [
            'area' => null,
            'colors' => AreaColor::cases(),
            'areaQuery' => $this->areaQuery($request),
            'backDate' => Organization::current()->today()->toDateString(),
        ]);
    }

    public function store(Request $request, CreateArea $createArea): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('create', Area::class);

        $request->validate(['name' => ['required', 'string', 'max:100']], [
            'name.required' => 'Give the area a name.',
            'name.max' => 'Keep the name to 100 characters or fewer.',
            'name.*' => 'Give the area a name.',
        ]);

        $area = $createArea->handle($request->user(), (string) $request->input('name'));

        return redirect()->route('calendar.week', $this->areaQuery($request))->with('status', "Added {$area->name}.");
    }

    public function edit(Request $request, Area $area): View
    {
        Gate::forUser($request->user())->authorize('update', $area);

        return view('calendar.area', [
            'area' => $area,
            'colors' => AreaColor::cases(),
            'areaQuery' => $this->areaQuery($request),
            'backDate' => Organization::current()->today()->toDateString(),
        ]);
    }

    public function update(Request $request, Area $area, UpdateArea $updateArea): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('update', $area);

        $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'color' => ['required', Rule::enum(AreaColor::class)],
        ], [
            'name.required' => 'Give the area a name.',
            'name.max' => 'Keep the name to 100 characters or fewer.',
            'name.*' => 'Give the area a name.',
            'color.*' => 'Pick one of the colors listed.',
        ]);

        $area = $updateArea->handle($request->user(), $area, (string) $request->input('name'), AreaColor::from((string) $request->input('color')));

        return redirect()->route('calendar.week', $this->areaQuery($request))->with('status', "Saved {$area->name}.");
    }
}
