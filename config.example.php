<?php
/**
 * Copy this file to config.php and fill in your own values.
 * config.php holds secrets and is gitignored — never commit it.
 */

return [

    // Database
    'db_host' => 'localhost',
    'db_name' => 'adstracker',
    'db_user' => 'adstracker',
    'db_pass' => 'change-me',

    // Which data provider to use: 'dataforseo', 'serpapi' or 'scrapingdog'.
    'provider' => 'serpapi',

    // DataForSEO — https://app.dataforseo.com/api-access
    'dataforseo_login'    => '',
    'dataforseo_password' => '',
    'dataforseo_language' => 'en',
    'dataforseo_depth'    => 10,

    // Postback (DataForSEO Standard queue). site_url must be publicly reachable.
    'site_url'       => 'http://127.0.0.1:8080/',
    'postback_token' => 'generate-a-long-random-string',
    'postback_log'   => true,

    'queued_timeout_hours' => 6,

    // SerpApi — engine=google_ads
    'serpapi_key' => '',

    'serpapi_no_cache' => true,
    'use_provider_locations' => true,

    'scrapingdog_key' => '',

    'location_mode' => 'location',

    'results_per_page' => 0,
    'send_language'    => false,

    'append_location_to_query' => false,

    'monthly_credit_ceiling' => 5000,

    'batch_size' => 10,

    'worker_heartbeat' => true,

    'cron_token' => '',
    'cron_http_seconds' => 45,

    'raw_retention_days' => 30,

    'default_timezone' => 'America/Chicago',
];
