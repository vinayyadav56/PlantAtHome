<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Notify me when you deliver to my pincode" — public lead capture shown by the
 * storefront when the pincode check comes back unserviceable. Deduped per
 * (pincode + email/phone) so repeat submissions never pile up rows; the demand
 * signal feeds coverage-expansion decisions. Writes the module-owned
 * delivery_notify_requests table directly (raw table — no V2 class named).
 */
class DeliveryNotifyController extends CoreController
{
    /** POST delivery-notify {pincode, email?|phone?, product_id?} → {success:true} */
    public function store(Request $request)
    {
        $pincode = preg_replace('/\D+/', '', (string) $request->input('pincode'));
        if (!preg_match('/^\d{6}$/', $pincode)) {
            return response()->json(['message' => 'A valid 6-digit pincode is required.'], 422);
        }

        $email = trim((string) $request->input('email', ''));
        $email = $email !== '' ? $email : null;
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => 'A valid email address is required.'], 422);
        }

        $phone = preg_replace('/[^\d+]/', '', (string) $request->input('phone', ''));
        $phone = strlen($phone) >= 10 ? $phone : null;

        if ($email === null && $phone === null) {
            return response()->json(['message' => 'An email address or phone number is required.'], 422);
        }

        $productId = $request->filled('product_id') ? (int) $request->input('product_id') : null;
        $userId = optional($request->user('sanctum'))->id;
        $now = now();

        // Dedupe: one pending row per (pincode + contact). A resubmission
        // refreshes the row (and fills in a newly-provided second contact).
        $existing = DB::table('delivery_notify_requests')
            ->where('pincode', $pincode)
            ->where('status', 'pending')
            ->where(function ($q) use ($email, $phone) {
                if ($email !== null) {
                    $q->orWhere('email', $email);
                }
                if ($phone !== null) {
                    $q->orWhere('phone', $phone);
                }
            })
            ->first();

        if ($existing) {
            DB::table('delivery_notify_requests')->where('id', $existing->id)->update([
                'email'      => $existing->email ?? $email,
                'phone'      => $existing->phone ?? $phone,
                'user_id'    => $existing->user_id ?? $userId,
                'product_id' => $existing->product_id ?? $productId,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('delivery_notify_requests')->insert([
                'pincode'    => $pincode,
                'email'      => $email,
                'phone'      => $phone,
                'user_id'    => $userId,
                'product_id' => $productId,
                'status'     => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return ['success' => true];
    }
}
