<?php

namespace Marvel\Integrations;

use Illuminate\Support\Facades\Http;
use Marvel\Database\Models\IntegrationProvider;
use Throwable;

/**
 * "Test Connection" for a provider.
 *
 * The point is to distinguish the failure modes an operator actually cares about, because they have
 * completely different fixes:
 *
 *   auth_failed   — the credential is wrong or revoked        → re-enter it
 *   connected     — credentials work                          → nothing to do
 *   webhook_error / maintenance / unknown — reachable but not usable
 *
 * A probe must be READ-ONLY. Nothing here may create an order, a charge, or a message: an operator
 * clicking a button in a settings screen must never move money or dispatch a rider.
 *
 * Where no safe read-only probe exists, this says so plainly rather than inventing a green tick.
 */
class ConnectionTester
{
    /**
     * Which action the resulting log row is attributed to. An operator clicking "Test Connection"
     * and the scheduler sweeping every provider run the identical probe, but only one of them is
     * someone waiting for an answer — telling them apart in the log matters when reading it back.
     */
    private string $logAction = IntegrationLog::ACTION_TEST;

    public function asScheduledCheck(): self
    {
        $this->logAction = IntegrationLog::ACTION_HEALTH;

        return $this;
    }

    public function __construct(private ?IntegrationService $integrations = null)
    {
        $this->integrations = $integrations ?? new IntegrationService();
    }

    /**
     * @return array{status:string, ok:bool, message:string, detail:array}
     */
    public function test(string $slug): array
    {
        $def = ProviderRegistry::find($slug);
        if ($def === null) {
            return $this->result(IntegrationProvider::HEALTH_UNKNOWN, false, "Unknown provider: {$slug}");
        }

        $started = microtime(true);
        try {
            $res = $this->probe($slug, $def);
        } catch (Throwable $e) {
            // NOT $e->getMessage(): a transport failure from Guzzle quotes the request URL, and
            // the Maps probe carries its server key in the query string. That message is
            // returned to the browser AND written to integration_logs.error_message, so echoing
            // it would put a live credential in two places. The class name is enough to act on;
            // the detail goes to the application log.
            \Illuminate\Support\Facades\Log::warning('integration probe threw', [
                'provider' => $slug,
                'error'    => self::scrubSecrets($e->getMessage()),
            ]);
            $res = $this->result(IntegrationProvider::HEALTH_UNKNOWN, false, 'Probe failed (' . class_basename($e) . ') — see the application log.');
        }
        $res['detail']['latency_ms'] = (int) round((microtime(true) - $started) * 1000);

        $this->integrations->recordHealth($slug, $res['status'], $res['detail']);

        // The row keeps only the LATEST health result, which cannot answer "when did this start
        // failing" or "is it flapping". The log keeps the history that makes that answerable.
        IntegrationLog::record(
            $slug,
            $this->logAction,
            ($res['ok'] ?? false) ? IntegrationLog::STATUS_OK : IntegrationLog::STATUS_FAILED,
            [
                'duration_ms'   => $res['detail']['latency_ms'],
                'http_status'   => $res['detail']['http_status'] ?? null,
                'error_code'    => $res['status'],
                'error_message' => ($res['ok'] ?? false) ? null : ($res['message'] ?? null),
            ]
        );

        return $res;
    }

    private function probe(string $slug, ProviderDefinition $def): array
    {
        // Nothing configured at all — say that, rather than reporting an auth failure the operator
        // would go hunting for.
        $set = $this->integrations->credentialsSet($slug);
        $required = array_filter($def->credentialFields, static fn ($f) => !empty($f['required']));
        foreach ($required as $field) {
            if (empty($set[$field['name']])) {
                return $this->result(
                    IntegrationProvider::HEALTH_UNKNOWN,
                    false,
                    'Not configured: ' . ($field['label'] ?? $field['name']) . ' is missing.'
                );
            }
        }

        return match ($slug) {
            'shipping_service' => $this->testShippingService(),
            'porter', 'borzo', 'shiprocket' => $this->testDeliveryPartner($slug),
            'razorpay'  => $this->testRazorpay(),
            'openai'    => $this->testBearer('https://api.openai.com/v1/models', $this->integrations->secret('openai', 'api_key')),
            'anthropic' => $this->testAnthropic(),
            'sendgrid'  => $this->testBearer('https://api.sendgrid.com/v3/scopes', $this->integrations->secret('sendgrid', 'api_key')),
            'google_maps' => $this->testGoogleMaps(),
            'whatsapp'  => $this->testWhatsapp(),
            'aws_s3'    => $this->testS3(),
            'msg91'     => $this->testMsg91(),
            default => $this->result(
                IntegrationProvider::HEALTH_UNKNOWN,
                false,
                'No automated read-only test exists for this provider yet; credentials are stored but unverified.'
            ),
        };
    }

