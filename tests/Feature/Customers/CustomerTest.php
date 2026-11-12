<?php

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('pos:sync');
    $this->admin = User::where('username', 'admin')->first();
    $this->admin->forceFill(['must_change_password' => false])->save();
});

it('tạo khách: tự cấp mã, chọn nữ thì gán ảnh mặc định nữ', function () {
    $this->actingAs($this->admin)->post(route('customers.store'), ['name' => 'Lan Anh', 'gender' => 'female', 'phone' => '0912345678'])
        ->assertSessionDoesntHaveErrors();

    $c = Customer::firstOrFail();
    expect($c->code)->toBe('KH-00001')
        ->and($c->avatar)->toBe('preset:nu')
        ->and($c->sort_name)->toBe('Anh Lan');
});

it('ảnh chọn tay được ưu tiên hơn ảnh mặc định theo giới tính', function () {
    $this->actingAs($this->admin)->post(route('customers.store'), ['name' => 'Minh', 'gender' => 'male', 'avatar_preset' => 'a03']);

    expect(Customer::first()->avatar)->toBe('preset:a03');
});

it('không cho trùng số điện thoại và bắt buộc có tên', function () {
    Customer::create(['code' => 'KH-X', 'name' => 'A', 'phone' => '0912345678']);

    $this->actingAs($this->admin)->post(route('customers.store'), ['name' => 'B', 'phone' => '0912345678'])->assertSessionHasErrors('phone');
    $this->actingAs($this->admin)->post(route('customers.store'), ['name' => ''])->assertSessionHasErrors('name');
});

it('xóa mềm khách chưa mua và giải phóng số điện thoại; khách đã mua thì không xóa được', function () {
    $a = Customer::create(['code' => 'KH-A', 'name' => 'A', 'phone' => '0911111111']);
    $b = Customer::create(['code' => 'KH-B', 'name' => 'B', 'total_spent' => 1000]);

    $this->actingAs($this->admin)->delete(route('customers.destroy', $a));
    $this->actingAs($this->admin)->delete(route('customers.destroy', $b));

    expect(Customer::count())->toBe(1)
        ->and(Customer::withTrashed()->find($a->id)->phone)->toBeNull();
});

it('CustomerService cộng và trừ chi tiêu, không để âm', function () {
    $c = Customer::create(['code' => 'KH-A', 'name' => 'A']);
    $svc = app(CustomerService::class);

    $svc->addSpent($c->id, 500000);
    $svc->subtractSpent($c->id, 200000);
    expect((float) $c->fresh()->total_spent)->toBe(300000.0);

    $svc->subtractSpent($c->id, 999999);
    expect((float) $c->fresh()->total_spent)->toBe(0.0);
});

it('cashier xem được nhưng không nhập/xuất Excel', function () {
    $u = (new User)->forceFill(['name' => 'Thu ngân', 'username' => 'thungan', 'password' => bcrypt('123456'), 'must_change_password' => false]);
    $u->save();
    $u->assignRole('cashier');

    $this->actingAs($u)->get(route('customers.index'))->assertOk();
    $this->actingAs($u)->get(route('customers.template'))->assertForbidden();
});
