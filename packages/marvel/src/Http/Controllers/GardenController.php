<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Marvel\Database\Models\GardenLead;
use Marvel\Database\Models\GardenPackage;
use Marvel\Database\Models\GardenPackageTemplate;
use Marvel\Database\Models\GardenPackageVisit;

class GardenController extends CoreController
{
    // ---------------------------------------------------------------- LEADS
    /** Public — landing-page lead capture. */
    public function storeLead(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'phone' => 'required|string|max:32',
            'email' => 'nullable|email|max:191',
            'city' => 'nullable|string|max:191',
            'state' => 'nullable|string|max:191',
            'garden_type' => 'nullable|string|max:64',
            'space_size' => 'nullable|string|max:64',
            'budget_range' => 'nullable|string|max:64',
            'message' => 'nullable|string|max:2000',
            'source' => 'nullable|string|max:32',
            'company' => 'nullable|string|max:191',
            'occasion' => 'nullable|string|max:64',
            'quantity' => 'nullable|string|max:64',
        ]);
        // The /corporate-leads route is authoritative for the source.
        $source = $request->is('*corporate-leads') ? 'corporate' : ($data['source'] ?? 'garden');
        $lead = GardenLead::create($data + ['status' => 'new', 'source' => $source]);
        $msg = $source === 'corporate'
            ? 'Thanks! Our gifting team will reach out with a tailored proposal.'
            : 'Thanks! Our garden team will reach out shortly.';
        return response()->json(['data' => ['id' => $lead->id], 'message' => $msg], 201);
    }

    /** Admin — list/manage leads (filter by status and/or source). */
    public function leads(Request $request): JsonResponse
    {
        $q = GardenLead::query()->latest();
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($source = $request->query('source')) {
            $q->where('source', $source);
        }
        return response()->json($q->paginate(min((int) $request->query('limit', 30), 200)));
    }

    public function updateLead(Request $request, $id): JsonResponse
    {
        $lead = GardenLead::findOrFail($id);
        $lead->fill($request->only(['name', 'phone', 'email', 'city', 'state', 'garden_type', 'space_size', 'budget_range', 'message', 'status']))->save();
        return response()->json(['data' => $lead]);
    }

    // ------------------------------------------------------------ TEMPLATES
    /** Public — active templates for a service (garden|gifting) on the landing page. */
    public function templates(Request $request): JsonResponse
    {
        $service = $request->query('service', 'garden');
        $rows = GardenPackageTemplate::where('is_active', true)->where('service', $service)
            ->orderBy('sort')->orderBy('suggested_price')->get();
        return response()->json(['data' => $rows]);
    }

    /** Admin — full template list, optionally scoped to one service (garden|gifting). */
    public function allTemplates(Request $request): JsonResponse
    {
        $q = GardenPackageTemplate::orderBy('sort')->orderBy('suggested_price');
        if ($service = $request->query('service')) {
            $q->where('service', $service);
        }
        return response()->json(['data' => $q->get()]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $this->templateData($request);
        $tpl = GardenPackageTemplate::create($data);
        return response()->json(['data' => $tpl], 201);
    }

    public function updateTemplate(Request $request, $id): JsonResponse
    {
        $tpl = GardenPackageTemplate::findOrFail($id);
        $tpl->fill($this->templateData($request))->save();
        return response()->json(['data' => $tpl]);
    }

    public function destroyTemplate($id): JsonResponse
    {
        GardenPackageTemplate::where('id', $id)->delete();
        return response()->json(['message' => 'Deleted']);
    }

    private function templateData(Request $request): array
    {
        $v = $request->validate([
            'name' => 'required|string|max:191',
            'tagline' => 'nullable|string|max:191',
            'badge' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'items' => 'nullable|array',
            'suggested_visits' => 'nullable|integer',
            'suggested_price' => 'nullable|numeric',
            'duration_days' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'sort' => 'nullable|integer',
            'service' => 'nullable|string|in:garden,gifting',
        ]);
        $v['items'] = $request->input('items', []);
        // Default to gifting when the admin B2B section creates a tier; the
        // garden CRUD passes service explicitly.
        $v['service'] = $v['service'] ?? $request->input('service', 'gifting');
        return $v;
    }

    // ------------------------------------------------------------- PACKAGES
    /** Admin — list bespoke packages. */
    public function packages(Request $request): JsonResponse
    {
        $q = GardenPackage::with(['user:id,name,email'])->withCount(['visits as visits_used' => fn ($x) => $x->where('status', 'completed')])->latest();
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        return response()->json($q->paginate(min((int) $request->query('limit', 30), 200)));
    }

    public function storePackage(Request $request): JsonResponse
    {
        $pkg = GardenPackage::create($this->packageData($request));
        return response()->json(['data' => $pkg->load('visits')], 201);
    }

    public function updatePackage(Request $request, $id): JsonResponse
    {
        $pkg = GardenPackage::findOrFail($id);
        $pkg->fill($this->packageData($request))->save();
        return response()->json(['data' => $pkg->load('visits')]);
    }

    public function showPackage($id): JsonResponse
    {
        return response()->json(['data' => GardenPackage::with(['visits', 'user:id,name,email'])->findOrFail($id)]);
    }

    private function packageData(Request $request): array
    {
        $v = $request->validate([
            'user_id' => 'nullable|integer',
            'user_email' => 'nullable|email',
            'lead_id' => 'nullable|integer',
            'name' => 'required|string|max:191',
            'description' => 'nullable|string',
            'items' => 'nullable|array',
            'total_visits' => 'nullable|integer',
            'price' => 'nullable|numeric',
            'duration_days' => 'nullable|integer',
            'status' => 'nullable|string',
            'service' => 'nullable|string|max:32',
        ]);
        // Admin can assign by the customer's email instead of a raw id.
        if (!empty($v['user_email'])) {
            $user = \Marvel\Database\Models\User::where('email', $v['user_email'])->first();
            if ($user) {
                $v['user_id'] = $user->id;
            }
        }
        unset($v['user_email']);
        $v['items'] = $request->input('items', []);
        return $v;
    }

    // --------------------------------------------------------------- VISITS
    public function addVisit(Request $request, $id): JsonResponse
    {
        $pkg = GardenPackage::findOrFail($id);
        $data = $request->validate([
            'scheduled_date' => 'nullable|date',
            'gardener_name' => 'nullable|string|max:191',
            'notes' => 'nullable|string',
            'status' => 'nullable|string',
        ]);
        $visit = $pkg->visits()->create($data + ['status' => $data['status'] ?? 'scheduled']);
        if (($data['status'] ?? null) === 'completed' && !$visit->completed_at) {
            $visit->update(['completed_at' => now()]);
        }
        return response()->json(['data' => $pkg->fresh()->load('visits')], 201);
    }

    public function updateVisit(Request $request, $visitId): JsonResponse
    {
        $visit = GardenPackageVisit::findOrFail($visitId);
        $data = $request->validate([
            'scheduled_date' => 'nullable|date',
            'gardener_name' => 'nullable|string|max:191',
            'notes' => 'nullable|string',
            'status' => 'nullable|string',
        ]);
        $visit->fill($data);
        if (($data['status'] ?? null) === 'completed' && !$visit->completed_at) {
            $visit->completed_at = now();
        }
        $visit->save();
        return response()->json(['data' => GardenPackage::with('visits')->find($visit->garden_package_id)]);
    }

    // ----------------------------------------------------- CUSTOMER (authed)
    public function myPackages(Request $request): JsonResponse
    {
        $rows = GardenPackage::with('visits')->where('user_id', $request->user()->id)
            ->whereIn('status', ['awaiting_payment', 'active', 'completed'])->latest()->get();
        return response()->json(['data' => $rows]);
    }

    public function showMyPackage(Request $request, $id): JsonResponse
    {
        $pkg = GardenPackage::with('visits')->where('user_id', $request->user()->id)->findOrFail($id);
        return response()->json(['data' => $pkg]);
    }

    /**
     * Customer buys a gifting package directly from a template: create a package
     * for them from the template, generate a Razorpay link, return the pay URL.
     */
    public function giftingCheckout(Request $request): JsonResponse
    {
        $data = $request->validate(['template_id' => 'required|integer']);
        // Only active tiers are purchasable — a hidden/retired tier must not be
        // buyable by id enumeration (mirrors the public templates() invariant).
        $tpl = GardenPackageTemplate::where('service', 'gifting')
            ->where('is_active', true)
            ->findOrFail($data['template_id']);
        $user = $request->user();

        $pkg = GardenPackage::create([
            'user_id' => $user->id,
            'service' => 'gifting',
            'name' => $tpl->name,
            'description' => $tpl->tagline,
            'items' => $tpl->items ?? [],
            'total_visits' => 0,
            'price' => $tpl->suggested_price,
            'duration_days' => 0,
            'status' => 'draft',
            'payment_status' => 'unpaid',
        ]);
        $this->createLinkFor($pkg, $user);
        $pkg->refresh();

        return response()->json(['data' => ['url' => $pkg->razorpay_link_url, 'package_id' => $pkg->id]]);
    }

    /** Customer taps "Pay now" — returns the Razorpay hosted link. */
    public function pay(Request $request, $id): JsonResponse
    {
        $pkg = GardenPackage::where('user_id', $request->user()->id)->findOrFail($id);
        if ($pkg->payment_status === 'paid') {
            return response()->json(['message' => 'Already paid', 'data' => ['url' => null]]);
        }
        if (!$pkg->razorpay_link_url) {
            $this->createLinkFor($pkg, $request->user());
        }
        return response()->json(['data' => ['url' => $pkg->razorpay_link_url]]);
    }

    // ------------------------------------------------- PAYMENT (Razorpay)
    /** Admin — generate a Razorpay payment link for a bespoke package. */
    public function createPaymentLink(Request $request, $id): JsonResponse
    {
        $pkg = GardenPackage::with('user:id,name,email')->findOrFail($id);
        $this->createLinkFor($pkg, $pkg->user);
        return response()->json(['data' => $pkg->fresh()]);
    }

    private function createLinkFor(GardenPackage $pkg, $user = null): void
    {
        $keyId = config('shop.razorpay.key_id');
        $keySecret = config('shop.razorpay.key_secret');
        if (!$keyId || !$keySecret) {
            return; // not configured — admin can mark paid manually
        }
        $resp = Http::withBasicAuth($keyId, $keySecret)->acceptJson()->post('https://api.razorpay.com/v1/payment_links', [
            'amount' => (int) round(((float) $pkg->price) * 100),
            'currency' => 'INR',
            'accept_partial' => false,
            'description' => mb_substr($pkg->name, 0, 200),
            'customer' => array_filter([
                'name' => $user->name ?? null,
                'email' => $user->email ?? null,
            ]),
            'notify' => ['sms' => false, 'email' => (bool) ($user->email ?? false)],
            'reminder_enable' => true,
            'notes' => ['garden_package_id' => (string) $pkg->id],
        ]);
        if ($resp->successful()) {
            $pkg->update([
                'razorpay_link_id' => $resp->json('id'),
                'razorpay_link_url' => $resp->json('short_url'),
                'status' => 'awaiting_payment',
            ]);
        } else {
            \Log::warning('Razorpay payment link failed: ' . $resp->body());
        }
    }

    /** Razorpay webhook — activate the package on payment_link.paid. */
    public function razorpayWebhook(Request $request): JsonResponse
    {
        $secret = config('shop.razorpay.webhook_secret');
        if ($secret) {
            $expected = hash_hmac('sha256', $request->getContent(), $secret);
            if (!hash_equals($expected, (string) $request->header('X-Razorpay-Signature'))) {
                return response()->json(['message' => 'invalid signature'], 400);
            }
        }
        $event = $request->input('event');
        $entity = $request->input('payload.payment_link.entity', []);
        $linkId = $entity['id'] ?? null;
        if (in_array($event, ['payment_link.paid', 'payment_link.partially_paid']) && $linkId) {
            $pkg = GardenPackage::where('razorpay_link_id', $linkId)->first();
            if ($pkg && $pkg->payment_status !== 'paid') {
                $pkg->update([
                    'payment_status' => 'paid',
                    'status' => 'active',
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addDays((int) $pkg->duration_days)->toDateString(),
                    'razorpay_payment_id' => $request->input('payload.payment.entity.id'),
                ]);
            }
        }
        return response()->json(['status' => 'ok']);
    }
}
