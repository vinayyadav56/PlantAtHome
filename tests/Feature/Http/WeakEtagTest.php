<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * `cache.headers:etag` must answer with the SAME weak validator on the 200 and the 304. The proxy
 * weakens the 200's ETag when it gzips; a strong ETag on the body-less 304 then disagreed with what
 * the browser had cached (see App\Http\Middleware\SetCacheHeaders).
 */
final class WeakEtagTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/__etag-probe', fn () => response()->json(['data' => [1, 2, 3]]))
            ->middleware('cache.headers:etag');
    }

    public function test_the_200_carries_a_weak_etag(): void
    {
        $etag = $this->get('/__etag-probe')->assertOk()->headers->get('ETag');

        $this->assertStringStartsWith('W/"', (string) $etag);
    }

    public function test_a_revalidation_gets_a_304_with_the_same_weak_etag(): void
    {
        $etag = (string) $this->get('/__etag-probe')->headers->get('ETag');

        // The browser echoes what it cached — weak, and also the strong form a proxy might pass on.
        foreach ([$etag, substr($etag, 2)] as $sent) {
            $res = $this->get('/__etag-probe', ['If-None-Match' => $sent]);
            $res->assertStatus(304);
            $this->assertSame($etag, $res->headers->get('ETag'));
        }
    }
}
