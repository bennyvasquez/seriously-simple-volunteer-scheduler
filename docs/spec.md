# Seriously Simple Volunteer Scheduler (SSVS): product spec

Status: draft, October 2026. Owner: benny Vasquez.

This spec describes what the app does and why. It's written for anyone building or deploying it. Organization-specific setup lives in [deployments/](deployments/), starting with [the Lansing Makers Network](deployments/lmn.md).

## 1. The problem

Volunteer-run organizations keep a shared calendar, a chat channel, and a lot of goodwill. When someone can't make their shift, they post in chat, text a few people, or tell the coordinator, who then chases replacements by hand. Nobody can see at a glance which shifts are open, people miss the message in whichever channel it landed, and the coordinator becomes the bottleneck for every swap.

## 2. Goals

- A volunteer can release a shift or take an open one in under a minute, from a phone, starting from a link in an email, text, or chat message.
- Open shifts get filled without a coordinator sending messages by hand.
- A coordinator can see every gap for the week on one screen.
- New volunteers can find and sign up for training (shadow) shifts on their own.
- Small organizations can run it on a modest Linux server without paid services.

## 3. Non-goals

- Membership management, payments, or donor tracking. The app reads a member list from another system instead (section 11).
- Chat. The app posts to chat; it doesn't replace it.
- Hosting many organizations in one install.

## 4. UX principles

1. **Phone first.** Most actions start from a link in a message and happen on a phone.
2. **One question per decision.** When a choice matters (one date or every week?), the app asks it plainly and doesn't preselect an answer.
3. **Plain language.** "Your Thursday front desk shift", never "assignment instance". No internal terms on volunteer screens.
4. **Lists over grids for volunteers.** A volunteer's home screen is "my next shifts" and "open shifts I can take". The week grid is for coordinators.
5. **Accessible as built.** WCAG 2.1 AA, real buttons and form labels, touch targets of at least 44 pixels, and color never the only signal.
6. **Test before building.** Each new flow gets tried as a clickable prototype with three to five real volunteers before backend work starts.

## 5. Concepts

| Term | Meaning |
|---|---|
| Organization | The one group this install serves. Has a name, a time zone, and settings. |
| Area | A place or kind of work that needs staff, such as Front desk, Hosted events, or Outreach events. Every shift belongs to one area. |
| Event | Optional. Something with a date or date range, a location, a description, and an organizer, such as a festival booth. An event holds one or more shifts. Events are where event planning happens, and they bridge the gap between event volunteering and regular shifts. |
| Weekly slot | A repeating shift template: area, weekday, start and end time, how many people it needs, how many shadow spots it offers, an optional required skill, and whether it repeats every week or every other week. |
| Shift | One dated occurrence. Generated from a weekly slot, or created on its own (one-time shifts and event shifts). |
| Regular | A person attached to a weekly slot, with a start date, an optional end date, and whether they work every week or every other week (with an anchor date). Regulars are filled into each generated shift they cover automatically. |
| Assignment | A person on a specific shift. Type is regular or shadow. |
| Shadow spot | An optional extra place on a shift for someone in training. It never counts toward the shift's headcount and never triggers cover requests. |
| Skill | A qualification a lead grants, such as "Blacksmithing demos". A shift can require one. |
| Closure | A date or date range when an area is closed. Cancels shifts on those dates. |
| Cleared | A flag on a volunteer meaning they can take regular shifts. Off until a coordinator turns it on. |

## 6. Roles and permissions

| Role | Scope | Can do |
|---|---|---|
| Admin | Everything | Manage the install, settings, integrations, and all users. |
| Coordinator | All areas | Invite and remove volunteers, clear or un-clear them, create areas, events, slots and shifts, assign anyone, add closures. |
| Area lead | One or more areas | Create and edit slots and shifts in their areas, assign people, add shadow spots, grant skills, mark no-shows. |
| Event organizer | One event | Edit that event's shifts and see its roster. |
| Volunteer | Themselves | See the full schedule, take open shifts (once cleared), release their own shifts, sign up for shadow spots, manage notification settings. |

