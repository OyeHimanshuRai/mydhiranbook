<?php
namespace Tests\Feature\Ruthless;

use App\Http\Middleware\EnsureDhiranEnabled;
use App\Models\Customer;
use App\Models\Dhiran\{DhiranAttachment, DhiranLoan, DhiranLoanItem, DhiranSettings};
use App\Models\Permission;
use App\Models\Platform\{Plan, PlatformAdmin, PlatformAuditLog, PlatformInvoice, ShopSubscription, SubscriptionEvent};
use App\Models\{Role, Shop, User};
use App\Services\Dhiran\{DhiranAttachmentService, DhiranOldLoans};
use App\Services\{DhiranService, TenantRoleService};
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, Password, Route, Storage};
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Feature\Admin\AdminTestCase;
use Tests\Support\OnboardingFixtures;

/**
 * Adversarial access-control matrix, built from the router so a new route is covered automatically.
 * Real bugs found live in their own methods tagged #[Group('ruthless-bug')] and are meant to FAIL until fixed.
 */
class AccessMatrixTest extends AdminTestCase
{
    use OnboardingFixtures;

    private const PW = 'Owner-pass-12345';
    private const MARKER = 'Zorblax';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ───────────────────────── fixtures ─────────────────────────

    private function role(Shop $s, string $name, ?array $perms): Role
    {
        return TenantContext::runFor($s->id, function () use ($name, $perms) {
            $r = Role::create(['name' => $name, 'display_name' => ucfirst($name)]);
            $r->permissions()->sync($perms === null ? Permission::pluck('id') : Permission::whereIn('name', $perms)->pluck('id'));
            return $r;
        });
    }

