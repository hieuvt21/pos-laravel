<?php

use App\Models\User;
use App\Modules\Core\Support\DocumentNumber;
use App\Modules\Core\Support\ModuleRegistry;
use App\Modules\ServiceCatalog\Models\Service;
use App\Modules\ServiceCatalog\Models\ServiceCategory;
use App\Modules\ServiceCatalog\Services\ServiceCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('pos:sync');
    $this->manager = svUser('manager');
    $this->cat = ServiceCategory::create(['name' => 'Giao hàng', 'is_active' => true]);
});

function svUser(?string $role): User
{
    $user = User::factory()->create();
    if ($role) {
        $user->assignRole($role);
    }

    return $user;
}

function svPayload(array $over = []): array
{
    return array_merge([
        'name' => 'Giao hàng nội thành',
        'service_category_id' => test()->cat->id,
        'price' => 150000,
        'commission_type' => 'amount',
        'commission_value' => 100000,
        'note' => null,
        'is_active' => true,
    ], $over);
}

function svService(array $over = []): Service
{
    static $n = 0;
    $n++;

    return Service::create(array_merge([
        'code' => 'DV-T'.$n, 'name' => 'Dịch vụ '.$n, 'price' => 100000, 'commission_type' => 'amount', 'commission_value' => 10000, 'is_active' => true,
    ], $over));
}

it('quyền: thu ngân chỉ xem, người không có quyền bị chặn, quản lý làm được mọi việc', function () {
    $s = svService();
    $cashier = svUser('cashier');

    $this->get(route('services.index'))->assertRedirect(route('login'));   // khách chưa đăng nhập (phải kiểm trước khi actingAs)

    $this->actingAs($cashier)->get(route('services.index'))->assertOk();
    $this->actingAs($cashier)->getJson(route('services.check', ['value' => 'x']))->assertOk();
    $this->actingAs($cashier)->post(route('services.store'), svPayload())->assertForbidden();
    $this->actingAs($cashier)->put(route('services.update', $s), svPayload())->assertForbidden();
    $this->actingAs($cashier)->delete(route('services.destroy', $s))->assertForbidden();
    $this->actingAs($cashier)->get(route('service_categories.index'))->assertForbidden();

    foreach ([null, 'warehouse'] as $role) {
        $this->actingAs(svUser($role))->get(route('services.index'))->assertForbidden();
    }

    $this->actingAs($this->manager)->post(route('services.store'), svPayload())->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->put(route('services.update', $s), svPayload(['name' => 'Tên mới']))->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->delete(route('services.destroy', $s))->assertSessionHasNoErrors();
});

it('thêm dịch vụ với định mức bằng tiền: cấp mã DV, hoa hồng bằng đúng định mức', function () {
    $this->actingAs($this->manager)->post(route('services.store'), svPayload(['note' => 'Giao trong ngày', 'service_category_id' => $this->cat->id]))
        ->assertSessionHasNoErrors();

    $s = Service::first();
    expect($s->code)->toStartWith('DV-')->toEndWith('0001')
        ->and($s->name)->toBe('Giao hàng nội thành')
        ->and($s->service_category_id)->toBe($this->cat->id)
        ->and($s->price)->toBe(150000.0)
        ->and($s->commission_type)->toBe('amount')
        ->and($s->commission_value)->toBe(100000.0)
        ->and($s->commission)->toBe(100000.0)
        ->and($s->note)->toBe('Giao trong ngày')
        ->and($s->is_active)->toBeTrue();
});

