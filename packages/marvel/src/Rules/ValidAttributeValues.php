<?php

namespace Marvel\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Database\QueryException;
use Marvel\Services\AttributeValueWriter;

/**
 * A product's submitted specifications (`attribute_values`) are valid for their attributes
 * (value kind, options, limits). Validated before anything is saved, so a refused save never
 * leaves a half-written product.
 *
 * Which attributes are REQUIRED is judged by ProductController on the status the save will
 * store, which only it knows (a vendor's create is forced to under review; product review can
 * turn a request into publish).
 */
class ValidAttributeValues implements Rule
{
    private array $errors = [];

    /** @param int|null $productId the product being edited: a retired option it already has stays valid */
    public function __construct(private ?int $productId = null)
    {
    }

    public function passes($attribute, $value): bool
    {
        if (!is_array($value)) {
            $this->errors = ['The specifications must be a list.'];

            return false;
        }
        try {
            $this->errors = array_values(app(AttributeValueWriter::class)->errors($value, null, [], false, $this->productId));
        } catch (QueryException $e) {
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