One person can hold several roles. Nobody sees schedule details until a coordinator has invited them and they've accepted.

## 7. Scheduling model

- **Weekly slots generate shifts** a set number of weeks ahead (setting, default 8). Each generated shift copies the slot's details and fills in its regulars.
- **Generation runs daily** and never changes a shift that already exists, so per-date edits survive. It skips dates covered by a closure and shifts that have already started. Adding a regular also adds them to the slot's already-generated shifts that haven't started and still have room.
- **Every week or every other week.** An every-other-week slot has an anchor date. On a weekly slot, each regular can also work every week or every other week, with their own anchor date, so two people can split one weekly slot by each holding alternate weeks. A slot's headcount is checked per date, so alternating regulars don't count against each other.
- **Overlapping shifts are allowed.** A day can have several shifts in the same area at overlapping times, each with its own headcount. Coordinators set the times; volunteers only claim shifts that exist.
- **Headcount varies by slot.** Seasonal changes are handled by editing a slot from a date forward (section 8.4).
- **Times are stored in the organization's local time** for slots, so a weekly shift doesn't move by an hour when daylight saving time changes. Dated shifts store their start and end in UTC, plus the local date.
- **Exceptions are per date.** Releasing one date never changes the slot or the regular's other dates.
- **Closures** cancel the shifts they cover and notify everyone on them.
- **Rotation slots** are weekly slots with no regular, filled one date at a time. They use the same model; the only difference is a sign-up screen that lists upcoming dates and lets a volunteer take several at once.

## 8. Core flows

### 8.1 Taking an open shift

1. The volunteer opens the shift from the home screen, the schedule, or a link.
2. If the shift is an open spot in a weekly slot with no regular, the app asks: "Just this date", "Every [weekday]", or "Every other [weekday]". A cover request for a single date only offers "Just this date".
3. If the shift requires a skill the volunteer doesn't have, the app says so and doesn't offer the button.
4. If the volunteer isn't cleared, the app shows "You'll be able to take shifts once a coordinator clears you", plus any shadow spots on that shift.
5. On success, the volunteer, the area lead, and the organizer (for event shifts) get a notice, and calendars update.

### 8.2 "I can't make it"

1. The volunteer taps "Can't make it" and chooses: just this date, several dates (a from and to date), or give up the weekly slot from a date forward.
2. They can add an optional note for whoever covers.
3. **They're released as soon as they confirm.** Responsibility moves to the cover process.
4. Anyone shadowing on a released shift is told, and the shadow spot is paused until the shift is covered again.

### 8.3 Cover requests and escalation

Timings are settings per area; these are the defaults.

1. **At release:** post to the area's chat channel and notify every eligible volunteer (cleared, has any required skill, not already on an overlapping shift) through their chosen channels, with a claim link.
2. **72 hours before, still open:** send the request again.
3. **24 hours before, still open:** alert the area lead and all coordinators on every channel they've enabled, and mark the shift as urgent on the coordinator view and the public calendar.
4. **Released with less than 24 hours to go:** skip straight to step 3.
5. The first eligible person to claim gets it. Later clicks see "Someone already took this one" and a list of other open shifts.

### 8.4 Editing a shift

1. A coordinator or lead edits a shift that belongs to a weekly slot. The app asks: **"Only this date"** or **"This date and all following"**.
2. "Only this date" changes that one shift. Later edits to the slot don't overwrite it.
3. "This date and all following" splits the slot at that date. Earlier dates keep the old details; regulars carry over to the new version.
4. **Then** the app notifies everyone on the affected dates (regulars and shadows), showing what changed, with two buttons: "Still works for me" and "I can't make the new time". The second runs the release flow.
5. If the change moved the time and someone hasn't answered 48 hours before the shift, their lead gets a quiet heads-up.

### 8.5 Closures

A coordinator adds a closure for an area and a date range, with a reason. Affected shifts are cancelled and everyone on them is notified. Closures don't create cover requests.

### 8.6 Volunteer hours and credit

SSVS is the record of volunteer hours.

