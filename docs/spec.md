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
| Shadow spot | An optional extra place on a shift for someone in training. It never counts toward the shift's people needed and never triggers cover requests. |
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

One person can hold several roles. Nobody sees schedule details until a coordinator has invited them and they've accepted. Each area can name its leads (for example "Shift lead" for Front desk), and an area with no name set says "Area lead". Only the label changes: the role is still area lead.

## 7. Scheduling model

- **Weekly slots generate shifts** a set number of weeks ahead (setting, default 8). Each generated shift copies the slot's details and fills in its regulars, leaving out anyone whose account is turned off. All of a slot's regulars for that date go on, even when that is more than the shift's people needed.
- **Generation runs daily** and never changes a shift that already exists, so per-date edits survive. It skips dates covered by a closure and shifts that have already started. Adding a regular also adds them to the slot's already-generated shifts that haven't started, aren't cancelled and don't overlap another shift they're on.
- **Every week or every other week.** An every-other-week slot has an anchor date. On a weekly slot, each regular can also work every week or every other week, with their own anchor date, so two people can split one weekly slot by each holding alternate weeks. A slot's people needed is checked per date, so alternating regulars don't count against each other. People needed is the number a shift needs, not a limit (see the 2026-11-13 decision log row).
- **Overlapping shifts are allowed.** A day can have several shifts in the same area at overlapping times, each with its own people needed. Coordinators set the times; volunteers only claim shifts that exist. One person can't be on two active shifts whose times overlap (back to back is fine): putting them on one is refused with the shift they're already on, and adding a regular leaves those dates out and says which.
- **People needed varies by slot.** Seasonal changes are made by editing the slot, which updates its future shifts nobody changed on their own (section 8.4). Editing a slot from a date forward, so earlier dates keep the old details, comes with the release flow plan.
- **Times are stored in the organization's local time** for slots, so a weekly shift doesn't move by an hour when daylight saving time changes. Dated shifts store their start and end in UTC, plus the local date.
- **Exceptions are per date.** Releasing one date never changes the slot or the regular's other dates. A coordinator or area lead can remove someone from one shift, or from every shift they're on in a range of dates in the areas they manage; the assignment is deleted (the change log keeps who was on it), the person is emailed once, and the shift shows its open place in Needs attention. Ending a regular's time on a slot (section 8.6) removes them from the slot's shifts after the last date.
- **Closures** cancel the shifts they cover and notify everyone on them.
- **Shifts are never deleted.** Cancelling a shift sets a cancelled time and a reason, and the shift stays on the calendar marked as cancelled, so generation doesn't recreate it and a coordinator can see what happened. A shift cancelled by hand can be brought back with "Bring this shift back" (section 8.4). Assignments can be deleted, because the change log keeps who was on each shift.
- **Creating or editing a weekly slot generates its shifts right away**, for that slot only, using the same rules as the daily run.
- **Editing a weekly slot reaches the future shifts people are on** when nothing clashes. A staffed shift gets the slot's new details unless that would leave fewer shadow spots than shadows on it or double-book someone on it; then it keeps its old details and the form lists it with the reason. Each person who can sign in gets one email listing their shifts whose time or title changed. Changing only people needed or shadow spots emails nobody. Shifts that have started are never changed.
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

The full flow below is what the release flow plan builds. What exists today is the first half of it, and the rest waits.

**Built now.** A coordinator or area lead edits a shift that belongs to a weekly slot, and the app asks "This shift" or "The weekly slot (future shifts nobody changed on their own)".