    /** Shop A with a full object graph, shop B with an owner, platform fixtures. */
    private function world(): array
    {
        $a = self::createDhiranShop(['name' => 'Ruthless Shop A']);
        $b = self::createDhiranShop(['name' => 'Ruthless Shop B']);
        foreach ([$a, $b] as $s) {
            TenantContext::runFor($s->id, fn () => DhiranSettings::getForShop($s->id)->update(['is_enabled' => true]));
        }
        $n = 100;
        $mk = function (Shop $s, string $role, string $tag) use (&$n) {
            $n++;
            return self::userWithRole($s, ['name' => "$tag $role", 'email' => "$tag-$role@ruthless.test", 'mobile_number' => '9000000' . $n, 'password' => self::PW, 'email_verified_at' => now()], $role);
        };
        $ownerA = $mk($a, 'owner', 'a');
        $staffA = $mk($a, 'staff', 'a');
        $viewerA = $mk($a, 'viewer', 'a');
        $ownerB = $mk($b, 'owner', 'b');
        $roles = app(TenantRoleService::class)->ensureDefaultsForShop($a->id);

        $reader = $this->role($a, 'reader', ['dhiran.view', 'staff.view']);
        $special = $this->role($a, 'special', null);
        $make = function (Role $r, string $tag) use ($a, &$n) { return User::create(['name' => $tag, 'email' => "$tag@ruthless.test", 'mobile_number' => '9000000' . ++$n, 'password' => self::PW, 'shop_id' => $a->id, 'role_id' => $r->id, 'email_verified_at' => now()]); };
        $readerA = $make($reader, 'reader');
        $delegateA = $make($special, 'delegate');

        $svc = app(DhiranService::class);
        $item = [['description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000]];
        [$cust, $loan, $oldLoan, $pay, $att, $itemRow] = TenantContext::runFor($a->id, function () use ($a, $svc, $item, $ownerA) {
            $c = Customer::create(['shop_id' => $a->id, 'first_name' => self::MARKER, 'last_name' => 'Marker', 'mobile' => '9810000001']);
            $l = $svc->createLoan($a, $c, $item, ['principal_amount' => 100000, 'gold_rate_on_date' => 6000, 'created_by' => $ownerA->id, 'status' => 'active', 'loan_date' => today()->subDays(90)->toDateString()]);
            $p = $svc->recordPayment($l->refresh(), 500, 'cash');
            $old = $svc->createLoan($a, $c, $item, ['brought_forward' => true, 'status' => 'active', 'loan_number' => 'OLD-1', 'loan_date' => today()->subDays(100)->toDateString(),
                'principal_amount' => 50000, 'principal_owed' => 40000, 'interest_paid_until' => today()->subDays(20)->toDateString(), 'interest_rate_monthly' => 2, 'interest_type' => 'flat', 'tenure_months' => 12, 'gold_rate_on_date' => 6000, 'created_by' => $ownerA->id]);
            $at = app(DhiranAttachmentService::class)->store(UploadedFile::fake()->image('id.jpg', 200, 200), $a->id, DhiranAttachment::OWNER_LOAN, $l->id, 'id_proof_front', $ownerA->id);
            return [$c, $l, $old, $p, $at, DhiranLoanItem::where('dhiran_loan_id', $l->id)->firstOrFail()];
        });

        $sub = $a->currentSubscription();
        $inv = PlatformInvoice::create(['shop_id' => $a->id, 'shop_subscription_id' => $sub->id, 'plan_id' => $sub->plan_id, 'invoice_number' => 'DHR-2026-000777', 'invoice_sequence' => 777, 'billing_cycle' => 'yearly', 'billing_period_start' => today(), 'billing_period_end' => today()->addYear(), 'amount_before_tax' => 19999, 'total_amount' => 19999, 'issued_at' => now()]);
        $event = SubscriptionEvent::create(['shop_subscription_id' => $sub->id, 'shop_id' => $a->id, 'event_type' => 'payment.unresolved', 'reason' => 'ruthless']);
        $token = (string) \Illuminate\Support\Str::uuid();
        Storage::disk('local')->put("dhiran-old-loans/{$a->id}/{$token}.csv", DhiranOldLoans::template());

        // shop B own loan (for cross-shop mixing)
        [$custB, $loanB] = TenantContext::runFor($b->id, function () use ($b, $svc, $item, $ownerB) {
            $c = Customer::create(['shop_id' => $b->id, 'first_name' => 'Bee', 'last_name' => 'Borrower', 'mobile' => '9810000002']);
            return [$c, $svc->createLoan($b, $c, $item, ['principal_amount' => 1000, 'gold_rate_on_date' => 6000, 'created_by' => $ownerB->id, 'status' => 'active'])];
        });

        $this->admin();
        $targetAdmin = $this->admin(['email' => 'target-admin@example.test', 'role' => 'support', 'permissions' => []]);

        return compact('a', 'b', 'ownerA', 'staffA', 'viewerA', 'ownerB', 'readerA', 'delegateA', 'roles', 'loan', 'cust', 'loanB', 'custB', 'sub') + ['ids' => [
            'loan' => $loan->id, 'payment' => $pay->id, 'customer' => $cust->id, 'item' => $itemRow->id, 'attachment' => $att->id, 'token' => $token, 'doc' => 'pledge',
            'staff' => $staffA->id, 'role' => $roles['staff']->id, 'invoice' => $inv->id, 'shop' => $a->id, 'plan' => Plan::orderBy('id')->value('id'),
            'admin' => $targetAdmin->id, 'event' => $event->id,
        ], 'oldLoan' => $oldLoan, 'att' => $att];
    }

    /** Put the test client into one of the named user states. */
    private function enter(string $state, array $w): void
    {
        $sub = ShopSubscription::where('shop_id', $w['a']->id);
        match ($state) {
            'guest' => null,
            'unverified' => $this->actingAs($this->newOwner(['email_verified_at' => null])),
            'verified-shopless' => $this->actingAs($this->newOwner()),
            'paid-shopless' => $this->actingAs(tap($this->newOwner(), fn ($u) => $this->paidShopless($u))),
            'owner-active' => $this->actingAs($w['ownerA']),
            'owner-grace' => tap($this->actingAs($w['ownerA']), fn () => $sub->update(['status' => 'grace', 'ends_at' => today()->subDays(2), 'grace_ends_at' => today()->addDays(3)])),
            'owner-expired' => tap($this->actingAs($w['ownerA']), fn () => $sub->update(['status' => 'expired', 'ends_at' => today()->subDays(40), 'grace_ends_at' => today()->subDays(30)])),
            'owner-suspended' => tap($this->actingAs($w['ownerA']), fn () => $w['a']->update(['status' => 'suspended'])),
            'staff-minimal' => $this->actingAs($w['viewerA']),
            'deactivated-staff' => tap($w['staffA'], fn ($u) => $u->forceFill(['is_active' => false])->save()) && $this->actingAs($w['staffA']->fresh()),
            'deactivated-owner' => tap($w['ownerA'], fn ($u) => $u->forceFill(['is_active' => false])->save()) && $this->actingAs($w['ownerA']->fresh()),
            'admin-pre-mfa' => $this->post('/admin/login', ['email' => 'admin@example.test', 'password' => 'Admin-pass-12345'])->assertRedirect('/admin/mfa'),
            'admin-post-mfa' => $this->signIn(PlatformAdmin::where('email', 'admin@example.test')->firstOrFail()),
        };
    }

    // ───────────────────────── router helpers ─────────────────────────

    /** @return list<array{methods:list<string>,uri:string,name:?string,mw:list<string>,params:list<string>}> */
    private function routes(): array
    {
        $out = [];
        foreach (Route::getRoutes()->getRoutes() as $r) {
            $uri = $r->uri();
            if ($uri === 'up' || str_starts_with($uri, 'storage')) { continue; }
            $out[] = ['methods' => array_values(array_diff($r->methods(), ['HEAD'])), 'uri' => $uri, 'name' => $r->getName(),
                'mw' => array_values(array_filter($r->gatherMiddleware(), 'is_string')), 'params' => $r->parameterNames()];
        }
        return $out;
    }

    private function url(array $route, array $ids): string
    {
        return preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => (string) ($ids[$m[1]] ?? throw new \LogicException("No fixture for route parameter {$m[1]} ({$route['uri']}): extend world().")), '/' . $route['uri']);
    }

    private function mwHas(array $route, string $prefix): bool
    {
        return (bool) array_filter($route['mw'], fn ($m) => $m === $prefix || str_starts_with($m, $prefix . ':'));
    }

    private function isShopApp(array $r): bool { return $this->mwHas($r, 'auth') || $this->mwHas($r, 'shop'); }
    private function isAdminProtected(array $r): bool { return $this->mwHas($r, 'admin.session'); }
    private function writes(array $r): array { return array_values(array_diff($r['methods'], ['GET'])); }

    private function pathOf(?string $location): string
    {
        $p = parse_url((string) $location);
        return ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    }

    /** Next request = new HTTP request: drop the cached guard user so DB changes are seen, keep the session. */
    private function fresh(): void
    {
        $this->app['auth']->forgetGuards(); $this->app['auth']->shouldUse('web'); // a real request starts on the default guard
        TenantContext::clear();
    }

    /** @return array{0:int|string,1:list<string>} final status (or 'loop'/'too-many-hops') and the visited trail */
    private function follow(string $url): array
    {
        $seen = [];
        for ($hop = 0; $hop <= 10; $hop++) {
            if (isset($seen[$url])) { return ['loop', array_keys($seen)]; }
            $seen[$url] = true;
            $this->app['auth']->shouldUse('web');
            $res = $this->get($url);
            if (! $res->isRedirect()) { return [$res->getStatusCode(), array_keys($seen)]; }
            $url = $this->pathOf($res->headers->get('Location'));
        }
        return ['too-many-hops', array_keys($seen)];
    }

    private function snapshot(int $shopId): array
    {
        $tables = array_column(DB::select("select table_name from information_schema.columns where column_name='shop_id' and table_schema=current_schema() order by 1"), 'table_name');
        $s = [];
        foreach ($tables as $t) {
            $s[$t] = DB::selectOne("select coalesce(jsonb_agg(to_jsonb(x) order by to_jsonb(x)::text),'[]'::jsonb) j from \"$t\" x where shop_id=?", [$shopId])->j;
        }
        $s['shops'] = DB::selectOne("select coalesce(jsonb_agg(to_jsonb(x)),'[]'::jsonb) j from shops x where id=?", [$shopId])->j;
        $s['role_permission'] = DB::selectOne("select coalesce(jsonb_agg(to_jsonb(x) order by to_jsonb(x)::text),'[]'::jsonb) j from role_permission x where role_id in (select id from roles where shop_id=?)", [$shopId])->j;
        $files = Storage::disk('local')->allFiles(); sort($files);
        $s['files'] = $files;
        return $s;
    }

    private function payload(array $w): array
    {
        return ['amount' => 100, 'payment_amount' => 100, 'payment_method' => 'cash', 'method' => 'cash', 'reason' => 'ruthless', 'notes' => 'x', 'item_id' => $w['ids']['item'],
            'tenure_months' => 6, 'permissions' => [], 'name' => 'Hacked', 'mobile_number' => '9000000777', 'role_id' => $w['ids']['role'],
            'password' => 'Hacked-Pass-12345', 'password_confirmation' => 'Hacked-Pass-12345', 'photo' => UploadedFile::fake()->image('p.jpg')];
    }

    private function send(string $method, string $url, array $data = []): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->shouldUse('web'); // a real request starts on the default guard
        return $method === 'GET' ? $this->get($url) : $this->{strtolower($method)}($url, $data);
    }

