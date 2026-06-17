<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\DeliveryPartner;
use Marvel\Database\Models\DeliveryPartnerBalance;
use Marvel\Database\Models\DeliveryPartnerWithdraw;
use Marvel\Enums\Permission;

/**
 * Delivery-partner withdrawals. A DP requests a payout from their dashboard
 * (deducts from current_balance, status=pending); the admin approves/updates
 * status. Mirrors marvel's shop WithdrawController on the DP balance ledger.
 */
class DeliveryPartnerWithdrawController extends CoreController
{
    /** List withdrawals — admin sees all (filter by partner); a DP sees their own. */
    public function index(Request $request)
    {
        $limit = (int) ($request->limit ?? 15);
        $query = DeliveryPartnerWithdraw::with('deliveryPartner:id,full_name,mobile')->orderByDesc('id');

        $user = $request->user();
        if ($user && $user->hasPermissionTo(Permission::SUPER_ADMIN)) {
            if ($request->filled('delivery_partner_id')) {
                $query->where('delivery_partner_id', $request->delivery_partner_id);
            }
        } else {
            $dp = DeliveryPartner::where('user_id', optional($user)->id)->first();
            $query->where('delivery_partner_id', $dp->id ?? 0);
        }
        return $query->paginate($limit);
    }

    /** Request a withdrawal. DP self (from dashboard) or admin (delivery_partner_id in body). */
    public function store(Request $request)
    {
        $request->validate([
            'amount'         => 'required|numeric|min:1',
            'payment_method' => 'nullable|string',
            'details'        => 'nullable|string',
            'note'           => 'nullable|string',
        ]);

        $user = $request->user();
        $isAdmin = $user && $user->hasPermissionTo(Permission::SUPER_ADMIN);

        $dpId = $isAdmin && $request->filled('delivery_partner_id')
            ? (int) $request->input('delivery_partner_id')
            : optional(DeliveryPartner::where('user_id', optional($user)->id)->first())->id;

        if (!$dpId) {
            return response()->json(['message' => 'No delivery-partner profile for this account.'], 422);
        }

        $amount = (float) $request->input('amount');

        // Lock the balance row and re-check inside the transaction so two concurrent
        // requests can't both pass the balance check and overdraw the DP balance.
        return DB::transaction(function () use ($dpId, $amount, $request) {
            $balance = DeliveryPartnerBalance::where('delivery_partner_id', $dpId)->lockForUpdate()->first()
                ?? DeliveryPartnerBalance::create(['delivery_partner_id' => $dpId]);
            if ((float) $balance->current_balance < $amount) {
                abort(422, 'Insufficient balance.');
            }

            $withdraw = DeliveryPartnerWithdraw::create([
                'delivery_partner_id' => $dpId,
                'amount'              => $amount,
                'payment_method'      => $request->input('payment_method'),
                'details'             => $request->input('details'),
                'note'                => $request->input('note'),
                'status'              => 'pending',
            ]);

            $balance->withdrawn_amount = (float) $balance->withdrawn_amount + $amount;
            $balance->current_balance  = (float) $balance->current_balance - $amount;
            $balance->save();

            return $withdraw;
        });
    }

    /** Admin: update a withdrawal status (approved | processing | on_hold | rejected). */
    public function approve(Request $request)
    {
        // Lock the withdraw row and re-read its status inside the transaction so a
        // double-submitted "reject" can only refund the DP balance once (compare-and-swap).
        return DB::transaction(function () use ($request) {
            $withdraw = DeliveryPartnerWithdraw::whereKey($request->input('id'))->lockForUpdate()->firstOrFail();
            $status = $request->input('status', 'approved');

            // Refund the balance if a pending/approved request is rejected.
            if ($status === 'rejected' && $withdraw->status !== 'rejected') {
                $balance = DeliveryPartnerBalance::where('delivery_partner_id', $withdraw->delivery_partner_id)->lockForUpdate()->first();
                if ($balance) {
                    $balance->withdrawn_amount = max(0, (float) $balance->withdrawn_amount - (float) $withdraw->amount);
                    $balance->current_balance  = (float) $balance->current_balance + (float) $withdraw->amount;
                    $balance->save();
                }
            }

            $withdraw->status = $status;
            $withdraw->save();
            return $withdraw;
        });
    }
}
