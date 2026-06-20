<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\Designation;
use Marvel\Enums\ModulePermission;
use Marvel\Services\PermissionResolver;

/**
 * Phase B — CRUD for designations (permission templates). Gated by employees.*
 * in routes (super-admin bypasses). Editing a designation's defaults
 * re-materialises every employee that carries it, so changes propagate.
 */
class DesignationController extends CoreController
{
    public function __construct(private PermissionResolver $resolver)
    {
    }

    /** All designations (newest-first within display order), with users_count. */
    public function index(Request $request)
    {
        return Designation::query()
            ->when($request->filled('name'), fn ($q) => $q->where('name', 'like', "%{$request->name}%"))
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
    }

    public function show($id)
    {
        return Designation::findOrFail($id);
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        $data['slug'] = $this->uniqueSlug($data['name']);

        return Designation::create($data);
    }

    public function update(Request $request, $id)
    {
        $designation = Designation::findOrFail($id);
        $data = $this->validatePayload($request, $designation->id);

        return DB::transaction(function () use ($designation, $data) {
            $designation->update($data);

            // Re-materialise EVERY employee with this designation — both
            // designation-sourced and custom (custom effective also depends on
            // the designation defaults). Bounded fan-out.
            $designation->users()->each(function ($user) {
                $this->resolver->materialize($user);
            });

            return $designation->fresh();
        });
    }

    /** Activate / deactivate without touching permissions. */
    public function setStatus(Request $request, $id)
    {
        $request->validate(['is_active' => 'required|boolean']);
        $designation = Designation::findOrFail($id);
        $designation->update(['is_active' => $request->boolean('is_active')]);

        return $designation;
    }

    public function destroy($id)
    {
        $designation = Designation::findOrFail($id);
        if ($designation->users()->count() > 0) {
            throw ValidationException::withMessages([
                'designation' => ['Reassign or remove its employees before deleting this designation.'],
            ]);
        }
        $designation->delete();

        return ['id' => $id];
    }

    /** Shared validation; keeps only valid module permissions in defaults. */
    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        $request->validate([
            'name'                  => ['required', 'string', 'max:255'],
            'department'            => ['nullable', 'string', 'max:255'],
            'description'           => ['nullable', 'string'],
            'is_active'             => ['nullable', 'boolean'],
            'display_order'         => ['nullable', 'integer'],
            'default_permissions'   => ['present', 'array'],
            'default_permissions.*' => ['string'],
        ]);

        $defaults = array_values(array_filter(
            (array) $request->input('default_permissions', []),
            fn ($p) => is_string($p) && ModulePermission::isValid($p)
        ));

        return [
            'name'                => $request->input('name'),
            'department'          => $request->input('department'),
            'description'         => $request->input('description'),
            'is_active'           => $request->boolean('is_active', true),
            'display_order'       => (int) $request->input('display_order', 0),
            'default_permissions' => $defaults,
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'designation';
        $slug = $base;
        $i = 1;
        while (Designation::where('slug', $slug)->exists()) {
            $slug = "{$base}-" . (++$i);
        }

        return $slug;
    }
}
