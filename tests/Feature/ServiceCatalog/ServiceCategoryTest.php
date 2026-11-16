<?php

use App\Models\User;
use App\Modules\ServiceCatalog\Models\Service;
use App\Modules\ServiceCatalog\Models\ServiceCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('pos:sync');
    $this->manager = scUser('manager');
});

function scUser(?string $role): User
{
    $user = User::factory()->create();
    if ($role) {
        $user->assignRole($role);
    }

    return $user;
}

function scService(ServiceCategory $category, array $over = []): Service
{
    static $n = 0;
    $n++;

    return Service::create(array_merge(['code' => 'DV-C'.$n, 'name' => 'Dịch vụ danh mục '.$n, 'service_category_id' => $category->id, 'price' => 1000], $over));
}

it('chỉ người có quyền quản lý danh mục dịch vụ mới vào được', function () {
    $c = ServiceCategory::create(['name' => 'Giao hàng', 'is_active' => true]);

    foreach (['cashier', 'warehouse', null] as $role) {
        $u = scUser($role);
        $this->actingAs($u)->get(route('service_categories.index'))->assertForbidden();
        $this->actingAs($u)->getJson(route('service_categories.check', ['value' => 'x']))->assertForbidden();
        $this->actingAs($u)->post(route('service_categories.store'), ['name' => 'X', 'is_active' => true])->assertForbidden();
        $this->actingAs($u)->put(route('service_categories.update', $c), ['name' => 'Y', 'is_active' => true])->assertForbidden();
        $this->actingAs($u)->delete(route('service_categories.destroy', $c))->assertForbidden();
    }

    $this->actingAs($this->manager)->get(route('service_categories.index'))->assertOk();
});

it('thêm danh mục dịch vụ', function () {
    $this->actingAs($this->manager)->post(route('service_categories.store'), ['name' => 'Giao hàng', 'description' => 'Vận chuyển', 'is_active' => true])->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->post(route('service_categories.store'), ['name' => 'Sửa chữa', 'is_active' => false])->assertSessionHasNoErrors();

    $a = ServiceCategory::where('name', 'Giao hàng')->first();
    $b = ServiceCategory::where('name', 'Sửa chữa')->first();
    expect($a->description)->toBe('Vận chuyển')->and($a->is_active)->toBeTrue()->and($b->is_active)->toBeFalse()->and($b->description)->toBeNull();
});

it('kiểm tra dữ liệu danh mục: tên bắt buộc, không trùng, độ dài, trạng thái', function () {
    ServiceCategory::create(['name' => 'Giao hàng', 'is_active' => true]);
    $post = fn (array $over) => $this->actingAs($this->manager)->post(route('service_categories.store'), $over + ['name' => 'Mới', 'is_active' => true]);

    $post(['name' => ''])->assertSessionHasErrors('name');
    $post(['name' => 'giao HÀNG'])->assertSessionHasErrors('name');
    $post(['name' => str_repeat('a', 151)])->assertSessionHasErrors('name');
    $post(['description' => str_repeat('a', 256)])->assertSessionHasErrors('description');
    $post(['is_active' => null])->assertSessionHasErrors('is_active');

    expect(ServiceCategory::count())->toBe(1);
});

it('sửa danh mục: giữ nguyên tên được, không đặt trùng tên danh mục khác', function () {
    $a = ServiceCategory::create(['name' => 'Giao hàng', 'is_active' => true]);
    ServiceCategory::create(['name' => 'Sửa chữa', 'is_active' => true]);

    $this->actingAs($this->manager)->put(route('service_categories.update', $a), ['name' => 'Giao hàng', 'description' => 'Mới', 'is_active' => false])->assertSessionHasNoErrors();
    expect($a->fresh()->description)->toBe('Mới')->and($a->fresh()->is_active)->toBeFalse();

    $this->actingAs($this->manager)->put(route('service_categories.update', $a), ['name' => 'sửa chữa', 'is_active' => true])->assertSessionHasErrors('name');
});

it('kiểm tra trùng tên khi đang nhập', function () {
    $a = ServiceCategory::create(['name' => 'Giao hàng', 'is_active' => true]);

    $this->actingAs($this->manager)->getJson(route('service_categories.check', ['value' => 'GIAO HÀNG']))->assertJson(['available' => false, 'message' => 'Tên danh mục này đã có.']);
    $this->actingAs($this->manager)->getJson(route('service_categories.check', ['value' => 'Giao hàng', 'ignore' => $a->id]))->assertJson(['available' => true]);
    $this->actingAs($this->manager)->getJson(route('service_categories.check', ['value' => '']))->assertJson(['available' => true]);
});

it('danh sách danh mục kèm số dịch vụ (không tính dịch vụ đã xóa)', function () {
    $b = ServiceCategory::create(['name' => 'B Sửa chữa', 'is_active' => true]);
    $a = ServiceCategory::create(['name' => 'A Giao hàng', 'is_active' => false]);
    scService($a);
    scService($a);
    scService($a)->delete();

    $this->actingAs($this->manager)->get(route('service_categories.index'))
        ->assertInertia(fn ($page) => $page
            ->has('categories', 2)
            ->where('categories.0.name', 'A Giao hàng')
            ->where('categories.0.services_count', 2)
            ->where('categories.0.is_active', false)
            ->where('categories.1.services_count', 0));
});

it('xóa danh mục chưa có dịch vụ; đang có dịch vụ (kể cả đã xóa mềm) thì không xóa được', function () {
    $unused = ServiceCategory::create(['name' => 'Trống', 'is_active' => true]);
    $used = ServiceCategory::create(['name' => 'Đang dùng', 'is_active' => true]);
    $trashed = ServiceCategory::create(['name' => 'Chỉ còn dịch vụ đã xóa', 'is_active' => true]);
    scService($used);
    scService($trashed)->delete();

    $this->actingAs($this->manager)->delete(route('service_categories.destroy', $unused))->assertSessionHas('success');
    $this->actingAs($this->manager)->delete(route('service_categories.destroy', $used))->assertSessionHas('error');
    $this->actingAs($this->manager)->delete(route('service_categories.destroy', $trashed))->assertSessionHas('error');

    expect(ServiceCategory::find($unused->id))->toBeNull()
        ->and(ServiceCategory::find($used->id))->not->toBeNull()
        ->and(ServiceCategory::find($trashed->id))->not->toBeNull();
});
