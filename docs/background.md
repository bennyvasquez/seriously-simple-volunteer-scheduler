# Background: why SSVS exists

Research done in October 2026, when the Lansing Makers Network (LMN) volunteer management committee was struggling to manage front desk shifts and swaps. This page records what we looked at and what we learned, so the same options don't get re-evaluated from scratch. Prices and policies change, so re-check anything here before relying on it.

## What LMN needed

- Free or very cheap, ideally self-hosted on AlmaLinux.
- Many users with roles: coordinators, shift leads, and volunteers, across several areas (front desk, hosted events, events LMN attends).
- Volunteers can say they can't make a shift, and the open shift gets a direct link so someone else can take it.
- Invite-only, or at least no schedule details until someone is approved.
- A public calendar showing events that need more volunteers.
- Google Calendar sync, and notices into chat (Discord today, possibly something else later).
- Ideally, a connection to Hello Club, LMN's membership system.

## Options considered

| Option | Cost | What fit | Why we didn't use it |
|---|---|---|---|
| Hello Club (already in use for membership) | Already paid | Member list, roles, events with attendee limits, and an API | No shift rostering, swaps or cover requests that we could find. Using members-only events as shifts was an idea worth asking their support about, but it can't do cover requests or per-area leads. |
| Engelsystem | Free, GPL, self-hosted (PHP and MariaDB) | Teams, shifts, closed registration, OpenID Connect login, iCal export | Built around one multi-day event, so a year-round weekly front desk is awkward. No "please cover me" broadcast. |
| Volunteer Scheduler Pro | From $45/month (Capterra, Oct 2026) | Swap requests that email qualified volunteers, skills, calendar sync, SMS | Cost was the obstacle. Docs only showed admins flagging swaps. |
| Timecounts | Free plan; Pro $59/month | Roles and permissions, multiple locations | The free plan didn't cover LMN's needs (benny checked). |
| SignUpGenius | $12 to $60/month | Familiar to volunteers | Sign-up sheets, not real roles or standing shifts. |

Conclusion: build our own, owned by benny, with LMN's technology committee maintaining the LMN install.

## Things we learned that shaped the spec

- **Hello Club API:** key-based (`X-Api-Key`), read and write access to members, events and attendees, about 30 requests per minute, mid-migration to a v2 API with breaking changes. Source is an unofficial guide ([ispyisail/helloclub-api-guide](https://github.com/ispyisail/helloclub-api-guide)); confirm against Hello Club's own docs. No webhooks or single sign-on found.
- **Text messages in the US:** every app-sent text needs a registered sender, through 10DLC or a verified toll-free number, and registration needs the organization's EIN and can take weeks. Per Twilio's nonprofit guide, a 501(c)(3) pays $3/month for the 10DLC campaign, and AT&T and T-Mobile waive carrier fees for that use case. Twilio list price is $0.0083 per text segment, plus $1.15/month for a local number or $2.15 for toll-free. Twilio.org's Impact Access program offers $100 in credits and 25% off; eligibility wasn't confirmed. Sources: [Twilio nonprofit 10DLC guide](https://support.twilio.com/hc/en-us/articles/4405850570267-Nonprofit-and-Government-Guide-to-A2P-10DLC-Text-Messaging), [Twilio US SMS pricing](https://www.twilio.com/en-us/sms/pricing/us), [Impact Access](https://support.twilio.com/hc/en-us/articles/360019772314-Twilio-org-Impact-Access-Pricing-Benefits).
- **Carrier email-to-text gateways aren't an option.** AT&T shut its gateway down on June 17, 2025, T-Mobile's stopped delivering in late 2024, and Verizon's is scheduled to end March 31, 2027. Source is a vendor blog ([TextBolt](https://textbolt.com/blog/att-email-to-text-alternative/)), not the carriers.
- **Google Calendar two-way sync** needs each volunteer to sign in with Google. Calendar access is a sensitive scope, so an app for outside users goes through Google's verification review (not verified against Google's current policy). One-way publishing through a service account avoids all of that.
- **Chat portability:** Discord, Matrix, Zulip, Slack and Mattermost all accept incoming webhooks, so a generic webhook layer makes switching chats a configuration change.
- **LMN's old front desk calendar** showed that volunteers kept standing weekly slots, a Saturday rotation was filled week by week, some regulars worked every other week, and new volunteers did shadow shifts. It also showed why automatic import is a bad idea: open-ended entries from 2025 and the same person under several spellings. Details are in [deployments/lmn.md](deployments/lmn.md).
