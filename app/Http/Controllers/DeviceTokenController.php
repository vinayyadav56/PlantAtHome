<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Register / release this device's Expo push token so order and delivery events can reach it.
 *
 * Auth is enforced by the route group (auth:sanctum), so the owner is always the authenticated
 * user, and both routes are throttled.
 *
 * THE INVARIANT: one token is one PHYSICAL DEVICE, and a device belongs to exactly one user at a
 * time. `device_tokens.token` is therefore unique and registering re-points it — that is what lets
 * a shared or handed-over phone switch accounts. Keying on (user_id, token) instead would leave the
 * previous user's row alive and keep sending their notifications to a device someone else now
 * holds, which is a worse leak than the one it would close.
 *
 * The matching half is release(): signing out drops this device's row, so the previous account
 * stops being reachable here the moment they leave, rather than at the next sign-in.
 */
class DeviceTokenController extends Controller
{
    /**
     * Expo's token format. Anything else is a client bug or someone probing — a raw FCM/APNs
     * token sent here would be accepted by a bare `string` rule, stored, and then silently fail
     * at Expo forever, because Expo's endpoint only speaks its own token format.
     *
     * Both spellings are accepted: Expo's documented form is ExponentPushToken[...], and some SDK
     * versions emit ExpoPushToken[...].
     */
    private const EXPO_TOKEN = '/^Expo(nent)?PushToken\[[^\[\]\s]+\]$/';

    /** POST /device-tokens — register (or re-point) this device's token to the caller. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'    => ['required', 'string', 'max:255', 'regex:' . self::EXPO_TOKEN],
            'platform' => ['nullable', 'string', 'max:32', 'in:ios,android,web'],
        ]);

        $token = $data['token'];
        $userId = $request->user()->id;

        $existing = DeviceToken::where('token', $token)->first();
        // Idempotent by design: the app calls this on every sign-in and cold start, so the common
        // case is a row that already says exactly this. Touching nothing keeps updated_at honest as
        // "when this device last changed hands".
        if ($existing && (int) $existing->user_id === (int) $userId) {
            return response()->json(['ok' => true, 'status' => 'unchanged'], 200);
        }

        $reassigned = $existing !== null;
        DeviceToken::updateOrCreate(
            ['token' => $token],
            ['user_id' => $userId, 'platform' => $data['platform'] ?? null],
        );

        return response()->json(
            ['ok' => true, 'status' => $reassigned ? 'reassigned' : 'registered'],
            $reassigned ? 200 : 201,
        );
    }

    /**
     * DELETE /device-tokens — release this device on sign-out.
     *
     * Scoped to the caller's own row on purpose. Deleting by token alone would let anyone who
     * knows a token detach someone else's device, which is the same ownership-manipulation problem
     * in reverse. Idempotent: releasing a token that is already gone (or was never ours) is a
     * success, because the caller's goal — "this account is not reachable on this device" — holds
     * either way, and sign-out must never fail on a cleanup step.
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255', 'regex:' . self::EXPO_TOKEN],
        ]);

        $deleted = DeviceToken::where('token', $data['token'])
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['ok' => true, 'released' => (int) $deleted], 200);
    }
}
