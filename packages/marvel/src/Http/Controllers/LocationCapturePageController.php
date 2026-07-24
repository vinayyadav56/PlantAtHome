<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Database\Models\LocationCaptureRequest;
use Marvel\Services\LocationCaptureService;

/**
 * Public capture flow (web routes, session + CSRF): the emailed link opens a
 * standalone branded page; the browser's GPS reading posts back here. The
 * one-time token is the authorization — no login involved.
 */
class LocationCapturePageController extends CoreController
{
    /** GET /location/{token} — the capture page (or its expired/invalid/used states). */
    public function show(string $token, LocationCaptureService $service)
    {
        $request = $service->findByToken($token);

        $state = match (true) {
            $request === null                                                => 'invalid',
            $request->status === LocationCaptureRequest::STATUS_COMPLETED    => 'used',
            !$request->isPending()                                           => 'expired',
            default                                                          => 'capture',
        };

        // First page view doubles as "opened" when the pixel was blocked.
        if ($request && $request->opened_at === null) {
            $request->forceFill(['opened_at' => now()])->save();
        }

        return response()->view('location.capture', [
            'state' => $state,
            'token' => $state === 'capture' ? $token : null,
        ]);
    }

    /** POST /location/submit — one-time coordinate submission. */
    public function submit(Request $httpRequest, LocationCaptureService $service)
    {
        $data = $httpRequest->validate([
            'token'     => 'required|string|min:24|max:128',
            'latitude'  => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy'  => 'nullable|numeric|min:0',
        ]);

        $result = $service->complete(
            (string) $data['token'],
            (float) $data['latitude'],
            (float) $data['longitude'],
            isset($data['accuracy']) ? (float) $data['accuracy'] : null,
            $httpRequest->ip(),
            (string) $httpRequest->userAgent(),
        );

        if ($result['ok']) {
            return response()->json(['success' => true]);
        }

        $messages = [
            'invalid'  => 'Invalid location request.',
            'used'     => 'This link has already been used.',
            'expired'  => 'This location request has expired. Please request a new location capture email.',
            'coords'   => 'Those coordinates look invalid — please try again.',
            'accuracy' => 'Your location reading was not precise enough. Please enable precise location (GPS) and try again, ideally near a window or outdoors.',
        ];

        return response()->json([
            'success' => false,
            'error'   => $result['error'],
            'message' => $messages[$result['error']] ?? 'Could not save your location.',
        ], $result['error'] === 'invalid' ? 404 : 422);
    }

    /** GET /location/open/{uuid}.gif — email open-tracking pixel. */
    public function openPixel(string $uuid)
    {
        LocationCaptureRequest::where('uuid', $uuid)
            ->whereNull('opened_at')
            ->update(['opened_at' => now()]);

        // 1×1 transparent GIF.
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return response($gif, 200)
            ->header('Content-Type', 'image/gif')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }
}