- **A shift counts as worked once it ends**, unless its lead marks a no-show. Leads can correct a mark afterwards. Every mark and correction is in the change log.
- **Hours** are the length of each shift a person worked. Each person sees their own hours; leads see hours worked in their areas, and coordinators and admins see everyone's, filtered by person, area and date range.
- **Credit requests.** An organization can let volunteers turn hours into credit, such as credit toward a membership. A volunteer asks for credit for hours they haven't claimed yet, and a small group of people reviews the queue and approves or declines each request. Hours in an approved request can't be claimed again. Hours and credit requests come in phase 4. Who reviews, and what an hour is worth, are open questions (section 19).

## 9. New volunteers, shadowing and clearance

- New volunteers are invited like everyone else and can see the full schedule, but **start not cleared**. The flag is worded positively ("Cleared for regular shifts") so that forgetting to change it is the safe mistake.
- **Shadow spots** are added to existing shifts by a lead or coordinator, so a trainee always has someone to learn from. Not-cleared volunteers see a "Shadow this shift" button and a list of open shadow spots on their home screen.
- **A shadow shift counts once it ends**, unless the person shadowed or the lead marks a no-show.
- **Required shadow shifts is a coordinator setting** (default 2). Changing it never un-clears anyone; it only changes when the "ready for review" prompt appears.
- When a volunteer reaches the required number, coordinators see **"Ready for review"** with the shadow history. Clearing is always a coordinator decision; the app never clears anyone automatically.
- **Coordinators can override in both directions:** clear someone early, or move a cleared volunteer back to shadowing. Every change records who made it, when, and an optional note.
- **Moving someone back** asks the coordinator what to do with that person's upcoming regular shifts: keep them, or release them through the normal cover flow.
- Not-cleared volunteers don't receive cover requests.

## 10. Notifications

- **Channels:** email (always available), chat webhooks, and text messages. Each person picks which channels get which kinds of notice: cover requests, changes to my shifts, reminders, and (for leads and coordinators) escalations.
- **Email is the baseline.** Chat only reaches people who are active there, so it can't be the only channel for anything that matters.
- **Chat** goes through a generic outgoing-webhook layer, with Discord as the first adapter. Matrix, Zulip, Slack, and Mattermost all accept incoming webhooks, so switching chat platforms is a configuration change.
- **Text messages** come in phase 3. Email and chat are the channels until then, and notifications are written so a text channel can be added later without changing them. US senders need carrier registration (10DLC or toll-free verification) before texting, which can take weeks. Phone numbers need a confirmation code and an explicit opt-in per notice type. Carrier email-to-text gateways are not an option; the major US carriers have shut them down or are shutting them down.
- **Action links:** links in emails and texts are signed for that recipient, single-use, and short-lived (setting, default 7 days or until the shift starts, whichever comes first). Each link does exactly one thing, such as claiming one shift or confirming one change, and never opens a full session. A forwarded email therefore can't be used to get into someone's account. Links posted in chat are public, so they require a normal login first.
- **Reminders:** a reminder before each shift (setting, default 24 hours).

## 11. Integrations

Each one is optional and off by default.

- **Member directory sync.** A scheduled job reads people from an external membership system and creates invitations or deactivates accounts to match. It matches people by their ID in the membership system, never by email address, because volunteers can change their own address. The first adapter is Hello Club, which has an API with key-based access and a rate limit of about 30 requests per minute (per an unofficial guide; confirm against the vendor's docs).
- **Hours export,** for organizations that keep hours somewhere else as well. When a shift ends, the app sends the completed assignment (person, date, area, duration, regular or shadow) to a webhook, so a separate system can record volunteer hours without anyone typing them twice.
- **Credit to the membership system (later goal).** Approved credit requests could be added to the member's record in the membership system automatically, starting with Hello Club. That needs write access, unlike member sync, so it would be the one exception to read-only access (section 13).
- **Google Calendar, one way.** The app publishes shifts to organization-owned Google calendars (one per area, or one combined) through a Google service account. No volunteer has to connect a Google account. Two-way sync is out of scope; it needs per-user Google sign-in, which brings Google's app verification review and a lot of breakage.
- **Personal calendar feeds.** Every volunteer gets a private iCal feed of their own shifts, with an "Add to my calendar" button on the home screen. The feed address is a secret, so it shows only that person's shifts (no other volunteers' names) and has a "reset my calendar link" button that invalidates the old address.

