<?php
namespace Tests\Feature\Adversarial;

use PHPUnit\Framework\TestCase;

/**
 * Static dead-reference sweep after the payment gateway / collect-only / plans removal. No DB, no app boot:
 * filesystem + regex + token scan only. A test named test_BUG_* currently fails on a real dangling or dead reference.
 */
final class DeadReferencesTest extends TestCase
{
    private static ?array $routeCache = null;

    // ---------------------------------------------------------------- helpers

    private static function root(): string { return dirname(__DIR__, 3); }

    private static function rel(string $p): string { return ltrim(substr($p, strlen(self::root())), '/'); }

    /** @return list<string> */
    private static function files(array $paths, string $suffix): array
    {
        $out = [];
        $skip = self::root().'/tests/Feature/Adversarial/';
        foreach ($paths as $d) {
            $p = self::root().'/'.$d;
            if (is_file($p)) { if (str_ends_with($p, $suffix)) { $out[] = $p; } continue; }
            if (!is_dir($p)) { continue; }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($p, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $path = $f->getPathname();
                if (str_ends_with($path, $suffix) && !str_starts_with($path, $skip) && !str_contains($path, '/vendor/') && !str_contains($path, '/node_modules/')) { $out[] = $path; }
            }
        }
        sort($out);
        return $out;
    }

