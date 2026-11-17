# Module Core

Nền tảng của mọi module: đăng nhập nhân viên, hồ sơ, người dùng, vai trò & quyền, cài đặt cửa hàng, giao diện, khung nạp module.

## Thư mục
```
app/Modules/Core/
  module.php                    # khai báo quyền, vai trò, menu
  CoreServiceProvider.php       # quét app/Modules: nạp migration, route, provider riêng của module
  Console/SyncCommand.php       # php artisan pos:sync
  Http/Controllers/...          # Auth, Profile, User, Role, StoreSettings, Appearance, Dashboard, Home
  Http/Middleware/EnsurePasswordIsChanged.php
  Http/Requests/LoginRequest.php
  Models/Setting.php
  Support/{ModuleRegistry,DocumentNumber,Money}.php
  Database/migrations/
  Resources/js/Pages/{Auth,Users,Roles,Store,Appearance,Profile}/..., Dashboard.vue
  routes.php
app/Models/User.php            # giữ ở app/Models (Laravel mặc định)
app/Http/Middleware/HandleInertiaRequests.php
resources/js/{Layouts,Components,composables,utils}/   # giao diện dùng chung cho mọi module
resources/css/{app.css,ui.css}
```

## Bảng
- `users`: thêm `username` (unique), `phone` (10 số, unique), `avatar`, `must_change_password`; `email` cho phép rỗng.
- `settings` (key, value): `Setting::read/write/json/putJson`, có cache. Khóa dùng: `shop_name`, `theme`, `logo_*`, `store_info`, `finance`, `numbering`.
- `document_sequences` (type, period, last_number): bộ đếm mã chứng từ.
- Bảng Spatie (permissions, roles, ...) do `vendor:publish` của gói tạo.

## API công khai (module khác được gọi)
**Backend**
- `Setting::read($key)`, `Setting::finance()`, `Setting::storeInfo()`, `Setting::branding()`.
- `Commission::amount($type, $value, $price)` (`Support/Commission.php`): tính hoa hồng nhân viên từ định mức, dùng chung cho Dịch vụ và Sản phẩm (`amount`: đúng số tiền; `percent`: % của giá, tối đa 100; không vượt giá, làm tròn theo số lẻ tiền tệ). Ô nhập dùng chung: `resources/js/Components/CommissionField.vue`. Chú thích: `HelpTip.vue` (biểu tượng ? bấm để xem, dùng PrimeVue Popover) và `FieldLabel.vue` (nhãn ô nhập kèm ?, biểu tượng nằm ngoài thẻ `<label>`); thay cho các dòng `field-hint` dưới ô nhập.
- `DocumentNumber::next('invoice'|'sale_return'|'purchase'|'stock_in'|'stock_out'|'stock_check'|'service'|'cash_in'|'cash_out'|'shift'|'product'|'customer'|'supplier')`. Thứ tự nhóm menu cố định trong `ModuleRegistry::GROUP_ORDER` (Tổng quan, Bán hàng, Khách hàng, Sản phẩm, Dịch vụ, Kho, Hàng hóa, Báo cáo, Hệ thống); nhóm không có trong danh sách (hoặc không khai `order`) xếp ở mức 80. Mục menu có thể khai `'badge' => [Class::class, 'method']`: hàm tĩnh nhận người dùng và trả số (hoặc null) hiện thành huy hiệu đỏ cạnh mục; lỗi khi tính thì bỏ huy hiệu, không làm hỏng menu.
- `Money::round($n)`, `Money::format($n)`.
- `CustomerTiers::for($spent)` (hạng + hạng kế tiếp), `discountFor($spent)`, `config()`, `enabled()`; setting `customer_tiers`.
- `ModuleRegistry` (đọc module.php): `permissionNames()`, `permissionGroups()`, `defaultRoles()`, `navigation($user)`.
- Trait `ManagesIdentity` (chuẩn hóa username/email/phone, ảnh đại diện) cho controller liên quan người dùng.

**Frontend** (`@/`): `usePermission()`, `useMoney()`, `useNavigation()`, `useBranding()`, `useUiSettings()`, `useUniqueCheck(field, getValue, { url, ignoreId, original })`; component `AppDialog`, `FieldStatus`, `PhoneInput`, `ImageInput`, `AvatarPicker`, `UserAvatar`, `BrandMark`; layout `AppLayout`.

## Dữ liệu chia sẻ cho mọi trang (Inertia)
`auth.user {id,name,username,email,avatar_url,roles,permissions}`, `navigation` (menu đã lọc quyền), `branding`, `finance`, `flash {success,error}`.

## Quyền (khai báo trong module.php)
`users.view|create|update|delete`, `roles.manage`, `settings.manage` (gồm Giao diện chung, Cửa hàng, Hạng thành viên). Vai trò mặc định: `admin` (toàn quyền), `manager`, `cashier`, `warehouse`.

## Đăng nhập & đổi mật khẩu lần đầu
- Đăng nhập bằng tên đăng nhập, số điện thoại hoặc email; chặn dò mật khẩu 5 lần/khóa.
- `users.must_change_password = true` khi tài khoản mới tạo, hoặc quản trị viên đặt lại mật khẩu người khác. Middleware `EnsurePasswordIsChanged` chuyển mọi trang về `password.force` cho tới khi đổi xong.
- Mật khẩu tối thiểu 6 ký tự. Màn đổi lần đầu không hỏi mật khẩu cũ.
- Không có đăng ký công khai, không có quên mật khẩu tự phục vụ (admin đặt lại ở trang Người dùng).

## Lịch sử
- Chuyển từ Almira (bản phẳng): bỏ Breeze/Sanctum/đăng ký/xác minh email; menu và quyền chuyển sang khai báo theo module.
