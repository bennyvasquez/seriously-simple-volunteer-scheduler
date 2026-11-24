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
        // The first line, giving the code. {code} is the six digit code. The 10 minutes is how long the code works.
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
        // One line per shift in the list, when several are back on. {shift} is the shift in words.
        'shift_item' => 'The {shift}.',
        // The last line when several are back on. No variables.
        'outro' => 'If you can\'t make one, let your coordinator know.',
    ],

];
