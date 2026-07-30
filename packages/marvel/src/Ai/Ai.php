<?php

namespace Marvel\Ai;


class Ai
{
  public $ai;

  public function __construct(AiInterface $ai)
  {
    $this->ai = $ai;
  }

  /**
   * generateDescription
   *
   * @param  object $request
   * @return array
   */
  public function generateDescription($request)
  {
    return $this->ai->generateDescription($request);
  }

  /**
   * suggestCategories — map a product to existing categories.
   *
   * @param  object $request
   * @return array
   */
  public function suggestCategories($request)
  {
    return $this->ai->suggestCategories($request);
  }

  /**
   * generateProductContent — combined description + categories (bulk flow).
   *
   * @param  object $request
   * @return array
   */
  public function generateProductContent($request)
  {
    return $this->ai->generateProductContent($request);
  }
}
