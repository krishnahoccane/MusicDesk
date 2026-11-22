<?php

namespace Tests\Unit;

use App\View\Components\Icon;
use Tests\TestCase;

class IconComponentTest extends TestCase
{
    public function test_known_icon_returns_svg_paths(): void
    {
        $this->assertStringContainsString('<path', Icon::paths('music'));
    }

    public function test_unknown_icon_returns_empty_string(): void
    {
        $this->assertSame('', Icon::paths('definitely-not-an-icon'));
    }

    public function test_path_traversal_names_are_rejected(): void
    {
        $this->assertSame('', Icon::paths('../../../.env'));
        $this->assertSame('', Icon::paths('..\\composer'));
        $this->assertSame('', Icon::paths('Music'));
    }

    public function test_component_renders_accessible_svg(): void
    {
        $html = (string) $this->blade('<x-icon name="music" size="20" class="me-1" />');

        $this->assertStringContainsString('width="20"', $html);
        $this->assertStringContainsString('class="ld-icon me-1"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }
}