it('thêm dịch vụ với định mức phần trăm: hoa hồng tính theo giá, tự đổi khi giá đổi', function () {
    $this->actingAs($this->manager)->post(route('services.store'), svPayload(['commission_type' => 'percent', 'commission_value' => 10]))->assertSessionHasNoErrors();

    $s = Service::first();
    expect($s->commission_type)->toBe('percent')->and($s->commission_value)->toBe(10.0)->and($s->commission)->toBe(15000.0);

    $this->actingAs($this->manager)->put(route('services.update', $s), svPayload(['price' => 200000, 'commission_type' => 'percent', 'commission_value' => 10]))->assertSessionHasNoErrors();
    expect($s->fresh()->commission)->toBe(20000.0);     // không lưu số tiền hoa hồng nên đổi giá là tự theo
});

it('bắt buộc chọn danh mục dịch vụ; danh mục phải tồn tại và đang sử dụng', function () {
    $post = fn (array $over) => $this->actingAs($this->manager)->post(route('services.store'), array_merge(svPayload(), $over));
    $inactive = ServiceCategory::create(['name' => 'Ngừng sử dụng', 'is_active' => false]);

    $post(['name' => 'A', 'service_category_id' => null])->assertSessionHasErrors('service_category_id');
    expect(session('errors')->first('service_category_id'))->toBe('Vui lòng chọn danh mục dịch vụ.');
    $post(['name' => 'B', 'service_category_id' => ''])->assertSessionHasErrors('service_category_id');
    $post(['name' => 'C', 'service_category_id' => 999999])->assertSessionHasErrors('service_category_id');
    $post(['name' => 'D', 'service_category_id' => 'abc'])->assertSessionHasErrors('service_category_id');
    $post(['name' => 'E', 'service_category_id' => $inactive->id])->assertSessionHasErrors('service_category_id');
    expect(Service::count())->toBe(0);

    // thiếu hẳn khóa danh mục cũng bị chặn
    $payload = svPayload(['name' => 'F']);
    unset($payload['service_category_id']);
    $this->actingAs($this->manager)->post(route('services.store'), $payload)->assertSessionHasErrors('service_category_id');

    $post(['name' => 'G'])->assertSessionHasNoErrors();
    expect(Service::count())->toBe(1)->and(Service::first()->service_category_id)->toBe($this->cat->id);
});

it('sửa dịch vụ: không bỏ trống danh mục; giữ được danh mục hiện tại dù đã ngừng sử dụng, nhưng không chuyển sang danh mục ngừng khác', function () {
    $old = ServiceCategory::create(['name' => 'Danh mục cũ', 'is_active' => true]);
    $other = ServiceCategory::create(['name' => 'Danh mục ngừng khác', 'is_active' => false]);
    $s = svService(['name' => 'Dịch vụ cũ', 'service_category_id' => $old->id]);
    $old->update(['is_active' => false]);        // danh mục bị ngừng sau khi đã có dịch vụ

    $put = fn (array $over) => $this->actingAs($this->manager)->put(route('services.update', $s), svPayload(['name' => 'Dịch vụ cũ'] + $over));

    $put(['service_category_id' => null])->assertSessionHasErrors('service_category_id');
    $put(['service_category_id' => $other->id])->assertSessionHasErrors('service_category_id');
    expect($s->fresh()->service_category_id)->toBe($old->id);

    $put(['service_category_id' => $old->id, 'price' => 120000])->assertSessionHasNoErrors();      // giữ nguyên danh mục hiện tại
    expect($s->fresh()->price)->toBe(120000.0);

    $put(['service_category_id' => $this->cat->id])->assertSessionHasNoErrors();                    // chuyển sang danh mục đang dùng
    expect($s->fresh()->service_category_id)->toBe($this->cat->id);
});

