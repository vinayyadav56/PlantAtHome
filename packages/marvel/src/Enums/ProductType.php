<?php


namespace Marvel\Enums;

use BenSampo\Enum\Enum;

/**
 * Class RoleType
 * @package App\Enums
 */
final class ProductType extends Enum
{
	public const SIMPLE = 'simple';
	public const VARIABLE = 'variable';
	public const BUNDLE = 'bundle'; // PlantAtHome — a curated package of products at an offer price
}
