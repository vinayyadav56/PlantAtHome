<?php

/*
|--------------------------------------------------------------------------
| Storefront visitor tracking
|--------------------------------------------------------------------------
|
| Plain values on purpose — nothing on the production box writes new env
| vars and prod runs config:cache, so env() here would only add a way to be
| wrong. Edit this file to tune. Every reader goes through config('tracking.*').
|
| Definitions (the one place they are written down):
|   human    UA is a mainstream browser with no automation token.
|   bot      UA matches a named crawler below, or a generic automation token.
|   unknown  everything else — classification is deliberately conservative,
|            so uncertain traffic is "unknown", never "bot".
|   total    human + bot + unknown.   Business traffic = human only.
|   online   visitors.last_seen within online_timeout_sec (the storefront
|            heartbeats every 30 s, so 120 s = four missed beats).
|   session  one analytics_sessions row; a new one starts after
|            session_timeout_min without a ping.
|   engaged  a HUMAN session with >= 2 page views, OR >= 30 s on site, OR a
|            product_view / add_to_cart / begin_checkout event.
|   customer online human with a user_id; guest = online human without.
|            Bots are never customers, whatever user_id they send.
|
| A visitor is one pah_vid cookie: clearing cookies, another browser, another
| device or a private window all look like a new visitor. It is a browser,
| not a guaranteed unique person.
*/
return [
    'online_timeout_sec'  => 120,
    'session_timeout_min' => 30,
    'engaged_after_sec'   => 30,

    // ponytail: no daily rollup table — retention is long enough for every
    // report the admin shows. Add analytics_daily when analytics_sessions
    // passes ~1M rows and the by-day GROUP BY starts to hurt.
    'retention' => [
        'events_days'   => 30,
        'sessions_days' => 90,
        'visitors_days' => 90,
    ],

    'max_body_bytes' => 16384,
    'max_events'     => 20,

    // Shared secret for the crawler leg (POST /track/crawl). When set, a ping must carry
    // `X-Track-Key: <secret>` or it is dropped (still 204). The storefront's proxy.ts sends
    // it from TRACKING_CRAWL_SECRET. Unset = open, like the public SDK route: the fields
    // it carries (ip, coarse geo) are analytics-only and never authorise anything.
    'crawl_secret' => env('TRACKING_CRAWL_SECRET'),

    /*
    | Named crawlers: regex fragment (case-insensitive, matched against the
    | User-Agent) => [display name, type]. First match wins, so put the
    | specific entries before the broad ones. A null name means "use the
    | matched token". Types: search | ai | seo | social | monitor | tool | other.
    */
    'bots' => [
        'Googlebot|Google-InspectionTool|AdsBot-Google|Mediapartners-Google|Storebot-Google' => ['Googlebot', 'search'],
        'bingbot|BingPreview|adidxbot'      => ['Bingbot', 'search'],
        'Baiduspider'                       => ['Baiduspider', 'search'],
        'YandexBot|YandexImages'            => ['YandexBot', 'search'],
        'DuckDuckBot|DuckDuckGo'            => ['DuckDuckBot', 'search'],
        'Applebot'                          => ['Applebot', 'search'],
        'Sogou'                             => ['Sogou', 'search'],

        'GPTBot'                            => ['GPTBot', 'ai'],
        'ChatGPT-User'                      => ['ChatGPT-User', 'ai'],
        'OAI-SearchBot'                     => ['OAI-SearchBot', 'ai'],
        'ClaudeBot|anthropic-ai|Claude-Web|Claude-User|Claude-SearchBot' => ['ClaudeBot', 'ai'],
        'PerplexityBot|Perplexity-User'     => ['PerplexityBot', 'ai'],
        'Google-Extended'                   => ['Google-Extended', 'ai'],
        'CCBot'                             => ['CCBot', 'ai'],
        'Bytespider'                        => ['Bytespider', 'ai'],
        'Amazonbot'                         => ['Amazonbot', 'ai'],
        'meta-externalagent|FacebookBot'    => ['Meta-ExternalAgent', 'ai'],
        'cohere-ai'                         => ['Cohere', 'ai'],
        'Diffbot'                           => ['Diffbot', 'ai'],

        'AhrefsBot|AhrefsSiteAudit'         => ['AhrefsBot', 'seo'],
        'SemrushBot|SiteAuditBot'           => ['SemrushBot', 'seo'],
        'MJ12bot'                           => ['MJ12bot', 'seo'],
        'DotBot'                            => ['DotBot', 'seo'],
        'PetalBot'                          => ['PetalBot', 'seo'],
        'DataForSeoBot'                     => ['DataForSeoBot', 'seo'],
        'Screaming Frog'                    => ['Screaming Frog', 'seo'],
        'BLEXBot'                           => ['BLEXBot', 'seo'],

        'facebookexternalhit|Facebot'       => ['Facebook', 'social'],
        'Twitterbot'                        => ['Twitterbot', 'social'],
        'LinkedInBot'                       => ['LinkedInBot', 'social'],
        'WhatsApp'                          => ['WhatsApp', 'social'],
        'TelegramBot'                       => ['TelegramBot', 'social'],
        'Pinterestbot|Pinterest'            => ['Pinterest', 'social'],
        'Slackbot|Slack-ImgProxy'           => ['Slackbot', 'social'],
        'Discordbot'                        => ['Discordbot', 'social'],

        'Chrome-Lighthouse|PTST|GTmetrix|PageSpeed|UptimeRobot|Pingdom|StatusCake|vercel-screenshot|Site24x7|BetterUptime' => [null, 'monitor'],
        'HeadlessChrome|PhantomJS|Selenium|Puppeteer|Playwright|WebDriver' => [null, 'tool'],
    ],

    // Anything automated that the table above does not name. The storefront
    // proxy's coarse regex must be a SUBSET of (bots ∪ this), or an "unknown"
    // client could be counted by both the server and the SDK leg.
    'generic_bot_regex' => '/\b(bot|crawl(?:er|ing)?|spider|slurp|scrap(?:er|y|ing)?|fetch(?:er)?|archiver|validator|monitor(?:ing)?|curl|wget|python-requests|python-urllib|aiohttp|httpx|java\/|go-http-client|okhttp|axios|node-fetch|undici|libwww|httpclient|postman|insomnia|apache-httpclient|feed(?:fetcher|parser)|preview|headless)\b/i',

    // What a real browser looks like. Mozilla/5.0 + a mainstream engine token.
    'browser_regex' => '/^Mozilla\/5\.0.*\b(Chrome|CriOS|Safari|Firefox|FxiOS|Edg|EdgA|EdgiOS|OPR|Opera|SamsungBrowser|UCBrowser|Vivaldi|Brave|YaBrowser)\b/i',
];