## 12. Public calendar

A public page lists events and areas marked public, with counts such as "needs 2 more volunteers". It never shows volunteer names. Members are sent to log in; everyone else is sent to the organization's "become a volunteer" page.

## 13. Security and privacy

Volunteers trust the organization with their contact details and their whereabouts. The app should hold as little as it needs, show it only to the people who need it, and be safe to run for organizations without a security team.

### Accounts and access

- **Invite-only.** Accounts exist only after a coordinator invites someone, directly or through member sync.
- **Login:** password, or a one-time email link. Passwords are hashed with a modern algorithm (bcrypt or Argon2id); login, reset and invite endpoints are rate-limited. Passwords need at least 10 characters. Login-link requests and password reset requests answer the same way for every address, so they never reveal who has an account. A deactivated account gets no login link or reset email, with the same answer on screen. Opening an emailed link shows a page with a button rather than logging in straight away, because some email security scanners open every link in a message.
- **Login, invitations and two-factor setup are built on Laravel Fortify**, Laravel's first-party authentication backend, with our own screens.
- **Two-factor authentication is required** for admins, coordinators and area leads, since they can all see volunteers' contact details. They also need a password. The second factor is a code from an authenticator app, with one-time recovery codes. If the phone isn't available, a code can be emailed instead, but only after a password login: after an email-link login the inbox has already been used once, so an emailed code wouldn't be a second factor. For the same reason, after a password reset by email the emailed code is refused until the person next logs in with their authenticator app or a recovery code, or connects a new app. A reset also emails the person to say their password was changed. Someone who has lost both their phone and their recovery codes asks an admin, who can reset their two-factor after checking in person or by phone that it's really them, never only by email or chat. The reset logs them out everywhere, emails them to say it happened, and goes in the change log; they set up a new app and get new recovery codes the next time they log in with their password.
- **Action links in emails and texts do one thing only** (section 10) and never open a full session. Login, invitation and confirm-it's-you links are login links, not action links.
- **Sessions** expire after 120 minutes of inactivity, and a person can sign out of all devices from their profile. Deactivating a person ends their open sessions on their next request.
- **Changing your email address.** Anyone can change their own address after confirming who they are again: with their password (plus their app code, for area leads and up), or with a link emailed to their current address if they have no password. We then email a link to the new address, and the change happens only when that link is opened, so a typo can't lock anyone out; until then the old address keeps working. The old address gets an email saying the login address changed, showing the new one partly hidden, so the real owner hears about a change they didn't make. The change logs the person out on every other device, keeps the one they used signed in, and goes in the change log. An address another account already uses gets the same answer on screen, so the form never reveals who has an account. Typing your own current address gets a plain "That's already your address.", since that says nothing about anyone else.
- **First-run setup:** `php artisan ssvs:setup` creates the organization and its first admin, and prints that admin's invitation link so an install works before email is configured.
- **Change log** for every change to stored data, including clearance, roles, accounts and the schedule. Each entry says who made the change (a person, or the system for automatic jobs), what changed, with the old and new values where there are any (secrets show only that they changed), and when. Coordinators and admins can filter it by who made a change, who it was about, which record, which kind of change and a date range. Entries are never edited. Password hashes, tokens and two-factor secrets are never copied into it.

### Who sees what

| Information | Volunteers | Leads (their areas) | Coordinators and admins | Public |
|---|---|---|---|---|
| The schedule and first names on shifts | Yes | Yes | Yes | No |
| Email addresses and phone numbers | Their own | Yes | Yes | No |
| Notification settings | Their own | No | Admins only | No |
| Clearance status and shadow history | Their own | Yes | Yes | No |
| Last login and last seen | Their own | Yes | Yes | No |
| Volunteer hours and credit requests | Their own | Hours in their areas | Yes | No |
| Open shifts and "needs N more" counts | Yes | Yes | Yes | Only areas and events marked public, never names |

