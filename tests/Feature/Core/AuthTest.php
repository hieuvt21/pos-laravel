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

it('đổi mật khẩu lần đầu xong thì vào được bảng điều khiển', function () {
    $this->post('/login', ['login' => 'admin', 'password' => 'admin']);

    $this->put('/password', [
        'current_password' => 'admin',
        'password' => 'matkhau-moi-1',
        'password_confirmation' => 'matkhau-moi-1',
    ])->assertRedirect(route('dashboard'));

    expect(User::where('username', 'admin')->first()->must_change_password)->toBeFalse();

    $this->get('/dashboard')->assertOk();
});

it('không cho dùng lại mật khẩu cũ làm mật khẩu mới', function () {
    $this->post('/login', ['login' => 'admin', 'password' => 'admin']);

    $this->put('/password', [
        'current_password' => 'admin',
        'password' => 'admin',
        'password_confirmation' => 'admin',
    ])->assertSessionHasErrors('password');
});

it('từ chối đăng nhập sai mật khẩu', function () {
    $this->post('/login', ['login' => 'admin', 'password' => 'sai'])->assertSessionHasErrors('login');

    $this->assertGuest();
});

it('khách chưa đăng nhập bị chuyển về trang đăng nhập', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
});