1. "This shift" changes that one date, and later edits to the slot don't overwrite it. It's allowed when people are on it, except that it's refused when it would leave fewer shadow spots than shadows on it, or move the time so it overlaps another shift one of them is on. Lowering people needed below the people on it is allowed, since going over is allowed.
2. "The weekly slot" changes the slot and the future shifts nobody changed on their own, including shifts people are on when nothing clashes (section 7). The slot's day and how often it repeats still can't change while it has regulars or people on future shifts.
3. **Plain change emails.** A change to a shift's time or title emails everyone on it who can sign in, once, listing what changed. A slot change sends each person one email listing all their changed shifts. A change to people needed or shadow spots alone emails nobody. The panel says so before saving: "The 1 person on it is emailed if the time or what it's for changes." (or "The {n} people on it are emailed if the time or what it's for changes.").
4. **Bring this shift back.** A shift cancelled by hand has a "Bring this shift back" button. A confirm step says how many people will be emailed that it's back on and who will be removed because they're now on another shift at that time. It's not offered, with the reason shown instead, when a closure cancelled the shift ("Cancelled by the {reason} closure. Change the closure to bring it back."), when the weekly slot no longer runs on that date ("The weekly slot doesn't run on this date any more. Change the slot to bring it back."), or when a later closure covers the date ("{Area} is closed that day: {reason}."). Shifts that have started are never brought back. A shift from a weekly slot that nobody changed on its own comes back with the slot's current details for that date (time, people needed and title, and shadow spots unless the slot's are fewer than the shadows on it), since slot changes skip cancelled shifts, and the slot's regulars who work that date and aren't turned off are put on it, including ones added while it was cancelled. A regular who was taken off that date before it was cancelled stays off it. A shift changed on its own comes back with its own details and only the people already on it. The "back on" email shows the shift's time as it comes back, and the confirm step counts the regulars it adds.
5. **Remove from shift.** Each person on a shift has a "Remove from shift" link. The page asks "Which shifts?": just that date, or all of the person's shifts from one date to another, with a "Check which shifts" step that lists them before anything is removed. The range only includes shifts in areas the person using it manages, and says how many it left out. The range refuses with "Pick two dates." (a date missing or not a date), "Pick dates from today on." and "The last date can't be before the first date." When the range goes past the shifts on the calendar (the generation window), the check step adds "Shifts after {Weekday, Month day} aren't on the calendar yet, so they aren't included. To stop {First name} working those, end their time as a regular." The person is emailed once, listing every shift, and after it the status says "{First name} was removed from 1 shift." or "{First name} was removed from {n} shifts." and adds "They were emailed." only when at least one of the shifts wasn't cancelled and the person can sign in. Shifts that have started are never touched. A shift they're removed from shows its open place in Needs attention.

**Still waiting for the release flow plan** (it needs "Can't make it", cover requests and single-use action links first):

1. The "This date and all following" choice, which splits the slot at that date. Earlier dates keep the old details; regulars carry over to the new version.
2. The two buttons in the change email, "Still works for me" and "I can't make the new time". The second runs the release flow.
3. The 48-hour heads-up: if the change moved the time and someone hasn't answered 48 hours before the shift, their lead gets a quiet heads-up.

### 8.5 Closures

A coordinator or admin adds a closure for one or more areas and a date range, with a reason. Affected shifts are cancelled and everyone on them is notified. Closures don't create cover requests.

- The form lists what it will cancel, with who is on each shift, before saving.
- Shifts that have started are never touched, and shifts already cancelled (by hand or by an earlier closure) stay as they are, so nobody is emailed twice for the same shift. Closures for the same area may overlap; both show.
- Each active person on the cancelled shifts gets one email listing all of their cancelled shifts. A later closure that overlaps an earlier one emails people only about the shifts it newly cancels. Cancelled shifts stay on the calendar, marked with the reason, and the nightly run never makes shifts for closed days, even when a closure is saved while it is running.
- A closure whose first day is before today is stored starting today, and one whose last day is before today is refused.
- A one-time shift can't be added on a day a closure covers in its area; the form says the area is closed and why.
- Closures show on the week and day views as an all-day bar at the top of each closed day, and on the month view as "Closed: {reason}". A slot edit never brings back a shift a closure cancelled.
- **Changing a closure.** Coordinators and admins (not area leads) open a closure from its label on the calendar. A closure for several areas is one closure per area, each changed on its own: a label shared by several areas reads "Closed: Thanksgiving. Change: Front desk, Shop", with a link for each area. The change page shows "What this changes": the shifts it would cancel and the shifts it would bring back, with who is on each. Days it no longer covers get their shifts back, newly covered days have theirs cancelled, and a new reason is copied onto the cancelled shifts without another email. A day that comes out from under a closure but whose weekly slot no longer runs on it stays cancelled with the reason "The weekly slot changed", and nobody is emailed. A shift that comes back gets its weekly slot's current details and regulars the same way "Bring this shift back" does (section 8.4), and the previews count those regulars among the people emailed. Anyone on a shift that comes back who is now on another shift at that time is removed from it. Each person gets one email per change, covering shifts cancelled and shifts brought back together. Shifts missing for days that reopened are made right after the change is saved.
- **Limits on changing.** A closure that has started keeps its first day, and its last day can't move before yesterday ("The last day can't be before yesterday."); ending it yesterday reopens today. A closure that has ended can't be changed ("This closure has ended. To close more days, add a new closure."), and one that has started says "This closure has already started, so only its last day can change. To fix days that have passed, remove it."
- **Removing a closure**, including one whose days have passed. Future shifts it cancelled come back (or pass to another closure that covers the day, or stay cancelled when the slot no longer runs that day), and people on them are emailed. Days that have passed lose the "Closed" label and their shifts stay cancelled. The screen says so: "Days that have passed lose the Closed label. Their shifts stay cancelled."

### 8.6 Volunteer hours and credit

SSVS is the record of volunteer hours.

