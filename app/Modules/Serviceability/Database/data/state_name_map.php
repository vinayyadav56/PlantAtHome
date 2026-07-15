<?php

/**
 * Dataset state name -> canonical `states.name` (LocationSeeder's 35 rows).
 * The GeoMasterSeeder ABORTS (never skips) when a dataset state has no
 * mapping and no exact match — a wrong state poisons every rule downstream.
 *
 * Note: the bundled GeoNames dataset has no separate Ladakh; its pincodes
 * appear under Jammu and Kashmir (documented in README.md).
 */
return [
    'Andaman & Nicobar Islands' => 'Andaman and Nicobar Islands',
    'Jammu & Kashmir' => 'Jammu and Kashmir',
    'Pondicherry' => 'Puducherry',
    'Orissa' => 'Odisha',
    'Uttaranchal' => 'Uttarakhand',
    'NCT of Delhi' => 'Delhi',
    'National Capital Territory of Delhi' => 'Delhi',
];
