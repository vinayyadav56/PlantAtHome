<?php

namespace Tests\Unit\Marketing;

use App\Modules\Marketing\Domain\VariableMapper;
use Tests\TestCase;

class VariableMapperTest extends TestCase
{
    public function test_extracts_distinct_variables_in_order(): void
    {
        $vars = VariableMapper::extract('Hi {{name}}, your order {{order_number}} for {{name}} ships {{delivery_date}}');
        $this->assertSame(['name', 'order_number', 'delivery_date'], $vars);
    }

    public function test_renders_and_leaves_no_tokens_for_missing_vars(): void
    {
        $out = VariableMapper::render('Hi {{name}} in {{city}}!', ['name' => 'Vinay']);
        $this->assertSame('Hi Vinay in !', $out);
    }

    public function test_render_is_case_insensitive_on_columns(): void
    {
        $this->assertSame('Delhi', VariableMapper::render('{{City}}', ['city' => 'Delhi']));
    }

    public function test_mapping_flags_matched_columns(): void
    {
        $map = VariableMapper::mapping(['name', 'plant_name'], ['id', 'name', 'email']);
        $this->assertSame([
            ['name' => 'name', 'mapped' => true],
            ['name' => 'plant_name', 'mapped' => false],
        ], $map);
    }
}
