<?php

namespace Tests\Feature\Dhiran;

use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** Skeleton loaders, loading states and error recovery: the markup the scripts rely on (resources/js/ux/skeleton.js, recovery.js). */
class UxFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $shop = self::createDhiranShop(['name' => 'Ux Co', 'shop_type' => 'dhiran', 'phone' => '9390024200', 'owner_first_name' => 'O', 'owner_last_name' => 'W',
            'owner_mobile' => '9390024200', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create(['mobile_number' => '9390024200', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'email' => 'ux@example.com',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id]);
    }

    private function skeleton(string $attrs): string
    {
        return Blade::render('<x-dhiran.skeleton '.$attrs.' />');
    }

    public function test_every_variant_renders_hidden_placeholders_with_a_loading_status_and_the_requested_rows(): void
    {
        foreach (['table', 'cards', 'form', 'detail'] as $variant) {
            $html = $this->skeleton('variant="'.$variant.'" :rows="5"');
            $this->assertStringContainsString('data-skeleton-variant="'.$variant.'"', $html);
            $this->assertStringContainsString('aria-hidden="true"', $html);
            $this->assertMatchesRegularExpression('/<span class="ux-sr" role="status">Loading<\/span>/', $html);
            $this->assertSame(5, substr_count($html, 'data-skel-row'), $variant.' row count');
            $this->assertStringContainsString('ux-skel ', $html);
        }
    }

    public function test_default_is_a_table_of_eight_rows_and_the_row_count_is_kept_in_range(): void
    {
        $default = $this->skeleton('');
        $this->assertStringContainsString('data-skeleton-variant="table"', $default);
        $this->assertSame(8, substr_count($default, 'data-skel-row'));
        $this->assertSame(1, substr_count($this->skeleton(':rows="0"'), 'data-skel-row'));
        $this->assertSame(30, substr_count($this->skeleton(':rows="500"'), 'data-skel-row'));
        $this->assertStringContainsString('data-skeleton-variant="table"', $this->skeleton('variant="nonsense"'));
    }

    public function test_shop_pages_load_the_script_entry_and_have_exactly_one_content_region(): void
    {
        foreach ([route('dhiran.loans'), route('dhiran.loans', ['status' => 'active'])] as $url) {
            $page = $this->actingAs($this->owner)->get($url)->assertOk()->assertSee('build/assets', false);
            $this->assertSame(1, substr_count($page->getContent(), 'class="dh-content"'), $url.' must have one .dh-content');
        }
    }

    public function test_the_loans_list_still_has_the_table_the_skeleton_chooser_looks_for_and_the_results_frame(): void
    {
        $page = $this->actingAs($this->owner)->get(route('dhiran.loans'))->assertOk();
        $page->assertSee('data-list-scroll', false)->assertSee('id="dh-results"', false);
        $this->assertStringContainsString('data-skeleton-variant="table"', $this->skeleton('variant="table" :rows="5"'));
    }
}
