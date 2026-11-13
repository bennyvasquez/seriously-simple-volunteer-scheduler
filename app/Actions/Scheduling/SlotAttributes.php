<?php

namespace App\Actions\Scheduling;

use App\Enums\SlotFrequency;
use App\Models\Organization;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The one place a weekly slot's values are checked and turned into model attributes, shared by CreateWeeklySlot, UpdateWeeklySlot and the slot form.
 *
 * Action keys: weekday (0 is Sunday), start_time, end_time, people_needed, shadow_spots, frequency, valid_from, valid_until, title, is_rotation. Form keys are the same, except "fills_by" (regulars or rotation) in place of is_rotation and an empty last date for none.
 */
final class SlotAttributes
{
    /**
     * Checks the values and returns the attributes to fill a WeeklySlot with. When editing, a first date in the past is accepted only when it is unchanged.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validate(array $input, ?WeeklySlot $existing): array
    {
        $organization = Organization::current();
        $frequency = $input['frequency'] instanceof SlotFrequency ? $input['frequency'] : SlotFrequency::from($input['frequency']);
        $validFrom = CarbonImmutable::parse($input['valid_from'])->toDateString();
        $validUntil = ($input['valid_until'] ?? null) === null ? null : CarbonImmutable::parse($input['valid_until'])->toDateString();
        $title = trim((string) ($input['title'] ?? ''));
        $weekday = (int) $input['weekday'];
        $start = CarbonImmutable::parse($input['start_time'])->format('H:i');
        $end = CarbonImmutable::parse($input['end_time'])->format('H:i');

        if ($weekday < 0 || $weekday > 6) {
            throw ValidationException::withMessages(['weekday' => 'Pick a day of the week.']);
        }

        if ($validFrom !== $existing?->valid_from->toDateString() && $validFrom < $organization->today()->toDateString()) {
            throw ValidationException::withMessages(['valid_from' => 'Pick a date from today on.']);
        }

        if ($validUntil !== null && $validUntil < $validFrom) {
            throw ValidationException::withMessages(['valid_until' => 'The last date has to be on or after the first date.']);
        }

        if ($frequency === SlotFrequency::EveryOtherWeek && CarbonImmutable::parse($validFrom, 'UTC')->dayOfWeek !== $weekday) {
            throw ValidationException::withMessages(['valid_from' => 'For every other week, the first date has to fall on the day the slot is for.']);
        }

        if ($start === $end) {
            throw ValidationException::withMessages(['end_time' => 'A shift has to end at a different time than it starts.']);
        }

        if ((int) $input['people_needed'] < 1) {
            throw ValidationException::withMessages(['people_needed' => 'At least 1 person is needed.']);
        }

        if ((int) $input['shadow_spots'] < 0) {
            throw ValidationException::withMessages(['shadow_spots' => 'Enter 0 if there are no shadow spots.']);
        }

        if (mb_strlen($title) > 80) {
            throw ValidationException::withMessages(['title' => 'Keep it to 80 characters or fewer.']);
        }

        return [
            'weekday' => $weekday,
            'start_time' => $start,
            'end_time' => $end,
            'people_needed' => (int) $input['people_needed'],
            'shadow_spots' => (int) $input['shadow_spots'],
            'frequency' => $frequency,
            'anchor_date' => $frequency === SlotFrequency::EveryOtherWeek ? $validFrom : null,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'title' => $title === '' ? null : $title,
            'is_rotation' => (bool) ($input['is_rotation'] ?? false),
        ];
    }

    /**
     * Request rules for the full slot form. The first date's "from today on" check is left to validate() when editing, since an unchanged past first date is fine.
     *
     * @return array<string, mixed>
     */
    public static function formRules(?WeeklySlot $existing): array
    {
        $validFrom = ['bail', 'required', 'date_format:Y-m-d'];

        if ($existing === null) {
            $validFrom[] = 'after_or_equal:'.Organization::current()->today()->toDateString();
        }

        return [
            'title' => ['nullable', 'string', 'max:80'],
            'weekday' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'people_needed' => ['required', 'integer', 'min:1', 'max:200'],
            'shadow_spots' => ['required', 'integer', 'min:0', 'max:200'],
            'frequency' => ['required', Rule::enum(SlotFrequency::class)],
            'fills_by' => ['required', Rule::in(['regulars', 'rotation'])],
            'valid_from' => $validFrom,
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ];
    }

    /**
     * Turns validated form data into action keys.
     *
     * @return array<string, mixed>
     */
    public static function fromForm(array $data): array
    {
        return [
            'weekday' => $data['weekday'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'people_needed' => $data['people_needed'],
            'shadow_spots' => $data['shadow_spots'],
            'frequency' => $data['frequency'],
            'valid_from' => $data['valid_from'],
            'valid_until' => ($data['valid_until'] ?? '') === '' ? null : $data['valid_until'],
            'title' => $data['title'] ?? null,
            'is_rotation' => ($data['fills_by'] ?? 'regulars') === 'rotation',
        ];
    }
}
