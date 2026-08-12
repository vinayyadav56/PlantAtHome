# WhatsApp OTP login (Meta Cloud API)

"Continue with WhatsApp" is **not a separate auth system** — it is a *channel*
on the existing phone-OTP login. Every path (email, phone/SMS, WhatsApp,
Google, LinkedIn) resolves to the same `users` row and issues the same Sanctum
token, so nothing downstream (checkout, orders, addresses, cart) can tell them
apart.

```
Client ──► POST /api/send-otp-code   {phone_number, channel:'whatsapp'}
             └─ UserController::resolveOtpGatewayName('whatsapp') → WhatsappGateway
                  └─ Meta Graph API  /{version}/{phone_number_id}/messages
                       └─ AUTHENTICATION template delivers the code
Client ──► POST /api/otp-login       {phone_number, code, otp_id, channel, …}
             └─ verify → find-or-create customer → Sanctum token
```

The **channel must be echoed on verify/login**: `verifyOtp()` re-resolves the
gateway from `channel`, and only the WhatsApp gateway can verify a WhatsApp
code (it is stored locally; Twilio/MSG91 verify server-side).

## Endpoints

| Method | Path | Auth | Notes |
|---|---|---|---|
| POST | `/api/send-otp-code` | public, `throttle:otp` | `{phone_number, channel?}` → `{success, id, provider, channel, expires_in, resend_after}` |
| POST | `/api/verify-otp-code` | public, `throttle:otp` | `{phone_number, code, otp_id, channel?}` — verification only (checkout contact) |
| POST | `/api/otp-login` | public, `throttle:otp` | `{phone_number, code, otp_id, channel?, first_name?, last_name?, email?}` → `{token, permissions, role}` |
| GET | `/api/webhooks/whatsapp` | public | Meta subscription handshake (echoes `hub_challenge`) |
| POST | `/api/webhooks/whatsapp` | public | Delivery/status events, `X-Hub-Signature-256` verified |

`send-otp-code` never reveals whether a number is registered. `otp-login`
returns `is_contact_exist` only via the send step's response so the client
knows whether to collect registration fields.

### Error codes

`INVALID_PHONE` (422) · `WHATSAPP_SEND_FAILED` / `OTP_SEND_FAILED` (502) ·
`OTP_VERIFICATION_FAILED` (400) · 429 with `Retry-After` for cooldown /
daily-cap / lockout / route limits.

## Security properties (each pinned by a test)

- Code is CSPRNG (`random_int`), **stored only as a bcrypt hash** in the cache —
  never in the DB, a log, a response, a URL or an analytics event.
- **Single-use**: the entry is dropped on the first correct verification.
- **Attempt-capped**: 5 wrong guesses burn the code (`WHATSAPP_OTP_MAX_ATTEMPTS`);
  a wrong guess never extends the expiry window.
- **Resend invalidates** the previous code (same cache key).
- **TTL** 5 min (`WHATSAPP_OTP_TTL_MINUTES`).
- `OtpAbuseGuard`: 45 s cooldown, 8 sends/number/24 h, 5 failed verifies →
  15 min lockout. Counters key on the **last 10 digits**, identical to the
  `throttle:otp` route limiter (5 req/min/phone + 20 req/min/IP), so
  reformatting a number cannot reset them.
- Access token lives only in server config; it is never returned by any
  endpoint and never logged (only the WA message id + last 4 digits of the
  recipient are logged).

## Identity & duplicate prevention

`otpLogin` resolves the customer by `user_profiles.contact_clean` (last-10
normalized) with a raw-string fallback for pre-backfill rows, so `9876543210`,
`+91 98765 43210` and `919876543210` are one account. A successful login
records a row in `providers` (`provider = whatsapp|phone`, `provider_user_id =
normalized number`) — the same table social login uses.

**Account conflict:** if the phone is new but the supplied email already
belongs to an account, registration is refused with a 422 telling the customer
to sign in with email and add the phone from their profile. Two existing
accounts are never merged automatically.

## Meta configuration (human steps — not code)

1. WhatsApp Business Account + a phone number in **Meta Business Manager**.
2. Create and get **approved**: an **AUTHENTICATION** template (the login code)
   and a **UTILITY** template with one body variable (order notifications).
   Set `WHATSAPP_OTP_HAS_BUTTON=true` only if the approved template carries a
   copy-code button.
3. System-user **permanent access token** with `whatsapp_business_messaging`
   → `WHATSAPP_ACCESS_TOKEN` (rotatable from admin → Integrations).
4. Webhook: callback `https://<host>/api/webhooks/whatsapp`, verify token =
   `WHATSAPP_WEBHOOK_VERIFY_TOKEN`, subscribe to the **messages** field. Set
   `WHATSAPP_APP_SECRET` (App Dashboard → Settings → Basic) to enforce
   signature verification.
5. Until a template is approved and the token is live, WhatsApp sends fail with
   `WHATSAPP_SEND_FAILED` and clients should fall back to the SMS channel.

## Cart & redirect

No cart work is needed: both the storefront (`cart.context.tsx`) and the app
(`cart.store.ts`) merge the guest cart into the account cart when
`isAuthorized` flips — WhatsApp login flips the same flag as every other login.
`/signin?redirect=` is honoured because the redirect effect watches
`authorizationAtom`.
