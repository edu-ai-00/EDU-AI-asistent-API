<?php

return [
    'verification' => [
        'subject' => 'Your EduAI verification code',
        'title' => 'Your verification code',
        'greeting' => 'Hello,',
        'intro' => 'Use the following code to verify your e-mail address in the EduAI app:',
        'expiry' => 'The code is valid for :minutes minutes.',
        'ignore' => "If you didn't request this code, you can ignore this e-mail.",
        'footer_auto' => 'This e-mail was sent automatically from the EduAI app.',
        'footer_no_reply' => 'Please do not reply to this e-mail.',
    ],

    'team_invite' => [
        'subject' => 'You have access to the EduAI admin',
        'title' => 'Welcome to the EduAI admin',
        'greeting' => 'Hello :name,',
        'intro' => 'An EduAI account has been created for you with the :role role. You can now sign in to the admin.',
        'how_to_sign_in' => 'You sign in with the e-mail address this message was sent to — no password needed. Enter the address and we will e-mail you a one-time verification code.',
        'cta' => 'Sign in to the admin',
        'expires' => 'This invitation is valid until :date. If you do not sign in by then, ask an administrator to send it again.',
        'role_teacher' => 'teacher',
        'role_admin' => 'administrator',
        'footer_auto' => 'This e-mail was sent automatically from the EduAI app.',
        'footer_no_reply' => 'Please do not reply to this e-mail.',
    ],
];
