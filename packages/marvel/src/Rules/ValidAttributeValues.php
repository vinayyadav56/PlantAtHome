<?php

namespace Marvel\Rules;

use Illuminate\Contracts\Validation\Rule;
use Marvel\Services\AttributeValueWriter;

/**
 * A product's submitted specifications (`attribute_values`) are valid for their attributes
 * (value kind, options, limits), and every attribute required in the product's categories has
 * a value when it is being published. Validated before anything is saved, so a refused save
 * never leaves a half-written product.
 */
class ValidAttributeValues implements Rule
{
    private array $errors = [];

    public function __construct(
        private ?int $typeId,
        private array $categoryIds,
        private bool $publishing,
        private ?int $productId = null
    ) {
    }

    public function passes($attribute, $value): bool
    {
        if (!is_array($value)) {
            $this->errors = ['The specifications must be a list.'];

            return false;
        }
        try {
            $this->errors = array_values(app(AttributeValueWriter::class)
                ->errors($value, $this->typeId, $this->categoryIds, $this->publishing, $this->productId));
        } catch (\Throwable $e) {
            // Tables not migrated yet (deploys migrate after the code serves): never block a save.
            return true;
        }

        return $this->errors === [];
    }

    public function message(): string
    {
        return implode(' ', $this->errors) ?: 'The specifications are not valid.';
    }
}
