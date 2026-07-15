<?php

namespace App\Modules\Nursery\Http\Controllers;

use App\Modules\Nursery\Application\WithdrawalService;
use App\Modules\Nursery\Domain\WithdrawalStatus;
use App\Modules\Nursery\Http\Concerns\ResolvesNursery;
use App\Modules\Nursery\Http\Requests\DecideWithdrawalRequest;
use App\Modules\Nursery\Http\Requests\RequestWithdrawalRequest;
use App\Modules\Nursery\Http\Resources\WithdrawalResource;
use App\Modules\Nursery\Infrastructure\Models\Nursery;
use App\Modules\Nursery\Infrastructure\Models\NurseryWithdrawal;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WithdrawalController extends ApiController
{
    use ResolvesNursery;

    public function __construct(private readonly WithdrawalService $withdrawals)
    {
    }

    /** GET /api/v1/withdrawals?status=&nursery= (admin) */
    public function index(Request $request): JsonResponse
    {
        $query = NurseryWithdrawal::query()->with('nursery')->latest('id');

        $status = $request->query('status');
        if ($status && WithdrawalStatus::isValid($status)) {
            $query->where('status', $status);
        }

        if ($nurseryUuid = $request->query('nursery')) {
            $query->whereHas('nursery', fn ($inner) => $inner->where('uuid', $nurseryUuid));
        }

        $perPage = min((int) $request->query('per_page', 20), 100);

        return $this->paginated($query->paginate($perPage), fn (NurseryWithdrawal $w) => WithdrawalResource::make($w));
    }

    /** GET /api/v1/withdrawals/{withdrawal} (admin) */
    public function show(NurseryWithdrawal $withdrawal): JsonResponse
    {
        return $this->ok(WithdrawalResource::make($withdrawal->load('nursery')));
    }

    /** POST /api/v1/withdrawals/{withdrawal}/decide (admin) */
    public function decide(DecideWithdrawalRequest $request, NurseryWithdrawal $withdrawal): JsonResponse
    {
        $data = $request->validated();

        $withdrawal = $this->withdrawals->decide($withdrawal, $data['action'], $data['note'] ?? null, $request->user()->uuid);

        return $this->ok(WithdrawalResource::make($withdrawal));
    }

    /** POST /api/v1/nurseries/{nursery}/withdrawals — owner (own nursery) or admin */
    public function request(RequestWithdrawalRequest $request, string $nursery): JsonResponse
    {
        $nursery = $this->resolveNursery($nursery);

        if (! $this->canActFor($request, $nursery)) {
            return $this->fail('FORBIDDEN', 'You may only request withdrawals for your own nursery.', 403);
        }

        $data = $request->validated();

        $withdrawal = $this->withdrawals->request(
            $nursery,
            (float) $data['amount'],
            $data['payment_method'] ?? null,
            $data['details'] ?? null,
            $request->header('Idempotency-Key'),
            $request->user()->uuid,
        );

        return $this->created(WithdrawalResource::make($withdrawal));
    }

    /** GET /api/v1/nurseries/{nursery}/balance — owner (own nursery) or admin */
    public function balance(Request $request, string $nursery): JsonResponse
    {
        $nursery = $this->resolveNursery($nursery);

        if (! $this->canActFor($request, $nursery)) {
            return $this->fail('FORBIDDEN', 'You may only view your own nursery balance.', 403);
        }

        $balance = $nursery->balance;

        return $this->ok([
            'total_earnings'   => (float) ($balance?->total_earnings ?? 0),
            'withdrawn_amount' => (float) ($balance?->withdrawn_amount ?? 0),
            'current_balance'  => (float) ($balance?->current_balance ?? 0),
            'payment_info'     => $balance?->payment_info,
        ]);
    }

    /** Admins act for any nursery; an owner only for their scoped one. */
    private function canActFor(Request $request, Nursery $nursery): bool
    {
        $user = $request->user();

        return $user->isPlatformAdmin() || $user->nursery_id === $nursery->uuid;
    }
}
