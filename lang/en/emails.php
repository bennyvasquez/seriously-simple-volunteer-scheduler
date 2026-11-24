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

];
