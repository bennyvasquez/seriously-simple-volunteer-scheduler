<?php

namespace App\Http\Controllers\Calendar;

use Illuminate\Http\Request;

/** Calendar screens that post back to the week keep the areas[] filter the person came from. */
trait KeepsAreaFilter
{
    /** The areas[] filter the person came from, so every redirect back to the week keeps it. Empty when no filter was in use. */
    private function areaQuery(Request $request): array
    {
        $asked = $request->input('areas');

        if (! is_array($asked)) {
            return [];
        }

        $ids = array_values(array_filter(array_map('intval', array_filter($asked, fn ($id) => is_scalar($id) && ctype_digit((string) $id)))));

        return ['areas' => $ids === [] ? [''] : $ids];
    }
}
