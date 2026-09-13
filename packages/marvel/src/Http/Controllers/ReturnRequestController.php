<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderItem;
use Marvel\Services\Accounting\ReturnService;

/** Returns lifecycle (spec §27). Customers request against their own lines; admins decide. */
class ReturnRequestController extends CoreController
{
    private function actor(Request $r): string
    {
        return (string) ($r->user()?->id ?? 'system');
    }

    public function index(Request $request)
    {
        $q = DB::table('return_requests')->orderByDesc('id');
        if ($request->filled('order_id')) {
            $q->where('order_id', (int) $request->order_id);
        }
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }
        return $q->paginate((int) ($request->limit ?? 30));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['order_item_id' => ['required', 'integer'], 'quantity' => ['required', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:500']]);
        $item = OrderItem::findOrFail((int) $data['order_item_id']);
        $order = Order::findOrFail($item->order_id);
        $user = $request->user();
        if (!$user || !($user->id === $order->customer_id || $user->hasPermissionTo(\Marvel\Enums\Permission::SUPER_ADMIN))) {
            abort(403, 'Not your order.');
        }
        try {
            return response()->json(['data' => (new ReturnService())->request($item->id, (int) $data['quantity'], $data['reason'] ?? null, $this->actor($request))]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function transition(Request $request, $id, string $action)
    {
        try {
            if ($action === 'refund') {
                $request->validate(['method' => ['nullable', 'in:wallet,gateway,manual']]);
                return response()->json(['data' => (new ReturnService())->refund((int) $id, $request->input('method'), $this->actor($request))]);
            }
            return response()->json(['data' => (new ReturnService())->transition((int) $id, $action, $this->actor($request), $request->input('note'))]);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