it('kiểm tra dữ liệu cơ bản: tên, giá, đơn vị định mức, trạng thái', function () {
    $post = fn (array $over) => $this->actingAs($this->manager)->post(route('services.store'), svPayload($over + ['name' => 'Dịch vụ mới']));

    $post(['name' => ''])->assertSessionHasErrors('name');
    $post(['name' => str_repeat('a', 151)])->assertSessionHasErrors('name');
    $post(['price' => null])->assertSessionHasErrors('price');
    $post(['price' => -1])->assertSessionHasErrors('price');
    $post(['price' => 'abc'])->assertSessionHasErrors('price');
    $post(['price' => 10.123])->assertSessionHasErrors('price');
    $post(['commission_type' => 'khac'])->assertSessionHasErrors('commission_type');
    $post(['commission_type' => null])->assertSessionHasErrors('commission_type');
    $post(['commission_value' => null])->assertSessionHasErrors('commission_value');
    $post(['commission_value' => -1])->assertSessionHasErrors('commission_value');
    $post(['commission_value' => 'abc'])->assertSessionHasErrors('commission_value');
    $post(['note' => str_repeat('a', 501)])->assertSessionHasErrors('note');
    $post(['is_active' => null])->assertSessionHasErrors('is_active');

    expect(Service::count())->toBe(0);
});

it('định mức phần trăm không được vượt quá 100% và tối đa 2 chữ số thập phân', function () {
    $post = fn (array $over) => $this->actingAs($this->manager)->post(route('services.store'), svPayload(['commission_type' => 'percent'] + $over));

    $post(['name' => 'A', 'commission_value' => 100.01])->assertSessionHasErrors('commission_value');
    expect(session('errors')->first('commission_value'))->toContain('100%');
    $post(['name' => 'B', 'commission_value' => 150])->assertSessionHasErrors('commission_value');
    $post(['name' => 'C', 'commission_value' => 12.345])->assertSessionHasErrors('commission_value');
    expect(Service::count())->toBe(0);

    $post(['name' => 'D', 'commission_value' => 100])->assertSessionHasNoErrors();     // đúng 100% là được
    $post(['name' => 'E', 'commission_value' => 12.5])->assertSessionHasNoErrors();
    $post(['name' => 'F', 'commission_value' => 0])->assertSessionHasNoErrors();       // không có hoa hồng
    expect(Service::count())->toBe(3);
});

it('định mức bằng tiền không được lớn hơn giá dịch vụ', function () {
    $post = fn (array $over) => $this->actingAs($this->manager)->post(route('services.store'), svPayload($over));

    $post(['name' => 'A', 'price' => 150000, 'commission_value' => 150001])->assertSessionHasErrors('commission_value');
    expect(session('errors')->first('commission_value'))->toContain('lớn hơn giá dịch vụ');
    $post(['name' => 'B', 'price' => 0, 'commission_value' => 1])->assertSessionHasErrors('commission_value');
    expect(Service::count())->toBe(0);

    $post(['name' => 'C', 'price' => 150000, 'commission_value' => 150000])->assertSessionHasNoErrors();   // bằng giá thì được
    $post(['name' => 'D', 'price' => 0, 'commission_value' => 0])->assertSessionHasNoErrors();             // dịch vụ miễn phí
    expect(Service::count())->toBe(2);
});

it('tên dịch vụ không trùng (không phân biệt hoa thường); dịch vụ đã xóa thì dùng lại được tên', function () {
    $s = svService(['name' => 'Giặt ủi']);

    $this->actingAs($this->manager)->post(route('services.store'), svPayload(['name' => 'giặt ỦI']))->assertSessionHasErrors('name');

    $this->actingAs($this->manager)->delete(route('services.destroy', $s));
    $this->actingAs($this->manager)->post(route('services.store'), svPayload(['name' => 'Giặt ủi']))->assertSessionHasNoErrors();
    expect(Service::count())->toBe(1);
});

it('sửa dịch vụ: giữ mã và tên, không đặt trùng tên dịch vụ khác', function () {
    $a = svService(['name' => 'Dịch vụ A']);
    svService(['name' => 'Dịch vụ B']);

    $this->actingAs($this->manager)->put(route('services.update', $a), svPayload(['name' => 'Dịch vụ A', 'price' => 300000, 'commission_value' => 50000, 'is_active' => false]))->assertSessionHasNoErrors();
    expect($a->fresh()->price)->toBe(300000.0)->and($a->fresh()->is_active)->toBeFalse()->and($a->fresh()->code)->toBe($a->code);

    $this->actingAs($this->manager)->put(route('services.update', $a), svPayload(['name' => 'dịch vụ b']))->assertSessionHasErrors('name');
    expect($a->fresh()->name)->toBe('Dịch vụ A');
});