New volunteers who aren't cleared yet can see the full schedule. That includes when someone is working alone, so each deployment should decide whether that's acceptable; the invite-only model is the main protection.

### Data we keep, and for how long

- **Stored:** name, email, optional phone number, roles, skills, clearance status, notification settings, last login and last seen times, and schedule history. Nothing else is required.
- **Phone numbers** are only stored after the person confirms them with a code, and are deleted when they turn text messages off.
- **Inactive accounts** (not seen and no shifts for a set period; setting, default 18 months) are flagged to coordinators for removal.
- **Removal on request:** a coordinator can delete a person's account and contact details. Past schedule history keeps a placeholder name so staffing records still add up. In the change log, the person's name becomes "Removed person" and their contact details are blanked; the entries themselves stay.
- **Export on request:** a person can download the information the app holds about them.

### Secrets and integrations

- **No secrets in the repository.** API keys (member sync, Google service account, SMS provider), SMTP passwords and chat webhook addresses live only in the server's environment configuration. The repository ships an example config with placeholders.
- **Chat webhook addresses are treated as secrets.** Anyone with one can post into that channel.
- **Integrations get the least access they need:** read-only member sync where the provider allows it, and a Google service account that can only write to the organization's scheduling calendars.
- **Outgoing messages never include phone numbers or email addresses** of other volunteers.

### Running the server

The install guide will cover, for AlmaLinux:

- automatic security updates (dnf-automatic);
- SELinux left in enforcing mode, with the policy the app needs documented;
- firewalld allowing only HTTPS (and SSH for admins);
- HTTPS everywhere, with certificates from Let's Encrypt and HTTP redirected;
- nightly encrypted database backups stored off the server, plus a documented restore that's tested at least once before launch;
- the database reachable only from the app.

### People leaving

When someone stops volunteering, a coordinator deactivates their account, which removes them from future shifts through the normal release flow. Leaving a lead or coordinator role removes that access immediately. Member sync, where configured, deactivates accounts for people who leave the organization.

### The project itself

- **SECURITY.md** in the repository says how to report a vulnerability privately (security@encylia.com) and how fast to expect a reply.
- **Automated dependency checks** (for example Dependabot) open pull requests for vulnerable packages.
- **Release notes call out security fixes** so organizations running their own install know when to update.
- **A security review before the first deployment goes live**, covering access rules (who sees what), action links and the login flow.

## 14. Settings

Organization: name, time zone, weeks to generate ahead, required shadow shifts, reminder timing, public calendar on or off, "become a volunteer" link.
Per area: name, escalation timings, chat webhook, whether it appears on the public calendar.
Integrations: email (SMTP), chat webhooks, SMS provider, member sync, hours export, Google Calendar.

## 15. Technical approach

- **One install per organization.** No multi-tenancy.
- **Server:** AlmaLinux.
- **Language and framework:** PHP with Laravel, starting on Laravel 13 (PHP 8.3 or newer, which AlmaLinux 10 ships by default). Laravel's notification system already covers mail, SMS, and webhook channels, it has a built-in scheduler and job queue for generation, escalation, and sync jobs, and its Fortify package handles login, two-factor authentication and rate limiting.
- **Screens:** Blade, Laravel's built-in server-rendered templates, with as little JavaScript as possible. It has the fewest dependencies to upgrade, works well on phones, and makes accessible markup the default. Styling is one plain CSS file with no build step and no Node on the server, and the font (Atkinson Hyperlegible, as in the wireframes) is served from the install itself, so no outside font service sees who's using the app.
- **Tests:** PHPUnit, Laravel's default test framework, run against MariaDB rather than SQLite so date and time handling matches production.
- **Planning for upgrades:** Laravel releases a major version about once a year, and each gets security fixes for two years, so every install will face a major upgrade roughly yearly. Organizations run SSVS with volunteer maintainers, so upgrades have to stay small and safe:
  - Stay close to framework defaults and documented conventions. Avoid overriding framework internals.
  - Add third-party packages only when they save real work, prefer first-party Laravel packages, and record why each one was added.
  - Keep automated tests on every core flow (section 8) and on the access rules (section 13), so an upgrade can be checked by running the tests.
  - Move SSVS to each new Laravel major within six months of its release, and note the required PHP version and any server changes in the release notes.
  - Run automated dependency checks so vulnerable packages show up as pull requests.
