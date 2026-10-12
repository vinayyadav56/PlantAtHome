<?php

namespace Marvel\Ai;

interface AiInterface
{
  public function generateDescription(object $request): mixed;

  /** Map a product to the best-fitting EXISTING categories (never invents new ones). */
  public function suggestCategories(object $request): mixed;

  /** Combined single-call generation (description + categories) used by the bulk flow. */
  public function generateProductContent(object $request): mixed;

  /**
   * One plant's missing fields in one call ("generate missing content" run).
   * Returns ['fields' => [field => ?value], 'scientific_name_confidence' => ?string,
   *          'usage' => ['prompt_tokens' => int, 'completion_tokens' => int], 'model' => string].
   */
  public function generatePlantContent(object $request): mixed;
}
