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
| Hello Club (already in use for membership) | Already paid | Member list, roles, events with attendee limits, and an API | No shift rostering, swaps or cover requests that we could find. Using members-only events as shifts was considered and ruled out in October 2026: it can't do cover requests or per-area leads. |
| Engelsystem | Free, GPL, self-hosted (PHP and MariaDB) | Teams, shifts, closed registration, OpenID Connect login, iCal export | Built around one multi-day event, so a year-round weekly front desk is awkward. No "please cover me" broadcast. |
| Volunteer Scheduler Pro | From $45/month (Capterra, Oct 2026) | Swap requests that email qualified volunteers, skills, calendar sync, SMS | Cost was the obstacle. Docs only showed admins flagging swaps. |
| Timecounts | Free plan; Pro $59/month | Roles and permissions, multiple locations | The free plan didn't cover LMN's needs (benny checked). |
| SignUpGenius | $12 to $60/month | Familiar to volunteers | Sign-up sheets, not real roles or standing shifts. |

Conclusion: build our own, owned by benny, with LMN's technology committee maintaining the LMN install.

## Things we learned that shaped the spec

- **Hello Club API:** key-based (`X-Api-Key`), read and write access to members, events and attendees, about 30 requests per minute, mid-migration to a v2 API with breaking changes. Source is an unofficial guide ([ispyisail/helloclub-api-guide](https://github.com/ispyisail/helloclub-api-guide)); confirm against Hello Club's own docs. No webhooks or single sign-on found.
- **Text messages in the US** (checked October 2026): every app-sent text needs a registered sender, either a local number registered through 10DLC or a toll-free number that passes toll-free verification. For a small organization sending a few thousand texts a month, toll-free is the simpler path: no registration fee, no need to prove 501(c)(3) status (only the EIN and the exact legal name the IRS has), and Twilio quotes about 3 to 5 business days for approval. 10DLC costs about $61 to register, and its cheaper Charity category is defined as "help and raising money for those in need", which may not cover volunteer scheduling. Twilio list prices: $0.0083 per text segment plus carrier fees, $2.15 a month for a toll-free number, and a 25% discount for nonprofits through Twilio.org Impact Access. Reviewers reject forced consent, so the text opt-in has to be unchecked, optional and separate from creating an account, and the organization needs public Privacy Policy and SMS Terms pages before applying. Twilio has a maintained Laravel notification channel; Telnyx is cheaper per text but has none. Sources: [Twilio toll-free verification](https://support.twilio.com/hc/en-us/articles/5377174717595), [Twilio toll-free onboarding guide](https://support.twilio.com/hc/en-us/articles/13264118705051), [Twilio 10DLC fees](https://support.twilio.com/hc/en-us/articles/1260803965530), [Twilio 10DLC special use cases](https://support.twilio.com/hc/en-us/articles/4402972441243), [Twilio US SMS pricing](https://www.twilio.com/en-us/sms/pricing/us), [Twilio.org Impact Access](https://support.twilio.com/hc/en-us/articles/360019772314), [Telnyx pricing](https://telnyx.com/pricing/messaging.md).
- **Carrier email-to-text gateways aren't an option.** AT&T shut its gateway down on June 17, 2025, T-Mobile's stopped delivering in late 2024, and Verizon's is scheduled to end March 31, 2027. Source is a vendor blog ([TextBolt](https://textbolt.com/blog/att-email-to-text-alternative/)), not the carriers.
- **Google Calendar two-way sync** needs each volunteer to sign in with Google. Calendar access is a sensitive scope, so an app for outside users goes through Google's verification review (not verified against Google's current policy). One-way publishing through a service account avoids all of that.
- **Chat portability:** Discord, Matrix, Zulip, Slack and Mattermost all accept incoming webhooks, so a generic webhook layer makes switching chats a configuration change.
- **LMN's old front desk calendar** showed that volunteers kept standing weekly slots, a Saturday rotation was filled week by week, some regulars worked every other week, and new volunteers did shadow shifts. It also showed why automatic import is a bad idea: open-ended entries from 2025 and the same person under several spellings. Details are in [deployments/lmn.md](deployments/lmn.md).
