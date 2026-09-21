<?php

namespace Marvel\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Marvel\Http\Requests\CreateTaxRequest;
use Marvel\Http\Requests\UpdateTaxRequest;
use Marvel\Database\Models\Accounting\AccountingAuditLog;
use Marvel\Database\Models\Tax;
use Marvel\Database\Models\TaxRateVersion;
use Marvel\Database\Repositories\TaxRepository;
use Marvel\Exceptions\MarvelException;
use Prettus\Validator\Exceptions\ValidatorException;

class TaxController extends CoreController
{
    public $repository;

    public function __construct(TaxRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return \Illuminate\Database\Eloquent\Collection|Type[]
     */
    public function index(Request $request)
    {
        return $this->repository->all();
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param CreateTaxRequest $request
     * @return LengthAwarePaginator|Collection|mixed
     * @throws ValidatorException
     */
    public function store(CreateTaxRequest $request)
    {
        $validateData = $request->validated();
        $tax = $this->repository->create($validateData);
        AccountingAuditLog::record('tax_class', $tax->id ?? 0, 'created', null, $validateData);

        return $tax;
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show($id)
    {
        try {
            return $this->repository->findOrFail($id);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param CreateTaxRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdateTaxRequest $request, $id)
    {
        try {
            $validatedData = $request->validated();
            $tax = $this->repository->findOrFail($id);
            $before = $tax->only(array_keys($validatedData));
            $result = $tax->update($validatedData);
            AccountingAuditLog::record('tax_class', $tax->id, 'updated', $before, $validatedData);

            return $result;
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return JsonResponse
     */
    /**
     * GET taxes/{id}/versions — scheduled rate changes, newest start first.
     */
    public function versions($id)
    {
        $tax = Tax::findOrFail((int) $id);

        return $tax->versions()->get()->map(fn ($v) => [
            'id'             => (int) $v->id,
            'rate'           => (float) $v->rate,
            'effective_from' => $v->effective_from?->toDateString(),
            'effective_to'   => $v->effective_to?->toDateString(),
            'note'           => $v->note,
            'in_force'       => $v->effective_from && $v->effective_from->toDateString() <= now()->toDateString(),
        ])->values();
    }

    /**
     * POST taxes/{id}/versions — schedule a rate change.
     *
     * Future dates only. A version starting today or earlier would silently
     * reprice live checkouts the instant it is saved, which is exactly the
     * hand-edit-at-9am this exists to replace; to change the rate NOW, edit the
     * class itself, where it is obvious that is what you are doing.
     */
    public function storeVersion(Request $request, $id)
    {
        $tax = Tax::findOrFail((int) $id);

        $data = $request->validate([
            'rate'           => ['required', 'numeric', 'min:0', 'max:100'],
            'effective_from' => ['required', 'date', 'after:today'],
            'effective_to'   => ['nullable', 'date', 'after:effective_from'],
            'note'           => ['nullable', 'string', 'max:255'],
        ]);

        if ($tax->versions()->whereDate('effective_from', $data['effective_from'])->exists()) {
            abort(422, 'A rate change is already scheduled for that date.');
        }

        $version = TaxRateVersion::create($data + [
            'tax_class_id'       => $tax->id,
            'created_by_user_id' => optional($request->user())->id,
        ]);

        AccountingAuditLog::record('tax_rate_version', $version->id, 'created', null, $data + ['tax_class_id' => $tax->id]);

        return $version;
    }

    /**
     * DELETE taxes/{id}/versions/{versionId} — cancel a scheduled change.
     *
     * Only while it is still in the future: once a version has taken effect it
     * has priced real orders, and removing it would make those unreproducible.
     */
    public function destroyVersion($id, $versionId)
    {
        $version = TaxRateVersion::where('tax_class_id', (int) $id)->findOrFail((int) $versionId);

        if ($version->effective_from && $version->effective_from->toDateString() <= now()->toDateString()) {
            abort(422, 'That rate change has already taken effect and cannot be removed.');
        }

        AccountingAuditLog::record('tax_rate_version', $version->id, 'deleted', $version->only(['tax_class_id', 'rate', 'effective_from', 'effective_to', 'note']), null);
        $version->delete();

        return ['deleted' => true];
    }

    public function destroy($id)
    {
        try {
            $tax = $this->repository->findOrFail($id);
            AccountingAuditLog::record('tax_class', $tax->id, 'deleted', $tax->only(['name', 'rate', 'hsn_code', 'tax_category', 'is_active']), null);

            return $tax->delete();
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }
}
