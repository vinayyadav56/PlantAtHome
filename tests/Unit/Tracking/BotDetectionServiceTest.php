<?php

namespace Tests\Unit\Tracking;

use Marvel\Services\Tracking\BotDetectionService;
use Tests\TestCase;

/**
 * Conservative classification: named crawlers and obvious automation are bots,
 * mainstream browsers are human, and anything we cannot place is UNKNOWN —
 * never bot. A normal shopper must never be labelled a bot.
 */
final class BotDetectionServiceTest extends TestCase
{
    private BotDetectionService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new BotDetectionService();
    }

    /** @dataProvider bots */
    public function test_named_crawlers_are_bots_with_a_name_and_a_type(string $ua, string $name, string $type): void
    {
        $r = $this->svc->classify($ua);
        $this->assertSame('bot', $r['type'], $ua);
        $this->assertSame($name, $r['name'], $ua);
        $this->assertSame($type, $r['bot_type'], $ua);
    }

    public static function bots(): array
    {
        return [
            'googlebot'  => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'Googlebot', 'search'],
            'google-insp' => ['Mozilla/5.0 (compatible; Google-InspectionTool/1.0;)', 'Googlebot', 'search'],
            'bingbot'    => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm) Chrome/116.0.1938.76 Safari/537.36', 'Bingbot', 'search'],
            'gptbot'     => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)', 'GPTBot', 'ai'],
            'claudebot'  => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', 'ClaudeBot', 'ai'],
            'perplexity' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)', 'PerplexityBot', 'ai'],
            'ahrefs'     => ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)', 'AhrefsBot', 'seo'],
            'semrush'    => ['Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)', 'SemrushBot', 'seo'],
            'facebook'   => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', 'Facebook', 'social'],
            'whatsapp'   => ['WhatsApp/2.23.20.0 A', 'WhatsApp', 'social'],
            'lighthouse' => ['Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Mobile Safari/537.36 Chrome-Lighthouse', 'Chrome-Lighthouse', 'monitor'],
            'headless'   => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36', 'HeadlessChrome', 'tool'],
        ];
    }

    /** @dataProvider generic */
    public function test_generic_automation_is_a_bot_of_type_other(string $ua, string $name): void
    {
        $r = $this->svc->classify($ua);
        $this->assertSame('bot', $r['type'], $ua);
        $this->assertSame('other', $r['bot_type'], $ua);
        $this->assertSame($name, $r['name'], $ua);
    }

    public static function generic(): array
    {
        return [
            'python'  => ['python-requests/2.31.0', 'Python-Requests'],
            'curl'    => ['curl/8.4.0', 'Curl'],
            'go'      => ['Go-http-client/1.1', 'Go-Http-Client'],
            'unnamed' => ['Mozilla/5.0 (compatible; SomeNewCrawler/1.0; +https://example.com/crawler)', 'Crawler'],
        ];
    }

    /** @dataProvider humans */
    public function test_mainstream_browsers_are_human(string $ua): void
    {
        $this->assertSame(['type' => 'human', 'name' => null, 'bot_type' => null], $this->svc->classify($ua), $ua);
    }

    public static function humans(): array
    {
        return [
            'chrome win'     => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36'],
            'safari mac'     => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15'],
            'firefox'        => ['Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0'],
            'edge'           => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Edg/129.0.0.0'],
            'android chrome' => ['Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36'],
            'ios safari'     => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'],
            'samsung'        => ['Mozilla/5.0 (Linux; Android 13; SAMSUNG SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36'],
            // a word like "robot" inside a product name must not trip the generic regex
            'brand word'     => ['Mozilla/5.0 (Linux; Android 12; Robotic X1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36'],
        ];
    }

    /** @dataProvider unknowns */
    public function test_anything_we_cannot_place_is_unknown_not_bot(?string $ua): void
    {
        $this->assertSame('unknown', $this->svc->classify($ua)['type'], (string) $ua);
    }

    public static function unknowns(): array
    {
        return [
            'null'          => [null],
            'empty'         => [''],
            'short'         => ['Mozilla'],
            'bare mozilla'  => ['Mozilla/5.0 (compatible)'],
            'odd app'       => ['SomeApp/1.0 (internal build)'],
            'dart'          => ['Dart/3.4 (dart:io)'],
        ];
    }
}
