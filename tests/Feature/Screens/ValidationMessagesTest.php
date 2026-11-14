<?php

namespace Tests\Feature\Screens;

use App\Actions\Accounts\GrantRole;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Field errors are plain language that says what to do next, like the rest of the sign-in screens (issue #9). */
class ValidationMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_email_says_to_enter_it(): void
    {
        $this->post(route('login-link.request'), ['email' => ''])->assertSessionHasErrors(['email' => 'Enter your email address.']);
    }

    public function test_an_address_that_isnt_an_email_says_so(): void
    {
        $this->post(route('login-link.request'), ['email' => 'not an address'])
            ->assertSessionHasErrors(['email' => 'That doesn\'t look like an email address. Check it and try again.']);
    }

    public function test_an_empty_password_says_to_enter_it(): void
    {
        $this->post('/login', ['email' => 'sam@example.org', 'password' => ''])->assertSessionHasErrors(['password' => 'Enter your password.']);
    }

    public function test_a_short_password_says_how_long(): void
    {
        $this->actingAs($this->coordinatorWithoutPassword())
            ->post(route('account.password.set'), ['password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors(['password' => 'Use at least 10 characters.']);
    }

    public function test_passwords_that_dont_match_say_to_type_them_again(): void
    {
        $this->actingAs($this->coordinatorWithoutPassword())
            ->post(route('account.password.set'), ['password' => 'a long enough password', 'password_confirmation' => 'a different password'])
            ->assertSessionHasErrors(['password' => 'The two passwords don\'t match. Type them again.']);
    }

    public function test_an_empty_code_says_to_enter_it(): void
    {
        Notification::fake();
        $lead = User::factory()->create();

        $this->withSession(['login.id' => $lead->id, 'login.via' => 'password'])
            ->post(route('two-factor.email.verify'), ['code' => ''])
            ->assertSessionHasErrors(['code' => 'Enter the code.']);
    }

    public function test_no_field_error_uses_laravels_default_wording(): void
    {
        foreach (['required', 'email', 'confirmed', 'accepted', 'string', 'integer', 'date', 'date_format', 'in', 'min.string', 'min.numeric', 'max.string', 'max.numeric', 'between.numeric', 'between.string'] as $key) {
            $message = __('validation.'.$key, ['attribute' => 'name', 'min' => 1, 'max' => 2, 'format' => 'Y-m-d']);
            $this->assertStringNotContainsString('field', $message, "validation.$key still uses the default wording: $message");
            $this->assertStringNotContainsString('The selected', $message, "validation.$key still uses the default wording: $message");
        }
    }

    private function coordinatorWithoutPassword(): User
    {
        $user = User::factory()->create(['password' => null]);
        app(GrantRole::class)->handle(null, $user, Role::Coordinator);

        return $user;
    }
}
