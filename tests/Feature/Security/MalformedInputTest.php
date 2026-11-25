<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SeedsAccessWorld;
use Tests\TestCase;

/**
 * An address that carries an array, or a date that doesn't exist, gets the page's normal answer (a validation error, today's date, an empty search), never an error page. The sweep sends every route in AccessMatrixTest::ROUTES both kinds of bad value for every input name the app reads, and the targeted tests pin the places that used to fail.
 */
class MalformedInputTest extends TestCase
{
    use RefreshDatabase, SeedsAccessWorld;

    /** Every input name the app reads from a request or from old input. */
    private const PARAMETERS = [
        'q', 'email', 'password', 'password_confirmation', 'current_password', 'code', 'recovery_code', 'recovery', 'token', 'date', 'from', 'to', 'which', 'week', 'month', 'area', 'areas', 'person', 'type', 'assignment', 'edit', 'reason', 'note', 'weeks', 'starts_on', 'ends_on', 'last_date', 'frequency', 'title', 'start_time', 'end_time', 'people_needed', 'shadow_spots', 'fills_by', 'ends', 'repeats', 'user', 'role', 'invitation', 'show',
        'name', 'starting', 'scope', 'restore', 'remember', 'past_confirmed', 'over_confirmed', 'loaded', 'lines', 'how', 'check', 'weeks_ahead', 'timezone', 'shift', 'required_shadow_shifts', 'remove', 'pick', 'over', 'more', 'lead_name', 'existing', 'confirm', 'confirmed', 'color', 'checked_to', 'checked_from', 'change', 'cancel', 'app', 'refresh', 'version', 'reminder_hours', 'reminder_emails', 'cover_request_emails', 'starts_as', 'cover_request', 'lead', 'status',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();
        $this->withoutDefer();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'America/Detroit'));
        $this->seedWorld();
    }

    /** @return array<string, array{0: string}> */
    public static function routeRows(): array
    {
        return AccessMatrixTest::routeRows();
    }

    #[DataProvider('routeRows')]
    public function test_no_route_answers_500_to_array_or_impossible_values(string $key): void
    {
        $row = AccessMatrixTest::ROUTES[$key];
        [$method, $name] = AccessMatrixTest::methodAndName($key);
        $failures = [];

        foreach (['admin', 'guest'] as $visitor) {
            // A guest sent away to log in never reads the values, so only rows a guest can reach are sent as one.
            if ($visitor === 'guest' && $row['expect']['guest'] !== 'ok') {
                continue;
            }

            foreach ([['x'], '2026-99-99'] as $bad) {
                $this->app['auth']->forgetGuards();
                $this->flushSession();
                Cache::flush();

                if ($this->visitors[$visitor] !== null) {
                    $this->actingAs(User::query()->findOrFail($this->visitors[$visitor]->id));
                }

                $data = array_merge($this->resolve($row['data'] ?? []), array_fill_keys(self::PARAMETERS, $bad));

                DB::beginTransaction();

                try {
                    $response = $this->call($method, route($name, $this->resolve($row['params'] ?? [])), $data);
                    $status = $response->status();
                } catch (\Throwable $exception) {
                    $status = 500;
                    $failures[] = "{$key} as {$visitor} with every value set to ".json_encode($bad).' threw '.get_class($exception).': '.$exception->getMessage();

                    continue;
                } finally {
                    DB::rollBack();
                }

                if ($status >= 500) {
                    $failures[] = "{$key} as {$visitor} with every value set to ".json_encode($bad)." answered {$status}".$this->why($response);
                }
            }
        }

        $this->assertSame([], $failures);
    }

    /** What went wrong, from the exception the response carries, so a failure names the line to fix. */
    private function why(TestResponse $response): string
    {
        $exception = $response->exception;

        return $exception === null ? '' : ' ('.class_basename($exception).': '.$exception->getMessage().' at '.basename($exception->getFile()).':'.$exception->getLine().')';
    }

    public function test_login_with_an_array_for_the_address_gets_a_validation_error(): void
    {
        $response = $this->post(route('login.store'), ['email' => ['x'], 'password' => 'secret-password']);

        $response->assertRedirect();
        $response->assertSessionHasErrors('email');
    }

    public function test_login_link_with_an_array_for_the_address_gets_a_validation_error(): void
    {
        $response = $this->post(route('login-link.request'), ['email' => ['x']]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('email');
    }

    public function test_the_shift_panel_with_an_array_search_still_opens(): void
    {
        $shift = $this->world['shift_a'];

        $this->actingAs(User::query()->findOrFail($this->visitors['admin']->id));

        $this->get(route('calendar.shift', ['shift' => $shift->id, 'q' => ['x']]))->assertOk();
    }

    public function test_the_add_regular_form_with_an_impossible_week_asks_for_weeks_again(): void
    {
        $this->actingAs(User::query()->findOrFail($this->visitors['admin']->id));
        $slot = $this->world['slot_a'];

        $response = $this->post(route('calendar.slots.regulars.store', ['slot' => $slot->id]), ['user' => $this->world['regular_a']->id, 'frequency' => 'every_other_week', 'starts_on' => '2026-10-20', 'weeks' => '2026-99-99']);

        $response->assertSessionHasErrors(['weeks' => 'Pick which weeks.']);
    }
}