    private function leaks(\Illuminate\Testing\TestResponse $res): bool
    {
        return ! $res->baseResponse instanceof StreamedResponse && str_contains((string) $res->getContent(), self::MARKER);
    }

    // ───────────────────────── 1. redirect-loop sweep ─────────────────────────

    public static function states(): array
    {
        return array_combine($s = ['guest', 'unverified', 'verified-shopless', 'paid-shopless', 'owner-active', 'owner-grace', 'owner-expired', 'owner-suspended', 'staff-minimal', 'deactivated-staff', 'deactivated-owner', 'admin-pre-mfa', 'admin-post-mfa'], array_map(fn ($x) => [$x], $s));
    }

    #[DataProvider('states')]
    public function test_every_get_route_ends_without_loop_or_server_error(string $state): void
    {
        $w = $this->world();
        $this->enter($state, $w);
        $bad = [];
        $covered = 0;
        foreach ($this->routes() as $r) {
            if (! in_array('GET', $r['methods'])) { continue; }
            $url = $this->url($r, $w['ids']);
            [$status, $trail] = $this->follow($url);
            $covered++;
            if (! is_int($status) || $status >= 500) { $bad[] = "$state GET $url => $status via " . implode(' > ', $trail); }
        }
        $this->assertGreaterThanOrEqual(50, $covered, 'router sweep covered too few GET routes');
        $this->assertSame([], $bad);
    }

    public static function landings(): array
    {
        return [
            'guest dhiran -> login' => ['guest', '/dhiran', '/login', 200],
            'guest admin -> admin login' => ['guest', '/admin', '/admin/login', 200],
            'unverified dhiran -> verify' => ['unverified', '/dhiran', '/verify-email', 200],
            'unverified plans -> verify' => ['unverified', '/onboarding/plans', '/verify-email', 200],
            'shopless dhiran -> business' => ['verified-shopless', '/dhiran', '/onboarding/business', 200],
            'shopless plans -> business' => ['verified-shopless', '/onboarding/plans', '/onboarding/business', 200],
            'paid dhiran -> business' => ['paid-shopless', '/dhiran', '/onboarding/business', 200],
            'paid plans -> business' => ['paid-shopless', '/onboarding/plans', '/onboarding/business', 200],
            'owner root -> loans' => ['owner-active', '/', '/dhiran/loans', 200],
            'owner onboarding bounced' => ['owner-active', '/onboarding/business', '/dhiran/loans', 200],
            'grace keeps new loans' => ['owner-grace', '/dhiran/create', '/dhiran/create', 200],
            'expired new loan page -> loans' => ['owner-expired', '/dhiran/create', '/dhiran/loans', 200],
            'expired books readable' => ['owner-expired', '/dhiran/loans', '/dhiran/loans', 200],
            'suspended shop blocked' => ['owner-suspended', '/dhiran', '/dhiran', 403],
            'minimal staff cannot create' => ['staff-minimal', '/dhiran/create', '/dhiran/create', 403],
            'minimal staff cannot see staff list' => ['staff-minimal', '/settings', '/settings', 403],
            'deactivated staff blocked' => ['deactivated-staff', '/dhiran', '/dhiran', 403],
            'deactivated owner blocked' => ['deactivated-owner', '/dhiran/loans', '/dhiran/loans', 403],
            'admin pre-mfa -> mfa' => ['admin-pre-mfa', '/admin', '/admin/mfa', 200],
            'admin pre-mfa shops -> mfa' => ['admin-pre-mfa', '/admin/shops', '/admin/mfa', 200],
            'admin post-mfa dashboard' => ['admin-post-mfa', '/admin', '/admin', 200],
        ];
    }

