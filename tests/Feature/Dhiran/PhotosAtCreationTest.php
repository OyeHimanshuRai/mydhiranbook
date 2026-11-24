<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\CustomerProfilePhoto;
use App\Models\Dhiran\DhiranAttachment;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Photos taken where the record is created: the new borrower's photo and each pledged item's photo, so activation needs no second trip. */
class PhotosAtCreationTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://127.0.0.1:8787';
    private $shop;
    private User $owner;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = self::createDhiranShop([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390021000', 'owner_first_name' => 'O', 'owner_last_name' => 'W',
            'owner_mobile' => '9390021000', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($this->shop->id)->update(['is_enabled' => true, 'processing_fee_value' => 0, 'default_min_lock_months' => 0]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $this->owner = User::create(['mobile_number' => '9390021000', 'password' => bcrypt('x'), 'realm' => 'dhiran', 'email' => 'o@example.com',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $this->shop->id, 'role_id' => $role->id]);
        $this->customer = TenantContext::runFor($this->shop->id, fn () => Customer::create(['shop_id' => $this->shop->id, 'first_name' => 'Pho', 'last_name' => 'To', 'mobile' => '9815600001']));
    }

    private function as(callable $fn)
    {
        return TenantContext::runFor($this->shop->id, fn () => $fn($this->actingAs($this->owner)));
    }

    private function loanForm(array $items): array
    {
        return ['customer_id' => $this->customer->id, 'principal_amount' => 50000, 'gold_rate_on_date' => 6000, 'interest_rate_monthly' => 2,
            'interest_type' => 'flat', 'tenure_months' => 6, 'idempotency_key' => 'ph-' . uniqid(), 'items' => $items];
    }

    private function item(string $desc, ?UploadedFile $photo = null): array
    {
        return array_filter(['description' => $desc, 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 20, 'rate_per_gram_at_pledge' => 6000, 'photo' => $photo], fn ($v) => $v !== null);
    }

    public function test_a_new_borrower_can_be_saved_with_a_photo_in_one_step(): void
    {
        $r = $this->as(fn ($t) => $t->post(self::HOST . '/dhiran/customers', [
            'first_name' => 'Nita', 'mobile' => '9815600002', 'customer_photo' => UploadedFile::fake()->image('face.jpg', 300, 300),
        ], ['Accept' => 'application/json']))->assertCreated();

        $this->assertNull($r->json('photo_error'));
        $c = Customer::withoutGlobalScopes()->findOrFail($r->json('customer.id'));
        $this->assertNotNull(CustomerProfilePhoto::withoutTenant()->where('customer_id', $c->id)->first(), 'the photo is stored with the borrower');
    }

    public function test_a_bad_photo_does_not_lose_the_borrower_and_says_so(): void
    {
        $bad = UploadedFile::fake()->createWithContent('face.jpg', 'this is not an image');
        $r = $this->as(fn ($t) => $t->post(self::HOST . '/dhiran/customers', [
            'first_name' => 'Omi', 'mobile' => '9815600003', 'customer_photo' => $bad,
        ], ['Accept' => 'application/json']))->assertCreated();

        $this->assertNotEmpty($r->json('photo_error'));
        $this->assertNotNull(Customer::withoutGlobalScopes()->find($r->json('customer.id')), 'the borrower was still saved');
        $this->assertNull(CustomerProfilePhoto::withoutTenant()->where('customer_id', $r->json('customer.id'))->first());
    }

    public function test_the_add_customer_page_form_takes_a_photo_too(): void
    {
        $html = $this->as(fn ($t) => $t->get(self::HOST . '/dhiran/borrowers/create'))->getContent();
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('name="customer_photo"', $html);

        $this->as(fn ($t) => $t->post(self::HOST . '/dhiran/customers', [
            'first_name' => 'Pia', 'mobile' => '9815600004', 'customer_photo' => UploadedFile::fake()->image('face.png', 200, 200),
        ]))->assertRedirect()->assertSessionHas('success');
        $c = Customer::withoutGlobalScopes()->where('mobile', '9815600004')->first();
        $this->assertNotNull(CustomerProfilePhoto::withoutTenant()->where('customer_id', $c->id)->first());
    }

    public function test_the_new_loan_page_offers_a_photo_per_item_and_in_the_borrower_dialog(): void
    {
        $html = $this->as(fn ($t) => $t->get(self::HOST . '/dhiran/create'))->getContent();
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('Photo of this item', $html);
        $this->assertStringContainsString("'items['+index+'][photo]'", $html);
        $this->assertStringContainsString('data-photo-form-file', $html, 'the borrower dialog carries the photo control');
        $this->assertStringContainsString('x-ref="camDialog"', $html, 'the live-camera dialog for item photos');
        $this->assertStringContainsString("openCamera({ kind: 'item', index })", $html, 'each item card has a camera button');
        $this->assertStringContainsString("openCamera({ kind: 'doc', key: d.key })", $html, 'and so do the ID proof and PAN card');
        $this->assertStringContainsString('Upload a photo of item', $html, 'an upload icon button');
        $this->assertStringContainsString('viewPhoto(item.photoUrl', $html, 'the photo opens full size');
        $this->assertStringContainsString('x-ref="photoView"', $html);
        $this->assertStringContainsString('PAN card', $html);
    }

    public function test_item_photos_are_saved_as_item_evidence_in_the_order_listed(): void
    {
        $this->as(fn ($t) => $t->post(self::HOST . '/dhiran', $this->loanForm([
            $this->item('Chain', UploadedFile::fake()->image('a.jpg', 120, 120)),
            $this->item('Ring'),
            $this->item('Bangle', UploadedFile::fake()->image('c.png', 90, 90)),
        ])))->assertSessionHasNoErrors()->assertSessionHas('success');

        $loan = DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->firstOrFail();
        $items = \App\Models\Dhiran\DhiranLoanItem::withoutGlobalScopes()->where("dhiran_loan_id", $loan->id)->orderBy("id")->get()->values();
        $photos = DhiranAttachment::withoutGlobalScopes()->where('document_type', 'item_photo')->where('owner_type', DhiranAttachment::OWNER_ITEM)->pluck('owner_id')->all();
        $this->assertEqualsCanonicalizing([$items[0]->id, $items[2]->id], $photos, 'Chain and Bangle have photos, Ring does not');
    }

    public function test_one_item_photo_leaves_only_the_borrower_id_to_activate(): void
    {
        $this->as(fn ($t) => $t->post(self::HOST . '/dhiran', $this->loanForm([$this->item('Chain', UploadedFile::fake()->image('a.jpg', 120, 120))])))->assertSessionHasNoErrors();
        $loan = DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->firstOrFail();
        $status = TenantContext::runFor($this->shop->id, fn () => app(\App\Services\DhiranService::class)->evidenceStatus($loan));

        $this->assertTrue($status['item_photo']);
        $this->assertFalse($status['id_proof'], 'the ID proof still has to come from the profile or the loan page');
    }

    public function test_a_wrong_file_type_or_size_for_an_item_photo_is_refused_before_anything_is_created(): void
    {
        $this->as(fn ($t) => $t->post(self::HOST . '/dhiran', $this->loanForm([$this->item('Chain', UploadedFile::fake()->create('x.exe', 10, 'application/octet-stream'))])))
            ->assertSessionHasErrors('items.0.photo');
        $this->as(fn ($t) => $t->post(self::HOST . '/dhiran', $this->loanForm([$this->item('Chain', UploadedFile::fake()->image('big.jpg', 10, 10)->size(8193))])))
            ->assertSessionHasErrors('items.0.photo');
        $this->assertSame(0, DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->count());
    }

    public function test_item_photo_plus_id_proof_on_the_form_leaves_the_loan_ready_to_activate(): void
    {
        $form = $this->loanForm([$this->item('Chain', UploadedFile::fake()->image('a.jpg', 120, 120))])
            + ['id_proof_front' => UploadedFile::fake()->image('id-front.jpg', 200, 120), 'id_proof_back' => UploadedFile::fake()->create('id-back.pdf', 50, 'application/pdf')];
        $r = $this->as(fn ($t) => $t->post(self::HOST . '/dhiran', $form))->assertSessionHasNoErrors();

        $loan = DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->firstOrFail();
        $status = TenantContext::runFor($this->shop->id, fn () => app(\App\Services\DhiranService::class)->evidenceStatus($loan));
        $this->assertTrue($status['ok'], 'item photo and ID proof are both in');
        $this->assertStringContainsString('activate the loan', session('success'));
        $this->assertSame(2, DhiranAttachment::withoutGlobalScopes()->where('owner_type', DhiranAttachment::OWNER_CUSTOMER)->where('owner_id', $this->customer->id)
            ->whereIn('document_type', ['id_proof_front', 'id_proof_back'])->count(), 'kept on the borrower, so later loans reuse it');
    }

    public function test_the_message_names_what_is_still_missing(): void
    {
        $this->as(fn ($t) => $t->post(self::HOST . '/dhiran', $this->loanForm([$this->item('Chain')]) + ['id_proof_front' => UploadedFile::fake()->image('id.jpg', 200, 120)]))->assertSessionHasNoErrors();
        $this->assertSame('Loan created. Still needed before activation: the pledged-item photo.', session('success'));
    }

    public function test_a_wrong_or_huge_id_proof_is_refused_before_the_loan_is_created(): void
    {
        $this->as(fn ($t) => $t->post(self::HOST . '/dhiran', $this->loanForm([$this->item('Chain')]) + ['id_proof_front' => UploadedFile::fake()->create('id.exe', 10, 'application/octet-stream')]))
            ->assertSessionHasErrors('id_proof_front');
        $this->as(fn ($t) => $t->post(self::HOST . '/dhiran', $this->loanForm([$this->item('Chain')]) + ['id_proof_back' => UploadedFile::fake()->create('big.pdf', 8193, 'application/pdf')]))
            ->assertSessionHasErrors('id_proof_back');
        $this->assertSame(0, DhiranLoan::withoutGlobalScopes()->where('shop_id', $this->shop->id)->count());
    }

    public function test_the_form_has_the_id_proof_inputs_and_the_kyc_call_says_if_one_is_on_file(): void
    {
        $html = $this->as(fn ($t) => $t->get(self::HOST . '/dhiran/create'))->getContent();
        foreach (['id_proof_front', 'id_proof_back', 'pan_card'] as $key) {
            $this->assertStringContainsString("key: '{$key}'", $html, "{$key} has its upload slot");
        }
        $this->assertStringContainsString(':name="d.key"', $html, 'each slot posts under its own field name');

        $kyc = fn () => $this->as(fn ($t) => $t->getJson(self::HOST . "/dhiran/borrowers/{$this->customer->id}/kyc"))->json('has_id_proof');
        $this->assertFalse($kyc());
        TenantContext::runFor($this->shop->id, fn () => app(\App\Services\Dhiran\DhiranAttachmentService::class)->store(
            UploadedFile::fake()->image('id.jpg', 100, 100), $this->shop->id, DhiranAttachment::OWNER_CUSTOMER, $this->customer->id, 'id_proof_front', $this->owner->id));
        $this->assertTrue($kyc());
    }

    public function test_the_pan_card_photo_is_saved_to_the_borrower_like_the_id_proof(): void
    {
        $this->as(fn ($t) => $t->post(self::HOST . '/dhiran', $this->loanForm([$this->item('Chain')]) + ['pan_card' => UploadedFile::fake()->image('pan.jpg', 200, 120)]))->assertSessionHasNoErrors();
        $this->assertSame(1, DhiranAttachment::withoutGlobalScopes()->where('owner_type', DhiranAttachment::OWNER_CUSTOMER)->where('owner_id', $this->customer->id)->where('document_type', 'pan_card')->count());

        $this->as(fn ($t) => $t->post(self::HOST . '/dhiran', $this->loanForm([$this->item('Chain')]) + ['pan_card' => UploadedFile::fake()->create('pan.exe', 5, 'application/octet-stream')]))->assertSessionHasErrors('pan_card');
    }

    public function test_the_borrower_photo_comes_back_with_the_picker_and_after_saving_so_the_customer_card_can_show_it(): void
    {
        $r = $this->as(fn ($t) => $t->post(self::HOST . '/dhiran/customers', [
            'first_name' => 'Rita', 'mobile' => '9815600009', 'customer_photo' => UploadedFile::fake()->image('face.jpg', 300, 300),
        ], ['Accept' => 'application/json']))->assertCreated();
        $this->assertStringContainsString('/photo', (string) $r->json('customer.photo_url'), 'the saved response carries the photo address');

        $hit = $this->as(fn ($t) => $t->getJson(self::HOST . '/dhiran/borrowers/search?q=Rita'))->json('0');
        $this->assertSame($r->json('customer.photo_url'), $hit['photo_url'], 'search results carry it too');

        $plain = $this->as(fn ($t) => $t->getJson(self::HOST . '/dhiran/borrowers/search?q=Pho'))->json('0');
        $this->assertNull($plain['photo_url'], 'no photo, no address');

        $html = $this->as(fn ($t) => $t->get(self::HOST . '/dhiran/create'))->getContent();
        $this->assertStringContainsString('fm-avatar-btn', $html, 'the Customer card has the borrower photo');
    }
}
