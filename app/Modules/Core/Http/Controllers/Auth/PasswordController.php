<?php

namespace App\Modules\Core\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    /** Trang bắt buộc đổi mật khẩu ở lần đăng nhập đầu (hoặc sau khi quản trị viên đặt lại). */
    public function edit(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/ForceChangePassword');
    }

    /** Đổi mật khẩu: dùng cho cả trang hồ sơ và trang bắt buộc đổi lần đầu. */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => [
                'required', 'confirmed', Password::min(8),
                'different:current_password',
                Rule::notIn(array_filter([$user->username, $user->phone])),
            ],
        ], [
            'current_password.required' => 'Vui lòng nhập mật khẩu hiện tại.',
            'current_password.current_password' => 'Mật khẩu hiện tại không đúng.',
            'password.required' => 'Vui lòng nhập mật khẩu mới.',
            'password.min' => 'Mật khẩu mới tối thiểu 8 ký tự.',
            'password.confirmed' => 'Nhập lại mật khẩu mới chưa khớp.',
            'password.different' => 'Mật khẩu mới phải khác mật khẩu hiện tại.',
            'password.not_in' => 'Mật khẩu mới không được trùng tên đăng nhập hoặc số điện thoại.',
        ]);

        $wasForced = (bool) $user->must_change_password;

        $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();

        if ($wasForced) {
            return redirect()->route('dashboard')->with('success', 'Đã đổi mật khẩu.');
        }

        return back();
    }
}
