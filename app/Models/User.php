<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\InvitationStatus;
use App\Enums\LoginLinkPurpose;
use App\Enums\Role;
use App\Notifications\InvitationNotification;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /** Fields that identify a person; the change log scrubs these when an account is removed. The membership ID counts, because it names the person in the membership system. Phone joins when phone numbers are built. */
    public const PERSONAL_FIELDS = ['name', 'email', 'member_id'];

    /** The shared record's email address. Addresses ending in @invalid can never receive mail. */
    public const REMOVED_PERSON_EMAIL = 'removed-person@invalid';

    /** Addresses ending in @invalid can never receive mail, and the shared record uses one, so no account may have one. */
    public static function isUnusableEmail(string $email): bool
    {
        return str_ends_with(mb_strtolower(trim($email)), '@invalid');
    }

    /** The one shared, anonymous record that removed people's shifts and change log entries move onto, made the first time someone is removed (logged as made by the system). It has no password, was never invited and is turned off, so it can never sign in, get email, be picked or be listed. Its membership ID stays null, so the import never matches it. */
    public static function removedPerson(): self
    {
        $existing = static::query()->where('is_removed_person', true)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () {
                $removed = new static;
                $removed->forceFill([
                    'name' => ChangeLogEntry::REMOVED_PERSON,
                    'email' => self::REMOVED_PERSON_EMAIL,
                    'password' => null,
                    'invited_at' => null,
                    'email_verified_at' => null,
                    'deactivated_at' => now(),
                    'member_id' => null,
                    'is_removed_person' => true,
                ]);
                $changes = ChangeLogEntry::changesOf($removed);
                $removed->save();

                ChangeLogEntry::record(null, 'user.created', $removed, changes: $changes);

                return $removed;
            });
        } catch (UniqueConstraintViolationException) {
            // Two first removals at once: the other one made it. Found by the flag, never by the address, and with a locking read, which sees it even inside a transaction that started before it was committed.
            return static::query()->where('is_removed_person', true)->lockForUpdate()->firstOrFail();
        }
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'deactivated_at' => 'datetime',
            'invited_at' => 'datetime',
            'is_removed_person' => 'boolean',
            'password_reset_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function roles(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function regulars(): HasMany
    {
        return $this->hasMany(SlotRegular::class);
    }

    public function loginLinks(): HasMany
    {
        return $this->hasMany(LoginLink::class);
    }

    /** Sent after the response, so asking for a reset takes the same time whether or not the address has an account. A turned-off account gets no email, and the answer on screen stays the same. */
    public function sendPasswordResetNotification($token): void
    {
        if (! $this->canSignIn()) {
            return;
        }

        defer(fn () => $this->notify(new ResetPassword($token)));
    }

    public function hasRole(Role $role, ?Area $area = null): bool
    {
        return $this->roles()
            ->where('role', $role->value)
            ->when($area !== null, fn ($query) => $query->where('area_id', $area->id))
            ->exists();
    }

    /** Admins can do everything a coordinator can. */
    public function isCoordinator(): bool
    {
        return $this->roles()->whereIn('role', [Role::Admin->value, Role::Coordinator->value])->exists();
    }

    /**
     * The names of this person's roles, in the order admin, coordinator, then leads by area name, such as "Shift lead: Front desk".
     *
     * @return array<int, string>
     */
    public function roleLabels(): array
    {
        // Uses the roles (and their areas) when the caller already loaded them, such as the people list, so a page of people doesn't query per person.
        $assignments = $this->relationLoaded('roles') ? $this->roles : $this->roles()->with('area')->get();
        $labels = [];

        if ($assignments->contains('role', Role::Admin)) {
            $labels[] = 'Admin';
        }

        if ($assignments->contains('role', Role::Coordinator)) {
            $labels[] = 'Coordinator';
        }

        $leads = $assignments->where('role', Role::AreaLead)->sortBy(fn ($assignment) => mb_strtolower($assignment->area?->name ?? ''));

        foreach ($leads as $assignment) {
            $labels[] = $assignment->area ? $assignment->area->leadTitle().': '.$assignment->area->name : 'Area lead';
        }

        return $labels;
    }

    /** The ids of the areas this person leads. */
    public function ledAreaIds(): Collection
    {
        return $this->roles()->where('role', Role::AreaLead->value)->whereNotNull('area_id')->pluck('area_id');
    }

    /** Anyone with a role can open the coordinator calendar; a turned-off account cannot. */
    public function canViewCalendar(): bool
    {
        return $this->isActive() && $this->roles()->exists();
    }

    /** Coordinators and admins manage every area; a lead manages only the areas they lead. */
    public function canManageArea(Area $area): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        return $this->isCoordinator() || $this->hasRole(Role::AreaLead, $area);
    }

    /** Every role can see volunteers' contact details (leads for their areas), so anyone with a role needs two-factor (spec section 13). */
    public function requiresTwoFactor(): bool
    {
        return $this->roles()->exists();
    }

    /** The first word of the name, for places that show a person to someone who can't see their full name. */
    public function firstName(): string
    {
        if ($this->is_removed_person) {
            return ChangeLogEntry::REMOVED_PERSON;
        }

        return explode(' ', trim($this->name))[0];
    }

    /** First name and the first letter of the last word, "Robin E.", for showing someone to a viewer who may not see their full name. Just the first name when the name is one word. */
    public function shortName(): string
    {
        if ($this->is_removed_person) {
            return ChangeLogEntry::REMOVED_PERSON;
        }

        $words = preg_split('/\s+/', trim($this->name));

        return count($words) < 2 ? $words[0] : $words[0].' '.mb_strtoupper(mb_substr($words[count($words) - 1], 0, 1)).'.';
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    public function isInvited(): bool
    {
        return $this->invited_at !== null;
    }

    /** Whether this person may use a login link, a password or a reset. Someone who isn't invited yet gets exactly what an unknown address gets. */
    public function canSignIn(): bool
    {
        return $this->isActive() && $this->isInvited() && ! $this->is_removed_person;
    }

    /** Where to send mail. Laravel's mail channel sends nothing when this is null, so every notification, current and future, skips people who can't sign in. The one exception is an invitation, which is how an invited person gets in. */
    public function routeNotificationForMail(Notification $notification): ?string
    {
        if ($this->canSignIn()) {
            return $this->email;
        }

        return $notification instanceof InvitationNotification && $this->isActive() && ! $this->is_removed_person ? $this->email : null;
    }

    /** Pass whether a usable invitation link exists when the caller already loaded that, such as the people list, so this doesn't query per person. */
    public function invitationStatus(?bool $hasWaitingLink = null): InvitationStatus
    {
        return match (true) {
            ! $this->isInvited() => InvitationStatus::NotInvited,
            $this->email_verified_at !== null => InvitationStatus::Accepted,
            ($hasWaitingLink ?? $this->loginLinks()->where('purpose', LoginLinkPurpose::Invitation->value)->whereNull('used_at')->where('expires_at', '>', now())->exists()) => InvitationStatus::Waiting,
            default => InvitationStatus::RanOut,
        };
    }

    /** The same rule as invitationStatus(), in SQL. */
    public function scopeWithInvitationStatus(Builder $query, InvitationStatus $status): void
    {
        $waiting = fn (Builder $links) => $links->where('purpose', LoginLinkPurpose::Invitation->value)->whereNull('used_at')->where('expires_at', '>', now());

        match ($status) {
            InvitationStatus::NotInvited => $query->whereNull('invited_at'),
            InvitationStatus::Accepted => $query->whereNotNull('invited_at')->whereNotNull('email_verified_at'),
            InvitationStatus::Waiting => $query->whereNotNull('invited_at')->whereNull('email_verified_at')->whereHas('loginLinks', $waiting),
            InvitationStatus::RanOut => $query->whereNotNull('invited_at')->whereNull('email_verified_at')->whereDoesntHave('loginLinks', $waiting),
        };
    }

    /** Leaves out the shared "Removed person" record that removed people's history moves onto. */
    public function scopePeople(Builder $query): void
    {
        $query->where('is_removed_person', false);
    }

    /** Admins can grant any role; coordinators can grant area lead. */
    public function canGrant(Role $role): bool
    {
        if ($this->hasRole(Role::Admin)) {
            return true;
        }

        return $role === Role::AreaLead && $this->hasRole(Role::Coordinator);
    }

    /**
     * The people this person may see on the users page. Coordinators and admins see everyone. Area leads see anyone with a shift or a regular slot in one of their areas, past or future, and themselves. Everyone else sees only themselves. Deactivated people are included so the page can filter them, and the query has no order.
     *
     * @return Builder<User>
     */
    public function visiblePeople(): Builder
    {
        if ($this->isCoordinator()) {
            return static::query()->people();
        }

        $areaIds = $this->roles()->where('role', Role::AreaLead->value)->pluck('area_id');

        return static::query()->people()->where(fn (Builder $query) => $query
            ->whereKey($this->id)
            ->when($areaIds->isNotEmpty(), fn (Builder $query) => $query
                ->orWhereHas('assignments.shift', fn ($shift) => $shift->whereIn('area_id', $areaIds))
                ->orWhereHas('regulars.weeklySlot', fn ($slot) => $slot->whereIn('area_id', $areaIds))));
    }

    public function canSee(User $other): bool
    {
        return $this->visiblePeople()->whereKey($other->id)->exists();
    }
}
