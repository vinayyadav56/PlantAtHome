<?php

namespace App\Services;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Send Expo push notifications to a user's registered devices. Expo's push endpoint
 * needs NO server secret (it proxies to APNs/FCM), so this is credential-free.
 *
 * Best-effort by design: it never throws into the caller (a queued notification
 * listener — a push nicety must never fail an order-status update), and it prunes any
 * token Expo reports as unregistered so dead devices don't accumulate.
 */
class ExpoPushService
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    /** Push to every device registered to $user. $user may be null (no-op). */
    public function sendToUser($user, string $title, string $body, array $data = []): void
    {
        if (!$user || !isset($user->id)) {
            return;
        }
        try {
            $tokens = DeviceToken::where('user_id', $user->id)->pluck('token')->all();
        } catch (\Throwable $e) {
            return; // table missing (pre-migration) or DB error — push is best-effort
        }
        $this->send($tokens, $title, $body, $data);
    }

    /** @param string[] $tokens */
    public function send(array $tokens, string $title, string $body, array $data = []): void
    {
        $tokens = array_values(array_filter(array_unique($tokens)));
        if (empty($tokens)) {
            return;
        }

        $messages = array_map(fn ($t) => [
            'to'    => $t,
            'title' => $title,
            'body'  => $body,
            'data'  => $data,
            'sound' => 'default',
        ], $tokens);

        try {
            $res = Http::acceptJson()->post(self::ENDPOINT, $messages);
            $this->pruneInvalid($tokens, (array) $res->json('data'));
        } catch (\Throwable $e) {
            Log::warning('expo.push.failed', ['error' => $e->getMessage(), 'count' => count($tokens)]);
        }
    }

    /**
     * Expo returns one receipt per message, in order. A DeviceNotRegistered error means
     * the app was uninstalled or the token rotated — drop it so we stop sending to a dead
     * device (and stop the volume from creeping up forever).
     */
    private function pruneInvalid(array $tokens, array $receipts): void
    {
        $dead = [];
        foreach ($receipts as $i => $r) {
            if (($r['status'] ?? '') === 'error'
                && (($r['details']['error'] ?? '') === 'DeviceNotRegistered')
                && isset($tokens[$i])) {
                $dead[] = $tokens[$i];
            }
        }
        if (!empty($dead)) {
            DeviceToken::whereIn('token', $dead)->delete();
        }
    }
}
