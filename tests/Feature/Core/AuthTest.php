<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->artisan('pos:sync')->assertSuccessful();
});

it('đăng nhập bằng tên đăng nhập và bị chuyển sang trang đổi mật khẩu lần đầu', function () {
    $this->post('/login', ['login' => 'admin', 'password' => 'admin'])->assertRedirect();

    $this->get('/dashboard')->assertRedirect(route('password.force'));
});

it('đổi mật khẩu lần đầu không cần mật khẩu cũ', function () {
    $this->post('/login', ['login' => 'admin', 'password' => 'admin']);

    $this->put('/password', [
        'password' => 'mkmoi1',
        'password_confirmation' => 'mkmoi1',
    ])->assertRedirect(route('dashboard'));

    expect(User::where('username', 'admin')->first()->must_change_password)->toBeFalse();

    $this->get('/dashboard')->assertOk();
});

it('mật khẩu mới tối thiểu 6 ký tự', function () {
    $this->post('/login', ['login' => 'admin', 'password' => 'admin']);

    $this->put('/password', ['password' => '12345', 'password_confirmation' => '12345'])
        ->assertSessionHasErrors('password');
});

it('không cho dùng lại mật khẩu đang dùng', function () {
    User::where('username', 'admin')->first()->forceFill(['password' => '123456'])->save();
    $this->post('/login', ['login' => 'admin', 'password' => '123456']);

    $this->put('/password', ['password' => '123456', 'password_confirmation' => '123456'])
        ->assertSessionHasErrors('password');
});

it('tự đổi mật khẩu ở trang hồ sơ thì phải nhập mật khẩu hiện tại', function () {
    $user = User::where('username', 'admin')->first();
    $user->forceFill(['must_change_password' => false])->save();
    $this->actingAs($user);

    $this->put('/password', ['password' => 'mkmoi1', 'password_confirmation' => 'mkmoi1'])
        ->assertSessionHasErrors('current_password');

    $this->put('/password', ['current_password' => 'admin', 'password' => 'mkmoi1', 'password_confirmation' => 'mkmoi1'])
        ->assertSessionDoesntHaveErrors();
});

it('từ chối đăng nhập sai mật khẩu', function () {
    $this->post('/login', ['login' => 'admin', 'password' => 'sai'])->assertSessionHasErrors('login');

    $this->assertGuest();
});

it('khách chưa đăng nhập bị chuyển về trang đăng nhập', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
});
