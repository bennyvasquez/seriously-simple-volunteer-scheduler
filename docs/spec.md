# Stupid Simple Volunteer Scheduler: product spec

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
- Logging volunteer hours as the system of record. The app sends completed shifts to whatever system does that (section 11).
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
| Event | Optional. Something with a date or date range, a location, a description, and an organizer, such as a festival booth. An event holds one or more shifts. |
| Weekly slot | A repeating shift template: area, weekday, start and end time, how many people it needs, how many shadow spots it offers, an optional required skill, and whether it repeats every week or every other week. |
| Shift | One dated occurrence. Generated from a weekly slot, or created on its own (one-time shifts and event shifts). |
| Regular | A person attached to a weekly slot, with a start date and an optional end date. Regulars are filled into each generated shift automatically. |
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
- **Every week or every other week.** An every-other-week slot has an anchor date. Two people can split one weekly slot by each holding alternate weeks.
- **Overlapping shifts are allowed.** A day can have several shifts in the same area at overlapping times, each with its own headcount. Coordinators set the times; volunteers only claim shifts that exist.
- **Headcount varies by slot.** Seasonal changes are handled by editing a slot from a date forward (section 8.4).
- **Times are stored in the organization's local time** for slots, so a weekly shift doesn't move by an hour when daylight saving time changes. Dated shifts store the full date and time with time zone.
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
- **Text messages** are built in phase 1 behind a provider interface and stay off until an organization configures a provider. US senders need carrier registration (10DLC or toll-free verification) before texting, which can take weeks. Phone numbers need a confirmation code and an explicit opt-in per notice type. Carrier email-to-text gateways are not an option; the major US carriers have shut them down or are shutting them down.
- **Signed links:** links in emails and texts are signed for that recipient, single-use, and short-lived, and log the person straight in to the relevant screen. Links posted in chat are public, so they require a normal login first.
- **Reminders:** a reminder before each shift (setting, default 24 hours).

## 11. Integrations

Each one is optional and off by default.

- **Member directory sync.** A scheduled job reads people from an external membership system and creates invitations or deactivates accounts to match. The first adapter is Hello Club, which has an API with key-based access and a rate limit of about 30 requests per minute (per an unofficial guide; confirm against the vendor's docs).
- **Hours export.** When a shift ends, the app sends the completed assignment (person, date, area, duration, regular or shadow) to a webhook, so a separate system can record volunteer hours without anyone typing them twice.
- **Google Calendar, one way.** The app publishes shifts to organization-owned Google calendars (one per area, or one combined) through a Google service account. No volunteer has to connect a Google account. Two-way sync is out of scope; it needs per-user Google sign-in, which brings Google's app verification review and a lot of breakage.
- **Personal calendar feeds.** Every volunteer gets a private iCal feed of their own shifts, with an "Add to my calendar" button on the home screen.

## 12. Public calendar

A public page lists events and areas marked public, with counts such as "needs 2 more volunteers". It never shows volunteer names. Members are sent to log in; everyone else is sent to the organization's "become a volunteer" page.

## 13. Accounts and security

- **Invite-only.** Accounts exist only after a coordinator invites someone, directly or through member sync.
- **Login:** password, or a one-time email link. Passwords are hashed with a modern algorithm; login and reset are rate-limited.
- **Two-factor authentication is required** for coordinators and admins, since they can see everyone's contact details.
- **Change log** for clearance, role changes, and schedule edits.
- **Data minimization:** the app stores name, email, optional phone, roles, skills, and schedule history. Nothing else is required.

## 14. Settings

Organization: name, time zone, weeks to generate ahead, required shadow shifts, reminder timing, public calendar on or off, "become a volunteer" link.
Per area: name, escalation timings, chat webhook, whether it appears on the public calendar.
Integrations: email (SMTP), chat webhooks, SMS provider, member sync, hours export, Google Calendar.

## 15. Technical approach

- **One install per organization.** No multi-tenancy.
- **Server:** AlmaLinux.
- **Language and framework:** PHP. Laravel is the proposed framework because its notification system already covers mail, SMS, and webhook channels, and it has a built-in scheduler and job queue for generation, escalation, and sync jobs. Confirm with the first deploying team before scaffolding.
- **Database:** MariaDB.
- **Deployment:** a single server behind nginx, optionally in Docker. Nightly database dump stored off the server, plus the host's snapshot backups.
- **License:** AGPL-3.0, so anyone who runs a modified version as a service shares their changes.

## 16. Phases

**Phase 1: run the front desk.**
Invite-only accounts with password and email-link login, 2FA for coordinators and admins. Roles. Areas. Weekly slots (every week and every other week) with regulars. One-time shifts. Overlapping shifts. Closures. Taking, releasing, and claim links. Cover requests and the full escalation ladder. Editing with "only this date" or "all following", followed by change notices. Shadow spots, clearance, the required-shadow setting, and moving back. Rotation sign-up. Email and chat notifications. SMS built but switched off. Per-person notification settings. The coordinator week view and "needs attention" list.

**Phase 2: visibility.**
Events with organizers. Public calendar. Google Calendar publishing and personal iCal feeds. Member directory sync. SMS switched on once a provider is registered.

**Phase 3: polish.**
Hours export. Direct person-to-person swaps. Staffing reports. Skills on event shifts.

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
| 2026-10-04 | Generic product, one install per organization; the Lansing Makers Network is the first deployment. |

## 19. Open questions

- Framework: confirm Laravel with the first deploying team.
- Repository home: benny's account for now; whether and when it moves to an organization.
- Text message provider and sender type.
- Whether hours export needs a specific format for the first deployment.
- Wireframes: a clickable prototype exists as a private design canvas; it needs to be shared with the volunteer committee and tested with three to five volunteers.
