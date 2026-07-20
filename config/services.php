<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'google' => [
        // Comma-separated OAuth client IDs accepted as id_token audiences.
        // Include every platform client (Web, iOS, Android) that signs users in.
        'client_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GOOGLE_CLIENT_IDS', ''))
        ))),
    ],

    'apple' => [
        // Comma-separated client IDs accepted as identity-token audiences:
        // the native iOS bundle ID and (for web) the Services ID.
        'client_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('APPLE_CLIENT_IDS', ''))
        ))),
    ],

    'microsoft' => [
        // Comma-separated Entra ID application (client) IDs accepted as
        // id_token audiences.
        'client_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MICROSOFT_CLIENT_IDS', ''))
        ))),
        // Tenant: a specific GUID (single-tenant) or common/organizations/
        // consumers (multi-tenant). Must match the app's aad_oauth config.
        'tenant' => env('MICROSOFT_TENANT', 'common'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