    /**
     * S3 through whatever identity the SDK resolved — the instance role in production. HeadBucket
     * is the cheapest call that still proves both reachability and permission on THIS bucket.
     */
    private function testS3(): array
    {
        $bucket = trim((string) ($this->integrations->config('aws_s3', 'bucket') ?? config('filesystems.disks.s3.bucket') ?? ''));
        if ($bucket === '') {
            return $this->result(IntegrationProvider::HEALTH_UNKNOWN, false, 'Not configured: bucket is missing.');
        }

        $started = microtime(true);
        try {
            /** @var \Aws\S3\S3Client $client */
            $client = \Illuminate\Support\Facades\Storage::disk('s3')->getClient();
            $client->headBucket(['Bucket' => $bucket]);

            return $this->result(IntegrationProvider::HEALTH_CONNECTED, true, "Bucket {$bucket} is reachable.", [
                'bucket'     => $bucket,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        } catch (\Aws\S3\Exception\S3Exception $e) {
            $code = (string) ($e->getAwsErrorCode() ?: $e->getStatusCode());
            $authFailure = in_array($code, ['AccessDenied', 'InvalidAccessKeyId', 'SignatureDoesNotMatch', '403'], true);

            return $this->result(
                $authFailure ? IntegrationProvider::HEALTH_AUTH_FAILED : IntegrationProvider::HEALTH_UNKNOWN,
                false,
                $authFailure
                    ? "S3 refused access to {$bucket} ({$code}) — check the IAM role or key policy."
                    : "S3 returned {$code} for {$bucket}.",
                ['bucket' => $bucket, 'code' => $code]
            );
        } catch (Throwable $e) {
            return $this->result(IntegrationProvider::HEALTH_UNKNOWN, false, 'S3 probe failed: ' . class_basename($e) . '.');
        }
    }

    /** The Go shipping-service itself: its health endpoint is public and cheap. */
    private function testShippingService(): array
    {
        $url = rtrim((string) $this->integrations->config('shipping_service', 'url'), '/');
        if ($url === '') {
            return $this->result(IntegrationProvider::HEALTH_UNKNOWN, false, 'Service URL is not set.');
        }

        $res = Http::timeout(10)->get($url . '/health');
        if (!$res->successful()) {
            return $this->result(IntegrationProvider::HEALTH_UNKNOWN, false, 'Health endpoint returned ' . $res->status());
        }

        $body = (array) $res->json();
        $detail = ['env' => $body['env'] ?? null, 'checks' => $body['checks'] ?? null];

        // A 200 from /health proves reachability, not that OUR api key is accepted — probe an
        // authenticated route for that, otherwise a wrong key reads as "connected".
        $auth = Http::withHeaders(['X-Api-Key' => $this->integrations->secret('shipping_service', 'api_key')])
            ->timeout(10)
            ->get($url . '/v1/health/egress');

        if ($auth->status() === 401 || $auth->status() === 403) {
            return $this->result(IntegrationProvider::HEALTH_AUTH_FAILED, false, 'Service reachable but rejected our API key.', $detail);
        }
        if ($auth->successful()) {
            $egress = (array) $auth->json();
            // The egress IP is exactly what an IP-allowlisting partner (Porter) needs.
            $detail['egress_ip'] = $egress['source_ip'] ?? null;
            $detail['partner_hosts'] = $egress['partner_hosts'] ?? null;
        }

        return $this->result(IntegrationProvider::HEALTH_CONNECTED, true, 'Shipping service reachable and authenticated.', $detail);
    }

    /**
     * Delivery partners are exercised through the Go service's read-only quote probe — the monolith
     * never calls a courier directly, and a quote creates nothing.
     */
    private function testDeliveryPartner(string $slug): array
    {
        $url = rtrim((string) $this->integrations->config('shipping_service', 'url'), '/');
        $key = $this->integrations->secret('shipping_service', 'api_key');
        if ($url === '' || $key === '') {
            return $this->result(IntegrationProvider::HEALTH_UNKNOWN, false, 'The shipping service link must be configured before a delivery partner can be tested.');
        }

        $res = Http::withHeaders(['X-Api-Key' => $key])->timeout(20)
            ->post($url . '/v1/partners/' . urlencode($slug) . '/test/quote', [
                // A short, real intra-city leg. Read-only: a quote books nothing.
                'pickup' => ['lat' => 12.939391, 'lng' => 77.626294, 'city' => 'Bengaluru', 'state' => 'Karnataka', 'pincode' => '560029', 'address' => 'Sona Towers', 'name' => 'PlantAtHome', 'phone' => '+919999999999'],
                'drop'   => ['lat' => 12.916575, 'lng' => 77.610116, 'city' => 'Bengaluru', 'state' => 'Karnataka', 'pincode' => '560029', 'address' => 'BTM Layout', 'name' => 'Test', 'phone' => '+919999999999'],
                'mode'   => 'same_city',
            ]);

        if ($res->status() === 401 || $res->status() === 403) {
            return $this->result(IntegrationProvider::HEALTH_AUTH_FAILED, false, 'The shipping service rejected our API key.');
        }
        if (!$res->successful()) {
            return $this->result(IntegrationProvider::HEALTH_UNKNOWN, false, 'Shipping service returned ' . $res->status());
        }

        $body = (array) $res->json();
        // The console endpoints answer 200 with ok:false when the PARTNER failed — that is the
        // house contract for anything proxied to a microservice.
        if (($body['ok'] ?? false) === false) {
            $error = (string) ($body['error'] ?? 'Partner call failed.');
            $status = $this->classifyPartnerError($error, (array) ($body['exchange'] ?? []));

            return $this->result($status, false, $error, ['exchange' => $this->safeExchange($body['exchange'] ?? null)]);
        }

        return $this->result(IntegrationProvider::HEALTH_CONNECTED, true, 'Partner returned a live quote.', [
            'exchange' => $this->safeExchange($body['exchange'] ?? null),
        ]);
    }

    /**
     * Turn a partner error into a health state. Porter's UAT gateway is the case worth special
     * handling: it is IP-allowlisted at the edge and returns an identical 403 for every path
     * whether the key is valid, wrong or absent — so reporting "authentication failed" would send
     * an operator to re-enter a key that was never the problem.
     */
    private function classifyPartnerError(string $error, array $exchange): string
    {
        $status = (int) ($exchange['response']['status'] ?? 0);
        $body = strtolower((string) ($exchange['response']['body'] ?? ''));

        if ($status === 403 && str_contains($body, 'unauthorized access')) {
            return IntegrationProvider::HEALTH_WEBHOOK_ERROR; // reachable, but blocked before auth
        }
        if (in_array($status, [401, 403], true)) {
            return IntegrationProvider::HEALTH_AUTH_FAILED;
        }
        if ($status === 503 || $status === 502) {
            return IntegrationProvider::HEALTH_MAINTENANCE;
        }
        if (str_contains(strtolower($error), 'unauthor')) {
            return IntegrationProvider::HEALTH_AUTH_FAILED;
        }

        return IntegrationProvider::HEALTH_UNKNOWN;
    }

    /** Razorpay: list one payment. Read-only, and it proves the SECRET (not just the public key id). */
    private function testRazorpay(): array
    {
        $keyId = (string) $this->integrations->config('razorpay', 'key_id');
        $secret = $this->integrations->secret('razorpay', 'key_secret');
        if ($keyId === '' || $secret === '') {
            return $this->result(IntegrationProvider::HEALTH_UNKNOWN, false, 'Key ID and Key Secret are both required.');
        }

        $res = Http::withBasicAuth($keyId, $secret)->timeout(15)
            ->get('https://api.razorpay.com/v1/payments', ['count' => 1]);

        if ($res->status() === 401) {
            return $this->result(IntegrationProvider::HEALTH_AUTH_FAILED, false, 'Razorpay rejected these credentials.');
        }
        if (!$res->successful()) {
            return $this->result(IntegrationProvider::HEALTH_UNKNOWN, false, 'Razorpay returned ' . $res->status());
        }

        // The mode matters more than the connection: a test key silently declines real customer
        // cards, which looks like "payments are broken" rather than a configuration mistake.
        $isTest = str_starts_with($keyId, 'rzp_test_');

        return $this->result(
            IntegrationProvider::HEALTH_CONNECTED,
            true,
            $isTest
                ? 'Connected — but this is a TEST key: real customer payments will not succeed.'
                : 'Connected in live mode.',
            ['mode' => $isTest ? 'test' : 'live']
        );
    }

    private function testAnthropic(): array
    {
        $key = $this->integrations->secret('anthropic', 'api_key');
        $res = Http::withHeaders([
            'x-api-key'         => $key,
            'anthropic-version' => '2023-06-01',
        ])->timeout(15)->get('https://api.anthropic.com/v1/models');

        if (in_array($res->status(), [401, 403], true)) {
            return $this->result(IntegrationProvider::HEALTH_AUTH_FAILED, false, 'Anthropic rejected this API key.');
        }
        if (!$res->successful()) {
            return $this->result(IntegrationProvider::HEALTH_UNKNOWN, false, 'Anthropic returned ' . $res->status());
        }

        return $this->result(IntegrationProvider::HEALTH_CONNECTED, true, 'Connected.');
    }

    private function testBearer(string $url, string $token): array
    {
        if ($token === '') {
            return $this->result(IntegrationProvider::HEALTH_UNKNOWN, false, 'No API key stored.');
        }
        $res = Http::withToken($token)->timeout(15)->get($url);
        if (in_array($res->status(), [401, 403], true)) {
            return $this->result(IntegrationProvider::HEALTH_AUTH_FAILED, false, 'The provider rejected this API key.');
        }
        if (!$res->successful()) {
            return $this->result(IntegrationProvider::HEALTH_UNKNOWN, false, 'Provider returned ' . $res->status());
        }

        return $this->result(IntegrationProvider::HEALTH_CONNECTED, true, 'Connected.');
    }

    private function testGoogleMaps(): array
    {
        $key = $this->integrations->secret('google_maps', 'server_key');
        $res = Http::timeout(15)->get('https://maps.googleapis.com/maps/api/geocode/json', [
            'address' => 'Bengaluru',
            'key'     => $key,
        ]);
        $body = (array) $res->json();
        $status = (string) ($body['status'] ?? '');

        if ($status === 'REQUEST_DENIED') {
            return $this->result(IntegrationProvider::HEALTH_AUTH_FAILED, false, (string) ($body['error_message'] ?? 'Google denied the request.'));
        }
        if ($status === 'OVER_QUERY_LIMIT') {
            return $this->result(IntegrationProvider::HEALTH_MAINTENANCE, false, 'Quota exceeded.');
        }
        if ($status !== 'OK' && $status !== 'ZERO_RESULTS') {
            return $this->result(IntegrationProvider::HEALTH_UNKNOWN, false, 'Geocoding returned ' . ($status ?: $res->status()));
        }

        return $this->result(IntegrationProvider::HEALTH_CONNECTED, true, 'Connected.');
    }

    /**
     * The Go console already masks credential headers before returning an exchange, but this drops
     * the request half entirely — the response is what diagnoses a failure, and the request can only
     * add ways for a secret to travel.
     */
    private function safeExchange(mixed $exchange): ?array
    {
        if (!is_array($exchange)) {
            return null;
        }
        $response = (array) ($exchange['response'] ?? []);

        return [
            'status'      => $response['status'] ?? null,
            'body'        => isset($response['body']) ? mb_substr((string) $response['body'], 0, 500) : null,
            'duration_ms' => $exchange['duration_ms'] ?? null,
        ];
    }

    /**
     * Strip credential values out of a transport error before it is logged.
     *
     * Keeping the URL out of the API response was only half the fix: a Guzzle
     * ConnectionException quotes the full request URL, and two probes have to pass
     * their credential in the query string because that is the only contract the
     * vendor offers -- Google Maps takes `key`, MSG91's balance API takes `authkey`.
     * Writing that message to the application log verbatim files a live credential
     * in a place the whole ops team can read.
     */
    private static function scrubSecrets(string $message): string
    {
        return (string) preg_replace(
            '/\b(authkey|auth_key|key|api_key|token|password|secret)=[^&\s\'\"]*/i',
            '$1=[REDACTED]',
            $message
        );
    }

    /**
     * MSG91 read-only probe: the v5 balance report.
     *
     * The first version of this used the legacy `api.msg91.com/api/balance.php`, and it was
     * WRONG in a way that mattered: that endpoint answers a bogus auth key with the body `0`,
     * which is_numeric() accepts, so a completely invalid credential reported "Connected" with a
     * zero balance. A test that cannot fail is worse than no test; so is a probe that cannot go
     * red. Measured against the live API before rewriting:
     *
     *   balance.php, bogus key   -> HTTP 200, body "0"          (indistinguishable from no credit)
     *   balance.php, no key      -> HTTP 200, {"msgType":"error"}
     *   v5/report/balance, bogus -> HTTP 401, {"errors":"Unauthorized"}   <- a real contract
     *
     * The v5 route also takes the key in a HEADER rather than the query string, which removes the
     * whole class of leak the query-string version needed scrubbing for: a Guzzle transport error
     * quotes the request URL, and that URL used to carry a live auth key into the application log.
     */
    private function testMsg91(): array
    {
        $key = $this->integrations->secret('msg91', 'auth_key') ?: config('services.msg91.auth_key');
        if (!$key) {
            return $this->result(IntegrationProvider::HEALTH_UNKNOWN, false, 'Not configured: Auth Key is missing.');
        }

        $res = Http::withHeaders(['authkey' => $key])
            ->acceptJson()
            ->timeout(10)
            ->get('https://control.msg91.com/api/v5/report/balance');

        if (in_array($res->status(), [401, 403], true)) {
            return $this->result(IntegrationProvider::HEALTH_AUTH_FAILED, false, 'MSG91 rejected the auth key.');
        }
        if ($res->status() >= 500) {
            return $this->result(IntegrationProvider::HEALTH_MAINTENANCE, false, 'MSG91 returned ' . $res->status() . '.');
        }
        if (!$res->successful()) {
            // Never echo the body: an unrecognised MSG91 reply has historically been the request
            // echoed back, and the request is the thing carrying the credential.
            return $this->result(IntegrationProvider::HEALTH_UNKNOWN, false, 'MSG91 returned HTTP ' . $res->status() . '.');
        }

        $balance = $this->numericIn((array) $res->json());

        // A working key with no credit still fails every send, so say so rather than reporting a
        // flat green that the next failed OTP will contradict.
        if ($balance !== null && $balance <= 0) {
            return $this->result(
                IntegrationProvider::HEALTH_MAINTENANCE,
                false,
                'Credentials are valid, but the MSG91 balance is 0 — sends will fail.',
                ['route_balance' => $balance]
            );
        }

        return $this->result(
            IntegrationProvider::HEALTH_CONNECTED,
            true,
            'Connected.',
            $balance === null ? [] : ['route_balance' => $balance]
        );
    }

    /**
     * First numeric value anywhere in a shallow response tree.
     *
     * MSG91's balance payload is not documented and has changed shape before, so this looks for a
     * number rather than pinning one key and reporting "unknown" the day they rename it.
     */
    private function numericIn(array $data, int $depth = 0): ?float
    {
        if ($depth > 3) {
            return null;
        }
        foreach ($data as $value) {
            if (is_numeric($value)) {
                return (float) $value;
            }
            if (is_array($value)) {
                $found = $this->numericIn($value, $depth + 1);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function result(string $status, bool $ok, string $message, array $detail = []): array
    {
        return ['status' => $status, 'ok' => $ok, 'message' => $message, 'detail' => $detail];
    }

    /**
     * Read-only WhatsApp probe: fetch the phone number's own record. Verifies the token, the
     * phone-number id and that the number is registered — the three things that break sends —
     * without messaging anyone.
     */
    private function testWhatsapp(): array
    {
        $token = $this->integrations->secret('whatsapp', 'access_token') ?: config('services.whatsapp.access_token');
        $phoneId = $this->integrations->config('whatsapp', 'phone_number_id') ?: config('services.whatsapp.phone_number_id');
        $version = $this->integrations->config('whatsapp', 'api_version') ?: (config('services.whatsapp.api_version') ?: 'v21.0');

        if (!$token || !$phoneId) {
            return $this->result(IntegrationProvider::HEALTH_FAILING, false,
                'Add both the access token and the Phone Number ID, then test again.');
        }

        try {
            $res = \Illuminate\Support\Facades\Http::withToken($token)->timeout(8)
                ->get("https://graph.facebook.com/{$version}/{$phoneId}", [
                    'fields' => 'display_phone_number,verified_name,quality_rating,code_verification_status',
                ]);
            $body = $res->json() ?? [];

            if ($res->successful()) {
                $name = $body['verified_name'] ?? 'unnamed';
                $number = $body['display_phone_number'] ?? $phoneId;
                $quality = $body['quality_rating'] ?? 'unknown';
                return $this->result(IntegrationProvider::HEALTH_CONNECTED, true,
                    "Connected to {$number} ({$name}); quality rating {$quality}.", $body);
            }
            // Meta's own words are far more useful than a generic failure.
            $msg = $body['error']['message'] ?? 'Meta rejected the request.';
            return $this->result(IntegrationProvider::HEALTH_FAILING, false, $msg, $body);
        } catch (\Throwable $e) {
            return $this->result(IntegrationProvider::HEALTH_FAILING, false, $e->getMessage());
        }
    }
}
