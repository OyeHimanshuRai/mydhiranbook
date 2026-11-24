<?php
namespace Tests\Feature;

use Tests\TestCase;

/**
 * Restrained motion (owner 2026-11-23): never `transition: all`, only compositor-friendly properties, and every stylesheet that
 * animates also honours prefers-reduced-motion (khata-tokens.css holds the global switch every bundle imports).
 */
class MotionCssTest extends TestCase
{
    /** @return array<string,string> */
    private function sheets(): array
    {
        $out = [];
        foreach (glob(resource_path('css/*.css')) as $f) {
            $out[basename($f)] = (string) file_get_contents($f);
        }

        return $out;
    }

    public function test_no_transition_all_and_no_layout_properties_are_animated(): void
    {
        $bad = [];
        foreach ($this->sheets() as $name => $css) {
            if (preg_match('/transition(?:-property)?\s*:\s*[^;{}]*\ball\b(?!-)/', preg_replace('/\ballow-discrete\b/', '', $css), $m)) {
                $bad[] = "$name: $m[0]";
            }
            if (preg_match('/transition(?:-property)?\s*:[^;{}]*\b(width|height|top|left|right|bottom|margin[a-z-]*|padding[a-z-]*)\b\s+\d/', $css, $m)) {
                $bad[] = "$name animates layout: $m[0]";
            }
        }
        $this->assertSame([], $bad);
    }

    public function test_every_animating_stylesheet_has_a_reduced_motion_override(): void
    {
        $tokens = $this->sheets()['khata-tokens.css'];
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $tokens);
        $this->assertMatchesRegularExpression('/animation-duration:\s*1ms !important/', $tokens);
        foreach ($this->sheets() as $name => $css) {
            if ($name === 'khata-tokens.css' || ! preg_match('/@keyframes|animation\s*:/', $css)) {
                continue;
            }
            $imports = str_contains($css, 'khata-tokens.css');
            $this->assertTrue($imports || str_contains($css, 'prefers-reduced-motion'), "$name animates without a reduced-motion override");
        }
    }
}
