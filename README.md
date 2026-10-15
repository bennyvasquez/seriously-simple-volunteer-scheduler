# Seriously Simple Volunteer Scheduler

The Seriously Simple Volunteer Scheduler (SSVS) is a self-hosted scheduler for organizations that run on volunteer shifts: makerspaces, food pantries, community centers, libraries, festivals, and anyone else with a front desk, a booth, or a rota to keep staffed.

Volunteers see their shifts, take open ones, and release the ones they can't make. When someone releases a shift, the app asks other volunteers to cover it right away and gets louder as the shift gets closer. Coordinators see the gaps at a glance instead of chasing people through six chat channels.

## Status

The foundation and scheduling core are built (development environment, Laravel 13 skeleton, shift generation), with no login or screens yet. To run it locally, see [DEVELOPMENT.md](DEVELOPMENT.md). The spec is in [docs/spec.md](docs/spec.md). Why it exists and what else we considered: [docs/background.md](docs/background.md).

## What it does

- Invite-only accounts, with roles for coordinators, area leads, event organizers, and volunteers.
- Weekly standing shifts (every week or every other week) and one-time shifts, side by side.
- "I can't make it" in one step, with an automatic cover request and a claim link.
- Escalation when a shift is still open close to its start time.
- Shadow shifts for new volunteers, and a "cleared for regular shifts" sign-off.
- Notifications by email, chat webhook, and text message, each person choosing their own.
- Calendar feeds, plus a public calendar that shows where help is needed without showing names.

## Principles

1. The volunteer's experience comes first. If a volunteer is confused, the feature isn't done.
2. One job, done well. This is a scheduler, not a membership system, a CRM, or a chat app. It connects to those instead.
3. One install per organization. No multi-tenant hosting, no accounts across organizations.
4. Free software you can run yourself on a small Linux server.

## Reference deployment

The first deployment is the [Lansing Makers Network](docs/deployments/lmn.md) in Lansing, Michigan.

## License

GNU Affero General Public License v3.0. See [LICENSE](LICENSE).
