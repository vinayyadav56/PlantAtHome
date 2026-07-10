<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Search (v2) — Elasticsearch with MySQL fallback
    |--------------------------------------------------------------------------
    | When `elasticsearch_host` is empty (the staging default), Search uses the
    | MySQL search_products projection directly. Point it at an ES cluster to
    | promote ES to the primary index; the MySQL projection remains the fallback.
    */
    'elasticsearch_host' => env('SEARCH_ES_HOST', ''),
    'index'              => env('SEARCH_ES_INDEX', 'products'),
    'ping_timeout'       => 2,
];
