<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\ProposalService;
use App\Modules\Catalog\Http\Requests\FileProposalRequest;
use App\Modules\Catalog\Http\Requests\ReviewProposalRequest;
use App\Modules\Catalog\Http\Resources\ProposalResource;
use App\Modules\Catalog\Infrastructure\Models\ProductProposal;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProposalController extends ApiController
{
    public function __construct(private readonly ProposalService $proposals)
    {
    }

    /**
     * GET /api/v1/catalog/proposals — admins see all; a nursery sees only its
     * own (enforced here, since a proposal list is inherently scoped).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = ProductProposal::query()->with('product')->latest('id');

        if (! $user->isPlatformAdmin()) {
            $query->where('nursery_id', (string) $user->nursery_id);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('limit', 20), 100)),
            fn (ProductProposal $p) => ProposalResource::make($p),
        );
    }

    /** POST /api/v1/catalog/proposals (nursery) — file a new-product proposal. */
    public function store(FileProposalRequest $request): JsonResponse
    {
        $user = $request->user();

        if (empty($user->nursery_id)) {
            return $this->fail('NO_NURSERY_SCOPE', 'Only a nursery account can file a product proposal.', 422);
        }

        $proposal = $this->proposals->file(
            nurseryId: (string) $user->nursery_id,
            proposedBy: $user->uuid,
            payload: $request->validated(),
        );

        return $this->created(ProposalResource::make($proposal));
    }

    /** GET /api/v1/catalog/proposals/{proposal} */
    public function show(Request $request, ProductProposal $proposal): JsonResponse
    {
        $user = $request->user();
        if (! $user->isPlatformAdmin() && (string) $proposal->nursery_id !== (string) $user->nursery_id) {
            return $this->fail('FORBIDDEN', 'You can only view your own nursery proposals.', 403);
        }

        return $this->ok(ProposalResource::make($proposal->load('product')));
    }

    /** POST /api/v1/catalog/proposals/{proposal}/approve (admin) */
    public function approve(ReviewProposalRequest $request, ProductProposal $proposal): JsonResponse
    {
        $reviewed = $this->proposals->approve($proposal, $request->user()?->uuid, $request->input('note'));

        return $this->ok(ProposalResource::make($reviewed));
    }

    /** POST /api/v1/catalog/proposals/{proposal}/reject (admin) */
    public function reject(ReviewProposalRequest $request, ProductProposal $proposal): JsonResponse
    {
        $reviewed = $this->proposals->reject($proposal, $request->user()?->uuid, $request->input('note'));

        return $this->ok(ProposalResource::make($reviewed));
    }
}