it('sửa giá xuống thấp hơn định mức tiền thì báo lỗi', function () {
    $s = svService(['price' => 150000, 'commission_type' => 'amount', 'commission_value' => 100000]);

    $this->actingAs($this->manager)->put(route('services.update', $s), svPayload(['name' => $s->name, 'price' => 80000, 'commission_value' => 100000]))
        ->assertSessionHasErrors('commission_value');
    expect($s->fresh()->price)->toBe(150000.0);
});

it('xóa dịch vụ là xóa mềm: không còn trong danh sách nhưng API vẫn tra được', function () {
    $s = svService(['name' => 'Cắt tóc']);

    $this->actingAs($this->manager)->delete(route('services.destroy', $s))->assertSessionHas('success');

    expect(Service::find($s->id))->toBeNull()
        ->and(Service::withTrashed()->find($s->id))->not->toBeNull()
        ->and(app(ServiceCatalogService::class)->find($s->id)['name'])->toBe('Cắt tóc');

    $this->actingAs($this->manager)->get(route('services.index'))->assertInertia(fn ($page) => $page->has('services.data', 0));
    $this->actingAs($this->manager)->put(route('services.update', $s), svPayload())->assertNotFound();
});

it('danh sách: hiện hoa hồng tự tính, tìm kiếm, lọc danh mục và trạng thái', function () {
    svService(['name' => 'A Giao hàng', 'service_category_id' => $this->cat->id, 'price' => 150000, 'commission_type' => 'percent', 'commission_value' => 10]);
    svService(['name' => 'B Photo', 'code' => 'DV-PHOTO', 'price' => 2000, 'commission_type' => 'amount', 'commission_value' => 500]);
    svService(['name' => 'C Lắp đặt', 'is_active' => false]);

    $this->actingAs(svUser('cashier'))->get(route('services.index'))
        ->assertInertia(fn ($page) => $page
            ->has('services.data', 3)
            ->where('services.data.0.name', 'A Giao hàng')
            ->where('services.data.0.category', 'Giao hàng')
            ->where('services.data.0.commission_type', 'percent')
            ->where('services.data.0.commission_value', 10)
            ->where('services.data.0.commission', 15000)
            ->where('services.data.1.commission', 500)
            ->has('categories', 1));

    $this->actingAs($this->manager)->get(route('services.index', ['search' => 'photo']))->assertInertia(fn ($page) => $page->has('services.data', 1)->where('services.data.0.name', 'B Photo'));
    $this->actingAs($this->manager)->get(route('services.index', ['search' => 'DV-PHOTO']))->assertInertia(fn ($page) => $page->has('services.data', 1));
    $this->actingAs($this->manager)->get(route('services.index', ['category' => $this->cat->id]))->assertInertia(fn ($page) => $page->has('services.data', 1)->where('services.data.0.name', 'A Giao hàng'));
    $this->actingAs($this->manager)->get(route('services.index', ['status' => 'inactive']))->assertInertia(fn ($page) => $page->has('services.data', 1)->where('services.data.0.name', 'C Lắp đặt'));
    $this->actingAs($this->manager)->get(route('services.index', ['status' => 'active']))->assertInertia(fn ($page) => $page->has('services.data', 2));
    $this->actingAs($this->manager)->get(route('services.index', ['status' => 'xyz', 'category' => 'abc']))->assertInertia(fn ($page) => $page->has('services.data', 3)->where('filters.status', null)->where('filters.category', null));
});

