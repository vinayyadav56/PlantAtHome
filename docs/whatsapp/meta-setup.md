# WhatsApp Business (Meta Cloud API) — setup guide

Your WhatsApp Business Account is approved. This is what has to happen in Meta's consoles,
what values to collect, and where each one goes. **No credential belongs in git or in a .env
committed to the repo** — they are pasted into Admin → Integrations, which stores them
encrypted.

---

## 1. What you need from Meta (collect these five values)

| Value | Where to get it |
|---|---|
| **Phone Number ID** | WhatsApp Manager → your number → **API Setup**. A numeric id (not the phone number itself). |
| **WhatsApp Business Account ID (WABA ID)** | Same API Setup screen, or Business Settings → Accounts → WhatsApp Accounts. |
| **Permanent access token** | Business Settings → **Users → System Users** → add a system user (role: Admin) → **Generate token** → select your app → tick `whatsapp_business_messaging` **and** `whatsapp_business_management` → set expiry **Never**. |
| **App ID** (optional, for webhooks) | Meta for Developers → your app → Settings → Basic. |
| **Webhook verify token** | You invent this string; you paste the same value into Meta and into our admin. |

> ⚠️ Do **not** use the temporary 24-hour token shown on the API Setup page for anything but a
> first smoke test — it expires and OTP login will die with it. Use the System User token.

### Register the sending number
WhatsApp Manager → API Setup → **register** the phone number and set its **two-step PIN**.
A number that is not registered returns `131009`/`133010` on every send.

---

## 2. Message templates you must create

Meta requires **pre-approved templates** for anything outside a 24-hour customer-service
window — which is every case we have. Create these in WhatsApp Manager → **Message Templates**.
The names below are exactly what the code sends; if you rename one in Meta, change it in
Admin → Integrations → WhatsApp too.

### 2.1 OTP (category: **Authentication**)

- **Name:** `plantathome_otp`
- **Language:** English (`en`) — add `en_US` too if Meta offers it for your account
- **Category:** Authentication
- Meta controls the wording of authentication templates. Choose:
  - Body: *"{{1}} is your verification code."*
  - Add the **"Copy code"** button (Meta requires a button type on authentication templates)
  - Tick **"Add security recommendation"** if offered
- Our send passes exactly one body variable: the OTP code.

### 2.2 Order updates (category: **Utility**)

One template covers every order status, because the code renders the sentence and passes it as
a single variable.

- **Name:** `plantathome_update`
- **Language:** English (`en`)
- **Category:** Utility
- **Body:** `PlantAtHome update: {{1}}`
- **Sample for review:** `PlantAtHome update: Your order #2026081193062890 is out for delivery today.`

> Keep the static prefix. Meta rejects templates whose body is *only* a variable, so a bare
> `{{1}}` will not be approved.

If you would rather have distinct wording per status (confirmed / shipped / out for delivery /
delivered), say so — that needs a small code change to select a template per event, and four
templates instead of one.

Approval is usually minutes, occasionally up to 24h. **Utility** templates cost less than
marketing ones and are the correct category for transactional order updates — do not file them
as Marketing or they may be rejected and will bill at a higher rate.

---

## 3. Where the values go

Admin → **Integrations** → WhatsApp:

- Phone Number ID, WABA ID, template names → *configuration* (visible)
- Access token, webhook verify token → *credentials* (encrypted at rest, masked in the UI)

Saving runs a health check against Meta and shows connected / failing with Meta's own error
text. Nothing goes into the repo.

---

## 4. Webhooks (optional but recommended)

Gives delivery/read receipts and lets customers reply.

- **Callback URL:** `https://api.plantathome.in/api/webhooks/whatsapp`
- **Verify token:** the string you invented above
- **Subscribe to:** `messages`

Meta calls the URL once with `hub.challenge` to verify; our endpoint echoes it back when the
token matches.

---

## 5. Order of operations

1. Register the number + set its PIN.
2. Create the system-user token (never expiring).
3. Submit the five templates; wait for **Approved**.
4. Paste credentials into Admin → Integrations → WhatsApp; save; confirm health = connected.
5. Use **Send test message** on that screen to your own number.
6. Flip OTP over: Admin → Integrations → WhatsApp → *Use for OTP*.

Until step 4 is done, OTP login stays disabled and order updates fall back to whatever other
channels are enabled — no customer-visible errors either way.
