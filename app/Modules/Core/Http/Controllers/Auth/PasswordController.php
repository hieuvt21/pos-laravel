<?php

namespace App\Modules\Core\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    /** Độ dài tối thiểu của mật khẩu (cố ý không khắt khe). */
    public const MIN_LENGTH = 6;

    /** Trang bắt buộc đổi mật khẩu ở lần đăng nhập đầu (hoặc sau khi quản trị viên đặt lại). */
    public function edit(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/ForceChangePassword');
    }

    /**
     * Đổi mật khẩu.
     * - Đổi bắt buộc (lần đăng nhập đầu / vừa được đặt lại): KHÔNG cần nhập mật khẩu cũ.
     * - Tự đổi ở trang Hồ sơ: cần nhập mật khẩu hiện tại.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $forced = (bool) $user->must_change_password;

        $rules = ['password' => ['required', 'string', 'min:'.self::MIN_LENGTH, 'confirmed']];
        if (! $forced) {
            $rules['current_password'] = ['required', 'current_password'];
        }

        $data = $request->validate($rules, [
            'current_password.required' => 'Vui lòng nhập mật khẩu hiện tại.',
            'current_password.current_password' => 'Mật khẩu hiện tại không đúng.',
            'password.required' => 'Vui lòng nhập mật khẩu mới.',
            'password.min' => 'Mật khẩu mới tối thiểu '.self::MIN_LENGTH.' ký tự.',
            'password.confirmed' => 'Nhập lại mật khẩu mới chưa khớp.',
        ]);

        // Mật khẩu mới phải khác mật khẩu đang dùng (kể cả mật khẩu mặc định được cấp)
        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Mật khẩu mới phải khác mật khẩu hiện tại.']);
        }

        $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();

        if ($forced) {
            return redirect()->route('dashboard')->with('success', 'Đã đổi mật khẩu.');
        }

        return back();
    }
}
