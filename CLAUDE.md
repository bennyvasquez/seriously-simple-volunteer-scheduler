# Working on SSVS

The Seriously Simple Volunteer Scheduler (SSVS) helps a small organization run its volunteer schedule: weekly shifts with regulars, cover requests when someone can't make it, and shadowing for new volunteers. The Lansing Makers Network (LMN) is its first deployment. Keep the generic product (README, docs/spec.md) separate from any one organization's setup, which lives outside this repository (docs/deployments/README.md says what it covers). Don't add an organization's setup notes here.

## Start here

- docs/spec.md is the source of truth for what SSVS does. Its decision log (section 18) records every decision; add a row when a decision changes or a new one is made. Open questions are in section 19.
- docs/background.md explains why SSVS exists and which alternatives were rejected. Don't re-propose them without new information.
- docs/plans/ holds the implementation plans already built, each with the decisions it made and its follow-ups. Read the most recent ones before starting related work.
- Known issues, follow-ups and open questions are tracked in GitHub issues, labeled by kind (`bug`, `security`, `tech debt`, `tests`, `copy`, `accessibility`, `enhancement`, `question`) and by timing (`before launch`, `phase 3`, `phase 4`). The follow-up sections in docs/plans/ are where they were first found; new ones go in issues.
- DEVELOPMENT.md: how to run and test the app locally (Docker, matching AlmaLinux 10).
- SECURITY.md: report security problems privately to security@encylia.com, never in a public issue or pull request.

## How to work

- Plan before building anything bigger than a small fix, and check the plan with the maintainer before writing code.
- Run everything through Docker from the repository root, for example `docker compose exec app php artisan test`. Run the whole test suite before every commit, and don't commit a failing state.
- Run `docker compose exec app ./vendor/bin/pint --dirty` before committing.
- Run the browser tests (`docker compose --profile browser run --rm dusk`) before committing changes to screens or their scripts.
- Work on a branch and open a pull request. Never push to `main` directly, and never force-push a shared branch.
- Match the existing commit message style: one plain sentence saying what changed.

## Conventions

- Stack: AlmaLinux, PHP, Laravel, MariaDB. License: AGPL-3.0. One install per organization; never multi-tenant or hosted for others.
- Build for upgrades (spec section 15): stay close to Laravel defaults, add packages only when they save real work and record why, and keep tests on every core flow and access rule. When a choice makes the next Laravel major upgrade harder, say so before making it.
- The volunteer's experience comes first. Anything that confuses a volunteer isn't done.
- Never put real volunteers' names, contact details or schedules in this repository, including tests and examples. Use example names and example.org addresses.
- Never commit secrets. Configuration examples use placeholders.
- Screens use resources/views/layouts/app.blade.php and public/css/app.css. No Node, no build step, no outside fonts or scripts, and every screen works with JavaScript off.
- Writing, in code comments, copy, docs and commit messages: no em dashes or en dashes; never hard-wrap prose (one line per paragraph); plain language with contractions; define acronyms on first use.
- Any change to behavior, setup or decisions updates the spec (and this file, if a convention changes) in the same pull request.
- Any change to who can do what (a policy, a gate, a role or clearance check, or who gets an email because of their role) updates the "What each role can do" tables in spec section 6 in the same pull request. Those tables describe what's built; anything promised but not built goes in the list under them.
- Shifts are never deleted; cancelling sets cancelled_at, and cancelled shifts stay visible. Weeks start on Sunday. Dates in copy use US order ("Thursday, October 22"). When an action locks more than one of an area, a weekly slot and shifts, it locks them in that order: area, then weekly slot, then shifts.
- Every change to stored data goes through an action that records a change log entry (with `ChangeLogEntry::changesOf()` for old and new values) in the same transaction, and that action's tests assert the entry. Names and contact details go only in `actor_name` and `changes`, never in `details`. The exceptions are activity and sign-in machinery: `last_login_at` and `last_seen_at`, login links, sessions, and the sign-in side effects named in the spec's decision log.
- No response, message or timing may reveal whether an email address has an account (spec section 13).
