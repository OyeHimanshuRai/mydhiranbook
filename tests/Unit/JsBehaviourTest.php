<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Runs the browser-logic tests in tests/js (photo resize, live search) with Node's built-in runner. */
class JsBehaviourTest extends TestCase
{
    public function test_browser_logic_passes_under_node(): void
    {
        $root = dirname(__DIR__, 2);
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is not installed');
        }
        exec('cd '.escapeshellarg($root).' && node --test tests/js 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
