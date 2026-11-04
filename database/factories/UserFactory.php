<?php

namespace Database\Factories;

use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'invited_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** A volunteer who logs in with email links only. */
    public function withoutPassword(): static
    {
        return $this->state(fn () => ['password' => null]);
    }

    /** Someone added to the list who hasn't been invited yet: no email goes to them and they can't sign in. */
    public function notInvited(): static
    {
        return $this->state(fn () => ['invited_at' => null, 'email_verified_at' => null]);
    }

    public function deactivated(): static
    {
        return $this->state(fn () => ['deactivated_at' => now()]);
    }

    /** The shared record that removed people's history moves onto: anonymous, turned off and never invited. The same values User::removedPerson() uses, so a test makes at most one; prefer User::removedPerson(), which also logs it. */
    public function removedPerson(): static
    {
        return $this->state(fn () => [
            'name' => ChangeLogEntry::REMOVED_PERSON,
            'email' => User::REMOVED_PERSON_EMAIL,
            'member_id' => null,
            'is_removed_person' => true,
            'deactivated_at' => now(),
            'invited_at' => null,
            'email_verified_at' => null,
            'password' => null,
        ]);
    }
}