- **Database:** MariaDB.
- **Deployment:** a single server behind nginx, optionally in Docker. Nightly database dump stored off the server, plus the host's snapshot backups.
- **License:** AGPL-3.0, so anyone who runs a modified version as a service shares their changes.

## 16. Phases

**Phase 1: run the front desk.**
Invite-only accounts with password and email-link login, 2FA for admins, coordinators and area leads. Roles. Areas. Weekly slots (every week and every other week) with regulars. One-time shifts. Overlapping shifts. Closures. Taking, releasing, and claim links. Cover requests and the full escalation ladder. Editing with "only this date" or "all following", followed by change notices. Shadow spots, clearance, the required-shadow setting, and moving back. Rotation sign-up. Email and chat notifications. Per-person notification settings. The coordinator week view and "needs attention" list. The security and privacy requirements in section 13, including SECURITY.md and a security review before go-live.

**Phase 2: visibility.**
Events with organizers. Public calendar. Google Calendar publishing and personal iCal feeds. Member directory sync.

**Phase 3: polish.**
Hours export. Direct person-to-person swaps. Staffing reports. Skills on event shifts. Text messages, once a provider is chosen and registered.

**Phase 4: hours and credit.**
Volunteer hours (worked shifts, no-shows and each person's totals), credit requests and the review queue (section 8.6). Adding approved credit to the membership system automatically comes after that.

## 17. Launch approach

Run a test copy a couple of weeks before launch. Coordinators enter the real schedule there, confirming each regular as they go, which doubles as their training and surfaces confusing screens while they're cheap to fix. Copy the finished schedule into the live install, then launch, so on day one every volunteer logs in and finds their shifts already there. Don't auto-import an old shared calendar: they tend to carry stale recurring entries and inconsistent names.

## 18. Decision log

| Date | Decision |
|---|---|
| 2026-10-04 | Build our own after reviewing hosted and self-hosted options; price and integration needs ruled out the paid tools. |
| 2026-10-04 | Standalone app, not part of an existing member tool. |
| 2026-10-04 | Releasing a shift frees the volunteer immediately; escalation makes sure it doesn't stay empty. |
| 2026-10-04 | Edits ask "only this date" or "all following" before anyone is notified. |
| 2026-10-04 | Volunteers claim existing shifts and never set their own hours. |
| 2026-10-04 | Every-other-week slots are supported from phase 1. |
| 2026-10-04 | Shadow shifts are their own assignment type and are never required to be filled. |
| 2026-10-04 | "Cleared for regular shifts" is a flag on the volunteer, off by default; required shadow count is a coordinator setting, default 2. |
| 2026-10-04 | Moving someone back to shadowing asks whether to keep or release their upcoming shifts. |
| 2026-10-04 | Google Calendar sync is one way. |
| 2026-10-04 | Text messaging is built now and stays off until a provider is chosen. |
| 2026-10-04 | AlmaLinux, PHP, MariaDB, AGPL-3.0. |
| 2026-10-04 | Email and text links do one action only; calendar feeds are resettable and show only the owner's shifts. |
| 2026-10-04 | Contact details are visible only to leads, coordinators and admins. |
| 2026-10-04 | Security reports go to security@encylia.com (SECURITY.md). |
| 2026-10-04 | Generic product, one install per organization; the Lansing Makers Network is the first deployment. |
| 2026-10-14 | AGPL-3.0 confirmed; LICENSE file added. |
| 2026-10-14 | Laravel confirmed as the framework, starting on Laravel 13, with upgrades planned for from the start (section 15). |
| 2026-10-14 | Hello Club can't stand in for shifts, so SSVS is built separately. Member directory sync from Hello Club stays in phase 2. |
| 2026-10-14 | No integration with Badgey (LMN's hours tool). The generic hours export stays in phase 3 for other organizations. |
| 2026-10-14 | Wireframes reviewed with LMN volunteers; the feedback was positive, so building starts. |
| 2026-10-14 | Screens use Blade with minimal JavaScript; tests use PHPUnit against MariaDB. |
| 2026-10-14 | One time zone per install. Volunteers work at the organization's own location, so every shift uses the organization's time zone and shift times follow the local clock through daylight saving changes (a 6 to 9pm shift stays 6 to 9pm). |
| 2026-10-14 | Shifts are generated daily and existing shifts are never changed by generation; adding a regular also adds them to already-generated shifts that haven't started, still have room, and don't already include them. Dated shift times are stored in UTC. |
| 2026-10-14 | Alternate-week regulars live on one weekly slot: each regular has their own frequency and anchor date, rather than splitting the slot into two every-other-week slots. Coordinators think of "the Thursday desk shift" as one thing. |
| 2026-10-16 | Login, invitations and two-factor are built backend first; their screens follow a quick volunteer check of new wireframes (UX principle 6). |
| 2026-10-16 | Two-factor for coordinators and admins: authenticator app plus recovery codes, with an emailed code as a fallback after password login only. |
| 2026-10-16 | Laravel Fortify provides login, password reset, rate limiting and two-factor. Passkeys stay off for now (Fortify's passkey package is pre-1.0). |
| 2026-10-16 | Area leads also need two-factor and a password: they can see contact details for their areas. |
| 2026-10-16 | Screens use one plain CSS file with no build step; the skeleton's Vite, Tailwind and npm setup is removed, and the font is self-hosted. |
| 2026-10-16 | Minimum password length is 10 characters, with no check against breached-password lists, because that needs an outside service call on every password change. |
| 2026-10-16 | An invitation link lasts 7 days and an email-login link lasts 15 minutes; asking for a new link cancels the previous unused one of the same kind. Opening a link never logs anyone in; only the button on its page does, because email security scanners open links. |
| 2026-10-16 | An emailed two-factor code lasts 10 minutes and allows 5 tries, and is offered only after a successful password login in the same attempt. |
| 2026-10-16 | Sessions expire after 120 minutes of inactivity (Laravel's default). |
| 2026-10-16 | Admins can grant any role; coordinators can grant only area lead. Only admins can deactivate an admin, and nobody can deactivate themselves. |
| 2026-10-16 | `ssvs:setup` prints the first admin's invitation link as well as emailing it, so a new install works before email is configured. |
| 2026-10-16 | Login-link requests and password reset requests give the same answer for every address, and their emails are sent after the response, so neither the wording nor the timing reveals who has an account. |
| 2026-10-16 | A deactivated person's open session ends on their next request, whatever session store the install uses. |
| 2026-10-16 | After a password reset by email, the emailed two-factor code is refused until the person next logs in with their authenticator app or a recovery code, because a reset uses only the inbox. |
| 2026-10-17 | Every change to stored data is logged with who, what (old and new values where there are any) and when, and the log is filterable by actor, affected person, record, kind of change and date. |
| 2026-10-17 | When a person's account is removed, their name in the change log becomes "Removed person" and their contact details are blanked, so the log keeps its history without keeping their details. |
| 2026-10-17 | Shift generation logs one entry per run that creates shifts, not one per shift, written after the run because each shift is created in its own transaction. |
| 2026-10-17 | The app records each person's last login and last seen times (last seen at most once an hour). They aren't change log entries. Inactivity is measured from last seen, because a remember-me cookie means some people rarely log in. |
| 2026-10-17 | A users page lets area leads and up see people's roles, invitation status, last login and last seen. Coordinators and admins see everyone; an area lead sees the people who have worked or are scheduled in their areas. Deactivated people stay in that list so the page can filter them. |
| 2026-10-17 | Coordinators never see an invitation link, only its status, because the link logs in as that person. Only `ssvs:setup` prints one. |
| 2026-10-17 | Signing in changes nothing in the change log: clearing a pending password reset after an authenticator-app login and starting a session aren't logged. Using a recovery code is logged, because it uses one up. |
| 2026-10-17 | An admin can reset a person's two-factor when they've lost both their phone and their recovery codes. The admin first checks in person or by phone that it's really them, because anyone can send an email or chat message pretending to be someone. The reset logs the person out everywhere and emails them, and they set it up again on their next login. The reset is logged. Anyone can add a password and log in with it; area leads, coordinators and admins must have one. |
| 2026-10-17 | Volunteers can change their own email address. They confirm who they are first, the new address has to be confirmed by a link before it takes effect, the old address is told, and every other device is logged out. Member sync matches people by membership ID so a changed address doesn't break it. |
| 2026-10-17 | How an email change works in detail: someone without a password confirms it's them with a link to their current address that works for 15 minutes, and only people without a password can ask for one. The link to the new address works for 60 minutes and marks the new address as confirmed. Opening the confirm-it's-you link logs that person in on that device, like a sign-in link, and logs out anyone else who was logged in there; it only works for people with no password, who never have two-factor. The link to the new address never logs anyone in, and neither link works on the login link page. Confirming with that link counts only for changing the email address, never as a password confirmation anywhere else. The new address's format is checked before the app code, so a typo doesn't use up a code. Each person can send the change form five times every 10 minutes, and separately ask for the link to their current address five times every 10 minutes. |
| 2026-10-17 | The home page (`/`) is a greeting placeholder until the schedule screens exist, and guests who go there are sent to the login page. |
| 2026-10-17 | "Keep me logged in on this device" sits in the password block and on the login link landing page, not on the link request, because the link is often opened on a different device and the box would remember the wrong one. |
| 2026-10-17 | Setting a first password also starts the authenticator app setup: the password counts as just confirmed and the QR code shows straight away. Someone who already has a password sees a "Set up the app" button instead. |
| 2026-10-17 | Recovery codes are shown only right after the app setup or after making new ones, and there is no page to view current codes. "Download codes" is a plain link to a text file and "Copy codes" is the only script in the sign-in screens. |
| 2026-10-17 | An admin can't reset their own two-factor, so another admin has to, and a reset needs the person's app to be connected. Between the reset and the person's next login, anyone who knows their password could log in and connect their own app; the identity check and the email to the person limit that, and a reset that expires if unused is a possible later change. |
| 2026-10-17 | An email change keeps the session that confirmed it, if it belongs to the person, and ends every other session. It also rotates the remember-me token, so a remember-me cookie on this device stops working the next time its session ends. |
| 2026-10-17 | SSVS is the record of volunteer hours, which reverses the earlier non-goal. A shift counts as worked once it ends unless its lead marks a no-show. Volunteers can ask to turn hours into credit, and a small group reviews the requests. Adding approved credit to the membership system automatically is a later goal. |
| 2026-10-17 | Text messages move to phase 3. Two-factor never used them (it uses an authenticator app with an emailed fallback), and email and chat cover notifications until then. |
| 2026-10-17 | Home, Login and security, and both account setup steps have a "Log out" button. Common errors (403, 404, 419 and 429) get plain pages with a way forward, and the 429 page says how long to wait when the limit says so. Setting a first password when you already have one sends you to Login and security instead of an error. "Forgot your password?" on the confirm-your-password screen logs you out first, because the reset screens are for logged-out people. |
| 2026-10-17 | Pages that show the authenticator setup key, QR code or recovery codes send `Cache-Control: no-store`, so the browser doesn't keep a copy. Connecting a new app clears a pending password reset the same way an app code at login does, and isn't logged separately. Typing your own address in the email change form gets a field error, since it reveals nothing about other accounts. A used or expired invitation explains invitations instead of login links. |

## 19. Open questions

- Text message provider and sender type, for phase 3. The October 2026 research in docs/background.md recommends a verified toll-free number.
- Who reviews credit requests (a new permission an admin grants, or a role), and whether the value of an hour is a setting.
