<?php

namespace App\Modules\Nursery\Http\Controllers;

use App\Modules\Nursery\Application\NurseryService;
use App\Modules\Nursery\Domain\NurseryStatus;
use App\Modules\Nursery\Http\Concerns\ResolvesNursery;
use App\Modules\Nursery\Http\Requests\ApproveNurseryRequest;
use App\Modules\Nursery\Http\Requests\DocumentStatusRequest;
use App\Modules\Nursery\Http\Requests\UpdateCommissionRequest;
use App\Modules\Nursery\Http\Requests\UpsertNurseryRequest;
use App\Modules\Nursery\Http\Resources\NurseryResource;
use App\Modules\Nursery\Infrastructure\Models\Nursery;
use App\Modules\Nursery\Infrastructure\Models\NurseryDocument;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NurseryAdminController extends ApiController
{
    use ResolvesNursery;

    public function __construct(private readonly NurseryService $nurseries)
    {
    }

    /** GET /api/v1/nurseries?status=&q=&sort=&dir=&per_page= (admin) */
    public function index(Request $request): JsonResponse
    {
        $query = Nursery::query()->with('balance');

        $status = $request->query('status');
        if ($status && NurseryStatus::isValid($status)) {
            $query->where('status', $status);
        }

        if ($q = $request->query('q')) {
            $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', '%'.$q.'%')
                    ->orWhere('slug', 'like', '%'.$q.'%');
            });
        }

        $sort = in_array($request->query('sort'), ['created_at', 'name'], true) ? $request->query('sort') : 'created_at';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $dir);

        $perPage = min((int) $request->query('per_page', 20), 100);

        return $this->paginated($query->paginate($perPage), fn (Nursery $n) => NurseryResource::make($n));
    }

    /** POST /api/v1/nurseries (admin) */
    public function store(UpsertNurseryRequest $request): JsonResponse
    {
        $nursery = $this->nurseries->create($request->validated(), $request->user()?->uuid);

        $data = NurseryResource::make($nursery->load(['balance', 'documents']), withDetails: true);
        $data['credentials_email_sent'] = (bool) $nursery->getAttribute('credentials_email_sent');

        return $this->created($data);
    }

    /** GET /api/v1/nurseries/{nursery} (admin) — accepts uuid or slug */
    public function show(string $nursery): JsonResponse
    {
        $nursery = $this->resolveNursery($nursery)->load(['balance', 'documents']);

        return $this->ok(NurseryResource::make($nursery, withDetails: true));
    }

    /** PATCH /api/v1/nurseries/{nursery} (admin) */
    public function update(UpsertNurseryRequest $request, string $nursery): JsonResponse
    {
        $nursery = $this->nurseries->update($this->resolveNursery($nursery), $request->validated(), $request->user()?->uuid);

        return $this->ok(NurseryResource::make($nursery, withDetails: true));
    }

    /** POST /api/v1/nurseries/{nursery}/approve (admin) — idempotent */
    public function approve(ApproveNurseryRequest $request, string $nursery): JsonResponse
    {
        $rate = $request->validated()['commission_rate'] ?? null;
        $nursery = $this->nurseries->approve(
            $this->resolveNursery($nursery),
            $rate !== null ? (float) $rate : null,
            $request->user()->uuid,
        );

        return $this->ok(NurseryResource::make($nursery));
    }

    /** POST /api/v1/nurseries/{nursery}/suspend (admin) */
    public function suspend(Request $request, string $nursery): JsonResponse
    {
        $nursery = $this->nurseries->suspend($this->resolveNursery($nursery), $request->user()->uuid);

        return $this->ok(NurseryResource::make($nursery));
    }

    /** PATCH /api/v1/nurseries/{nursery}/commission (admin) */
    public function commission(UpdateCommissionRequest $request, string $nursery): JsonResponse
    {
        $nursery = $this->nurseries->changeCommission(
            $this->resolveNursery($nursery),
            (float) $request->validated()['commission_rate'],
            $request->user()->uuid,
        );

        return $this->ok(NurseryResource::make($nursery));
    }

    /** POST /api/v1/nurseries/{nursery}/documents/{document}/status (admin) */
    public function documentStatus(DocumentStatusRequest $request, string $nursery, NurseryDocument $document): JsonResponse
    {
        $nursery = $this->resolveNursery($nursery);

        if ($document->nursery_id !== $nursery->id) {
            return $this->fail('NOT_FOUND', 'Document does not belong to this nursery.', 404);
        }

        $data = $request->validated();
        $this->nurseries->setDocumentStatus($document, $data['status'], $data['note'] ?? null, $request->user()->uuid);

        return $this->ok(NurseryResource::make($nursery->fresh(['balance', 'documents']), withDetails: true));
    }

    /** POST /api/v1/nurseries/{nursery}/transfer-ownership (admin) */
    public function transferOwnership(Request $request, string $nursery): JsonResponse
    {
        $data = $request->validate(['new_owner_email' => ['required', 'email']]);

        $nursery = $this->nurseries->transferOwnership($this->resolveNursery($nursery), $data['new_owner_email'], $request->user()->uuid);

        return $this->ok(NurseryResource::make($nursery));
    }
}
