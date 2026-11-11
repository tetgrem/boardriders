<?php

return [
    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    'nova_poshta' => [
        'api_key' => env('NOVA_POSHTA_API_KEY'),
        'sender_city_ref' => env('NOVA_POSHTA_SENDER_CITY_REF'),
    ],
    'cod_financial_gateway' => [
        'enabled' => env('COD_FINANCIAL_GATEWAY_ENABLED', false),

        'keys' => [
            'primary' => env('COD_FINANCIAL_GATEWAY_PRIMARY_SECRET'),
            'secondary' => env('COD_FINANCIAL_GATEWAY_SECONDARY_SECRET'),
        ],
    ],

    'cod_bank_evidence' => [
        'enabled' => env('COD_BANK_EVIDENCE_ENABLED', false),
        'providers' => [
            'provider_primary' => [
                'enabled' => env('COD_BANK_EVIDENCE_PROVIDER_ENABLED', false),
                'account_alias' => env(
                    'COD_BANK_EVIDENCE_ACCOUNT_ALIAS',
                    'cod_primary_ua'
                ),
                'public_keys' => [
                    'active' => env('COD_BANK_EVIDENCE_PUBLIC_KEY_ACTIVE'),
                    'previous' => env('COD_BANK_EVIDENCE_PUBLIC_KEY_PREVIOUS'),
                ],
            ],
        ],
    ],

    'privatbank_autoclient' => [
        'enabled' => env('PRIVATBANK_AUTOCLIENT_ENABLED', false),
        'token' => env('PRIVATBANK_AUTOCLIENT_TOKEN'),
        'account_iban' => env('PRIVATBANK_AUTOCLIENT_IBAN'),
        'account_alias' => env(
            'PRIVATBANK_AUTOCLIENT_ACCOUNT_ALIAS',
            'pb_primary_ua'
        ),
    ],

];