    #[DataProvider('landings')]
    public function test_state_lands_where_the_product_says(string $state, string $start, string $final, int $status): void
    {
        $w = $this->world();
        $this->enter($state, $w);
        [$got, $trail] = $this->follow($start);
        $this->assertSame($status, $got, implode(' > ', $trail));
        $this->assertSame($final, end($trail), implode(' > ', $trail));
    }

    // ───────────────────────── 2. tenant sweep ─────────────────────────

    public static function foreignActors(): array
    {
        return ['shop B owner' => ['owner-b'], 'paid shopless user' => ['paid-shopless'], 'shop B owner expired plan' => ['owner-b-expired']];
    }

    private function enterForeign(string $who, array $w): void
    {
        match ($who) {
            'owner-b' => $this->actingAs($w['ownerB']),
            'owner-b-expired' => tap($this->actingAs($w['ownerB']), fn () => ShopSubscription::where('shop_id', $w['b']->id)->update(['status' => 'expired', 'ends_at' => today()->subDays(40), 'grace_ends_at' => today()->subDays(30)])),
            default => $this->enter($who, $w),
        };
    }

    #[DataProvider('foreignActors')]
    public function test_foreign_user_cannot_read_or_write_shop_a_objects_by_id(string $who): void
    {
        $w = $this->world();
        $before = $this->snapshot($w['a']->id);
        $this->enterForeign($who, $w);
        $bad = [];
        $tried = 0;
        foreach ($this->routes() as $r) {
            if (! $this->isShopApp($r) || ! $r['params'] || $this->isAdminProtected($r)) { continue; }
            if ($r['params'] === ['doc']) { continue; } // the made-up sample documents: no object id, always the caller's own shop (SampleDocumentsTest)
            $url = $this->url($r, $w['ids']);
            foreach ($r['methods'] as $m) {
                $res = $this->send($m, $url, $this->payload($w));
                $tried++;
                $s = $res->getStatusCode();
                if (($s >= 200 && $s < 300) || $s >= 500 || $this->leaks($res)) { $bad[] = "$who $m $url => $s" . ($this->leaks($res) ? ' LEAKS shop A data' : ''); }
            }
        }
        $this->assertGreaterThanOrEqual(25, $tried);
        $this->assertSame([], $bad);
        $this->assertSame($before, $this->snapshot($w['a']->id), 'shop A rows/files changed by a foreign user');
    }

    public function test_shop_b_cannot_mix_its_own_loan_with_shop_a_payment_or_hit_shop_a_ids_in_bodies(): void
    {
        $w = $this->world();
        $before = $this->snapshot($w['a']->id);
        $this->actingAs($w['ownerB']);
        $ids = $w['ids'];
        $this->get("/dhiran/loans/{$w['loanB']->id}/payments/{$ids['payment']}/receipt")->assertNotFound();
        $this->post('/dhiran/attachments', ['owner_type' => 'dhiran_loan', 'owner_id' => $ids['loan'], 'document_type' => 'id_proof_front', 'file' => UploadedFile::fake()->image('x.jpg')])->assertNotFound();
        $this->post('/dhiran/attachments', ['owner_type' => 'dhiran_loan_item', 'owner_id' => $ids['item'], 'document_type' => 'item_photo', 'file' => UploadedFile::fake()->image('x.jpg')])->assertNotFound();
        $this->post('/dhiran/attachments', ['owner_type' => 'customer', 'owner_id' => $ids['customer'], 'document_type' => 'id_proof_front', 'file' => UploadedFile::fake()->image('x.jpg')])->assertNotFound();
        $res = $this->post('/dhiran', ['customer_id' => $ids['customer'], 'principal_amount' => 1000, 'gold_rate_on_date' => 6000, 'items' => [['description' => 'Ring', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 5, 'rate_per_gram_at_pledge' => 6000]]]);
        $this->assertNotSame(201, $res->getStatusCode());
        $this->assertSame(0, DB::table('dhiran_loans')->where('customer_id', $ids['customer'])->where('shop_id', $w['b']->id)->count(), 'B opened a loan against A\'s customer');
        // inline borrower creation with A's mobile must not reveal or adopt A's customer
        $res = $this->post('/dhiran/customers', ['first_name' => 'Spy', 'mobile' => '9810000001'], ['Accept' => 'application/json']);
        $this->assertStringNotContainsString(self::MARKER, (string) $res->getContent());
        $this->assertSame($before, $this->snapshot($w['a']->id));
    }

    public function test_a_loan_cannot_be_read_through_another_loans_payment_inside_one_shop(): void
    {
        $w = $this->world();
        $this->actingAs($w['ownerA']);
        $this->get("/dhiran/loans/{$w['oldLoan']->id}/payments/{$w['ids']['payment']}/receipt")->assertNotFound();
    }

    // ───────────────────────── 3. permission sweep ─────────────────────────