- **Ending a regular.** A coordinator or area lead ends a regular's time on a weekly slot with "End regular" on the shift panel, choosing the last date they work. They're removed from every shift of that slot after that date, and emailed about the future ones. The date can be in the past, so the record matches when they really stopped. In that case they're also removed from the past shifts after that date, so those hours don't count as worked, but only after a confirmation that lists the past shifts ("{First name} is on {n} past shifts after {date}. Ending their time as a regular removes them from those too, so they don't count as worked:"). Nobody is emailed about past shifts. Only the regular's own spots within that stint are removed: a later stint as a regular on the same slot is left alone, and shadow spots stay. A later last date than the one a regular already has is refused ("To extend it, add them as a regular again."). A regular ended before they ever started is stored with an end the day before their start and counts as never current, so it doesn't stop the person being added as a regular again for overlapping dates. In that case the email and the status say they're no longer the regular without naming the date, and the status leaves out the shifts sentence when they weren't removed from any.

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

- **Member directory sync.** A scheduled job reads people from an external membership system and creates invitations or deactivates accounts to match. It matches people by their ID in the membership system, never by email address, because volunteers can change their own address. The first adapter is Hello Club, which has an API with key-based access and a rate limit of about 30 requests per minute (per an unofficial guide; confirm against the vendor's docs). Until that sync exists, an admin can load the volunteer list from a Hello Club members export with `ssvs:import-people` (section 13). It stores each person's membership ID (the export's Profile ID column) so the sync can recognize them later; confirm against Hello Club's API documentation that this is the ID its API uses before building the sync.
- **Hours export,** for organizations that keep hours somewhere else as well. When a shift ends, the app sends the completed assignment (person, date, area, duration, regular or shadow) to a webhook, so a separate system can record volunteer hours without anyone typing them twice.
- **Credit to the membership system (later goal).** Approved credit requests could be added to the member's record in the membership system automatically, starting with Hello Club. That needs write access, unlike member sync, so it would be the one exception to read-only access (section 13).
- **Google Calendar, one way.** The app publishes shifts to organization-owned Google calendars (one per area, or one combined) through a Google service account. No volunteer has to connect a Google account. Two-way sync is out of scope; it needs per-user Google sign-in, which brings Google's app verification review and a lot of breakage.
- **Personal calendar feeds.** Every volunteer gets a private iCal feed of their own shifts, with an "Add to my calendar" button on the home screen. The feed address is a secret, so it shows only that person's shifts (no other volunteers' names) and has a "reset my calendar link" button that invalidates the old address.

## 12. Public calendar

A public page lists events and areas marked public, with counts such as "needs 2 more volunteers". It never shows volunteer names. Members are sent to log in; everyone else is sent to the organization's "become a volunteer" page.

## 13. Security and privacy

Volunteers trust the organization with their contact details and their whereabouts. The app should hold as little as it needs, show it only to the people who need it, and be safe to run for organizations without a security team.

### Accounts and access

- **Invite-only.** Accounts exist only after a coordinator invites someone, directly or through member sync. People brought in by the volunteer import exist before they're invited, but they can't sign in or get any email until a coordinator invites them.
- **Login:** password, or a one-time email link. Passwords are hashed with a modern algorithm (bcrypt or Argon2id); login, reset and invite endpoints are rate-limited. Passwords need at least 10 characters. Login-link requests and password reset requests answer the same way for every address, so they never reveal who has an account. A deactivated account gets no login link or reset email, with the same answer on screen. Opening an emailed link shows a page with a button rather than logging in straight away, because some email security scanners open every link in a message.
- **Login, invitations and two-factor setup are built on Laravel Fortify**, Laravel's first-party authentication backend, with our own screens.
- **Two-factor authentication is required** for admins, coordinators and area leads, since they can all see volunteers' contact details. They also need a password. The second factor is a code from an authenticator app, with one-time recovery codes. If the phone isn't available, a code can be emailed instead, but only after a password login: after an email-link login the inbox has already been used once, so an emailed code wouldn't be a second factor. For the same reason, after a password reset by email the emailed code is refused until the person next logs in with their authenticator app or a recovery code, or connects a new app. A reset also emails the person to say their password was changed. Someone who has lost both their phone and their recovery codes asks an admin, who can reset their two-factor after checking in person or by phone that it's really them, never only by email or chat. The reset logs them out everywhere, emails them to say it happened, and goes in the change log; they set up a new app and get new recovery codes the next time they log in with their password.
- **Action links in emails and texts do one thing only** (section 10) and never open a full session. Login, invitation and confirm-it's-you links are login links, not action links.
- **Sessions** expire after 120 minutes of inactivity, and a person can sign out of all devices from their profile. Deactivating a person ends their open sessions on their next request.
- **Changing your email address.** Anyone can change their own address after confirming who they are again: with their password (plus their app code, for area leads and up), or with a link emailed to their current address if they have no password. We then email a link to the new address, and the change happens only when that link is opened, so a typo can't lock anyone out; until then the old address keeps working. The old address gets an email saying the login address changed, showing the new one partly hidden, so the real owner hears about a change they didn't make. The change logs the person out on every other device, keeps the one they used signed in, and goes in the change log. An address another account already uses gets the same answer on screen, so the form never reveals who has an account. Typing your own current address gets a plain "That's already your address.", since that says nothing about anyone else.
- **Not invited yet.** A person who has been added but not yet invited (`invited_at` is empty) gets no email of any kind and can't sign in by link, password or password reset. Every screen answers for them exactly as it does for an address with no account, so nothing reveals the difference. Inviting them is what changes that. Accounts that existed before this status was added count as invited. Coordinators and admins invite one person from the person page or the invite form, or everyone not invited yet from the people list.
- **First-run setup:** `php artisan ssvs:setup` creates the organization and its first admin, and prints that admin's invitation link so an install works before email is configured.
- **Importing the volunteer list:** `php artisan ssvs:import-people {file} --as=<admin email>` reads a Hello Club members export, shows who it would add, who already exists and who it skipped (with the reason), and saves nothing until the admin says yes. It reads only the Full name, Email and Profile ID columns (and Categories, which it reads but uses only when `--lead-tag` is given) and never stores, logs or prints anything else. New people are added as not invited yet, so nobody is emailed. People who already exist are left alone, except that someone matched by email with no membership ID gets it filled in, logged as `user.member_id_added`. `--lead-tag` with `--lead-area` makes everyone with that category an area lead of that area.
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

- **Stored:** name, email, membership ID (when the organization's membership system gives one), optional phone number, roles, skills, clearance status, notification settings, last login and last seen times, and schedule history. Nothing else is required.
- **Phone numbers** are only stored after the person confirms them with a code, and are deleted when they turn text messages off.
- **Inactive accounts** (not seen and no shifts for a set period; setting, default 18 months) are flagged to coordinators for removal.
- **Removal on request:** a coordinator or admin can delete a person's account and contact details. Their past shifts, regular slots and change log entries move onto one shared "Removed person" record (email `removed-person@invalid`, never able to sign in, never listed), and their own row is deleted, so nothing left can tell one removed person from another. It also blanks their name, email address and membership ID in the change log, rewrites their user ID wherever it is stored inside change log details and changes (assignment and regular entries, the people told about a cancelled shift, the regulars a slot change put back) to the shared record, and deletes their password reset tokens. The entries themselves stay. Removal first removes them from their future shifts and ends their regular slots, and is refused only if something lands on their schedule in between (someone puts them on a shift, say), in which case trying again releases that too. Only an admin can remove an admin, nobody can remove themselves, and it can't be undone. Login throttling can hold the address in the cache for up to 10 minutes after removal (the login limiter is per minute and the login link limiter allows 3 in 10 minutes, both keyed by email and IP address). No account may use an address ending in `@invalid`, so nobody can take the shared record's address.
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

When someone stops volunteering, a coordinator turns their account off. That removes them from every future shift (cancelled ones included) and ends each of their regular slots yesterday, in the same click and without emailing them, because the person is leaving and the people running the schedule asked for it. The Turn off section on their page says how many shifts and slots that is before the button. Shifts that have started keep them, so history stays. Turning an account back on doesn't put them back on anything. Removing a person does the same first: their schedule is released and committed, and then the removal happens, so if the removal is then refused their schedule stays cleared. People turned off before this change were left on their future shifts; turning them off again tidies that, and there's no button for it yet. Leaving a lead or coordinator role removes that access immediately. Member sync, where configured, deactivates accounts for people who leave the organization.

### The project itself

- **SECURITY.md** in the repository says how to report a vulnerability privately (security@encylia.com) and how fast to expect a reply.
- **Automated dependency checks** (for example Dependabot) open pull requests for vulnerable packages.
- **Release notes call out security fixes** so organizations running their own install know when to update.
- **A security review before the first deployment goes live**, covering access rules (who sees what), action links and the login flow.

## 14. Settings

Organization: name, time zone, weeks to generate ahead, required shadow shifts, reminder timing, public calendar on or off, "become a volunteer" link. Built so far: name, time zone and weeks to generate ahead (1 to 26), changed by admins only. The rest come with their features.
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

Run a test copy a couple of weeks before launch. Coordinators enter the real schedule there, confirming each regular as they go, which doubles as their training and surfaces confusing screens while they're cheap to fix. Load the volunteer list into the test copy with `ssvs:import-people`, which adds people as not invited yet. Copy the whole test database to the live install, which keeps the schedule, the people and the change log together, use the test copy's `APP_KEY` in the live install's `.env` (Fortify encrypts two-factor secrets and recovery codes with it, so a different key breaks every coordinator's authenticator), then press "Invite everyone not invited yet" on the people list, so on day one every volunteer logs in and finds their shifts already there. Don't invite volunteers from the test copy. Don't auto-import an old shared calendar: they tend to carry stale recurring entries and inconsistent names.

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
| 2026-10-17 | On the coordinator calendar, a coordinator or the area's lead can put an active person on a shift (as a regular for that date or as a shadow) and cancel one date. Both are logged (about the person for adding, about the shift for cancelling) and email the people affected after the response. A cancelled date stays on the calendar in the week, day and phone views, shown muted and marked "Cancelled" with its reason, because the nightly run would otherwise recreate it. It never counts as an open place, is never urgent, and is left out of Needs attention; the month view counts cancelled dates separately ("1 Front desk shift cancelled"). The person search on the shift panel matches names only for anyone who can't manage every area (an area lead), and shows an email or a full name only for people that viewer may see (section 13). A shift that has started or been cancelled can't be changed. Putting someone on a full shift is refused with "This shift is full." Putting someone on a shift is logged with the shift as the subject, about the person, with the new assignment's values, so a shift's history shows everyone put on it. |
| 2026-10-17 | A coordinator or the area's lead adds a regular to a weekly slot from the shift panel's "Add a regular" button (not offered for rotations or one-time shifts). The person is chosen by the same private search as the shift panel; anyone active can be a regular until clearance exists. For "every other week" the screen offers each alternating regular's off weeks and same weeks by name, or a date the coordinator picks, and the choice becomes the anchor date; a slot that already runs every other week has no such choice. The action itself checks the actor can manage the area, refuses rotations and people who are turned off, and emails the new regular after the response. Dates in the "slot is full" message are in US order. |
| 2026-10-17 | Until the editing plan adds change notices, editing never changes a shift someone is on or when someone works. Saving a weekly slot updates its future shifts that nobody is on and nobody edited on their own (times, headcount, shadow spots and title). If the first or last date, the day or how often changed, those shifts that no longer fit are cancelled with the reason "The weekly slot changed", without emails since nobody is on them, and the new dates are made right away. A date the slot runs on again whose shift a slot change cancelled (going by the change log, so a person who typed the same reason keeps their cancellation), with nobody on it and no closure, comes back with the slot's details and its current regulars, without emails; nothing else cancelled is ever brought back. Changing the day or how often it repeats is refused (changing an every-other-week slot's first date counts as changing how often it repeats, since it changes which weeks people work) while the slot has current or future regulars or a future shift someone is on, and switching to a rotation is refused while it has current or future regulars; the form says who, and suggests a last date and a new slot for a change of day, or asking an admin to end their time as regulars for a rotation. Shifts with people on them, and shifts edited on their own, keep their old details, and after saving the form lists them as links, separately for ones whose details differ and ones no longer on the slot's dates. Every shift a slot edit cancels, brings back or changes gets its own change log entry, alongside the slot's. A slot's area doesn't change when editing, and a first date in the past is accepted only when it is unchanged. On the shift panel, Edit on a repeating shift asks "This shift" or "The weekly slot (shifts nobody is on yet)"; editing one shift is refused while someone is on it, and marks it as edited so later slot changes leave it alone. |
| 2026-10-17 | Closures (section 8.5) are added by coordinators and admins only, for one or more areas at once, from "Add a closure" on the month view and in the week and day sidebar. A closure cancels the shifts in its areas and days that haven't started and aren't already cancelled, logs a change log entry for the closure and one for each shift it cancels, and emails each active person once with all their cancelled shifts. A closure that started before today is stored from today, one that ended before today is refused, and its reason is up to 200 characters. Overlapping closures for the same area are allowed, and a later one emails people only about the shifts it newly cancels. Closures and shift generation take the area's row lock, so a closure saved while shifts are being made still keeps its days empty. |
| 2026-10-17 | The coordinator side of the schedule is one calendar laid out like Google Calendar, because LMN's schedulers already use it. It has week, day and month views, weeks start on Sunday, and there is no dragging, so everything is a link or a form and it works without JavaScript. On a phone the week shows as a list by day. The week grid runs from an hour before the earliest shift to an hour after the latest, and at least 8am to 10pm. It replaces the volunteer-reviewed week wireframe for coordinators and leads; volunteers' own screens come in a later plan. |
| 2026-10-17 | On the calendar, coordinators and admins can change anything; area leads create and edit slots and shifts, put people on shifts, add regulars and cancel dates in their own areas; only coordinators and admins add areas and closures; only admins change settings. Everyone with a role can view the whole calendar. "Needs attention" lists, for the next 14 days, shifts with open places (thick border inside 24 hours), rotation shifts nobody signed up for, and weekly slots with no regular. "Ready for review" waits for clearance. |
| 2026-10-17 | In person pickers, the shift panel, the closure form's list of who is on each shift and the "Which weeks" choices, anyone who can't manage every area (an area lead) sees people outside their areas as a first name and last initial ("Robin E.") with no email, because a first name alone can't tell two people apart and a full name would go beyond section 13. |
| 2026-10-17 | Area colors come from a fixed palette of six whose text passes a 4.5 to 1 contrast ratio, given out in order and changeable when editing the area. There is no free color picker. |
| 2026-10-17 | Organization settings built so far are name, time zone and weeks to generate ahead (1 to 26), changed by admins only; the other organization settings in section 14 come with their features. Changing the time zone doesn't move or regenerate shifts already made; raising weeks ahead makes the extra shifts right away. Coordinators and admins add and edit areas from the calendar sidebar; a new area takes the next unused color, and names are unique ignoring case. |
| 2026-10-17 | One person can't be on two active shifts at overlapping times. Putting someone on a shift that overlaps another active shift they're on (as a regular or a shadow) is refused with that shift's area and time, and adding a regular leaves out the dates that overlap and lists them in the confirmation. Back-to-back shifts and cancelled shifts don't count. |
| 2026-10-17 | Every assignment is logged as `assignment.added` with the shift as the subject and the person as who it's about, with the assignment's id, type and values, whether a coordinator put the person on one shift or adding a regular filled the slot's existing shifts (one entry per shift). Regulars filled in by generation or when a slot change brings a date back are covered by those entries instead. |
| 2026-10-17 | A one-time shift is refused on a day a closure covers in its area ("Front desk is closed that day: Thanksgiving.") and when its start time has already passed. Creating one takes the area's row lock first, the same as closures and generation. Adding a regular locks the slot's shifts it reads, in date order, after the regulars, and checks on the locked rows that each is still open and has room. |
| 2026-10-17 | Generation and a slot change that brings a date back leave out regulars whose accounts are turned off, and put at most the shift's headcount of regulars on a new shift. |
| 2026-10-17 | On the slot and closure forms, choosing from a list, a radio button or a checkbox reloads the preview, but typing a date or time doesn't, since the page would reload mid-entry. "Update the preview" and "Check what this cancels" are always shown for that. Save buttons send the reload flag empty, so a form the browser restored on back or forward still saves. |
| 2026-10-17 | A weekly slot saved with no shifts on the calendar yet (one that starts beyond the weeks shown) links to its edit page and to adding a regular from the confirmation, and an area's edit page lists its weekly slots that haven't ended, with Edit and Add a regular links, so such a slot can always be reached. The slot form's preview gives the first date the slot runs on and the day it appears on the calendar: the weeks ahead times 7, less one, days before that date (Friday, November 13 for a first shift on Thursday, January 7 with 8 weeks ahead). |
| 2026-11-03 | An area's edit form has an optional lead name (up to 40 characters), such as "Shift lead" for Front desk at LMN. An area with no name set says "Area lead". Only the label changes: the role is still area lead. Role labels that name an area use it, such as "Shift lead: Front desk" on a person's security page, and later people screens use the same labels. |
| 2026-11-03 | The people list (`/people`) is open to anyone with a role. Coordinators and admins see everyone and can search by name or email; an area lead sees only the people section 13 lets them see and searches by name only. It filters by role, area, invitation status and active or turned-off accounts, orders by name and pages by 50. Coordinators and admins invite one person at a time (name, email and what they start as, which is Volunteer or lead of an area) and can invite everyone who is active and not invited yet with one confirmation. Pressing it twice, or two coordinators pressing it together, sends each person one invitation, because each is invited under its own row lock that re-reads the invitation. |
| 2026-11-03 | The person page (`/people/{id}`) shows roles, login (invitation status, last login, last seen, password or email links) and, for admins, a link to the login and security page. Coordinators and admins who may manage the person can add and remove roles they are allowed to grant, invite or re-invite, and turn the account off; an area lead sees roles and login only, with no controls. Someone the viewer can't see, or who doesn't exist, is a 404. Coordinators and admins also see the ten newest change log entries about the person, written as sentences by `App\ChangeLog\Describe` (which the change log screen reuses), with older ones a page at a time. Turning off doesn't yet take the person off future shifts. |
| 2026-11-03 | The change log screen (`/change-log`, screens 28 and 29) is for active coordinators and admins (the `view-change-log` gate) and lists every entry newest first, 50 a page, under day headings in the organization's time zone. Filters are in the address, so links keep them: made by (the system or anyone who has made a change), about (a name search on the person an entry is about), kind of change (Roles; Invitations and accounts; Passwords and two-factor, which also covers logging out everywhere; Schedule; Shift generation; Settings), and from and to dates, where "to" runs to the end of that day. A filter value that makes no sense is ignored. A person who made a change links to their page; entries by the system say "by the system" and by a removed person say "by Removed person" with no link. |
| 2026-11-03 | The old Google Calendar is still not imported. The volunteer list comes from a Hello Club export, and the schedule is entered by hand on the test copy. |
| 2026-11-03 | The volunteer list is imported by `php artisan ssvs:import-people {file} --as=<admin email>`, run on the server by an admin. It reads only Full name, Email and Profile ID (and Categories, which it reads but uses only when a lead tag is given), shows a preview, saves nothing until the admin confirms, and prints nothing beyond names and emails. It is a stopgap until member sync (section 11). |
| 2026-11-03 | Imported people are "not invited yet" (`users.invited_at` is empty). They get no email of any kind and can't sign in by link, password or reset until invited, on any install, and every screen answers for them as for an unknown address. This is enforced in `User::canSignIn()` and `User::routeNotificationForMail()`. The migration set `invited_at` to `created_at` for everyone already in the database, so existing accounts count as invited. |
| 2026-11-03 | The launch copies the whole test database to the live install, and coordinators then press "Invite everyone not invited yet" on the people list. Telling a newly invited person about the shifts and regular slots they already have is a follow-up. |
| 2026-11-03 | A Hello Club category named by `--lead-tag` makes people area leads of the area named by `--lead-area`, and no other Hello Club field grants a role. The rule is passed to the import as `--lead-tag` and `--lead-area`, so no organization's details are in the code; the three column names are fixed to Hello Club's export. LMN's full mapping is in docs/deployments/lmn.md. |
| 2026-11-03 | The import only adds new people. People who already exist (matched by membership ID, then email) are listed and left alone, except that someone matched by email who has no membership ID yet gets it filled in, so member sync recognizes them (this covers people made by `ssvs:setup` or the invite form). That fill-in is logged as `user.member_id_added`. Addresses ending in `@invalid` are refused. |
| 2026-11-03 | Removed people are anonymous. Their history moves onto one shared "Removed person" record (`removed-person@invalid`) and their row is deleted, with their name, email and membership ID scrubbed from the change log, the user IDs stored inside change log entries pointed at the shared record, and their password reset tokens deleted. Removal is refused while they're on a future active shift or still a regular, until the editing plan adds taking someone off a shift and ending a regular. Addresses ending in `@invalid` are refused everywhere so nobody can take the shared record's address. |
| 2026-11-03 | Generic sentences keep saying "area leads", and the per-area lead name is used wherever a specific area is named. |
| 2026-11-03 | The `assignments` unique index on (`shift_id`, `user_id`) is dropped, because two removed people can share a past shift. `PutOnShift` already refuses someone who is on the shift, under a lock on the shift, and `GenerateShifts` already checks before adding a regular. |
| 2026-11-03 | The change log's "about" filter is a name search, as wireframed, matching the name of the person an entry is about. |
| 2026-11-13 | People needed is the number a shift needs, not a limit. A coordinator or area lead can put more people on a shift, or add a regular who takes a date over the number, after a confirmation that reads "This puts Thursday, October 22 at 4 people. It needs 3." (shift panel) or lists the dates (add-a-regular page, up to 5 and then "and 2 more dates"). Without the confirmation nothing is saved, and a confirmed add is logged the same as any other. Generation and bringing a cancelled date back put every regular on, with no cap. A shift with more people than it needs shows no open places and isn't in Needs attention. Shadow spots stay a limit. This replaces "This shift is full." and the add-a-regular refusal of 2026-10-17. |
| 2026-11-13 | "Headcount" is now "people needed" everywhere: the code, the database column (`people_needed`), the form field and all copy. Earlier rows in this log still use the old word "headcount", because the log is history. Old change log entries keep the key `headcount` in their stored changes, and nothing reads it on screen. |
| 2026-11-13 | Adding a regular asks for the over confirmation only when the slot's own regulars take a date over the number needed. Someone put on one date by hand doesn't trigger it, and the shift panel then says "{n} people on it. It needs {m}." instead of "{n} of {m} places filled." Shadow spots stay a limit, and shadowing is its own plan. Adding a regular locks the area and the weekly slot first (issue #21), the same order ending a regular uses, so adding and ending a regular on one slot at the same time can't leave someone on shifts after their last date. |
| 2026-11-13 | The wording for removing someone from a shift is "Remove from shift", always with "from shift" or "from shifts" so it can't be mistaken for removing a person's account. It deletes the assignment (the change log keeps who was on it) and emails the person. Nobody else is told yet, since cover requests don't exist, and the shift shows its open place in Needs attention. This replaces "editing never changes a shift someone is on" of 2026-10-17 for removing people. |
| 2026-11-13 | Removing someone from shifts can cover a range of dates, and the person gets one email listing every shift. A range removes them only from shifts in areas the person using it manages, and the preview says how many it left out. The range also refuses "Pick two dates." and "The last date can't be before the first date." (as well as dates before today), and "They were emailed." shows only when at least one removed shift wasn't cancelled and the person can sign in. |
| 2026-11-13 | Ending a regular accepts any last date, including one in the past, so the record matches when they stopped. They're removed from the slot's shifts after that date, including past ones, so phase 4 doesn't count hours they didn't work, but only after a confirmation that lists the past shifts, and nobody is emailed about past shifts. Only shifts within that stint are touched (a later stint is left alone) and only regular spots (shadow spots stay). A later last date than the one a regular already has is refused ("To extend it, add them as a regular again."), because extending would mean filling shifts back in. A regular ended before they ever started is stored with an end the day before their start and counts as never current everywhere. |
| 2026-11-13 | Turning someone off removes them from every future shift (cancelled ones included) and ends each regular slot yesterday, in the same click and without emailing them. Removing a person does the same first: their schedule is released and committed, then the removal runs, so a refusal afterwards leaves the schedule cleared. This replaces the 2026-11-03 notes that turning off doesn't remove someone from future shifts and that removal is refused while they're on one. Turning back on doesn't put them back. People turned off before this change can be tidied by turning them off again; there's no separate button for it. |
| 2026-11-13 | "Bring this shift back" is only on shifts cancelled by hand. A shift a closure cancelled says "Cancelled by the {reason} closure. Change the closure to bring it back.", and one a slot change cancelled says "The weekly slot doesn't run on this date any more. Change the slot to bring it back." A hand-cancelled shift also can't come back when its weekly slot no longer runs on the date or a later closure covers it ("{Area} is closed that day: {reason}."). The confirm step counts only the people who can sign in and stay, and names people who'll be removed for an overlap. This replaces "nothing else cancelled is ever brought back" of 2026-10-17. |
| 2026-11-13 | Coordinators and admins can change a closure's days and reason, and remove a closure, including one whose days have passed. A closure that has started keeps its first day, and its last day can move from yesterday on, which reopens today. A closure that has ended can't be changed. A closure made for several areas is several closures, one per area, each changed on its own, and a label shared by several areas links each one ("Closed: Thanksgiving. Change: Front desk, Shop"). Changing the reason updates the cancelled shifts' reason without emailing anyone again. Past days lose the "Closed" label when the closure is removed and their shifts stay cancelled; phase 4 needs to add the hours by hand for a day the desk was open after all. |
| 2026-11-13 | When a closure change or removal frees a day whose weekly slot no longer runs on it, the shift stays cancelled with the reason "The weekly slot changed" and nobody is emailed. Each person gets one email per closure change, covering shifts cancelled and brought back together, and missing shifts are made right after the change is saved. |
| 2026-11-13 | Editing a shift with people on it is allowed. It refuses fewer shadow spots than shadows on it and a time that overlaps another shift one of them is on, and allows lowering people needed below the people on it, since going over is allowed. A change to the time or title emails everyone on it who can sign in, once, and a change to people needed or shadow spots alone emails nobody. The panel says "emailed if the time or what it's for changes" and counts only people who can sign in. This replaces "editing never changes a shift someone is on" of 2026-10-17. |
| 2026-11-13 | Saving a weekly slot changes the future shifts people are on when nothing clashes (the same two checks as editing one shift), emails each person once listing their changed shifts, and lists the shifts it kept with their reasons. A hand-edited shift stays "Changed on its own", and a staffed shift the slot no longer runs on stays as it is and shows "The slot doesn't run on this date any more". Changing a slot's day or how often it repeats is still refused while it has regulars or people on future shifts, until the release flow's change notices exist. |
| 2026-11-13 | Scope of the editing plan: corrections plus plain change emails. The two-button change notices, the 48-hour heads-up and "This date and all following" wait for the volunteer release flow (issue #21). For the release flow: a released spot goes to the first person who claims it, and the link then says "Someone already took this one". Separately, a volunteer can join a shift that already has the people it needs, as an extra. |
| 2026-11-13 | Bringing a shift back, by hand or because a closure changed or was removed, catches it up with its weekly slot when nobody changed it on its own: the slot's current time for that date, people needed and title (shadow spots too, unless that would be fewer than the shadows on it), and the slot's regulars who work that date and aren't turned off, logged in the shift.restored entry. Slot changes and new regulars skip cancelled shifts, so without this a shift came back at its old time and missed newer regulars. Anyone then on another shift at the new time is removed, as before. A shift changed on its own keeps its details. Bringing a shift back locks its weekly slot and the slot's regulars before the shift, and a closure change or removal locks all the area's slots and their regulars. |
| 2026-11-13 | Generation, adding a regular and bringing a cancelled date back put every regular on a shift, never capping at people needed. This replaces "at most the shift's headcount of regulars" of 2026-10-17. |
| 2026-11-14 | Bringing a cancelled shift back, by hand, by a closure change or by a weekly slot change, leaves off any regular who was taken off that date before it was cancelled (going by the change log). Otherwise someone who said they couldn't make that day was put back on it and emailed that it was back on. |

## 19. Open questions

- Text message provider and sender type, for phase 3. The October 2026 research in docs/background.md recommends a verified toll-free number.
- Who reviews credit requests (a new permission an admin grants, or a role), and whether the value of an hour is a setting.
