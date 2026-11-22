<?php
namespace Tests\Feature;

use Tests\TestCase;

/**
 * Owner 2026-11-22: "looks cheap" — no yellow or gold fills anywhere in the product (Bahi-khata identity).
 * Scans the source (not public/build): any colour literal in the yellow/gold/amber band (hue 35-65 deg, saturation > 30%),
 * any amber-/yellow- utility class, and any custom property named *gold* outside khata-tokens.css (which holds the legacy aliases).
 */
class NoYellowTest extends TestCase
{
    /** @return list<array{string,string}> [relative path, text] */
    private function sources(): array
    {
        $out = [];
        foreach (['resources/css', 'resources/views', 'resources/js'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && preg_match('/\.(css|php|js|svg)$/', $f->getFilename())) {
                    $out[] = [str_replace(base_path().'/', '', $f->getPathname()), (string) file_get_contents($f->getPathname())];
                }
            }
        }
        foreach (glob(base_path('public/*.{css,svg}'), GLOB_BRACE) as $p) {
            $out[] = [str_replace(base_path().'/', '', $p), (string) file_get_contents($p)];
        }
        $out[] = ['tailwind.config.js', (string) file_get_contents(base_path('tailwind.config.js'))];

        return $out;
    }

    private function isYellow(int $r, int $g, int $b): bool
    {
        [$r, $g, $b] = [$r / 255, $g / 255, $b / 255];
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $d = $max - $min;
        if ($d == 0) { return false; }
        $l = ($max + $min) / 2;
        $s = $d / (1 - abs(2 * $l - 1));
        $h = match ($max) {
            $r => fmod(($g - $b) / $d, 6),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        } * 60;
        if ($h < 0) { $h += 360; }

        return $h >= 35 && $h <= 65 && $s > 0.3;
    }

    public function test_no_yellow_colour_literals_in_source(): void
    {
        $hits = [];
        foreach ($this->sources() as [$path, $text]) {
            if (preg_match_all('/#([0-9a-f]{6}|[0-9a-f]{3})\b/i', $text, $m)) {
                foreach ($m[1] as $hex) {
                    $hex = strlen($hex) === 3 ? preg_replace('/./', '$0$0', $hex) : $hex;
                    if ($this->isYellow(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)))) {
                        $hits[] = "$path: #$hex";
                    }
                }
            }
            if (preg_match_all('/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $text, $m, PREG_SET_ORDER)) {
                foreach ($m as $c) {
                    if ($this->isYellow((int) $c[1], (int) $c[2], (int) $c[3])) {
                        $hits[] = "$path: $c[0]";
                    }
                }
            }
        }
        $this->assertSame([], $hits, 'Yellow/gold/amber colour literals are banned; use the khata tokens.');
    }

    public function test_no_amber_or_yellow_utilities_and_no_gold_custom_properties(): void
    {
        $hits = [];
        foreach ($this->sources() as [$path, $text]) {
            if (preg_match('/\b(?:amber|yellow)-\d{2,3}\b/', $text, $m)) {
                $hits[] = "$path: $m[0]";
            }
            if ($path !== 'resources/css/khata-tokens.css' && preg_match('/--[a-z0-9-]*gold[a-z0-9-]*\s*:/i', $text, $m)) {
                $hits[] = "$path: $m[0]";
            }
        }
        $this->assertSame([], $hits, 'amber-/yellow- utilities and --*gold* custom properties may only exist as khata-tokens.css aliases.');
    }
}