    public function test_dhiran_view_only_staff_gets_403_on_every_write_route_of_the_shop_app(): void
    {
        $w = $this->world();
        $before = $this->snapshot($w['a']->id);
        $this->actingAs($w['viewerA']);
        $bad = []; $tried = 0;
        foreach ($this->routes() as $r) {
            $isShop = $this->mwHas($r, 'shop') || str_starts_with($r['uri'], 'subscription/');
            // notices/seen only records the caller's own "I saw today's card" (a per-user date, no shop data): any viewer may send it.
            if (! $isShop || in_array($r['uri'], ['subscription/payment/webhook', 'dhiran/notices/seen'], true)) { continue; }
            foreach ($this->writes($r) as $m) {
                $res = $this->send($m, $this->url($r, $w['ids']), $this->payload($w));
                $tried++;
                if ($res->getStatusCode() !== 403) { $bad[] = "$m {$r['uri']} => {$res->getStatusCode()}"; }
            }
        }
        $this->assertGreaterThan(25, $tried);
        $this->assertSame([], $bad);
        $this->assertSame($before, $this->snapshot($w['a']->id));
    }

    public function test_dhiran_view_only_staff_gets_403_on_every_get_route_that_needs_another_permission(): void
    {
        $w = $this->world();
        $this->actingAs($w['viewerA']);
        $bad = [];
        foreach ($this->routes() as $r) {
            $need = array_values(array_filter(array_map(fn ($m) => str_starts_with($m, 'can:') ? substr($m, 4) : null, $r['mw'])));
            if (! in_array('GET', $r['methods']) || ! $need || in_array('dhiran.view', $need)) { continue; }
            $s = $this->get($this->url($r, $w['ids']))->getStatusCode();
            if ($s !== 403) { $bad[] = "GET {$r['uri']} needs " . implode(',', $need) . " => $s"; }
        }
        $this->assertSame([], $bad);
    }

    public function test_role_without_staff_manage_or_settings_edit_gets_403_on_staff_and_role_routes(): void
    {
        $w = $this->world();
        $before = $this->snapshot($w['a']->id);
        $this->actingAs($w['readerA']);
        $this->get('/settings?tab=staff')->assertOk(); // staff.view alone may look
        $this->get('/settings?tab=roles')->assertForbidden();
        $bad = []; $tried = 0;
        foreach ($this->routes() as $r) {
            $need = array_values(array_filter(array_map(fn ($m) => str_starts_with($m, 'can:') ? substr($m, 4) : null, $r['mw'])));
            if (! array_intersect($need, ['staff.manage', 'settings.edit'])) { continue; }
            foreach ($r['methods'] as $m) {
                $s = $this->send($m, $this->url($r, $w['ids']), $this->payload($w))->getStatusCode(); $tried++;
                if ($s !== 403) { $bad[] = "$m {$r['uri']} => $s"; }
            }
        }
        $this->assertGreaterThan(6, $tried);
        $this->assertSame([], $bad);
        $this->assertSame($before, $this->snapshot($w['a']->id));
    }

    public function test_delegate_with_every_permission_cannot_touch_the_owner_role_or_owner_account(): void
    {
        $w = $this->world();
        $ownerRole = $w['roles']['owner'];
        $permsBefore = $ownerRole->permissions()->pluck('permissions.id')->sort()->values()->all();
        $this->actingAs($w['delegateA']);
        $this->patch("/settings/roles/{$ownerRole->id}", ['permissions' => []]);
        $this->assertSame($permsBefore, $ownerRole->permissions()->pluck('permissions.id')->sort()->values()->all(), 'owner role permissions changed');
        $this->put("/staff/{$w['ownerA']->id}", ['name' => 'Pwn', 'mobile_number' => '9000000888', 'role_id' => $w['roles']['staff']->id])->assertForbidden();
        $this->delete("/staff/{$w['ownerA']->id}")->assertForbidden();
        $this->get("/staff/{$w['ownerA']->id}/edit")->assertForbidden();
        $this->assertTrue($w['ownerA']->fresh()->is_active);
        // cannot hand a staff member the owner role
        $this->put("/staff/{$w['staffA']->id}", ['name' => 'S', 'mobile_number' => '9000000889', 'role_id' => $ownerRole->id])->assertSessionHasErrors('role_id');
        $this->assertNotSame($ownerRole->id, $w['staffA']->fresh()->role_id);
    }

    public function test_staff_manager_cannot_create_staff_with_a_role_stronger_than_their_own(): void
    {
        $w = $this->world();
        $cashierRole = $w['roles']['cashier'];
        $cashierRole->permissions()->syncWithoutDetaching(Permission::whereIn('name', ['staff.manage', 'staff.view'])->pluck('id'));
        $cashier = self::userWithRole($w['a'], ['name' => 'C', 'email' => 'cashier-x@ruthless.test', 'mobile_number' => '9000000891', 'password' => self::PW, 'email_verified_at' => now()], 'cashier');
        $this->actingAs($cashier);
        $this->post('/staff', ['name' => 'Esc', 'mobile_number' => '9000000892', 'password' => 'Esc-Pass-12345x', 'password_confirmation' => 'Esc-Pass-12345x', 'role_id' => $w['roles']['manager']->id])->assertForbidden();
        $this->assertNull(User::where('mobile_number', '9000000892')->first());
    }

    // ───────────────────────── collect-only (expired) ─────────────────────────

