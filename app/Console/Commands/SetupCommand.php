<?php

namespace App\Console\Commands;

use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\IssueLoginLink;
use App\Enums\LoginLinkPurpose;
use App\Enums\Role;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Rules\NoWebAddress;
use App\Support\EmailAddress;
use App\Support\PersonName;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class SetupCommand extends Command
{
    protected $signature = 'ssvs:setup
        {--organization= : The organization\'s name}
        {--timezone= : The organization\'s time zone, for example America/Detroit}
        {--admin-name= : The first admin\'s name, as volunteers will see it}
        {--admin-email= : The first admin\'s email address}';

    protected $description = 'Set up this install: the organization and its first admin';

    public function handle(GrantRole $grantRole, IssueLoginLink $issueLoginLink): int
    {
        if (Organization::query()->exists()) {
            $this->error('This install is already set up. Invite more people from the app instead.');

            return self::FAILURE;
        }

        $answers = [
            'organization' => $this->option('organization') ?? $this->ask('What is the organization called?'),
            'timezone' => $this->option('timezone') ?? $this->anticipate('Which time zone is it in? (for example America/Detroit)', DateTimeZone::listIdentifiers()),
            'admin_name' => (string) ($this->option('admin-name') ?? $this->ask('Your name, as volunteers will see it')),
            'admin_email' => EmailAddress::normalize((string) ($this->option('admin-email') ?? $this->ask('Your email address'))),
        ];

        $validator = Validator::make($answers, [
            'organization' => ['required', 'string', 'max:255', new NoWebAddress],
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'admin_name' => ['required', 'string', 'max:255', fn ($attribute, $value, $fail) => ($problem = PersonName::problem((string) $value)) !== null ? $fail($problem) : (PersonName::clean((string) $value) === '' ? $fail('Enter your name.') : null)],
            'admin_email' => ['required', 'email', 'max:255', fn ($attribute, $value, $fail) => EmailAddress::isPlain($value) ? null : $fail(EmailAddress::REFUSAL)],
        ], [
            'timezone.in' => 'That isn\'t a time zone this server knows. Use a name like America/Detroit.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        // The name was validated as typed (cleaning would hide control characters); it's stored cleaned.
        $answers['admin_name'] = PersonName::clean($answers['admin_name']);

        [$admin, $url] = DB::transaction(function () use ($answers, $grantRole, $issueLoginLink) {
            $organization = new Organization(['name' => $answers['organization'], 'timezone' => $answers['timezone']]);
            $organizationChanges = ChangeLogEntry::changesOf($organization);
            $organization->save();
            ChangeLogEntry::record(null, 'organization.created', $organization, changes: $organizationChanges);

            $admin = new User([
                'name' => $answers['admin_name'],
                'email' => $answers['admin_email'],
                'password' => null,
            ]);
            // Issuing the invitation link below is what invites the admin.
            $admin->invited_at = now();
            $adminChanges = ChangeLogEntry::changesOf($admin);
            $admin->save();
            ChangeLogEntry::record(null, 'user.created', $admin, changes: $adminChanges);

            $grantRole->handle(null, $admin, Role::Admin);

            return [$admin, $issueLoginLink->handle($admin, LoginLinkPurpose::Invitation)];
        });

        try {
            $admin->notify(new InvitationNotification($url));
        } catch (Throwable) {
            $this->warn('The invitation email couldn\'t be sent. That\'s fine: use the link below.');
        }

        $this->info("{$answers['organization']} is set up.");
        $this->line('Open this link to finish setting up your admin account. It works once, for 7 days:');
        $this->line($url);

        return self::SUCCESS;
    }
}
