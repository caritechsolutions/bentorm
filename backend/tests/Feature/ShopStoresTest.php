<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\StoreStatus;
use App\Filament\Pages\ShopSettings;
use App\Filament\Resources\Stores\Pages\EditStore as AdminEditStore;
use App\Filament\Resources\Stores\Pages\ViewStore;
use App\Filament\Store\Pages\Auth\Login;
use App\Filament\Store\Pages\Auth\Register;
use App\Filament\Store\Pages\Auth\ResetPassword;
use App\Filament\Store\Pages\EditStore;
use App\Filament\Store\Pages\Overview;
use App\Filament\Store\Pages\RegisterStore;
use App\Filament\Store\Resources\Products\Pages\ManageProducts;
use App\Filament\Store\Resources\Staff\Pages\ManageStaff;
use App\Models\AppSetting;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Product;
use App\Models\ServiceArea;
use App\Models\ShopCategory;
use App\Models\Store;
use App\Models\User;
use App\Services\ComplianceService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ShopStoresTest extends TestCase
{
    use RefreshDatabase;

    private ServiceArea $bb;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->bb = $this->seedReference();
    }

    private function category(string $slug = 'food'): ShopCategory
    {
        return ShopCategory::query()->where('slug', $slug)->firstOrFail();
    }

    /** A store with its owner, as if they had signed up. */
    private function storeWithOwner(string $name = 'Fish Pot', string $category = 'food', array $attrs = []): array
    {
        $owner = $this->makeUser('rider');
        $owner->forceFill(['email' => strtolower(str_replace(' ', '', $name)).'@example.com', 'password' => Hash::make('Secret-pass-1')])->save();
        $store = Store::query()->create($attrs + ['name' => $name, 'shop_category_id' => $this->category($category)->id, 'phone' => '2461234567',
            'address' => 'Bay Street', 'lat' => self::BRIDGETOWN[0], 'lng' => self::BRIDGETOWN[1]]);
        $store->users()->attach($owner->id, ['role' => 'owner']);

        return [$store->fresh(), $owner];
    }

    private function asStore(User $user, Store $store): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('store'));
        Filament::setTenant($store);
        Filament::bootCurrentPanel(); // as the panel middleware does: tenant scoping + ownership on create
    }

    public function test_reference_data_categories_store_documents_and_a_delivery_rate_card_that_is_never_a_ride(): void
    {
        $this->assertSame(['food', 'groceries', 'pharmacy', 'electronics', 'hardware', 'fashion', 'beauty', 'gifts'],
            ShopCategory::query()->orderBy('sort_order')->pluck('slug')->all());
        $this->assertSame(['business_registration', 'vat_certificate', 'food_handling_permit'],
            DocumentType::query()->where('applies_to', 'store')->orderBy('sort_order')->pluck('key')->all());

        $this->getJson('/api/v1/vehicle-types')->assertOk()->assertJsonMissing(['key' => 'delivery']);
        $quotes = $this->postJson('/api/v1/fares/estimate', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['vehicle_type' => null]))->assertOk()->json('quotes');
        $this->assertNotContains('delivery', array_column($quotes, 'vehicle_type'));
        $this->actingAs($this->makeUser('rider'))->postJson('/api/v1/trips', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['vehicle_type' => 'delivery']))
            ->assertStatus(422);
    }

    public function test_sign_up_with_a_phone_code_then_register_the_store(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('store'));
        Livewire::test(Register::class)
            ->fillForm(['name' => 'Ann Grant', 'phone' => '246 555 7001', 'email' => 'Ann@FishPot.bb', 'password' => 'Secret-pass-1', 'passwordConfirmation' => 'Secret-pass-1'])
            ->call('register')
            ->assertHasNoFormErrors()
            ->assertSet('codeSent', true)
            ->fillForm(['code' => '000000'])
            ->call('register')
            ->assertHasErrors(['data.code'])
            ->fillForm(['code' => '123456'])
            ->call('register')
            ->assertHasNoErrors();

        $user = User::query()->where('phone', '+12465557001')->firstOrFail();
        $this->assertSame('ann@fishpot.bb', $user->email);
        $this->assertTrue(Hash::check('Secret-pass-1', $user->password));
        $this->assertAuthenticatedAs($user);

        // No store yet → the portal asks to register one.
        $this->get('/store')->assertRedirect('/store/new');

        Livewire::test(RegisterStore::class)
            ->fillForm(['name' => 'Ann\'s Fish Pot', 'shop_category_id' => $this->category()->id, 'phone' => '2464281234', 'description' => 'Fresh fish',
                'address' => 'Oistins', 'lat' => self::OISTINS[0], 'lng' => self::OISTINS[1]])
            ->call('register')
            ->assertHasNoFormErrors();
        $store = Store::query()->firstOrFail();
        $this->assertSame(['anns-fish-pot', StoreStatus::Pending, $this->bb->id], [$store->slug, $store->status, $store->service_area_id]);
        $this->assertTrue($user->isStoreOwner($store));
        $this->assertFalse($store->is_open);
    }

    public function test_sign_up_reuses_an_existing_rider_account_but_never_an_admins(): void
    {
        $rider = $this->makeUser('rider', ['phone' => '+12465557002', 'name' => 'Bea Rider']);
        Filament::setCurrentPanel(Filament::getPanel('store'));
        Livewire::test(Register::class)
            ->fillForm(['name' => 'Someone Else', 'phone' => '2465557002', 'email' => 'bea@shop.bb', 'password' => 'Secret-pass-1', 'passwordConfirmation' => 'Secret-pass-1'])
            ->call('register')->fillForm(['code' => '123456'])->call('register')->assertHasNoErrors();
        $this->assertSame(1, User::query()->where('phone', '+12465557002')->count());
        $this->assertSame(['Bea Rider', 'bea@shop.bb'], [$rider->fresh()->name, $rider->fresh()->email], 'same person, one account');

        auth()->logout();
        $this->makeUser('admin', ['phone' => '+12465557003']);
        Livewire::test(Register::class)
            ->fillForm(['name' => 'X', 'phone' => '2465557003', 'email' => 'x@shop.bb', 'password' => 'Secret-pass-1', 'passwordConfirmation' => 'Secret-pass-1'])
            ->call('register')->assertHasErrors(['data.phone'])->assertSet('codeSent', false);

        Livewire::test(Register::class)
            ->fillForm(['name' => 'Y', 'phone' => '2465557004', 'email' => 'bea@shop.bb', 'password' => 'Secret-pass-1', 'passwordConfirmation' => 'Secret-pass-1'])
            ->call('register')->assertHasErrors(['data.email']);
    }

    public function test_log_in_with_phone_or_email_and_reset_the_password_with_a_code(): void
    {
        [, $owner] = $this->storeWithOwner();
        Filament::setCurrentPanel(Filament::getPanel('store'));
        Livewire::test(Login::class)->fillForm(['login' => $owner->email, 'password' => 'Secret-pass-1'])->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($owner);
        auth()->logout();
        Livewire::test(Login::class)->fillForm(['login' => substr($owner->phone, 2), 'password' => 'Secret-pass-1'])->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($owner);
        auth()->logout();
        Livewire::test(Login::class)->fillForm(['login' => $owner->email, 'password' => 'wrong'])->call('authenticate')->assertHasErrors(['data.login']);

        Livewire::test(ResetPassword::class)
            ->fillForm(['phone' => $owner->phone])->call('request')->assertSet('codeSent', true)
            ->fillForm(['code' => '123456', 'password' => 'New-pass-22', 'passwordConfirmation' => 'New-pass-22'])->call('request')
            ->assertHasNoErrors()->assertRedirect();
        $this->assertTrue(Hash::check('New-pass-22', $owner->fresh()->password));

        // Unknown numbers get the same answer, and no code works for them.
        Livewire::test(ResetPassword::class)->fillForm(['phone' => '2469990000'])->call('request')->assertSet('codeSent', true)
            ->fillForm(['code' => '123456', 'password' => 'New-pass-22', 'passwordConfirmation' => 'New-pass-22'])->call('request')->assertHasErrors(['data.code']);
        // Admins can't reset from the store portal.
        $admin = $this->makeUser('admin');
        $admin->forceFill(['password' => Hash::make('Admin-pass-1')])->save();
        Livewire::test(ResetPassword::class)->fillForm(['phone' => $admin->phone])->call('request')
            ->fillForm(['code' => '123456', 'password' => 'New-pass-22', 'passwordConfirmation' => 'New-pass-22'])->call('request')->assertHasErrors(['data.code']);
        $this->assertTrue(Hash::check('Admin-pass-1', $admin->fresh()->password));
    }

    public function test_each_login_only_ever_sees_its_own_store(): void
    {
        [$a, $ownerA] = $this->storeWithOwner('Fish Pot');
        [$b, $ownerB] = $this->storeWithOwner('Gadget Hub', 'electronics');
        $pa = $a->products()->create(['name' => 'Flying fish cutter', 'price' => 12]);
        $b->products()->create(['name' => 'USB-C cable', 'price' => 25]);

        $this->actingAs($ownerA);
        $this->get("/store/{$a->slug}/products")->assertOk()->assertSee('Flying fish cutter')->assertDontSee('USB-C cable');
        $this->get("/store/{$b->slug}/products")->assertNotFound();
        $this->get("/store/{$b->slug}/overview")->assertNotFound();
        $this->get("/store/{$b->slug}/profile")->assertNotFound();

        // Even through Livewire, A's form can't reach B's product.
        $this->asStore($ownerA, $a);
        Livewire::test(ManageProducts::class)->assertCanSeeTableRecords([$pa])->assertCountTableRecords(1);

        // Admin panel is still admins only.
        $this->get('/admin')->assertForbidden();
    }

    public function test_products_with_photos_sizes_options_and_sold_out(): void
    {
        [$store, $owner] = $this->storeWithOwner();
        $section = $store->sections()->create(['name' => 'Mains', 'sort_order' => 1]);
        $this->asStore($owner, $store);

        Livewire::test(ManageProducts::class)
            ->callAction('create', [
                'name' => 'Fried flying fish', 'description' => 'With cou-cou', 'price' => 22.5, 'menu_section_id' => $section->id, 'size' => 'small',
                'photo_path' => [UploadedFile::fake()->image('fish.jpg', 800, 600)], 'is_available' => true,
                'options' => [
                    'g1' => ['name' => 'Side', 'required' => true, 'multiple' => false, 'choices' => ['c1' => ['name' => 'Macaroni pie', 'price' => 0], 'c2' => ['name' => 'Breadfruit', 'price' => '3']]],
                ],
            ])
            ->assertHasNoActionErrors();

        $p = Product::query()->firstOrFail();
        $this->assertSame([$store->id, $section->id, 22.5, 'small', true], [$p->store_id, $p->menu_section_id, $p->price, $p->size, $p->is_available]);
        $this->assertEquals([['name' => 'Side', 'required' => true, 'multiple' => false, 'choices' => [['name' => 'Macaroni pie', 'price' => 0.0], ['name' => 'Breadfruit', 'price' => 3.0]]]], $p->options);
        Storage::disk('public')->assertExists($p->photo_path);

        Livewire::test(ManageProducts::class)->call('updateTableColumnState', 'is_available', (string) $p->id, false);
        $this->assertFalse($p->fresh()->is_available);
    }

    public function test_owners_manage_staff_and_settings_staff_cannot(): void
    {
        [$store, $owner] = $this->storeWithOwner();
        $existing = $this->makeUser('rider', ['phone' => '+12465558002']);
        $this->asStore($owner, $store);

        Livewire::test(ManageStaff::class)
            ->callAction('create', ['name' => 'Cal Cashier', 'phone' => '2465558001', 'email' => 'cal@fishpot.bb', 'role' => 'staff'])->assertHasNoActionErrors()
            ->callAction('create', ['name' => 'Ignored', 'phone' => '2465558002', 'role' => 'staff'])->assertHasNoActionErrors();
        $cal = User::query()->where('phone', '+12465558001')->firstOrFail();
        $this->assertNull($cal->password, 'sets one with Forgot password');
        $this->assertTrue($store->users()->whereKey($existing->id)->exists(), 'existing account linked, not duplicated');
        $this->assertSame(2, User::query()->whereIn('phone', ['+12465558001', '+12465558002'])->count());

        Livewire::test(ManageStaff::class)->callAction('create', ['name' => 'Dup', 'phone' => '2465558001', 'role' => 'staff'])->assertHasActionErrors(['phone']);

        // Staff: catalogue yes, staff list and store settings no.
        $this->actingAs($cal);
        $this->get("/store/{$store->slug}/products")->assertOk();
        $this->get("/store/{$store->slug}/staff")->assertForbidden();
        $this->get("/store/{$store->slug}/profile")->assertNotFound();
        $this->actingAs($owner);
        $this->get("/store/{$store->slug}/staff")->assertOk()->assertSee('Cal Cashier');
        $this->get("/store/{$store->slug}/profile")->assertOk();
    }

    public function test_store_settings_save_hours_and_operations(): void
    {
        [$store, $owner] = $this->storeWithOwner();
        $this->asStore($owner, $store);
        Livewire::test(EditStore::class)
            ->fillForm([
                'prep_minutes' => 25, 'busy_extra_minutes' => 10, 'min_order' => 15, 'delivery_radius_km' => 6, 'vat_registered' => true, 'prices_include_vat' => false,
                'hours' => ['mon' => ['x' => ['from' => '08:00', 'to' => '17:00']], 'sat' => ['y' => ['from' => '18:00', 'to' => '02:00']], 'sun' => []],
            ])
            ->call('save')->assertHasNoFormErrors();
        $store->refresh();
        $this->assertEquals(['mon' => [['from' => '08:00', 'to' => '17:00']], 'sat' => [['from' => '18:00', 'to' => '02:00']]], $store->hours);
        $this->assertSame([25, 6.0, 15.0, true, false], [$store->prep_minutes, $store->delivery_radius_km, $store->min_order, $store->vat_registered, $store->prices_include_vat]);

        // Hours are in Barbados time; past-midnight ranges work.
        $at = fn (string $local) => Carbon::parse($local, 'America/Barbados');
        $this->assertTrue($store->openAt($at('2026-10-26 09:00'))); // Monday
        $this->assertFalse($store->openAt($at('2026-10-26 17:30')));
        $this->assertTrue($store->openAt($at('2026-10-31 23:00'))); // Saturday night
        $this->assertFalse($store->openAt($at('2026-10-27 12:00'))); // Tuesday: closed
        $store->forceFill(['is_busy' => true])->save();
        $this->assertSame(35, $store->currentPrepMinutes());
    }

    public function test_documents_approval_and_opening_for_business(): void
    {
        [$store, $owner] = $this->storeWithOwner();
        $issues = app(ComplianceService::class)->storeIssues($store);
        $this->assertContains('Add at least one product.', $issues);
        $this->assertTrue(collect($issues)->contains(fn ($i) => str_contains($i, 'Business registration')));
        $this->assertTrue(collect($issues)->contains(fn ($i) => str_contains($i, 'Food handling')), 'food stores need the permit');
        [$gadgets] = $this->storeWithOwner('Gadget Hub', 'electronics');
        $this->assertFalse(collect(app(ComplianceService::class)->storeIssues($gadgets))->contains(fn ($i) => str_contains($i, 'Food handling')));

        $this->asStore($owner, $store);
        $reg = DocumentType::query()->where('key', 'business_registration')->firstOrFail();
        Livewire::test(Overview::class)
            ->assertSee('Waiting for approval')
            ->callAction('upload', ['file' => UploadedFile::fake()->create('reg.pdf', 120, 'application/pdf'), 'number' => 'BRN-1234'], ['type' => $reg->id])
            ->assertHasNoActionErrors()
            ->assertSee('Uploaded — being reviewed');
        $doc = Document::query()->where('documentable_type', Store::class)->firstOrFail();
        $this->assertSame([$store->id, 'BRN-1234', DocumentStatus::Pending], [$doc->documentable_id, $doc->number, $doc->status]);

        // The store can open its own file; another store's owner can't; admins can.
        $this->get(route('admin.document.file', $doc))->assertOk();
        [, $other] = $this->storeWithOwner('Other Shop', 'groceries');
        $this->actingAs($other)->get(route('admin.document.file', $doc))->assertForbidden();

        // Not approved yet: can't open.
        $this->asStore($owner, $store);
        Livewire::test(Overview::class)->assertActionHidden('toggleOpen');

        // Admin approves (overriding the missing permit), then the store opens itself.
        $admin = $this->makeUser('admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->get('/admin/stores')->assertOk()->assertSee('Fish Pot');
        $this->get("/admin/stores/{$store->id}")->assertOk()->assertSee('Add at least one product');
        Livewire::test(ViewStore::class, ['record' => $store->id])->callAction('approve', ['force' => true])->assertHasNoActionErrors();
        $this->assertSame(StoreStatus::Approved, $store->fresh()->status);

        $this->asStore($owner, $store);
        Livewire::test(Overview::class)->assertActionVisible('toggleOpen')->callAction('toggleOpen');
        $this->assertTrue($store->fresh()->is_open);
        $this->assertTrue($store->fresh()->isAcceptingOrders());

        // Suspending closes it.
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ViewStore::class, ['record' => $store->id])->callAction('suspend', ['note' => 'Complaints'])->assertHasNoActionErrors();
        $this->assertSame([StoreStatus::Suspended, false, 'Complaints'], [$store->fresh()->status, $store->fresh()->is_open, $store->fresh()->review_note]);
    }

    public function test_admin_sets_fees_categories_and_a_store_commission(): void
    {
        [$store] = $this->storeWithOwner();
        $this->actingAs($this->makeUser('admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach (['/admin/shop-categories', '/admin/shop-settings', "/admin/stores/{$store->id}/edit"] as $url) {
            $this->get($url)->assertOk();
        }
        $this->assertSame(0.15, ShopSettings::value('shop.commission_rate'));
        Livewire::test(ShopSettings::class)
            ->fillForm(['commission_rate' => 12.5, 'delivery_share' => 25, 'service_fee_flat' => 1.5, 'service_fee_rate' => 0])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame([0.125, 0.25, 1.5, 0.0], [ShopSettings::value('shop.commission_rate'), ShopSettings::value('shop.delivery_share'),
            ShopSettings::value('shop.service_fee_flat'), ShopSettings::value('shop.service_fee_rate')]);

        Livewire::test(AdminEditStore::class, ['record' => $store->id])->fillForm(['commission_rate' => 10])->call('save')->assertHasNoFormErrors();
        $this->assertSame(0.1, $store->fresh()->commission_rate);

        // Stores can't set their own commission or status.
        $store->update(['commission_rate' => 0, 'status' => 'approved']);
        $this->assertSame([0.1, StoreStatus::Pending], [$store->fresh()->commission_rate, $store->fresh()->status]);
        $this->assertNull(AppSetting::query()->find('nothing'));
    }

    public function test_a_pin_on_a_repeated_copy_of_the_world_map_is_brought_back(): void
    {
        // The real case: a store pinned at 13.08323, -779.5673078 (Oistins, two worlds to the west).
        [$store] = $this->storeWithOwner('Courts', 'electronics', ['lat' => 13.08323, 'lng' => -779.5673078]);
        $this->assertEqualsWithDelta(-59.5673078, $store->lng, 1e-6);
        $this->assertSame($this->bb->id, $store->service_area_id);
        $this->assertNotContains('The store is outside the areas we serve.', app(ComplianceService::class)->storeIssues($store));

        // Rows saved before the fix are repaired by the migration.
        \Illuminate\Support\Facades\DB::table('stores')->where('id', $store->id)->update(['lng' => 300.4326922, 'service_area_id' => null]);
        (require database_path('migrations/2026_10_24_000130_wrap_store_longitudes.php'))->up();
        $this->assertEqualsWithDelta(-59.5673078, $store->fresh()->lng, 1e-6);
        $this->assertSame($this->bb->id, $store->fresh()->service_area_id);

        // Area shapes drawn on a copy of the world move back in one piece instead of failing.
        $shape = \App\Support\GeoJson::normalise(['type' => 'Polygon', 'coordinates' => [[[-779.7, 13.0], [-779.4, 13.0], [-779.4, 13.3], [-779.7, 13.3]]]]);
        $this->assertEqualsWithDelta(-59.7, $shape['coordinates'][0][0][0], 1e-6);
        $this->assertTrue(\App\Support\GeoJson::contains($shape, 13.1, -59.55));
        $this->assertSame(179.5, \App\Support\GeoJson::wrapLng(-180.5));
        $this->assertSame(-59.5, \App\Support\GeoJson::wrapLng(-59.5));
    }
}