    public function test_expired_plan_blocks_every_new_business_write_and_page_but_not_settlement(): void
    {
        $w = $this->world();
        $this->enter('owner-expired', $w);
        $collect = (new \ReflectionClassConstant(EnsureDhiranEnabled::class, 'COLLECT_ONLY_WRITES'))->getValue();
        $newPages = (new \ReflectionClassConstant(EnsureDhiranEnabled::class, 'NEW_BUSINESS_PAGES'))->getValue();
        $before = $this->snapshot($w['a']->id);
        $bad = []; $n = 0;
        foreach ($this->routes() as $r) {
            if (! $this->mwHas($r, 'dhiran.enabled')) { continue; }
            foreach ($r['methods'] as $m) {
                $blocked = $m === 'GET' ? in_array($r['name'], $newPages, true) : ! in_array($r['name'], $collect, true);
                if (! $blocked) { continue; }
                $res = $this->send($m, $this->url($r, $w['ids']), $this->payload($w)); $n++;
                if (! $res->isRedirect() || $this->pathOf($res->headers->get('Location')) !== '/dhiran') { $bad[] = "$m {$r['uri']} => {$res->getStatusCode()}"; }
            }
        }
        $this->assertGreaterThan(8, $n);
        $this->assertSame([], $bad);
        $this->assertSame($before, $this->snapshot($w['a']->id), 'expired plan still let a new-business write change data');
    }

    // ───────────────────────── 4. admin sweep ─────────────────────────

    /** "METHOD uri" => permission flag (null = any signed-in admin). New admin routes must be classified here. */
    private const ADMIN_FLAGS = [
        'GET admin' => null,
        'GET admin/audit' => 'can_view_audit_logs',
        'GET admin/requests' => 'can_manage_shops',
        'POST admin/requests/{shop}/approve' => 'can_manage_shops',
        'POST admin/requests/{shop}/reject' => 'can_manage_shops',
        'GET admin/shops' => 'can_manage_shops',
        'GET admin/shops/{shop}' => 'can_manage_shops',
        'PATCH admin/shops/{shop}' => 'can_suspend_shop',
        'POST admin/shops/{shop}/subscription' => 'can_manage_subscriptions',
        'POST admin/shops/{shop}/subscription/terminate' => 'can_manage_subscriptions',
        'GET admin/receipts/{invoice}' => 'can_manage_subscriptions',
        'GET admin/plans' => 'can_manage_plans',
        'PATCH admin/plans/{plan}' => 'can_manage_plans',
        'GET admin/unresolved-payments' => 'can_manage_subscriptions',
        'POST admin/unresolved-payments/{event}/resolve' => 'can_manage_subscriptions',
        'GET admin/accounts' => 'can_manage_admins',
        'POST admin/accounts' => 'can_manage_admins',
        'PATCH admin/accounts/{admin}' => 'can_manage_admins',
        'GET admin/mfa' => 'challenge',
        'POST admin/mfa' => 'challenge',
        'POST admin/logout' => 'challenge',
    ];

    private function adminRoutes(): array
    {
        return array_values(array_filter($this->routes(), fn ($r) => $this->isAdminProtected($r)));
    }

    private function adminPayload(string $key, array $w): array
    {
        return match ($key) {
            'POST admin/requests/{shop}/approve' => ['plan' => 'dhiran_monthly', 'billing_cycle' => 'monthly', 'ends_at' => today()->addMonth()->toDateString()],
            'POST admin/requests/{shop}/reject' => ['reason' => 'ruthless audit'],
            'PATCH admin/shops/{shop}' => ['status' => 'suspended', 'reason' => 'ruthless audit'],
            'POST admin/shops/{shop}/subscription' => ['plan' => 'dhiran_monthly', 'billing_cycle' => 'monthly', 'ends_at' => today()->addDays(400)->toDateString(), 'reason' => 'ruthless audit'],
            'POST admin/shops/{shop}/subscription/terminate' => ['reason' => 'ruthless audit'],
            'PATCH admin/plans/{plan}' => ['price_monthly' => 1852, 'price_yearly' => 19999, 'grace_days' => 7, 'staff_limit' => 3, 'max_items' => 1000, 'is_active' => 1, 'reason' => 'ruthless audit'],
            'POST admin/unresolved-payments/{event}/resolve' => ['reason' => 'ruthless audit'],
            'POST admin/accounts' => ['name' => 'New Admin', 'email' => 'new-admin@example.test', 'password' => 'Admin-Created-12345', 'permissions' => []],
            'PATCH admin/accounts/{admin}' => ['is_active' => 1, 'permissions' => ['can_manage_shops']],
            default => [],
        };
    }

    public function test_every_admin_route_is_classified_by_permission_flag(): void
    {
        $missing = [];
        foreach ($this->adminRoutes() as $r) {
            foreach ($r['methods'] as $m) { if (! array_key_exists("$m {$r['uri']}", self::ADMIN_FLAGS)) { $missing[] = "$m {$r['uri']}"; } }
        }
        $this->assertSame([], $missing, 'classify new admin routes in ADMIN_FLAGS');
    }

    public static function nonAdminSessions(): array
    {
        return ['guest' => ['guest'], 'shop owner' => ['owner-active'], 'minimal staff' => ['staff-minimal'], 'deactivated owner' => ['deactivated-owner']];
    }

