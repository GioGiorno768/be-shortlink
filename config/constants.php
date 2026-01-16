<?php

/**
 * Application Constants
 * 
 * Static data that doesn't change frequently.
 * Moved from controllers for better organization and caching.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Country Names Mapping
    |--------------------------------------------------------------------------
    |
    | ISO 3166-1 alpha-2 country codes to full names.
    | Used in analytics/stats endpoints.
    |
    */
    'countries' => [
        // Southeast Asia
        'ID' => 'Indonesia',
        'MY' => 'Malaysia',
        'SG' => 'Singapore',
        'PH' => 'Philippines',
        'VN' => 'Vietnam',
        'TH' => 'Thailand',
        // South Asia
        'IN' => 'India',
        'BD' => 'Bangladesh',
        'PK' => 'Pakistan',
        // Americas
        'US' => 'United States',
        'CA' => 'Canada',
        'BR' => 'Brazil',
        'MX' => 'Mexico',
        'AR' => 'Argentina',
        'CO' => 'Colombia',
        'CL' => 'Chile',
        'PE' => 'Peru',
        // Europe
        'GB' => 'United Kingdom',
        'DE' => 'Germany',
        'FR' => 'France',
        'NL' => 'Netherlands',
        'SE' => 'Sweden',
        'NO' => 'Norway',
        'DK' => 'Denmark',
        'FI' => 'Finland',
        'BE' => 'Belgium',
        'AT' => 'Austria',
        'CH' => 'Switzerland',
        'IE' => 'Ireland',
        // Others
        'JP' => 'Japan',
        'AU' => 'Australia',
        'NZ' => 'New Zealand',
        'RU' => 'Russia',
        'TR' => 'Turkey',
        'EG' => 'Egypt',
        'ZA' => 'South Africa',
        'KE' => 'Kenya',
        'NG' => 'Nigeria',
        'OTHER' => 'Other Countries',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Countries for Analytics
    |--------------------------------------------------------------------------
    |
    | Countries that should always appear in analytics even if no data.
    |
    */
    'default_countries' => [
        'ID',   // Indonesia
        'US',   // United States
        'DE',   // Germany
        'PH',   // Philippines
        'MY',   // Malaysia
        'VN',   // Vietnam
        'TH',   // Thailand
    ],

    /*
    |--------------------------------------------------------------------------
    | Referrer Labels Mapping
    |--------------------------------------------------------------------------
    |
    | Referrer key to human-readable label mapping.
    |
    */
    'referrer_labels' => [
        'direct'    => 'Direct / Email / SMS',
        'google'    => 'Google',
        'facebook'  => 'Facebook',
        'instagram' => 'Instagram',
        'whatsapp'  => 'WhatsApp',
        'youtube'   => 'YouTube',
        'tiktok'    => 'TikTok',
        'telegram'  => 'Telegram',
        'twitter_x' => 'Twitter / X',
        'linkedin'  => 'LinkedIn',
        'pinterest' => 'Pinterest',
        'reddit'    => 'Reddit',
        'other'     => 'Other',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Referrers for Analytics
    |--------------------------------------------------------------------------
    |
    | Referrers that should always appear in analytics even if no data.
    |
    */
    'default_referrers' => [
        'direct',
        'google',
        'facebook',
        'instagram',
        'whatsapp',
        'youtube',
        'tiktok',
        'telegram',
    ],
];