it('kiểm tra trùng tên khi đang nhập: bỏ qua chính nó khi sửa và dịch vụ đã xóa', function () {
    $a = svService(['name' => 'Dịch vụ A']);
    $deleted = svService(['name' => 'Đã xóa']);
    $deleted->delete();

    $this->actingAs($this->manager)->getJson(route('services.check', ['value' => 'DỊCH VỤ A']))->assertJson(['available' => false, 'message' => 'Tên dịch vụ này đã có.']);
    $this->actingAs($this->manager)->getJson(route('services.check', ['value' => 'Dịch vụ A', 'ignore' => $a->id]))->assertJson(['available' => true]);
    $this->actingAs($this->manager)->getJson(route('services.check', ['value' => 'Đã xóa']))->assertJson(['available' => true]);
    $this->actingAs($this->manager)->getJson(route('services.check', ['value' => '']))->assertJson(['available' => true]);
});

it('API cho Bán hàng: tra một dịch vụ kèm hoa hồng, tìm dịch vụ đang bán', function () {
    $api = app(ServiceCatalogService::class);
    $giao = svService(['name' => 'A Giao hàng', 'code' => 'DV-GIAO', 'service_category_id' => $this->cat->id, 'price' => 150000, 'commission_type' => 'percent', 'commission_value' => 10]);
    $photo = svService(['name' => 'B Photo', 'price' => 2000, 'commission_value' => 500]);
    svService(['name' => 'C Ngừng bán', 'is_active' => false]);

    expect($api->find($giao->id))->toBe([
        'id' => $giao->id, 'code' => 'DV-GIAO', 'name' => 'A Giao hàng', 'category_id' => $this->cat->id, 'category' => 'Giao hàng',
        'price' => 150000.0, 'commission_type' => 'percent', 'commission_value' => 10.0, 'commission' => 15000.0, 'note' => null, 'is_active' => true,
    ])
        ->and($api->find(999999))->toBeNull()
        ->and(collect($api->search())->pluck('name')->all())->toBe(['A Giao hàng', 'B Photo'])                       // mặc định chỉ dịch vụ đang bán
        ->and(collect($api->search('', null, false))->pluck('name')->all())->toBe(['A Giao hàng', 'B Photo', 'C Ngừng bán'])
        ->and(collect($api->search('photo'))->pluck('id')->all())->toBe([$photo->id])
        ->and(collect($api->search('DV-GIAO'))->pluck('id')->all())->toBe([$giao->id])
        ->and(collect($api->search('', $this->cat->id))->pluck('id')->all())->toBe([$giao->id])
        ->and($api->search('', null, true, 1))->toHaveCount(1);
});

it('cấp mã dịch vụ DV', function () {
    expect(DocumentNumber::next('service'))->toStartWith('DV-');
});

it('menu: nhóm Dịch vụ nằm giữa Sản phẩm và Kho; thu ngân chỉ thấy mục Dịch vụ', function () {
    $nav = fn (User $u) => collect(app(ModuleRegistry::class)->navigation($u));
    $labels = $nav($this->manager)->pluck('label')->all();

    expect(array_search('Khách hàng', $labels, true))->toBeLessThan(array_search('Sản phẩm', $labels, true))
        ->and(array_search('Sản phẩm', $labels, true))->toBeLessThan(array_search('Dịch vụ', $labels, true))
        ->and(array_search('Dịch vụ', $labels, true))->toBeLessThan(array_search('Kho', $labels, true))
        ->and(collect($nav($this->manager)->firstWhere('label', 'Dịch vụ')['items'])->pluck('route')->all())->toBe(['services.index', 'service_categories.index', 'service_combos.index', 'service_courses.index'])
        ->and(collect($nav(svUser('cashier'))->firstWhere('label', 'Dịch vụ')['items'])->pluck('route')->all())->toBe(['services.index', 'service_combos.index', 'service_courses.index']);
});