    #[DataProvider('nonAdminSessions')]
    public function test_every_admin_route_sends_non_admins_to_admin_login_without_side_effects(string $state): void
    {
        $w = $this->world();
        $this->enter($state, $w);
        $shop = $w['a']->fresh()->only('status');
        $audits = PlatformAuditLog::count(); $admins = PlatformAdmin::count();
        $bad = [];
        foreach ($this->adminRoutes() as $r) {
            foreach ($r['methods'] as $m) {
                $res = $this->send($m, $this->url($r, $w['ids']), $this->adminPayload("$m {$r['uri']}", $w));
                if (! $res->isRedirect() || $this->pathOf($res->headers->get('Location')) !== '/admin/login') { $bad[] = "$state $m {$r['uri']} => {$res->getStatusCode()} " . $res->headers->get('Location'); }
            }
        }
        $this->assertSame([], $bad);
        $this->assertSame($shop, $w['a']->fresh()->only('status'));
        $this->assertSame($admins, PlatformAdmin::count());
        $this->assertSame($audits, PlatformAuditLog::count());
    }

    public static function adminFlags(): array
    {
        $flags = array_values(array_unique(array_filter(array_values(self::ADMIN_FLAGS), fn ($f) => $f && $f !== 'challenge')));
        return array_combine($flags, array_map(fn ($f) => [$f], $flags));
    }

    #[DataProvider('adminFlags')]
    public function test_admin_missing_one_flag_gets_403_on_exactly_the_routes_needing_it_and_the_denial_is_audited(string $flag): void
    {
        $w = $this->world();
        $this->admin(['email' => 'super@example.test']);
        $this->signIn($this->admin(['email' => 'limited@example.test', 'role' => 'support', 'permissions' => array_values(array_diff(PlatformAdmin::PERMISSIONS, [$flag]))]));
        $shop = $w['a']->fresh()->only('status'); $plan = Plan::find($w['ids']['plan'])->only('price_monthly');
        $denied = 0; $bad = [];
        foreach ($this->adminRoutes() as $r) {
            foreach ($r['methods'] as $m) {
                $key = "$m {$r['uri']}";
                if ((self::ADMIN_FLAGS[$key] ?? null) !== $flag) { continue; }
                $res = $this->send($m, $this->url($r, $w['ids']), $this->adminPayload($key, $w));
                if ($res->getStatusCode() !== 403) { $bad[] = "$key => {$res->getStatusCode()}"; }
                $denied++;
            }
        }
        $this->assertGreaterThan(0, $denied);
        $this->assertSame([], $bad);
        $this->assertGreaterThanOrEqual($denied, PlatformAuditLog::where('action', 'admin.permission_denied')->where('reason', $flag)->count(), 'denials must be audited');
        $this->assertSame($shop, $w['a']->fresh()->only('status'));
        $this->assertSame($plan, Plan::find($w['ids']['plan'])->only('price_monthly'));
    }

    #[DataProvider('adminFlags')]
    public function test_admin_holding_only_one_flag_reaches_its_routes_and_nothing_else(string $flag): void
    {
        $w = $this->world();
        $this->admin(['email' => 'super@example.test']);
        $this->signIn($this->admin(['email' => 'only@example.test', 'role' => 'support', 'permissions' => [$flag]]));
        $bad = [];
        foreach ($this->adminRoutes() as $r) {
            foreach ($r['methods'] as $m) {
                $key = "$m {$r['uri']}"; $need = self::ADMIN_FLAGS[$key] ?? null;
                if ($need === 'challenge') { continue; }
                if ($m !== 'GET' && $need !== $flag) { // do not mutate through allowed routes; only probe the forbidden ones
                    $s = $this->send($m, $this->url($r, $w['ids']), $this->adminPayload($key, $w))->getStatusCode();
                    if ($need !== null && $s !== 403) { $bad[] = "$key => $s (should be 403)"; }
                    continue;
                }
                if ($m !== 'GET') { continue; }
                $s = $this->get($this->url($r, $w['ids']))->getStatusCode();
                $expectOk = $need === null || $need === $flag;
                if ($expectOk ? $s >= 400 : $s !== 403) { $bad[] = "$key => $s"; }
            }
        }
        $this->assertSame([], $bad);
    }

    public function test_every_admin_write_by_a_super_admin_leaves_an_audit_row(): void
    {
        $w = $this->world();
        $this->signIn(PlatformAdmin::where('email', 'admin@example.test')->firstOrFail());
        $ids = ['shop' => $w['b']->id] + $w['ids'];
        $bad = []; $n = 0;
        foreach ($this->adminRoutes() as $r) {
            foreach ($this->writes($r) as $m) {
                $key = "$m {$r['uri']}";
                if (self::ADMIN_FLAGS[$key] === 'challenge') { continue; }
                $count = PlatformAuditLog::count();
                // approve / reject only act on a shop that is waiting: make shop B wait for just this call
                $waits = str_starts_with($key, 'POST admin/requests/');
                if ($waits) { \App\Models\Shop::whereKey($w['b']->id)->update(['status' => 'pending']); }
                $res = $this->send($m, $this->url($r, $ids), $this->adminPayload($key, $w)); $n++;
                if ($waits) { \App\Models\Shop::whereKey($w['b']->id)->update(['status' => 'active']); }
                if (! $res->isRedirect()) { $bad[] = "$key => {$res->getStatusCode()} (payload invalid?)"; continue; }
                if (PlatformAuditLog::count() <= $count) { $bad[] = "$key wrote data but no audit row"; }
            }
        }
        $this->assertGreaterThan(5, $n);
        $this->assertSame([], $bad);
    }

