<?php

/*
 * The wording of every email SSVS sends, one array per email, one line each. {variable_name} is filled in by the code (see App\Notifications\EmailText); the comment above each line says when it shows and what each variable holds. Keep a line to one string: lists, joins and typed text are built in code and passed in as one variable.
 */

return [

    'login_link' => [
        // The subject of the email with the one-time login link. No variables.
        'subject' => 'Your login link',
        // The first line, above the Log in button. No variables.
        'intro' => 'Tap the button to log in.',
        // The label on the button that opens the login link. No variables.
        'action' => 'Log in',
        // The last line, below the button. No variables.
        'outro' => 'This link works once and expires in 15 minutes. If you didn\'t ask for it, you can ignore this email: nobody can log in without the link.',
    ],

    'two_factor_code' => [
        // The subject of the email with the emailed second-step code. No variables.
        'subject' => 'Your login code',
        // The first line, giving the code. {code} is the six digit code. The 10 minutes is fixed text, not a variable: it's how long the code works, so change it only if the code's lifetime changes too.
        'code' => 'Your code is {code}. It works for 10 minutes.',
        // The last line, for someone who didn't try to log in. No variables.
        'outro' => 'If you didn\'t just try to log in, change your password: someone may know it.',
    ],

    'confirm_its_you' => [
        // The subject of the email sent to the current address of someone without a password who asked to change their email address. No variables.
        'subject' => 'Confirm it\'s you',
        // The first line, above the button. No variables.
        'intro' => 'You asked to change the email address you log in with. Open this link to confirm it\'s you and keep going.',
        // The label on the button that opens the confirmation link. No variables.
        'action' => 'Confirm it\'s me',
        // The last line, below the button. No variables.
        'outro' => 'This link works once and expires in 15 minutes. If you didn\'t ask for it, you can ignore this email and nothing changes.',
    ],

    'email_change_confirmation' => [
        // The subject of the email sent to the new address someone wants to log in with. No variables.
        'subject' => 'Confirm your new email address',
        // The only line, above the button. {organization} is the name of the organization (for example the makerspace).
        'intro' => 'Someone asked to use this address to log in to {organization}. If it was you, open this link to confirm. It works once, for 60 minutes. If it wasn\'t you, ignore this email and nothing changes.',
        // The label on the button that confirms the new address. No variables.
        'action' => 'Confirm this address',
    ],

    'email_changed' => [
        // The subject of the email sent to the old address after the login email changed. No variables.
        'subject' => 'Your login email was changed',
        // The only line. {organization} is the name of the organization. {new_address} is the new address with most of it hidden, such as r•••@example.org.
        'body' => 'Your login email for {organization} was changed to {new_address}. If this wasn\'t you, contact a coordinator right away.',
    ],

    'invitation' => [
        // The subject of the invitation email. {organization} is the name of the organization.
        'subject' => 'You\'re invited to volunteer with {organization}',
        // The greeting at the top. {first_name} is the invited person's first name.
        'greeting' => 'Hi {first_name},',
        // The first line when we know who sent the invitation. {invited_by} is that person's name. {organization} is the name of the organization.
        'intro_by_person' => '{invited_by} added you to {organization}\'s volunteer schedule.',
        // The first line when we don't know who sent the invitation. {organization} is the name of the organization.
        'intro' => 'You\'ve been added to {organization}\'s volunteer schedule.',
        // The label on the button that opens the invitation. No variables.
        'action' => 'Accept your invitation',
        // The last line, below the button. No variables.
        'outro' => 'This link works once and expires in 7 days. If you weren\'t expecting this email, you can ignore it.',
    ],

    'two_factor_reset' => [
        // The subject of the email sent to someone whose second step an admin reset. No variables.
        'subject' => 'Your second step was reset',
        // The only line. {admin} is the admin's first name and last initial. {organization} is the name of the organization.
        'body' => '{admin}, an admin at {organization}, reset the second step for your login. Next time you log in with your password, you\'ll set up your authenticator app again. If you didn\'t ask for this, contact a coordinator right away.',
    ],

    'password_changed' => [
        // The subject of the email sent after someone's password changes. No variables.
        'subject' => 'Your password was changed',
        // The first line. No variables.
        'intro' => 'Your password was just changed.',
        // The last line. No variables.
        'outro' => 'If that wasn\'t you, reset it again and tell a coordinator.',
    ],

    'put_on_shift' => [
        // The subject of the email sent to someone a coordinator or lead put on a shift. {area} is the area's name, such as Front desk.
        'subject' => 'You\'re on a {area} shift',
        // The only line. {area} is the area's name. {when} is the date and time, such as Thursday, October 22, 6:30 to 9:30pm.
        'body' => 'You\'re on the {area} shift on {when}. If you can\'t make it, let your coordinator know.',
    ],

    'cleared' => [
        // The subject of the email sent to someone a coordinator cleared for regular shifts. No variables.
        'subject' => 'You\'re cleared for regular shifts',
        // The greeting at the top. {first_name} is the cleared person's first name.
        'greeting' => 'Hi {first_name},',
        // The only line. {coordinator} is the coordinator's first name and last initial. {organization} is the name of the organization.
        'body' => '{coordinator} cleared you to take regular shifts at {organization}. You\'ll now get cover requests when someone can\'t make a shift you could take, and you can take open shifts from Home.',
    ],

    'removed_from_shifts' => [
        'subject' => [
            // The subject when a coordinator removed someone from one shift. {area} is the area's name.
            'one' => 'You\'ve been removed from a {area} shift',
            // The subject when they were removed from several shifts. {count} is how many.
            'other' => 'You\'ve been removed from {count} shifts',
        ],
        'intro' => [
            // The first line when it was one shift. {shift} is the shift in words, such as Front desk shift on Thursday, October 22, 6:30 to 9:30pm.
            'one' => 'You\'re no longer on the {shift}.',
            // The first line when it was several shifts, above the list of them. No variables.
            'other' => 'You\'re no longer on these shifts:',
        ],
        // The shift in words, used wherever the removed from shifts email lists a shift. {area} is the area's name. {when} is the day and time, such as Thursday, October 22, 6:30 to 9:30pm.
        'shift_phrase' => '{area} shift on {when}',
        // One line per shift in the list, when it was several shifts. {shift} is the shift in words, such as Front desk shift on Thursday, October 22, 6:30 to 9:30pm.
        'shift_item' => 'The {shift}.',
        // The last line. No variables.
        'outro' => 'If you didn\'t expect this, ask your coordinator.',
    ],

    'shadow_signed_up' => [
        // The subject of the email sent to someone who signed up to shadow a shift. {area} is the area's name. {date} is the day, such as Thursday, October 22.
        'subject' => 'You\'re shadowing {area} on {date}',
        // The greeting at the top. {first_name} is the shadow's first name.
        'greeting' => 'Hi {first_name},',
        // The first line. {area} is the area's name. {when} is the date and time, such as Thursday, October 22, 6:30 to 9:30pm.
        'intro' => 'You\'re signed up to shadow {area} on {when}.',
        // Shown only when the shift has regulars to shadow. {names} is their names joined in code, such as Robin R. and Jo K. A full stop is added after it unless the names already end in one, so this line has none.
        'regulars' => 'You\'ll shadow {names}',
        // The last line. No variables.
        'outro' => 'If you can\'t make it, press Can\'t make it on Home so a coordinator knows.',
    ],

    'shadow_signed_up_lead' => [
        // The subject of the email sent to an area's leads when someone signs up to shadow. {shadow} is the shadow's first name and last initial. {area} is the area's name. {date} is the day, such as Thursday, October 22.
        'subject' => '{shadow} is shadowing {area}, {date}',
        // The first line. {shadow} is the shadow's first name and last initial. {area} is the area's name. {when} is the date and time, such as Thursday, October 22, 6:30 to 9:30pm.
        'intro' => '{shadow} signed up to shadow {area} on {when}.',
        'regulars' => [
            // Shown when one regular is on the shift. {names} is that person's name.
            'one' => '{names} is on that shift.',
            // Shown when several regulars are on the shift. {names} is their names joined in code, such as Robin R. and Jo K.
            'other' => '{names} are on that shift.',
        ],
        // The label on the button that opens the shift. No variables.
        'action' => 'Open the shift',
    ],

    'made_regular' => [
        // The subject of the email sent to someone made a regular on a weekly slot. {area} is the area's name.
        'subject' => 'You\'re now a regular for {area}',
        // The first line. {area} is the area's name. {slot} describes the slot, such as Thursdays, 6:30 to 9:30pm. {how} is every_week or every_other_week below. {starting_on} is the first day, such as Thursday, October 22.
        'intro' => 'You\'re now the regular for {area} on {slot}, {how}, starting {starting_on}.',
        'how' => [
            // Goes in {how} above when the slot or the regular is weekly. No variables.
            'every_week' => 'every week',
            // Goes in {how} above when the slot or the regular is every other week. No variables.
            'every_other_week' => 'every other week',
        ],
        'shifts' => [
            // Shown when one shift is already on the schedule for them. The 1 is fixed, not a variable.
            'one' => 'We\'ve put you on 1 shift already on the schedule.',
            // Shown when several are. {count} is how many.
            'other' => 'We\'ve put you on {count} shifts already on the schedule.',
        ],
    ],

    'regular_ended' => [
        // The subject of the email sent to someone whose time as a regular ended. {area} is the area's name. {weekdays} is the weekday in plural, such as Thursdays.
        'subject' => 'You\'re no longer the regular for {area} on {weekdays}',
        // The first line when the last date has already passed. {area} is the area's name. {weekdays} is the weekday in plural, such as Thursdays.
        'ended' => 'You\'re no longer the regular for the {area} shift on {weekdays}.',
        // The first line when the last date is still ahead. {area} is the area's name. {weekdays} is the weekday in plural. {last_date} is the day, such as Thursday, October 22.
        'ending' => 'Your last date as the regular for the {area} shift on {weekdays} is {last_date}.',
        // Shown above the list when they were taken off upcoming shifts. No variables.
        'shifts_intro' => 'You\'ve been removed from these shifts:',
        // The shift in words, used wherever the regular ended email lists a shift. {area} is the area's name. {when} is the day and time, such as Thursday, October 22, 6:30 to 9:30pm.
        'shift_phrase' => '{area} shift on {when}',
        // One line per shift in the list. {shift} is the shift in words, such as Front desk shift on Thursday, October 22, 6:30 to 9:30pm.
        'shift_item' => 'The {shift}.',
        // The last line. No variables.
        'outro' => 'If you didn\'t expect this, ask your coordinator.',
    ],

    'shadow_left' => [
        'subject' => [
            // The subject to coordinators when a shadow can't make one shadow shift. {shadow} is the shadow's first name and last initial.
            'one' => '{shadow} can\'t make their shadow shift',
            // The subject when it was several shifts. {shadow} is the shadow's first name and last initial. {count} is how many.
            'other' => '{shadow} can\'t make {count} shadow shifts',
        ],
        'intro' => [
            // The first line when it was one shift. {shadow} is the shadow's first name and last initial. {area} is the area's name. {when} is the date and time, such as Thursday, October 22, 6:30 to 9:30pm.
            'one' => '{shadow} can\'t make their shadow shift on {area}, {when}. We\'ve taken them off it, so they won\'t be there and the shadow spot is open again.',
            // The first line when it was several shifts, above the list. {shadow} is the shadow's first name and last initial.
            'other' => '{shadow} can\'t make these shadow shifts. We\'ve taken them off, so they won\'t be there and the shadow spots are open again:',
        ],
        // One link per shift in the list, when it was several shifts. {area} is the area's name. {when} is the date and time. {url} is the address of the shift.
        'shift_link' => '[{area}, {when}]({url})',
        // Shown when the shadow left a note. {shadow} is the shadow's first name and last initial. {note} is what they typed.
        'note' => 'Note from {shadow}: {note}',
        // The label on the button that opens the shift, when it was one shift. No variables.
        'action' => 'Open the shift',
        // The last line. No variables.
        'outro' => 'You\'re getting this because you\'re a coordinator.',
    ],

    'moved_back' => [
        // The subject of the email sent to someone a coordinator moved back to shadowing. No variables.
        'subject' => 'You\'ve been moved back to shadowing',
        // The greeting at the top. {first_name} is the person's first name.
        'greeting' => 'Hi {first_name},',
        'intro' => [
            // The first line when one shadow shift is needed before review. {coordinator} is the coordinator's first name and last initial. {organization} is the name of the organization. The 1 is fixed, not a variable.
            'one' => '{coordinator} moved you back to shadowing at {organization}. You can sign up for shadow spots from Home, and a coordinator will review you again once you\'ve done 1 shadow shift.',
            // The first line when several are needed. {coordinator} is the coordinator's first name and last initial. {organization} is the name of the organization. {required} is how many.
            'other' => '{coordinator} moved you back to shadowing at {organization}. You can sign up for shadow spots from Home, and a coordinator will review you again once you\'ve done {required} shadow shifts.',
        ],
        // Shown above the shifts that other volunteers were asked to cover. No variables.
        'asked_intro' => 'You\'re off these shifts, and other volunteers were asked to cover them:',
        // Shown above the shifts that still have the people they need. No variables.
        'nobody_asked_intro' => 'You\'re off these shifts too. They still have the people they need, so nobody was asked:',
        // Shown when they were the regular on weekly slots. {slots} is the slots joined in code, such as Thursdays, Front desk; Fridays, Front desk.
        'no_longer_regular' => 'You\'re no longer the regular on {slots}.',
    ],

    'shift_changed' => [
        'subject' => [
            // The subject when one shift changed. {area} is the area's name.
            'one' => 'Your {area} shift changed',
            // The subject when several shifts changed. No variables.
            'other' => 'Your shifts changed',
        ],
        // The start of each shift's line. {area} is the area's name. {date} is the day, such as Thursday, October 22.
        'changed' => 'Your {area} shift on {date} changed.',
        // Added to a shift's line when its time changed. {now} is the new time, such as 6 to 9pm. {was} is the old time.
        'time' => 'It\'s now {now}; it was {was}.',
        // Added to a shift's line when it got a title. {title} is the new title.
        'title_now' => 'It\'s now for {title}.',
        // Added to a shift's line when its title was taken away. {was_title} is the old title.
        'title_removed' => 'It\'s no longer for {was_title}.',
        // The last line. No variables.
        'outro' => 'If you can\'t make it, let your coordinator know.',
    ],

    'shift_cancelled' => [
        'subject' => [
            // The subject when one shift was cancelled. No variables.
            'one' => 'A shift you were on is cancelled',
            // The subject when several were. No variables.
            'other' => 'Shifts you were on are cancelled',
        ],
        // The subject when the same email also says some shifts are back on. No variables.
        'subject_changes' => 'Changes to your shifts',
        'body' => [
            // The line when one shift was cancelled. {shift} is the shift in words, such as Front desk shift on Thursday, October 22, 6:30 to 9:30pm. {reason} is the reason a coordinator typed, ended with a full stop if it has none.
            'one' => 'The {shift} is cancelled: {reason} You don\'t need to do anything.',
            // The first line when several were, above the list. {reason} is the reason a coordinator typed, ended with a full stop if it has none.
            'other' => 'These shifts are cancelled: {reason}',
        ],
        // The shift in words, used wherever the shift cancelled email lists a shift. {area} is the area's name. {when} is the day and time, such as Thursday, October 22, 6:30 to 9:30pm.
        'shift_phrase' => '{area} shift on {when}',
        // One line per shift in the list, when several were cancelled. {shift} is the shift in words.
        'shift_item' => 'The {shift}.',
        // The last line when several were cancelled. No variables.
        'outro' => 'You don\'t need to do anything.',
    ],

    'shift_restored' => [
        'subject' => [
            // The subject when one shift is back on. {area} is the area's name.
            'one' => 'Your {area} shift is back on',
            // The subject when several are. {area} is the area's name.
            'other' => 'Your {area} shifts are back on',
        ],
        'body' => [
            // The line when one shift is back on. {shift} is the shift in words, such as Front desk shift on Thursday, October 22, 6:30 to 9:30pm.
            'one' => 'The {shift} is back on, and you\'re still on it. If you can\'t make it, let your coordinator know.',
            // The first line when several are, above the list. No variables.
            'other' => 'These shifts are back on, and you\'re still on them:',
        ],
        // The shift in words, used wherever the shift restored email lists a shift. {area} is the area's name. {when} is the day and time, such as Thursday, October 22, 6:30 to 9:30pm.
        'shift_phrase' => '{area} shift on {when}',
        // One line per shift in the list, when several are back on. {shift} is the shift in words.
        'shift_item' => 'The {shift}.',
        // The last line when several are back on. No variables.
        'outro' => 'If you can\'t make one, let your coordinator know.',
    ],

    'shifts_released' => [
        // The greeting at the top. {first_name} is the first name of the person who released their shifts.
        'greeting' => 'Hi {first_name},',
        // The subject when they only gave up their weekly slot and none of its dates are on the calendar yet. {weekday} is the day of the slot, such as Thursday.
        'subject_no_longer_regular' => 'You\'re no longer the {weekday} regular',
        // Shown when they gave up their weekly slot. {weekday} is the day of the slot, such as Thursday. {from} is the first date they're no longer the regular, such as Thursday, October 29.
        'no_longer_regular' => 'You\'re no longer the {weekday} regular from {from}.',
        // Shown after that line when none of the slot's dates are on the calendar yet. No variables.
        'none_on_calendar' => 'None of those dates are on the calendar yet, so nobody needed to be asked to cover them.',
        'subject' => [
            // The subject when they released one shift. {date} is the day, such as Thursday, October 22.
            'one' => 'You\'re off {date}',
            // The subject when they released several shifts. {count} is how many.
            'other' => 'You\'re off {count} shifts',
        ],
        // The first line when it was one shift. {area} is the area's name. {when} is the date and time, such as Thursday, October 22, 6:30 to 9:30pm.
        'off_shift' => 'You\'re off your {area} shift on {when}.',
        // The second line when it was one shift and nobody else was asked to cover it. {area} is the area's name.
        'nobody_asked' => '{area} still has the people it needs, so nobody else was asked.',
        // The second line when it was one shift and other volunteers were asked to cover it. No variables.
        'asked_one' => 'We\'ve asked other volunteers to cover it, and we\'ll email you when someone does.',
        'shadows_told' => [
            // Shown when it was one shift and one shadow was going to shadow it. {names} is that shadow's first name.
            'one' => '{names} was going to shadow you, so we\'ve told them too.',
            // Shown when it was one shift and several shadows were. {names} is their first names joined in code, such as Casey and Jo.
            'other' => '{names} were going to shadow you, so we\'ve told them too.',
        ],
        // Shown when it was one shift and they stay the regular for it. {weekday} is the day of the weekly slot, such as Thursday. {date} is the day, such as Thursday, October 22.
        'stay_regular' => 'You stay the {weekday} regular. This only changes {date}.',
        'take_back' => [
            // The last line when it was one shift that other volunteers were asked to cover. No variables.
            'one' => 'If you can make it after all, you can take it back from your home page while it\'s still open.',
            // The last line when it was several shifts and other volunteers were asked to cover some. No variables.
            'other' => 'If you can make any of them after all, you can take them back from your home page while they\'re still open.',
        ],
        // The label on the button that opens their shifts. No variables.
        'action' => 'Open my shifts',
        // The first line when it was several shifts, above the list. No variables.
        'list_intro' => 'You\'re off these shifts:',
        // One line per shift in the list. {area} is the area's name. {when} is the date and time, such as Thursday, October 22, 6:30 to 9:30pm.
        'shift_item' => '{area}, {when}',
        // One line in the list for a shift where they only left a shadow spot. {date} is the day, such as Thursday, October 22. {area} is the area's name.
        'shadow_spot_item' => '{date}: shadow spot on {area}',
        // Shown once for each shift in the list that still has the people it needs. {area} is the area's name. {date} is the day, such as Thursday, October 22.
        'nobody_asked_on_date' => '{area} still has the people it needs on {date}, so nobody else was asked.',
        // Shown when it was several shifts and other volunteers were asked to cover all of the ones left. No variables.
        'asked_all' => 'We\'ve asked other volunteers to cover them, and we\'ll email you when someone does.',
        // Shown instead of that line when some shifts in the list had nobody else asked. No variables.
        'asked_rest' => 'We\'ve asked other volunteers to cover the rest, and we\'ll email you when someone does.',
        'shadows_told_on_dates' => [
            // Shown when it was several shifts and one shadow was going to shadow some of them. {names} is that shadow's first name. {dates} is the days joined in code, such as Thursday, October 22 and Thursday, October 29.
            'one' => '{names} was going to shadow you on {dates}, so we\'ve told them too.',
            // Shown when several shadows were going to shadow the same shifts. {names} is their first names joined in code, such as Casey and Jo. {dates} is the days joined in code.
            'other' => '{names} were going to shadow you on {dates}, so we\'ve told them too.',
        ],
        'shadows_told_every_date' => [
            // Shown instead of that line when one shadow was going to shadow every shift in the list. {names} is that shadow's first name.
            'one' => '{names} was going to shadow you on these dates, so we\'ve told them too.',
            // Shown when several shadows were going to shadow every shift in the list. {names} is their first names joined in code, such as Casey and Jo.
            'other' => '{names} were going to shadow you on these dates, so we\'ve told them too.',
        ],
        // Shown when it was several shifts and they stay the regular for one weekday. {weekday} is the day of the weekly slot, such as Thursday.
        'stay_regular_weekday' => 'You stay the {weekday} regular. This only changes these dates.',
        // Shown when it was several shifts and they stay the regular for weekly slots on different days. No variables.
        'stay_regular_weekly' => 'You stay the regular on your weekly shifts. This only changes these dates.',
    ],

    'cover_request' => [
        // The greeting at the top. {first_name} is the first name of the volunteer being asked.
        'greeting' => 'Hi {first_name},',
        // The subject of the "Still open" email, sent 72 hours before the shift. {area} is the area's name. {date} is the day, such as Thursday, October 22.
        'subject_still_open' => 'Still open: {area} on {date}',
        // The first line of the "Still open" email. {releaser} is the first name of the person who released the shift. {area} is the area's name. {when} is the date and time, such as Thursday, October 22, 6:30 to 9:30pm.
        'still_open' => 'Nobody has taken {releaser}\'s {area} shift yet. It\'s {when}.',
        'subject_gave_up' => [
            // The subject when someone gave up their weekly slot and one date needs covering. {area} is the area's name. {date} is the day, such as Thursday, October 22.
            'one' => 'Can you cover {area} on {date}?',
            // The subject when several dates need covering. {area} is the area's name. {weekdays} is the weekday in plural, such as Thursdays.
            'other' => 'Can you cover {area} on some {weekdays}?',
        ],
        // The first line when someone gave up their weekly slot, above the dates. {releaser} is the first name of the person giving it up. {weekday} is the day of the slot, such as Thursday. {area} is the area's name.
        'gave_up' => '{releaser} is giving up their weekly {weekday} {area} slot. These dates need someone:',
        'subject' => [
            // The subject when one shift needs covering. {area} is the area's name. {date} is the day, such as Thursday, October 22.
            'one' => 'Can you cover {area} on {date}?',
            // The subject when several shifts need covering. {area} is the area's name. {count} is how many dates.
            'other' => 'Can you cover {area} on {count} dates?',
        ],
        'cant_make' => [
            // The first line when it was one shift. {releaser} is the first name of the person who can't make it. {area} is the area's name.
            'one' => '{releaser} can\'t make their {area} shift. Can you take it?',
            // The first line when it was several shifts. {releaser} is the first name of the person who can't make them. {area} is the area's name.
            'other' => '{releaser} can\'t make their {area} shifts. Can you take one?',
        ],
        // The line for the shift when it was one shift. {area} is the area's name. {when} is the date and time, such as Thursday, October 22, 6:30 to 9:30pm.
        'shift_item' => '{area}, {when}',
        // One link per shift when it was several shifts. {area} is the area's name. {when} is the date and time. {url} is the address of the page to take the shift.
        'shift_link' => '[{area}, {when}]({url})',
        // Shown when the person who released the shift left a note. {releaser} is their first name. {note} is what they typed.
        'note' => 'Note from {releaser}: {note}',
        // The label on the button that opens the page to take the shift, when it was one shift. No variables.
        'action' => 'Take this shift',
        // The line below the button when it was one shift. No variables.
        'first_person_one' => 'The first person to take it gets it. You\'ll log in first if you aren\'t already.',
        // The line after the list when it was several shifts. No variables.
        'first_person_several' => 'Pick a date to take it. The first person to take each date gets it. You\'ll log in first if you aren\'t already.',
        // Why they got the email. {organization} is the name of the organization.
        'reason' => 'You\'re getting this because you volunteer with {organization} and cover request emails are on for you.',
        // The last line, a link to turn these emails off. {url} is the address of the Emails page.
        'stop' => '[Stop getting cover requests]({url})',
    ],

    'cover_urgent' => [
        'subject' => [
            // The subject when one shift needs someone. {area} is the area's name. {date} is the day, such as Thursday, October 22.
            'one' => 'Needs someone tomorrow: {area}, {date}',
            // The subject when several shifts do. {count} is how many.
            'other' => 'Needs someone tomorrow: {count} shifts',
        ],
        'unclaimed' => [
            // The first line when it was one shift. The 24 hours is fixed, not a variable.
            'one' => 'Nobody has taken this shift yet, and it starts in less than 24 hours.',
            // The first line when it was several shifts. The 24 hours is fixed, not a variable.
            'other' => 'Nobody has taken these shifts yet, and they start in less than 24 hours.',
        ],
        // The second line when it was one shift. {area} is the area's name. {when} is the date and time, such as Thursday, October 22, 6:30 to 9:30pm. {needs} is how many more people it needs, from the needs lines below.
        'shift_line' => '{area}, {when}. {needs}',
        // One line per shift when it was several shifts. {area} is the area's name. {when} is the date and time. {url} is the address of the shift. {needs} is how many more people it needs, from the needs lines below. {released} is who released it and who was emailed, from the released lines below.
        'shift_link_line' => '[{area}, {when}]({url}). {needs} {released}',
        'needs' => [
            // How many more people a shift needs, when it's one. {count} is 1.
            'one' => 'Needs {count} more person.',
            // How many more people a shift needs, when it's none or several. {count} is how many.
            'other' => 'Needs {count} more people.',
        ],
        // The first part of who released it. {releaser} is the first name of the person who released the shift. {released_on} is the day they released it, such as Thursday, October 15.
        'released' => '{releaser} released it on {released_on}.',
        'emailed' => [
            // The second part, when one volunteer was emailed. {count} is 1.
            'one' => 'We emailed {count} volunteer when it was released.',
            // The second part when none or several were. {count} is how many.
            'other' => 'We emailed {count} volunteers when it was released.',
        ],
        'emailed_again' => [
            // The second part, when one volunteer was emailed and the email went out again 72 hours before. {count} is 1. The 72 hours is fixed, not a variable.
            'one' => 'We emailed {count} volunteer when it was released and again 72 hours before.',
            // The second part when none or several were. {count} is how many. The 72 hours is fixed, not a variable.
            'other' => 'We emailed {count} volunteers when it was released and again 72 hours before.',
        ],
        // The label on the button that opens the shift, when it was one shift. No variables.
        'action' => 'Open the shift',
        'marked_urgent' => [
            // The line after the shift when it was one shift. No variables.
            'one' => 'It\'s marked urgent on the calendar until someone takes it. From the shift you can put someone on it yourself.',
            // The line after the list when it was several shifts. No variables.
            'other' => 'They\'re marked urgent on the calendar until someone takes them. From each shift you can put someone on it yourself.',
        ],
        // The last line, saying why they got the email. {reasons} is the reasons joined in code, such as a shift lead for Front desk and a coordinator.
        'reason' => 'You\'re getting this because you\'re {reasons}.',
    ],

    'shadow_spot' => [
        'on_hold' => [
            'subject' => [
                // The subject when one of their shadow shifts is on hold. {date} is the day, such as Thursday, October 22.
                'one' => 'Your shadow shift on {date} is on hold',
                // The subject when several are. {count} is how many dates.
                'other' => 'Your shadow shifts on {count} dates are on hold',
            ],
            'intro' => [
                // The first line when it was one shift. {releaser} is the first name of the person who can't make it. {area} is the area's name. {date} is the day, such as Thursday, October 22.
                'one' => '{releaser} can\'t make their {area} shift on {date}, so right now there\'s nobody for you to shadow.',
                // The first line when it was several shifts, above the list. {releaser} is the first name of the person who can't make them.
                'other' => '{releaser} can\'t make these shifts, so right now there\'s nobody for you to shadow on them:',
            ],
            'outro' => [
                // The last line when it was one shift. No variables.
                'one' => 'We\'ve asked other volunteers to cover it, and we\'ll email you as soon as someone does. You don\'t need to do anything.',
                // The last line when it was several shifts. No variables.
                'other' => 'We\'ve asked other volunteers to cover them, and we\'ll email you as soon as someone does. You don\'t need to do anything.',
            ],
        ],
        'back_on' => [
            'subject' => [
                // The subject when one of their shadow shifts is back on. {date} is the day, such as Thursday, October 22.
                'one' => 'Your shadow shift on {date} is back on',
                // The subject when several are. {count} is how many dates.
                'other' => 'Your shadow shifts on {count} dates are back on',
            ],
            'intro' => [
                // The only line when it was one shift. {taker} is the short name of the person covering, such as Jordan P. {area} is the area's name. {when} is the date and time, such as Thursday, October 22, 6:30 to 9:30pm.
                'one' => '{taker} is covering, so you\'ll shadow {taker} on {area}, {when}.',
                // The first line when it was several shifts, above the list. {taker} is the short name of the person covering, such as Jordan P.
                'other' => '{taker} is covering, so you\'ll shadow {taker} on these shifts:',
            ],
        ],
        'still_uncovered' => [
            'subject' => [
                // The subject when one of their shadow shifts still has nobody to shadow. No variables.
                'one' => 'Your shadow shift tomorrow still has nobody to shadow',
                // The subject when several still have nobody. No variables.
                'other' => 'Your shadow shifts tomorrow still have nobody to shadow',
            ],
            'intro' => [
                // The first line when it was one shift. {releaser} is the first name of the person who released it. {area} is the area's name. {date} is the day, such as Thursday, October 22.
                'one' => 'Nobody has taken {releaser}\'s {area} shift on {date} yet. If someone does, we\'ll email you.',
                // The first line when it was several shifts, above the list. No variables.
                'other' => 'Nobody has taken these shifts yet. If someone does, we\'ll email you.',
            ],
            // The last line. No variables. When the area has leads, a sentence from the knows lines below follows it, with a space between.
            'reach_out' => 'If nobody does, there\'s nobody to shadow, so please reach out to the volunteer coordinators to confirm next steps.',
            'knows' => [
                // Added after the last line when one lead is named. {leads} is the lead's name and role, such as Alex (Shift lead).
                'one' => '{leads} knows.',
                // Added after the last line when several leads are named. {leads} is the leads joined in code, such as Alex (Shift lead) and Jo (Shift lead).
                'other' => '{leads} know.',
            ],
        ],
        // The list of shifts in the several-shifts emails is built in code, one line each, such as Front desk, Thursday, October 22, 6:30 to 9:30pm.
    ],

    'shift_taken' => [
        // The subject of the email sent to someone who took an open shift. {area} is the area's name. {date} is the day, such as Thursday, October 22.
        'subject' => 'You\'re on {area}, {date}',
        // The greeting at the top when they took a shift someone else released. {first_name} is their first name.
        'greeting_thanks' => 'Hi {first_name}, thanks for covering!',
        // The greeting at the top when they took back a shift they released themselves. {first_name} is their first name.
        'greeting_back_on' => 'Hi {first_name}, you\'re back on.',
        // Shown when they became the regular. {how} is how often, such as Thursday or every other Thursday. {area} is the area's name. {date} is the first day, such as Thursday, October 22.
        'regular' => 'You\'re now the {how} regular for {area}, starting {date}.',
        // The line giving the time. {when} is the date and time, such as Thursday, October 22, 6:30 to 9:30pm. {area} is the area's name.
        'when_area' => '{when}, {area}.',
        // Shown when the person who released the shift left a note. {releaser} is their first name. {note} is what they typed.
        'note' => 'Note from {releaser}: {note}',
        'shadowing' => [
            // Shown when one shadow is on the shift. {names} is that person's first name.
            'one' => '{names} is shadowing.',
            // Shown when several shadows are. {names} is their first names joined in code, such as Casey and Jo.
            'other' => '{names} are shadowing.',
        ],
        // Shown when they became the regular but some dates were left out because they're on another shift then. {dates} is the days joined in code with "or", such as Thursday, October 29 or Thursday, November 5.
        'not_on_other_shift' => 'You\'re not on {dates} because you\'re already on another shift then.',
        // Shown when some dates were left out because those shifts already have the people they need. {dates} is the days joined in code with "or".
        'not_on_full' => 'You\'re not on {dates} because they already have the people they need.',
    ],

    'shift_taken_lead' => [
        // The subject when someone covered a released shift. {area} is the area's name. {date} is the day, such as Thursday, October 22.
        'subject_covered' => 'Covered: {area} on {date}',
        // The first line when the person who released the shift took it back. {taker} is their first name and last initial. {area} is the area's name. {when} is the date and time, such as Thursday, October 22, 6:30 to 9:30pm.
        'took_back' => '{taker} took back their {area} shift on {when}.',
        // The first line when someone covered a released shift. {taker} is the first name and last initial of the person who took it. {releaser} is the first name of the person who released it. {area} is the area's name. {when} is the date and time.
        'covering' => '{taker} is covering {releaser}\'s {area} shift on {when}.',
        // Shown when they became the regular. {taker} is their first name and last initial. {how} is how often, such as Thursday or every other Thursday. {date} is the first day, such as Thursday, October 22.
        'regular' => '{taker} is now the {how} regular from {date}.',
        // The subject when someone took an open place that wasn't a cover request. {taker} is their first name and last initial. {area} is the area's name. {date} is the day.
        'subject_took' => '{taker} took {area} on {date}',
        // The first line when someone took an open place that wasn't a cover request and didn't become the regular. {taker} is their first name and last initial. {area} is the area's name. {when} is the date and time.
        'took_open' => '{taker} took the open {area} shift on {when}.',
        // The label on the button that opens the shift. No variables.
        'action' => 'Open the shift',
    ],

    'shift_covered' => [
        'subject' => [
            // The subject when someone is covering one of their shifts. {taker} is the first name of the person covering. {date} is the day, such as Thursday, October 22.
            'one' => '{taker} is covering your {date} shift',
            // The subject when someone is covering several. {taker} is the first name of the person covering. {count} is how many.
            'other' => '{taker} is covering {count} of your shifts',
        ],
        // The greeting at the top. {first_name} is the first name of the person who released the shifts.
        'greeting' => 'Hi {first_name},',
        'body' => [
            // The only line when it was one shift. {taker} is the first name of the person covering. {area} is the area's name. {date} is the day.
            'one' => '{taker} took your {area} shift on {date}. You don\'t need to do anything.',
            // The first line when it was several shifts, above the list of dates. {taker} is the first name of the person covering. {area} is the area's name.
            'other' => '{taker} took your {area} shifts on these dates. You don\'t need to do anything.',
        ],
        // The list of dates in the several-shifts email is built in code, one line each, such as Thursday, October 22.
    ],

    'shift_time_changed' => [
        // The greeting at the top. {first_name} is the first name of the person whose shifts moved.
        'greeting' => 'Hi {first_name},',
        // The shift in words, used for the cancelled and back on shifts that a closure change adds to this email. {area} is the area's name. {when} is the day and time, such as Thursday, October 22, 6:30 to 9:30pm.
        'shift_phrase' => '{area} shift on {when}',
        'subject' => [
            // The subject when every shift moved to the same time in one area. {area} is the area's name. {time} is the new time, such as 6:30 to 9:30pm.
            'moved' => 'Your {area} shift moved to {time}',
            // The subject when every shift is back on at the same new time in one area. {area} is the area's name. {time} is the new time.
            'back_on' => 'Your {area} shift is back on at {time}',
            // The subject when the shifts moved to different times or are in different areas. No variables.
            'changed' => 'Your shifts changed',
            // The subject when they're back on at different times or in different areas. No variables.
            'back_on_several' => 'Your shifts are back on at new times',
        ],
        'intro' => [
            // The first line when one shift is back on at a new time. {area} is the area's name. {date} is the day, such as Thursday, October 22.
            'back_on_one' => 'Your {area} shift on {date} is back on, at a new time.',
            // The first line when several shifts are back on at new times. {area} is the area's name.
            'back_on_several' => 'Your {area} shifts are back on, at new times.',
            // The first line when someone changed the time of one shift. {changed_by} is the first name and last initial of the person who changed it. {area} is the area's name.
            'changed_one' => '{changed_by} changed the time of your {area} shift.',
            // The first line when someone changed the time of several shifts. {changed_by} is the first name and last initial of the person who changed them. {area} is the area's name.
            'changed_several' => '{changed_by} changed the time of your {area} shifts.',
            // The first line when the system changed the time, so there's nobody to name. {area} is the area's name.
            'by_system' => 'The {area} schedule changed.',
        ],
        // One line per shift. {date} is the day, such as Thursday, October 22. {now} is the new time, such as 6:30 to 9:30pm. {was} is the old time.
        'line' => '{date}: {now} (was {was})',
        // One line per shift when its title changed too. {date}, {now} and {was} are as above. {title_sentence} is the sentence about the title, such as " It's now for Open house.", with its leading space.
        'line_with_title' => '{date}: {now} (was {was}).{title_sentence}',
        // The one line for several shifts of one weekly slot that are every date from the first to the last. {weekdays} is the day in the plural, such as Thursdays. {from} and {to} are the first and last days, such as October 22 and December 10. {now} is the new time. {was} is the old time.
        'range' => '{weekdays} from {from} to {to}: {now} (was {was})',
        // The question above the buttons. No variables.
        'question' => 'Does the new time still work for you?',
        // The label on the first button. No variables.
        'still_works' => 'Still works for me',
        // The label on the second button. No variables.
        'cant' => 'I can\'t make the new time',
        // The line below the buttons. No variables. When the shift is more than 48 hours away, the lead_checks line follows it, with a space between.
        'login' => 'You\'ll log in first if you aren\'t already.',
        // Added after the login line when the earliest shift starts in more than 48 hours. No variables. The 48 hours is fixed.
        'lead_checks' => 'If you don\'t answer, your shift lead will check with you before the shift.',
    ],

    'no_answer_lead' => [
        // The greeting at the top. {first_name} is the first name of the lead or coordinator.
        'greeting' => 'Hi {first_name},',
        // The subject. {area} is the area's name. {date} is the day, such as Thursday, October 22.
        'subject' => 'No answer yet: {area}, {date}',
        // The first line. No variables.
        'intro' => 'The time of this shift changed, and these people haven\'t said whether the new time works:',
        // The shift's line, with the day in bold above it and the names below it (each line ends in two spaces so they stay on separate lines). {date} is the day, such as Thursday, October 22. {shift_line} is the area, the new time and the old time, built from the shift_line wording. {names} is the first names and last initials of the people who haven't answered, joined in code with commas.
        'shift' => "**{date}**  \n{shift_line}  \n{names}",
        // The area, the new time and the old time, used inside the shift lines above. {area} is the area's name. {now} is the new time, such as 6:30 to 9:30pm. {was} is the old time.
        'shift_line' => '{area}, {now} (was {was})',
        // The line after the shift. No variables. The 48 hours is fixed.
        'outro' => 'It starts in less than 48 hours. You might check with them that they know.',
        // The label on the button that opens the shift. No variables.
        'action' => 'Open the shift',
        // The line after the button. No variables.
        'only_reminder' => 'This is the only reminder. Nobody else is emailed.',
        // The last line, saying why they got the email. {reason} is why this person gets it, such as a shift lead for Front desk or a coordinator.
        'reason' => 'You\'re getting this because you\'re {reason}.',
    ],

    'shift_reminder' => [
        // The greeting at the top. {first_name} is the first name of the person on the shift.
        'greeting' => 'Hi {first_name},',
        'subject' => [
            // The subject when the shift is today. {area} is the area's name. {time} is the time, such as 9am to 12pm.
            'today' => 'Reminder: {area} today, {time}',
            // The subject when the shift is tomorrow. {area} is the area's name. {time} is the time.
            'tomorrow' => 'Reminder: {area} tomorrow, {time}',
            // The subject when the shift is on a later day. {area} is the area's name. {date} is the day, such as Thursday, October 22. {time} is the time.
            'date' => 'Reminder: {area} on {date}, {time}',
        ],
        'intro' => [
            'regular' => [
                // The first line for someone on the shift when it's today. {area} is the area's name. {date} is the day, such as Wednesday, October 21. {time} is the time. {timezone} is the organization's time zone, such as Eastern Time.
                'today' => 'You\'re on {area} today, {date}, {time} ({timezone}).',
                // The same when it's tomorrow. The variables are the same.
                'tomorrow' => 'You\'re on {area} tomorrow, {date}, {time} ({timezone}).',
                // The same when it's on a later day. The variables are the same.
                'date' => 'You\'re on {area} {date}, {time} ({timezone}).',
            ],
            'shadow' => [
                // The first line for someone shadowing when it's today. The variables are the same as above.
                'today' => 'You\'re shadowing {area} today, {date}, {time} ({timezone}).',
                // The same when it's tomorrow.
                'tomorrow' => 'You\'re shadowing {area} tomorrow, {date}, {time} ({timezone}).',
                // The same when it's on a later day.
                'date' => 'You\'re shadowing {area} {date}, {time} ({timezone}).',
            ],
        ],
        // Shown instead of who else is on the shift when the person is shadowing and nobody but shadows is on it. No variables.
        'nobody_to_shadow' => 'Nobody is on this shift for you to shadow yet. Please reach out to the volunteer coordinators to confirm next steps.',
        // Shown when nobody else is on the shift. No variables.
        'nobody_else' => 'Nobody else is on this shift yet.',
        // Shown when other people are on the shift. {names} is their first names and last initials joined in code with commas.
        'also_on' => 'Also on this shift: {names}',
        // Shown when shadows are on the shift. {names} is their first names and last initials joined in code with commas.
        'shadowing' => 'Shadowing: {names}',
        // Shown above the two buttons when the person hasn't answered a change of time for this shift. No variables.
        'time_changed' => 'The time of this shift changed, and you haven\'t told us yet whether the new time works.',
        // The label on the first button of that change. No variables.
        'still_works' => 'Still works for me',
        // The label on the second button of that change. No variables.
        'cant' => 'I can\'t make the new time',
        // The line below those two buttons. No variables.
        'login' => 'You\'ll log in first if you aren\'t already.',
        // The label on the button that lets the person off the shift. No variables.
        'release' => 'Can\'t make it',
        // The last line. {emails_url} is the link to the Emails page. The words in the square brackets are the link's text.
        'turn_off' => 'You can turn these reminders off on the [Emails page]({emails_url}).',
    ],

];
