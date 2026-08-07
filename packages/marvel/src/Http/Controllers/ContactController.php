<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Marvel\Database\Models\EmailOtp;
use Marvel\Database\Models\Profile;
use Marvel\Database\Models\User;
use Marvel\Mail\EmailOtp as EmailOtpMail;

/**
 * Profile-based contact management: up to 2 phone numbers + 2 email addresses per
 * user. Phones are saved directly (verification skipped for now). Emails are
 * IMMUTABLE once added and verified via an emailed one-time code. The primary
 * email is users.email (verified via email_verified_at); the secondary email +
 * its verified-at live on user_profiles.
 */
class ContactController extends CoreController
{
    /** PUT me/contacts — set the caller's phone number(s). No verification. */
    public function updateContacts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contact'   => 'nullable|string|max:20',
            'contact_2' => 'nullable|string|max:20',
        ]);
        $user = $request->user();
        Profile::updateOrCreate(
            ['customer_id' => $user->id],
            [
                'contact'   => $this->normalizePhone($data['contact'] ?? null),
                'contact_2' => $this->normalizePhone($data['contact_2'] ?? null),
            ]
        );
        return response()->json(['data' => $this->contactState($user->fresh())]);
    }

    /** GET me/contacts — the caller's contact state (phones + emails + verified flags). */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->contactState($request->user())]);
    }

    /** POST me/email-otp/send { email } — email a one-time code to verify an address. */
    public function sendEmailOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email']);
        $email = strtolower(trim($data['email']));
        $user = $request->user();

        $slot = $this->resolveEmailSlot($user, $email); // throws-as-422 on invalid
        if ($slot instanceof JsonResponse) {
            return $slot;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        EmailOtp::where('email', $email)->delete();
        EmailOtp::create([
            'email'      => $email,
            'code_hash'  => Hash::make($code),
            'expires_at' => Carbon::now()->addMinutes(10),
        ]);

        // Queued through the engine (never blocks or 500s the request — the OTP
        // row is already stored, a transient mail hiccup retries in the queue);
        // legacy Mailable stays as the no-template fallback.
        app(\Marvel\Services\EmailService::class)->send('auth.otp', $email, ['otp' => $code], [
            'fallback' => fn () => Mail::to($email)->queue(new EmailOtpMail($code)),
        ]);

        return response()->json(['data' => ['sent' => true, 'slot' => $slot]]);
    }

    /** POST me/email-otp/verify { email, code } — verify + attach the email. */
    public function verifyEmailOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'code'  => 'required|string',
        ]);
        $email = strtolower(trim($data['email']));
        $user = $request->user();

        $otp = EmailOtp::where('email', $email)->where('expires_at', '>', Carbon::now())->latest('id')->first();
        if (!$otp || !Hash::check($data['code'], $otp->code_hash)) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }
        EmailOtp::where('email', $email)->delete();

        $slot = $this->resolveEmailSlot($user, $email);
        if ($slot instanceof JsonResponse) {
            return $slot;
        }

        if ($slot === 'primary') {
            if (!$user->email_verified_at) {
                $user->email_verified_at = Carbon::now();
                $user->save();
            }
        } else { // secondary
            Profile::updateOrCreate(
                ['customer_id' => $user->id],
                ['email_2' => $email, 'email_2_verified_at' => Carbon::now()]
            );
        }

        return response()->json(['data' => $this->contactState($user->fresh())]);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /**
     * Which email slot this address maps to, or a 422 response when it's not allowed.
     * Enforces immutability (a set+verified secondary can't be swapped) and that an
     * email isn't already owned by another account.
     */
    private function resolveEmailSlot(User $user, string $email)
    {
        if ($email === strtolower((string) $user->email)) {
            return 'primary';
        }
        $profile = $user->profile;
        $existingSecondary = $profile ? strtolower((string) $profile->email_2) : '';
        // Secondary email is immutable once verified.
        if ($existingSecondary && $existingSecondary !== $email && $profile?->email_2_verified_at) {
            return response()->json(['message' => 'Your second email is already set and cannot be changed.'], 422);
        }
        // Must not collide with another user's primary email or another profile's secondary.
        $takenByUser = User::whereRaw('LOWER(email) = ?', [$email])->where('id', '!=', $user->id)->exists();
        $takenByProfile = Profile::whereRaw('LOWER(email_2) = ?', [$email])->where('customer_id', '!=', $user->id)->exists();
        if ($takenByUser || $takenByProfile) {
            return response()->json(['message' => 'That email is already in use.'], 422);
        }
        return 'secondary';
    }

    private function normalizePhone($v): ?string
    {
        $v = trim((string) ($v ?? ''));
        return $v === '' ? null : $v;
    }

    private function contactState(User $user): array
    {
        $p = $user->profile;
        return [
            'phones' => array_values(array_filter([
                $p?->contact ? ['number' => $p->contact, 'primary' => true] : null,
                $p?->contact_2 ? ['number' => $p->contact_2, 'primary' => false] : null,
            ])),
            'emails' => array_values(array_filter([
                $user->email ? ['email' => $user->email, 'primary' => true, 'verified' => (bool) $user->email_verified_at] : null,
                $p?->email_2 ? ['email' => $p->email_2, 'primary' => false, 'verified' => (bool) $p?->email_2_verified_at] : null,
            ])),
        ];
    }
}