    public function test_admin_logout_and_failed_login_are_audited(): void
    {
        $this->world();
        $this->post('/admin/login', ['email' => 'admin@example.test', 'password' => 'wrong-password-xx']);
        $this->assertSame(1, PlatformAuditLog::where('action', 'admin.login_failed')->count());
        $this->signIn(PlatformAdmin::where('email', 'admin@example.test')->firstOrFail());
        $this->post('/admin/logout')->assertRedirect();
        $this->assertSame(1, PlatformAuditLog::where('action', 'admin.logout')->count());
    }

    // ───────────────────────── 5. sessions ─────────────────────────

    private function loginAs(User $u): void
    {
        $this->post('/login', ['email' => $u->email, 'password' => self::PW])->assertRedirect('/');
        $this->fresh();
    }

    public function test_login_regenerates_the_session_id(): void
    {
        $w = $this->world();
        $this->get('/login');
        $before = session()->getId();
        $this->loginAs($w['ownerA']);
        $this->assertNotSame($before, session()->getId());
        $this->assertAuthenticatedAs($w['ownerA']->fresh(), 'web');
    }

    public function test_registration_regenerates_the_session_id(): void
    {
        $this->get('/register');
        $before = session()->getId();
        $this->post('/register', ['name' => 'New Owner', 'email' => 'fresh@ruthless.test', 'mobile' => '9876500001', 'password' => 'Brand-new-pass-123', 'password_confirmation' => 'Brand-new-pass-123', 'terms' => '1'])->assertRedirect('/');
        $this->assertNotSame($before, session()->getId());
    }

    public function test_logout_invalidates_the_session_and_protected_pages_redirect_to_login(): void
    {
        $w = $this->world();
        $this->loginAs($w['ownerA']);
        $this->get('/dhiran/loans')->assertOk(); $this->fresh();
        session(['probe' => 'secret']); $id = session()->getId();
        $this->post('/logout')->assertRedirect('/login');
        $this->fresh();
        $this->assertGuest('web');
        $this->assertNotSame($id, session()->getId());
        $this->assertNull(session('probe'));
        $this->get('/dhiran')->assertRedirect('/login');
    }

    public function test_deactivating_a_user_kicks_the_very_next_request_everywhere(): void
    {
        $w = $this->world();
        $this->loginAs($w['staffA']);
        $this->get('/dhiran/loans')->assertOk(); $this->fresh();
        User::whereKey($w['staffA']->id)->update(['is_active' => false]);
        $bad = [];
        foreach (['/dhiran', '/dhiran/loans', '/dhiran/borrowers', '/settings', '/subscription/status', '/subscription/payment', '/', '/verify-email'] as $url) {
            $this->fresh();
            [$status, $trail] = $this->follow($url);
            if ($status === 200 && ! in_array('/login', $trail, true)) { $bad[] = "$url still 200 for a deactivated user (" . implode(' > ', $trail) . ')'; }
        }
        $this->assertSame([], $bad);
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        $w = $this->world();
        User::whereKey($w['staffA']->id)->update(['is_active' => false]);
        $this->post('/login', ['email' => $w['staffA']->email, 'password' => self::PW])->assertSessionHasErrors('email');
        $this->assertGuest('web');
    }

    public function test_password_reset_logs_out_other_sessions_of_that_user(): void
    {
        $w = $this->world();
        $this->loginAs($w['ownerA']);
        $this->get('/dhiran/loans')->assertOk(); $this->fresh(); // session now bound to the old password hash
        $token = Password::broker('users')->createToken($w['ownerA']->fresh());
        // the reset itself arrives with no session of its own: stash the logged-in one, as a second browser would
        $other = session()->all();
        session()->flush();
        $this->post('/reset-password', ['token' => $token, 'email' => $w['ownerA']->email, 'password' => 'Totally-new-pass-1', 'password_confirmation' => 'Totally-new-pass-1'])->assertRedirect('/login');
        session()->replace($other); $this->fresh();
        $this->get('/dhiran')->assertRedirect('/login');
        $this->assertGuest('web');
    }

    public function test_admin_session_dies_on_password_change(): void
    {
        $w = $this->world();
        $admin = PlatformAdmin::where('email', 'admin@example.test')->firstOrFail();
        // password change
        $this->signIn($admin);
        $this->get('/admin')->assertOk();
        $admin->update(['password' => 'Changed-admin-pass-1']);
        $this->fresh();
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_admin_session_dies_after_idle_timeout(): void
    {
        $this->world();
        // idle timeout (own admin: a TOTP code cannot be replayed inside its 30s window)
        $idle = $this->admin(['email' => 'idle@example.test', 'role' => 'support', 'permissions' => ['can_manage_shops']]);
        $this->signIn($idle);
        Carbon::setTestNow(now()->addMinutes(31)); $this->fresh();
        $this->get('/admin')->assertRedirect('/admin/login');
        Carbon::setTestNow();
    }

    public function test_admin_session_dies_when_the_admin_is_deactivated(): void
    {
        $this->world();
        // deactivated (second admin so the last-super-admin guard allows it)
        $second = $this->admin(['email' => 'second@example.test', 'role' => 'support', 'permissions' => ['can_manage_shops']]);
        $this->signIn($second);
        $this->get('/admin/shops')->assertOk();
        $second->update(['is_active' => false]); $this->fresh();
        $this->get('/admin/shops')->assertRedirect('/admin/login');
    }

    public function test_admin_login_regenerates_the_session_id(): void
    {
        $this->world();
        $this->get('/admin/login');
        $before = session()->getId();
        $this->signIn(PlatformAdmin::where('email', 'admin@example.test')->firstOrFail());
        $this->assertNotSame($before, session()->getId());
    }
}
