<?php

namespace Marvel\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Marvel\Services\CategoryTree;

/**
 * A category's parent (or a placement parent) must exist, sit in the same vertical, be switched
 * on, and be neither the category itself nor one of its own sub-categories. The last one matters
 * beyond tidiness: a cycle in `categories.parent` makes the recursive `children` eager-load
 * spin forever on GET /categories.
 */
class ValidCategoryParent implements Rule
{
    private string $message = 'The chosen parent category is not valid.';

    /**
     * @param int|null $typeId     the category's vertical (null: not known yet, skip that check)
     * @param int|null $categoryId the category being edited (null on create: it has no descendants)
     */
    public function __construct(private ?int $typeId, private ?int $categoryId = null)
    {
    }

    public function passes($attribute, $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        $parentId = (int) $value;
        $parent = DB::table('categories')->where('id', $parentId)->first();
        if (!$parent) {
            $this->message = 'The chosen parent category does not exist.';

            return false;
        }
        if ($this->typeId && (int) $parent->type_id !== $this->typeId) {
            $this->message = 'The parent category must be in the same vertical.';

            return false;
        }
        if (property_exists($parent, 'is_active') && !(bool) $parent->is_active) {
            $this->message = "\"{$parent->name}\" is switched off — switch it on before putting categories under it.";

            return false;
        }
        if ($this->categoryId !== null && app(CategoryTree::class)->wouldCycle($this->categoryId, $parentId)) {
            $this->message = $parentId === $this->categoryId
                ? 'A category cannot be its own parent.'
                : "\"{$parent->name}\" sits under this category, so it cannot also be its parent.";

            return false;
        }

        return true;
    }

    public function message(): string
    {
        return $this->message;
    }
}