    private static function php(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $t) {
            if (is_array($t)) {
                $out .= in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? str_repeat("\n", substr_count($t[1], "\n")) : $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    private static function blade(string $path): string
    {
        $nl = fn ($m) => str_repeat("\n", substr_count($m[0], "\n"));
        $s = (string) file_get_contents($path);
        $s = preg_replace_callback('/\{\{--.*?--\}\}/s', $nl, $s);
        return preg_replace_callback('/<!--.*?-->/s', $nl, $s);
    }

    private static function code(string $path): string { return str_ends_with($path, '.blade.php') ? self::blade($path) : self::php($path); }

    /** @return list<array{0:string,1:int}> [captured group, line] */
    private static function grab(string $text, string $re, int $g = 1): array
    {
        $out = [];
        if (preg_match_all($re, $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($m as $x) { $out[] = [$x[$g][0], substr_count(substr($text, 0, $x[$g][1]), "\n") + 1]; }
        }
        return $out;
    }

    private function assertNone(array $bad, string $what): void
    {
        $this->assertSame([], $bad, $what."\n  ".implode("\n  ", $bad));
    }

    // ---------------------------------------------------------------- routes

    /** @return array{names:array<string,string>,uris:list<string>} */
    private static function routes(): array
    {
        if (self::$routeCache) { return self::$routeCache; }
        $names = []; $uris = [];
        foreach (['routes/web.php', 'routes/dhiran.php'] as $f) {
            $lines = file(self::root().'/'.$f);
            $stack = [];
            foreach ($lines as $i => $line) {
                if (preg_match('/^(\s*)\}\);/', $line, $m) && $stack && strlen($m[1]) === end($stack)['indent']) { array_pop($stack); }
                $np = implode('', array_column($stack, 'name')); $up = implode('/', array_filter(array_column($stack, 'uri')));
                $defs = []; $nms = [];
                if (preg_match_all('/(?:Route::|->)(get|post|put|patch|delete|any|view)\(\s*\'([^\']*)\'/', $line, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                    foreach ($m as $x) { $defs[] = [$x[0][1], $x[2][0]]; }
                }
                if (preg_match_all('/->name\(\'([^\']+)\'\)/', $line, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                    foreach ($m as $x) { $nms[] = [$x[0][1], $x[1][0]]; }
                }
                foreach ($defs as $k => [$off, $uri]) {
                    $next = $defs[$k + 1][0] ?? PHP_INT_MAX; $name = null;
                    foreach ($nms as [$no, $n]) { if ($no > $off && $no < $next) { $name = $n; break; } }
                    if ($name === null && str_ends_with(rtrim($line), '{')) {
                        for ($j = $i + 1; $j < min($i + 6, count($lines)); $j++) { if (preg_match('/^\s*\}\)(.*)->name\(\'([^\']+)\'\)/', $lines[$j], $mm)) { $name = $mm[2]; break; } }
                    }
                    $uris[] = '/'.trim(($up !== '' ? $up.'/' : '').trim($uri, '/'), '/');
                    if ($name !== null) { $names[$np.$name] = $f.':'.($i + 1); }
                }
                if (preg_match('/->group\(function/', $line)) {
                    preg_match('/(?:->|::)prefix\(\'([^\']+)\'\)/', $line, $p); preg_match('/->name\(\'([^\']+)\'\)/', $line, $n); preg_match('/^(\s*)/', $line, $ind);
                    $stack[] = ['indent' => strlen($ind[1]), 'name' => $n[1] ?? '', 'uri' => isset($p[1]) ? trim($p[1], '/') : ''];
                }
            }
        }
        return self::$routeCache = ['names' => $names, 'uris' => array_values(array_unique($uris))];
    }

    private static function routeRefs(string $code): array
    {
        $refs = [];
        foreach (self::grab($code, '/(?<![\w>$:])(?:to_route|route)\(\s*([\'"])([A-Za-z0-9_.\-]+)\1/', 2) as $r) { $refs[] = $r; }
        foreach (self::grab($code, '/redirect\(\)\s*->\s*route\(\s*([\'"])([A-Za-z0-9_.\-]+)\1/', 2) as $r) { $refs[] = $r; }
        foreach (self::grab($code, '/Route::has\(\s*([\'"])([A-Za-z0-9_.\-]+)\1/', 2) as $r) { $refs[] = $r; }
        if (preg_match_all('/\brouteIs\(([^)]*)\)/', $code, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $line = substr_count(substr($code, 0, $x[0][1]), "\n") + 1;
                if (preg_match_all('/[\'"]([A-Za-z0-9_.*\-]+)[\'"]/', $x[1][0], $q)) { foreach ($q[1] as $n) { $refs[] = [$n, $line]; } }
            }
        }
        return $refs;
    }

    private function assertRouteNamesExist(array $paths, array $suffixes): void
    {
        $names = array_keys(self::routes()['names']); $bad = [];
        foreach ($suffixes as $sfx) {
            foreach (self::files($paths, $sfx) as $f) {
                if ($sfx === '.php' && str_ends_with($f, '.blade.php')) { continue; }
                foreach (self::routeRefs(self::code($f)) as [$n, $line]) {
                    $ok = str_contains($n, '*') ? (bool) array_filter($names, fn ($x) => fnmatch($n, $x)) : in_array($n, $names, true);
                    if (!$ok) { $bad[] = self::rel($f).':'.$line." route '$n' is not defined"; }
                }
            }
        }
        $this->assertNone($bad, 'Dangling route names:');
    }

    public function test_route_names_used_in_app_views_and_routes_exist(): void
    {
        $this->assertRouteNamesExist(['app', 'routes', 'bootstrap', 'config', 'database/seeders'], ['.php']);
        $this->assertRouteNamesExist(['resources/views'], ['.blade.php']);
    }

    public function test_route_names_used_in_tests_exist(): void
    {
        $this->assertRouteNamesExist(['tests'], ['.php']);
    }

    public function test_route_name_parser_sees_the_composed_names(): void
    {
        $n = self::routes()['names'];
        foreach (['dhiran.dashboard', 'dhiran.loans', 'dhiran.customer-loans', 'subscription.status', 'dhiran.expired', 'dhiran.blocked', 'dhiran.borrowers.kyc', 'site.pricing', 'admin.login'] as $k) {
            $this->assertArrayHasKey($k, $n, "parser lost $k");
        }
        $this->assertGreaterThan(80, count($n));
    }

    // ---------------------------------------------------------------- views

    private static function viewNames(): array
    {
        $base = self::root().'/resources/views/'; $out = [];
        foreach (self::files(['resources/views'], '.blade.php') as $f) { $out[] = str_replace('/', '.', substr($f, strlen($base), -10)); }
        return $out;
    }

    private static function componentExists(string $n): bool
    {
        $p = self::root().'/resources/views/components/'.str_replace('.', '/', $n);
        return is_file($p.'.blade.php') || is_file($p.'/index.blade.php') || is_file($p.'/'.basename($p).'.blade.php');
    }

    public function test_blade_include_extends_and_view_targets_exist(): void
    {
        $views = self::viewNames(); $bad = [];
        $blade = '/@(?:include|includeIf|extends|each|component)\(\s*[\'"]([A-Za-z0-9_.\-]+)[\'"]/';
        $when = '/@include(?:When|Unless)\([^,]+,\s*[\'"]([A-Za-z0-9_.\-]+)[\'"]/';
        foreach (self::files(['resources/views'], '.blade.php') as $f) {
            $c = self::blade($f);
            foreach (array_merge(self::grab($c, $blade), self::grab($c, $when)) as [$v, $line]) {
                if (!in_array($v, $views, true)) { $bad[] = self::rel($f).":$line view '$v' missing"; }
            }
            foreach (self::grab($c, '/\bview:\s*[\'"]([A-Za-z0-9_.\-]+)[\'"]/') as [$v, $line]) { if (!in_array($v, $views, true)) { $bad[] = self::rel($f).":$line view '$v' missing"; } }
        }
        $php = '/(?<![\w$])(?:view|markdown)\(\s*[\'"]([A-Za-z0-9_.\-]+)[\'"]/';
        foreach (self::files(['app', 'routes', 'bootstrap', 'config'], '.php') as $f) {
            $c = self::php($f);
            $refs = array_merge(self::grab($c, $php), self::grab($c, '/->view\(\s*[\'"]([A-Za-z0-9_.\-]+)[\'"]/'), self::grab($c, '/View::make\(\s*[\'"]([A-Za-z0-9_.\-]+)[\'"]/'),
                self::grab($c, '/\b(?:view|markdown):\s*[\'"]([A-Za-z0-9_.\-]+)[\'"]/'), self::grab($c, '/Route::view\(\s*[\'"][^\'"]*[\'"]\s*,\s*[\'"]([A-Za-z0-9_.\-]+)[\'"]/'));
            foreach ($refs as [$v, $line]) { if (!in_array($v, $views, true)) { $bad[] = self::rel($f).":$line view '$v' missing"; } }
        }
        $this->assertNone($bad, 'Dangling view references:');
    }

    public function test_blade_components_exist(): void
    {
        $bad = [];
        foreach (self::files(['resources/views', 'app'], '.blade.php') as $f) {
            foreach (self::grab(self::blade($f), '/<x-([A-Za-z0-9_.\-]+)(?=[\s\/>])/') as [$n, $line]) {
                if ($n === 'slot' || $n === 'dynamic-component') { continue; }
                if (!self::componentExists($n)) { $bad[] = self::rel($f).":$line component <x-$n> missing"; }
            }
        }
        $this->assertNone($bad, 'Dangling blade components:');
    }

    public function test_every_view_file_is_referenced_somewhere(): void
    {
        $corpus = '';
        foreach (self::files(['app', 'routes', 'bootstrap', 'config', 'resources/views'], '.php') as $f) { $corpus .= "\n".self::code($f); }
        $bad = [];
        foreach (self::viewNames() as $v) {
            if (str_starts_with($v, 'errors.') || str_starts_with($v, 'components.')) { continue; }
            $short = $v;
            if (!preg_match('/[\'"]'.preg_quote($short, '/').'[\'"]/', $corpus)
                && !preg_match('/[\'"]'.preg_quote(substr($short, 0, (int) strrpos($short, '.') + 1), '/').'[\'"]?\s*\./', $corpus)
                && !str_contains($corpus, "'".$short."'")) {
                $bad[] = "resources/views/".str_replace('.', '/', $v).'.blade.php is never referenced';
            }
        }
        $this->assertNone($bad, 'Orphan views (dead after removed features):');
    }

    // ---------------------------------------------------------------- classes

    private static function fqcnRefs(string $code): array
    {
        $code = str_replace('\\\\', '\\', $code);
        $refs = [];
        $code = preg_replace_callback('/\buse\s+((?:App|Database|Tests)[\w\\\\]*?)\\\\\{([^}]*)\}\s*;/', function ($m) use (&$refs) {
            foreach (explode(',', $m[2]) as $item) {
                $item = trim(preg_replace('/\s+as\s+\w+\s*$/i', '', trim($item)));
                if ($item !== '') { $refs[] = [$m[1].'\\'.$item, 0]; }
            }
            return str_repeat("\n", substr_count($m[0], "\n"));
        }, $code);
        $code = preg_replace('/^\s*namespace\s+[^;]+;/m', '', $code);
        foreach (self::grab($code, '/(?<![\w\\\\])\\\\?((?:App|Database\\\\Seeders|Tests)(?:\\\\\w+)+)/') as $r) { $refs[] = $r; }
        return $refs;
    }

    private static function classPath(string $fq): string
    {
        $fq = str_replace('\\', '/', $fq);
        foreach (['App/' => 'app/', 'Database/Seeders/' => 'database/seeders/', 'Tests/' => 'tests/'] as $a => $b) { if (str_starts_with($fq, $a)) { return $b.substr($fq, strlen($a)); } }
        return $fq;
    }

    private static function classExists(string $fq): bool
    {
        $p = self::root().'/'.self::classPath($fq);
        return is_file($p.'.php') || is_dir($p);
    }

    private function assertClassRefsResolve(array $paths, array $exts): void
    {
        $bad = [];
        foreach ($exts as $ext) {
            foreach (self::files($paths, $ext) as $f) {
                if ($ext === '.php' && str_ends_with($f, '.blade.php')) { continue; }
                foreach (self::fqcnRefs(self::code($f)) as [$fq, $line]) {
                    if (!self::classExists($fq)) { $bad[] = self::rel($f).($line ? ":$line" : '')." $fq does not exist"; }
                }
            }
        }
        $this->assertNone($bad, 'Dangling class references:');
    }

    public function test_class_references_in_app_routes_config_bootstrap_database_resolve(): void
    {
        $this->assertClassRefsResolve(['app', 'routes', 'bootstrap', 'config', 'database'], ['.php']);
    }

    public function test_class_references_in_views_resolve(): void
    {
        $this->assertClassRefsResolve(['resources/views'], ['.blade.php']);
    }

    public function test_class_references_in_tests_resolve(): void
    {
        $this->assertClassRefsResolve(['tests'], ['.php']);
    }

    public function test_route_controller_methods_exist(): void
    {
        $bad = [];
        foreach (['routes/web.php', 'routes/dhiran.php'] as $rf) {
            $text = self::php(self::root().'/'.$rf);
            $alias = [];
            if (preg_match_all('/\buse\s+([\w\\\\]+)\s+as\s+(\w+)\s*;/', $text, $m, PREG_SET_ORDER)) { foreach ($m as $x) { $alias[$x[2]] = $x[1]; } }
            if (preg_match_all('/\buse\s+([\w\\\\]+)\\\\\{([^}]*)\}\s*;/', $text, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) { foreach (explode(',', $x[2]) as $it) { if (preg_match('/^\s*(\w+)(?:\s+as\s+(\w+))?\s*$/', $it, $y)) { $alias[$y[2] ?? $y[1]] = $x[1].'\\'.$y[1]; } } }
            }
            if (preg_match_all('/\buse\s+(App[\w\\\\]*\\\\(\w+))\s*;/', $text, $m, PREG_SET_ORDER)) { foreach ($m as $x) { $alias[$x[2]] = $x[1]; } }
            $vars = [];
            if (preg_match_all('/\$(\w+)\s*=\s*\\\\?([\w\\\\]+)::class\s*;/', $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) { foreach ($m as $x) { $vars[] = [$x[0][1], $x[1][0], $x[2][0]]; } }
            if (preg_match_all('/\[\s*(?:(\\\\?[\w\\\\]+)::class|\$(\w+))\s*,\s*\'(\w+)\'\s*\]/', $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    $line = substr_count(substr($text, 0, $x[0][1]), "\n") + 1;
                    $cls = $x[1][0] ?? '';
                    if ($cls === '' && ($x[2][1] ?? -1) >= 0) {
                        $cls = null;
                        foreach ($vars as [$off, $v, $c]) { if ($v === $x[2][0] && $off < $x[0][1]) { $cls = $c; } }
                        if ($cls === null) { $bad[] = "$rf:$line unresolved variable \${$x[2][0]}"; continue; }
                    }
                    $cls = ltrim($cls, '\\');
                    $cls = $alias[$cls] ?? $cls;
                    if (!self::classExists($cls)) { $bad[] = "$rf:$line controller $cls missing"; continue; }
                    $src = (string) file_get_contents(self::root().'/'.self::classPath($cls).'.php');
                    $method = $x[3][0];
                    $found = (bool) preg_match('/function\s+'.$method.'\s*\(/', $src);
                    if (!$found) {
                        $extra = (string) file_get_contents(self::root().'/app/Http/Controllers/Controller.php');
                        foreach (self::files(['app/Http'], '.php') as $tf) { if (str_contains($tf, 'Concerns') && preg_match('/\buse\s+[^;]*\b'.basename($tf, '.php').'\b/', $src)) { $extra .= file_get_contents($tf); } }
                        $found = (bool) preg_match('/function\s+'.$method.'\s*\(/', $extra);
                    }
                    if (!$found) { $bad[] = "$rf:$line $cls::$method() not defined"; }
                }
            }
        }
        $this->assertNone($bad, 'Routes pointing to missing controller methods:');
    }

    public function test_scheduled_commands_exist(): void
    {
        $sigs = [];
        foreach (self::files(['app/Console/Commands'], '.php') as $f) { if (preg_match('/\$signature\s*=\s*[\'"]([^\s\'"]+)/', (string) file_get_contents($f), $m)) { $sigs[] = $m[1]; } }
        $console = self::php(self::root().'/routes/console.php');
        $bad = [];
        foreach (self::grab($console, '/Schedule::command\(\s*[\'"]([^\s\'"]+)/') as [$c, $line]) { if (!in_array($c, $sigs, true)) { $bad[] = "routes/console.php:$line schedules '$c' but no command has that signature"; } }
        foreach (self::grab($console, '/Schedule::job\(\s*(?:new\s+)?\\\\?([\w\\\\]+)/') as [$c, $line]) { if (!self::classExists($c)) { $bad[] = "routes/console.php:$line job $c missing"; } }
        $this->assertNone($bad, 'Scheduler dangling:');
        $this->assertGreaterThanOrEqual(5, count(self::grab($console, '/Schedule::command/', 0)));
    }

    public function test_middleware_names_used_in_routes_resolve(): void
    {
        $boot = self::php(self::root().'/bootstrap/app.php');
        preg_match('/alias\(\[(.*?)\]\)/s', $boot, $am);
        preg_match_all('/[\'"]([\w.]+)[\'"]\s*=>/', $am[1] ?? '', $km);
        $known = array_merge($km[1], ['auth', 'guest', 'throttle', 'can', 'signed', 'verified', 'web', 'api', 'password.confirm', 'cache', 'bindings']);
        $bad = [];
        foreach (['routes/web.php', 'routes/dhiran.php'] as $rf) {
            $t = self::php(self::root().'/'.$rf);
            foreach (self::grab($t, '/->?middleware\(\s*(\[[^\]]*\]|\'[^\']*\')\s*\)/') as [$blob, $line]) {
                if (preg_match_all('/[\'"]([\w.\-]+)(?::[^\'"]*)?[\'"]/', $blob, $q)) { foreach ($q[1] as $n) { if (!in_array($n, $known, true)) { $bad[] = "$rf:$line middleware '$n' has no alias"; } } }
            }
        }
        $this->assertNone($bad, 'Unknown middleware aliases:');
    }

    // ---------------------------------------------------------------- removed features

    private function scanLive(string $re, array $paths = ['app', 'routes', 'bootstrap', 'config', 'database/seeders', 'resources/views'], array $skipFiles = []): array
    {
        $bad = [];
        foreach (self::files($paths, '.php') as $f) {
            if (in_array(self::rel($f), $skipFiles, true)) { continue; }
            foreach (self::grab(self::code($f), $re, 0) as [$hit, $line]) { $bad[] = self::rel($f).":$line  $hit"; }
        }
        return $bad;
    }

    public function test_BUG_no_live_razorpay_references(): void
    {
        $this->assertNone($this->scanLive('/razorpay/i'), 'Razorpay still referenced in live code:');
    }

    public function test_BUG_composer_does_not_require_razorpay(): void
    {
        $c = json_decode((string) file_get_contents(self::root().'/composer.json'), true);
        $this->assertArrayNotHasKey('razorpay/razorpay', $c['require'] ?? [], 'composer.json still requires razorpay/razorpay (gateway was deleted)');
    }

    public function test_no_references_to_deleted_services_routes_and_config(): void
    {
        $re = '/SubscriptionPaymentService|SubscriptionWebhookService|PlatformInvoiceService|SendPlatformInvoiceEmail|PlatformInvoiceMail|UnresolvedPayment|[\'"]subscription\.(?:choose|payment|receipt|webhook|plans)|services\.razorpay|config\(\s*[\'"]billing|EnsureDhiranEnabled::(?:LAPSED|COLLECT_ONLY)|\b(?:LAPSED|COLLECT_ONLY)\b/';
        $this->assertNone($this->scanLive($re), 'Live references to deleted billing things:');
    }

    public function test_BUG_no_live_platform_invoice_model_use(): void
    {
        $this->assertNone($this->scanLive('/\bPlatformInvoice\b/', ['app', 'routes', 'bootstrap', 'config', 'database/seeders', 'resources/views'], ['app/Models/Platform/PlatformInvoice.php']),
            'PlatformInvoice (receipts) is deleted as a feature but still queried:');
    }

    public function test_BUG_platform_invoice_model_has_no_remaining_purpose(): void
    {
        $this->assertFileDoesNotExist(self::root().'/app/Models/Platform/PlatformInvoice.php', 'App\\Models\\Platform\\PlatformInvoice is a leftover of the deleted receipts feature');
    }
    public function test_BUG_isLapsed_branch_of_staff_message_is_unreachable(): void
    {
        // UseShop sends a shop without a valid term to /access-ended before any staff route runs, so the "plan has ended" text can never show.
        $s = self::php(self::root().'/app/Http/Controllers/StaffController.php');
        $this->assertFalse(str_contains($s, 'plan has ended') || str_contains($s, 'isLapsed'), 'StaffController still has an unreachable isLapsed() / "plan has ended, Renew it" branch');
    }

    // ---------------------------------------------------------------- copy

    private static function visible(string $path): string
    {
        $s = self::blade($path);
        $s = preg_replace('/<(script|style)\b.*?<\/\1>/is', ' ', $s);
        $s = preg_replace('/@php\b.*?@endphp/s', ' ', $s);
        $s = preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', ' ', $s);
        $s = preg_replace('/@\w+(?:\s*(\((?:[^()]|(?1))*\)))?/', ' ', $s);
        $s = preg_replace('/<[^>]+>/', ' ', $s);
        return html_entity_decode($s);
    }

    /** every PHP string literal in app/ (user-visible candidates): [text, file, line] */
    private static function appStrings(): array
    {
        $out = [];
        foreach (self::files(['app'], '.php') as $f) {
            $r = self::rel($f);
            if (str_starts_with($r, 'app/Console/') || $r === 'app/Support/RuntimeGuard.php' || $r === 'app/Models/Concerns/ImmutableLedger.php' || str_starts_with($r, 'app/Models/Platform/')) { continue; }
            foreach (token_get_all((string) file_get_contents($f)) as $t) {
                if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) { $out[] = [$t[1], $r, $t[2]]; }
            }
        }
        return $out;
    }

    private function copyHits(string $re, bool $includeAdmin = true): array
    {
        $bad = [];
        foreach (self::files(['resources/views'], '.blade.php') as $f) {
            if (!$includeAdmin && str_contains($f, '/views/admin/')) { continue; }
            $v = self::visible($f);
            if (preg_match_all($re, $v, $m, PREG_OFFSET_CAPTURE)) { foreach ($m[0] as [$h, $o]) { $bad[] = self::rel($f).' text "'.trim(substr($v, max(0, $o - 25), 70)).'"'; } }
        }
        foreach (self::appStrings() as [$s, $r, $line]) { if (preg_match($re, $s)) { $bad[] = "$r:$line ".trim(substr($s, 0, 110)); } }
        return $bad;
    }

    public function test_BUG_no_purchase_language_left_in_visible_copy(): void
    {
        $this->assertNone($this->copyHits('/\b(?:subscribe|checkout|check out|razorpay|free trial|trial period|platform invoice|tax invoice|renew (?:it|your)|upgrade your plan|upgrade)\b/i'), 'Purchase/plan wording that no longer matches the product (no payments, 1-year access granted by admin):');
    }
    public function test_BUG_product_is_never_named_dhiran_in_visible_text(): void
    {
        $bad = [];
        $re = '/(?<![\w\\\\\/.\-])Dhiran(?![\w\\\\])/';
        foreach (self::files(['resources/views'], '.blade.php') as $f) {
            $c = preg_replace('/<(script|style)\b.*?<\/\1>/is', ' ', self::blade($f));
            foreach (self::grab($c, $re, 0) as [$h, $line]) { $bad[] = self::rel($f).":$line  (view text or fallback)"; }
        }
        foreach (self::appStrings() as [$s, $r, $line]) { if (preg_match($re, $s)) { $bad[] = "$r:$line ".trim(substr($s, 0, 100)); } }
        foreach (['config/mail.php', '.env.example'] as $p) {
            foreach (file(self::root().'/'.$p) as $i => $l) { if (preg_match('/MAIL_FROM_NAME\W+[\'"]?Dhiran[\'"]?\s*(?:[)\],]|$)/', $l)) { $bad[] = $p.':'.($i + 1).' mail From name is "Dhiran"'; } }
        }
        $this->assertNone($bad, 'Visible product name still "Dhiran" (should be MyDhiranBook):');
    }

    public function test_every_root_relative_link_in_views_resolves_to_a_route_or_public_file(): void
    {
        $uris = self::routes()['uris']; $bad = [];
        $res = array_map(fn ($u) => '#^'.preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', preg_quote($u, '#')).'$#', $uris);
        foreach (self::files(['resources/views'], '.blade.php') as $f) {
            foreach (self::grab(self::blade($f), '/\b(?:href|action)=["\'](\/[^"\'#?]*)/') as [$p, $line]) {
                $p = preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', '{p}', $p);
                $p = rtrim($p, '/'); if ($p === '') { $p = '/'; }
                $ok = $p !== '/' && file_exists(self::root().'/public'.$p);
                foreach ($res as $re) { if (preg_match($re, $p)) { $ok = true; break; } }
                if (!$ok) { $bad[] = self::rel($f).":$line link $p matches no route"; }
            }
        }
        $this->assertNone($bad, 'Dead links:');
    }

    public function test_public_site_has_no_dead_legal_or_refund_pages(): void
    {
        $uris = self::routes()['uris'];
        foreach (['/refund', '/subscription/choose', '/subscription/payment', '/subscription/payment/webhook'] as $gone) { $this->assertNotContains($gone, $uris); }
        foreach (['/pricing', '/terms', '/privacy', '/contact', '/'] as $live) { $this->assertContains($live, $uris); }
        $this->assertFileExists(self::root().'/resources/views/site/pricing.blade.php');
    }

    // ---------------------------------------------------------------- env + docs

    private static function envUsed(): array
    {
        $out = [];
        foreach (self::files(['config', 'app', 'bootstrap', 'routes', 'database/seeders'], '.php') as $f) {
            $c = self::php($f);
            foreach (self::grab($c, '/\benv\(\s*([\'"])([A-Z][A-Z0-9_]*)\1(\s*,)?/', 2) as [$k]) { $out[$k] = $out[$k] ?? false; }
            if (preg_match_all('/\benv\(\s*([\'"])([A-Z][A-Z0-9_]*)\1(\s*,)?/', $c, $m, PREG_SET_ORDER)) { foreach ($m as $x) { $out[$x[2]] = ($out[$x[2]] ?? false) || isset($x[3]); } }
            foreach (self::grab($c, '/getenv\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/') as [$k]) { $out[$k] = true; }
        }
        return $out; // key => has default
    }

    private static function envExample(): array
    {
        preg_match_all('/^\s*([A-Z][A-Z0-9_]*)=/m', (string) file_get_contents(self::root().'/.env.example'), $m);
        return $m[1];
    }

    public function test_env_example_has_every_key_the_code_needs_and_no_obsolete_ones(): void
    {
        $used = self::envUsed(); $ex = self::envExample(); $bad = [];
        foreach ($used as $k => $hasDefault) { if (!$hasDefault && !in_array($k, $ex, true)) { $bad[] = "$k is read without a default but missing from .env.example"; } }
        foreach ($ex as $k) { if (!isset($used[$k])) { $bad[] = "$k is in .env.example but nothing reads it (obsolete)"; } }
        $this->assertNone($bad, '.env.example drift:');
    }

    public function test_keys_with_defaults_missing_from_env_example_are_documented_in_deployment_md(): void
    {
        $doc = (string) file_get_contents(self::root().'/docs/deployment.md'); $ex = self::envExample(); $bad = [];
        foreach (self::envUsed() as $k => $hasDefault) { if (!in_array($k, $ex, true) && !str_contains($doc, $k)) { $bad[] = "$k not in .env.example nor docs/deployment.md"; } }
        $this->assertNone($bad, 'Undocumented env keys:');
    }

    public function test_deployment_doc_only_mentions_env_keys_commands_and_files_that_exist(): void
    {
        $doc = (string) file_get_contents(self::root().'/docs/deployment.md'); $bad = [];
        $known = array_merge(array_keys(self::envUsed()), self::envExample(), ['GAP', 'NOT_NULL']);
        if (preg_match_all('/`([A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+)`/', $doc, $m)) { foreach (array_unique($m[1]) as $k) { if (!in_array($k, $known, true)) { $bad[] = "env key $k in docs is not read by code"; } } }
        $sigs = [];
        foreach (self::files(['app/Console/Commands'], '.php') as $f) { if (preg_match('/\$signature\s*=\s*[\'"]([^\s\'"]+)/', (string) file_get_contents($f), $x)) { $sigs[] = $x[1]; } }
        $builtin = ['optimize', 'optimize:clear', 'event:cache', 'config:clear', 'key:generate', 'migrate', 'migrate:status', 'db:seed', 'config:cache', 'route:cache', 'view:cache', 'route:list', 'queue:work', 'queue:failed', 'schedule:run', 'down', 'up', 'package:discover', 'storage:link'];
        if (preg_match_all('/php artisan\s+([a-z][\w:\-]*)/', $doc, $m)) { foreach (array_unique($m[1]) as $c) { if (!in_array($c, $sigs, true) && !in_array($c, $builtin, true)) { $bad[] = "artisan $c in docs does not exist"; } } }
        if (preg_match_all('/`((?:[\w.\-]+\/)+[\w.\-]+\.(?:php|json|md|sh))`/', $doc, $m)) { foreach (array_unique($m[1]) as $p) { if (!file_exists(self::root().'/'.$p) && !str_starts_with($p, 'storage/') && !str_contains($p, 'dhiran-')) { $bad[] = "file $p in docs does not exist"; } } }
        foreach (['PlanSeeder', 'DemoSeeder'] as $s) { if (str_contains($doc, $s)) { $this->assertFileExists(self::root()."/database/seeders/$s.php"); } }
        $this->assertNone($bad, 'docs/deployment.md drift:');
    }

    public function test_BUG_deployment_doc_cron_list_matches_scheduled_commands(): void
    {
        $doc = (string) file_get_contents(self::root().'/docs/deployment.md'); $bad = [];
        foreach (self::grab(self::php(self::root().'/routes/console.php'), '/Schedule::command\(\s*[\'"]([^\s\'"]+)/') as [$c]) { if (!str_contains($doc, $c)) { $bad[] = "$c is scheduled but not listed in docs/deployment.md"; } }
        $this->assertNone($bad, 'Cron docs drift:');
    }
}
