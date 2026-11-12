<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;

use App\Modules\Core\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AppearanceController extends Controller
{
    /** Trang Giao diện: ai cũng vào được (phần cá nhân hóa). Phần cửa hàng cần quyền settings.manage. */
    public function edit(): Response
    {
        return Inertia::render('Appearance/Edit', [
            'themes' => Setting::THEMES,
            'shopNameMax' => Setting::SHOP_NAME_MAX,
            'icons' => Setting::LOGO_ICONS,
        ]);
    }

    /** Lưu tên cửa hàng, logo, màu chủ đề (dùng chung toàn hệ thống). */
    public function updateBranding(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shop_name' => ['required', 'string', 'max:'.Setting::SHOP_NAME_MAX],
            'theme' => ['required', Rule::in([...Setting::THEMES, 'custom'])],
            'theme_custom' => ['nullable', 'required_if:theme,custom', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo_type' => ['required', Rule::in(['icon', 'image'])],
            'logo_icon' => ['required', Rule::in(Setting::LOGO_ICONS)],
            'logo_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024'],
        ], [
            'shop_name.required' => 'Vui lòng nhập tên cửa hàng.',
            'shop_name.max' => 'Tên hiển thị tối đa '.Setting::SHOP_NAME_MAX.' ký tự (tên đầy đủ nhập ở trang Cửa hàng).',
            'theme_custom.required_if' => 'Nhập mã màu, ví dụ #0f766e.',
            'theme_custom.regex' => 'Mã màu gồm # và 6 ký tự 0-9, a-f, ví dụ #0f766e.',
            'logo_file.max' => 'Ảnh logo tối đa 1 MB.',
            'logo_file.image' => 'Logo phải là ảnh JPG, PNG hoặc WebP.',
        ]);

        $values = [
            'shop_name' => trim($data['shop_name']),
            'theme' => $data['theme'],
            'logo_type' => $data['logo_type'],
            'logo_icon' => $data['logo_icon'],
        ];

        if (! empty($data['theme_custom'])) {
            $values['theme_custom'] = strtolower($data['theme_custom']);
        }

        if ($request->hasFile('logo_file')) {
            $file = $request->file('logo_file');
            $name = 'logo-'.Str::random(12).'.'.$file->extension();
            $file->move(public_path('uploads/branding'), $name);

            $this->deleteOldLogo();
            $values['logo_image'] = 'uploads/branding/'.$name;
            $values['logo_type'] = 'image';
        } elseif ($data['logo_type'] === 'image' && ! Setting::read('logo_image')) {
            return back()->withErrors(['logo_file' => 'Hãy chọn một ảnh logo để tải lên.']);
        }

        Setting::write($values);

        return back()->with('success', 'Đã lưu giao diện cửa hàng.');
    }

    private function deleteOldLogo(): void
    {
        $old = Setting::read('logo_image');

        if ($old && str_starts_with($old, 'uploads/branding/')) {
            @unlink(public_path($old));
        }
    }
}
