<?php

return [
    'environment' => env('FIRST_TRUST_ENVIRONMENT'),
    'installation_id' => env('FIRST_TRUST_INSTALLATION_ID'),
    'release_sha' => env('FIRST_TRUST_RELEASE_SHA'),

    'authority' => [
        'reference' => env('FIRST_TRUST_AUTHORITY_REFERENCE'),
        'issuer' => env('FIRST_TRUST_AUTHORITY_ISSUER'),
        'audience' => env('FIRST_TRUST_AUTHORITY_AUDIENCE'),
        'base_url' => env('FIRST_TRUST_AUTHORITY_BASE_URL'),
        'verification_bundle_path' => env('FIRST_TRUST_VERIFICATION_BUNDLE_PATH'),
    ],
];
