<?php

namespace App\Http\Controllers;

use App\ChangeLog\Describe;
use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * The change log (screens 28 and 29): every change, newest first, for coordinators and admins. Filters are who made it, who it's about, the kind of change and dates in the organization's time zone, all in the query string so a link keeps them (the person page links here with `about`). A filter value that doesn't make sense is ignored rather than refused.
 */
class ChangeLogController extends Controller
{
    private const PER_PAGE = 50;

    /** The kinds in the "Kind of change" select, with the actions each covers. */
    public const KINDS = [
        'roles' => ['Roles', ['role.*']],
        'invitations' => ['Invitations and accounts', ['user.created', 'user.invited', 'user.invitation_resent', 'user.accepted_invitation', 'user.deactivated', 'user.removed', 'user.email_changed', 'user.email_settings_changed', 'user.member_id_added']],
        'passwords' => ['Passwords and two-factor', ['user.password_set', 'user.password_changed', 'user.password_reset', 'user.signed_out_everywhere', 'two_factor.*']],
        'schedule' => ['Schedule', ['area.*', 'slot.*', 'shift.*', 'assignment.*', 'regular.*', 'closure.*', 'cover_request.*']],
        'generation' => ['Shift generation', ['shifts.generated']],
        'settings' => ['Settings', ['organization.*']],
    ];

    public function index(Request $request): View
    {
        $viewer = $request->user();
        $timezone = Organization::query()->value('timezone') ?? 'UTC';
        $filters = $this->filters($request, $timezone);

        $query = ChangeLogEntry::query()->with(['actor', 'affectedUser', 'subject']);

        if ($filters['by'] === 'system') {
            $query->whereNull('actor_id')->whereNull('actor_name');
        } elseif ($filters['by'] !== '') {
            $query->where('actor_id', (int) $filters['by']);
        }

        if ($filters['about'] !== '') {
            $query->whereHas('affectedUser', fn ($user) => $user->where('name', 'like', '%'.addcslashes($filters['about'], '%_\\').'%'));
        }

        if ($filters['kind'] !== '') {
            $query->ofAction(...self::KINDS[$filters['kind']][1]);
        }

        $query->between($filters['fromAt'], $filters['toAt']);

        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(self::PER_PAGE)->withQueryString();
        $entries = $page->getCollection();
        $entries->loadMorph('subject', [
            Shift::class => ['area'],
            WeeklySlot::class => ['area'],
            SlotRegular::class => ['weeklySlot.area'],
            AreaClosure::class => ['area'],
        ]);

        $areas = Area::query()->get()->keyBy('id');
        $affectedIds = $entries->map(fn (ChangeLogEntry $entry) => $entry->affected_user_id ?? ($entry->subject instanceof User ? $entry->subject->id : null))->filter()->unique()->all();
        $visibleIds = $viewer->visiblePeople()->whereIn('id', $affectedIds)->pluck('id');
        $seeable = $viewer->visiblePeople()->whereIn('id', $entries->pluck('actor_id')->filter()->unique()->all())->pluck('id');

        $thisYear = CarbonImmutable::now($timezone)->year;
        $days = $entries
            ->map(fn (ChangeLogEntry $entry) => [
                'date' => $entry->created_at->setTimezone($timezone)->format('Y-m-d'),
                'heading' => $entry->created_at->setTimezone($timezone)->format($entry->created_at->setTimezone($timezone)->year === $thisYear ? 'l, F j' : 'l, F j, Y'),
                'time' => $entry->created_at->setTimezone($timezone)->format('g:ia'),
                'actor' => $this->actorFor($entry, $seeable),
                'sentence' => Describe::entry($entry, $viewer, $areas, $visibleIds),
                'changes' => Describe::changes($entry),
            ])
            ->groupBy('date');

        return view('change-log.index', [
            'days' => $days,
            'page' => $page,
            'filters' => $filters,
            'kinds' => collect(self::KINDS)->map(fn (array $kind) => $kind[0]),
            'makers' => User::query()->whereIn('id', ChangeLogEntry::query()->whereNotNull('actor_id')->select('actor_id'))->orderBy('name')->get(['id', 'name']),
            'summary' => $this->summary($page->total(), $filters),
        ]);
    }

    /**
     * @return array{by: string, about: string, kind: string, from: string, to: string, fromAt: ?CarbonImmutable, toAt: ?CarbonImmutable, fromDay: ?CarbonImmutable, toDay: ?CarbonImmutable}
     */
    private function filters(Request $request, string $timezone): array
    {
        $input = Validator::make($request->query(), [
            'by' => ['nullable', 'string', 'regex:/^(system|\d{1,18})$/'],
            'about' => ['nullable', 'string', 'max:255'],
            'kind' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::KINDS))],
            'from' => ['nullable', 'string', 'date_format:Y-m-d'],
            'to' => ['nullable', 'string', 'date_format:Y-m-d'],
        ])->valid();

        $day = fn (?string $date) => $date ? CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone) : null;
        $from = $day($input['from'] ?? null);
        $to = $day($input['to'] ?? null);

        return [
            'by' => $input['by'] ?? '',
            'about' => trim($input['about'] ?? ''),
            'kind' => $input['kind'] ?? '',
            'from' => $input['from'] ?? '',
            'to' => $input['to'] ?? '',
            'fromAt' => $from?->startOfDay()->utc(),
            'toAt' => $to?->endOfDay()->utc(),
            'fromDay' => $from,
            'toDay' => $to,
        ];
    }

    /** Who made the change, as the row shows it: the system, the removed person (never linked), or a named person (linked when the viewer may see them). */
    private function actorFor(ChangeLogEntry $entry, $seeable): array
    {
        if ($entry->isBySystem()) {
            return ['label' => 'the system', 'id' => null];
        }

        if ($entry->actor === null || $entry->actor->is_removed_person) {
            return ['label' => ChangeLogEntry::REMOVED_PERSON, 'id' => null];
        }

        return ['label' => $seeable->contains($entry->actor->id) ? $entry->actor->name : $entry->actor->shortName(), 'id' => $seeable->contains($entry->actor->id) ? $entry->actor->id : null];
    }

    private function summary(int $total, array $filters): string
    {
        $words = $total.' '.($total === 1 ? 'change' : 'changes');

        if ($filters['by'] === 'system') {
            $words .= ' by the system';
        } elseif ($filters['by'] !== '') {
            $name = User::query()->whereKey((int) $filters['by'])->value('name');
            $words .= $name ? ' by '.$name : '';
        }

        if ($filters['about'] !== '') {
            $words .= ' about '.$filters['about'];
        }

        if ($filters['kind'] !== '') {
            $words .= ' in '.self::KINDS[$filters['kind']][0];
        }

        $format = fn (CarbonImmutable $date) => $date->format('M j');

        return $words.match (true) {
            $filters['fromDay'] !== null && $filters['toDay'] !== null => ' from '.$format($filters['fromDay']).' to '.$format($filters['toDay']),
            $filters['fromDay'] !== null => ' from '.$format($filters['fromDay']),
            $filters['toDay'] !== null => ' up to '.$format($filters['toDay']),
            default => '',
        };
    }
}
