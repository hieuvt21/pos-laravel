# POS Tạp hoá - Tóm tắt dự án

> Đọc file này đầu tiên trước khi làm bất cứ việc gì. Sau mỗi module hoàn thành hoặc mỗi lần sửa lớn, **cập nhật mục 8, 9, 10** và `docs/modules/<ten>.md`.
> Trả lời và viết giao diện bằng tiếng Việt. Trả lời ngắn gọn; chưa hiểu hoặc cần gì thì hỏi lại.

## 1. Mục tiêu & phạm vi

Phần mềm bán hàng (POS) cho **một cửa hàng tạp hoá, một kho**, dùng thật hằng ngày trên cPanel.

**Có làm:** bán hàng tại quầy (quét mã vạch), sản phẩm, tồn kho, nhập hàng, khách hàng + công nợ, ca làm việc, thu chi, báo cáo, phân quyền nhân viên, **cổng khách hàng** (khách đăng nhập xem lịch sử mua, đặt lịch hẹn; làm sau).

**KHÔNG làm:** nhiều chi nhánh, nhiều kho, bán hàng online, kế toán đầy đủ, app mobile riêng. Không thêm `store_id` / `warehouse_id` vào bảng.

## 2. Stack & môi trường

| Hạng mục | Chi tiết |
|---|---|
| Backend | Laravel 13, PHP ^8.4, MySQL, Spatie `laravel-permission` (+ `activitylog`, `maatwebsite/excel` đã cài, chưa dùng) |
| Frontend | Inertia 3 (`@inertiajs/vue3` ^3.11, `inertia-laravel` ^3.7) + Vue 3 (`<script setup>`) + PrimeVue 4 (^4.5, preset Aura, `@primeuix/themes` ^2) + PrimeIcons |
| Build / CSS | Vite 8 + Tailwind 4 (`@tailwindcss/vite`, **không** có `tailwind.config.js`, cấu hình bằng `@theme` trong CSS) + CSS riêng `resources/css/ui.css` (tiền tố `app-`) |
| Giao diện | Kính mờ, font Be Vietnam Pro (tự host qua `@fontsource`, subset latin + vietnamese), chỉ giao diện sáng |
| Test / chất lượng | Pest 4, Pint, Larastan |
| Dev | Windows + Laragon (Document Root của Laragon phải chứa thư mục dự án để có `pos.test`) |
| Chạy thật | cPanel: **không SSH, không Node, không Redis**. Build Vite ở GitHub Actions/máy dev; lệnh artisan chạy qua `deploy-helper.php` |

**Lưu ý phiên bản (AI hay viết nhầm cú pháp cũ):**
- Tailwind 4: `@import 'tailwindcss'`, `@theme`; không `tailwind.config.js`, không PostCSS plugin.
- Inertia 3: không Axios (XHR có sẵn), không `resolvePageComponent`; root view dùng `<x-inertia::head />` và `<x-inertia::app />`. Gọi API nhỏ bằng `fetch` (xem `useUniqueCheck`).
- PrimeVue 4 (không dùng bản 5 vì yêu cầu key). `import Aura from '@primeuix/themes/aura'`.
- `route()` của Ziggy: `@routes` trong `app.blade.php`, `import { ZiggyVue } from '../../vendor/tightenco/ziggy'` (không cài `ziggy-js`).
- Trong file route **không dùng closure** (để chạy được `route:cache`).

## 3. Kiến trúc module & quy ước code

Tự tổ chức trong `app/Modules/<Ten>/` (không dùng gói laravel-modules). `CoreServiceProvider` quét thư mục này: nạp `Database/migrations`, `routes.php` (nhóm `web`), và `<Ten>ServiceProvider` nếu có. **Thêm module mới không phải sửa file chung nào.**

```
app/Modules/<Ten>/
  module.php          # khai báo: label, depends_on, permissions, default_roles, navigation (xem docs/modules/core.md)
  routes.php
  Http/{Controllers,Requests}/  Models/  Services/  Policies/
  Database/migrations/
  Resources/js/Pages/<Tên trang>/...    # tên trang = phần sau "Pages/", phải duy nhất giữa các module
tests/Feature/<Ten>/
docs/modules/<ten>.md                   # theo docs/modules/_template.md
```
Dùng chung (không thuộc module nào): `resources/js/{Layouts,Components,composables,utils}`, `resources/css/{app.css,ui.css}`, `app/Models/User.php`, `app/Http/Middleware/HandleInertiaRequests.php`.

### Quy tắc bắt buộc
1. Module không truy cập Model của module khác; gọi qua Service công khai (vd `Sales` -> `Inventory\Services\StockService`).
2. Controller kiểu `UserController`: validate trong controller (hoặc Form Request), thông báo lỗi tiếng Việt, `redirect()->route(...)->with('success'|'error', ...)`. Logic nghiệp vụ nặng đặt trong Service.
3. **Migration chỉ thêm mới**, không sửa migration đã chạy (có dữ liệu thật trên cPanel).
4. Ghi nhiều bảng phải nằm trong `DB::transaction`. Tiền lưu số nguyên VND.
5. `SoftDeletes` cho sản phẩm, khách hàng, nhà cung cấp. Hoá đơn không xoá, chỉ huỷ/trả.
6. Mỗi route có `can:<quyền>` (hoặc `role:admin`); route cố định (`/x/check`) đặt **trước** route có `{id}`.
7. Quyền khai báo trong `module.php` của module (không sửa seeder chung); chạy `php artisan pos:sync` để đồng bộ.
8. Mã tự sinh dùng `DocumentNumber::next(...)`; tiền luôn qua `useMoney()` / `Money`, không viết cứng `₫`.
9. Mỗi module có test Pest cho logic quan trọng; chạy `pint` + `pest` sau mỗi thay đổi.
10. Form: ô đầu tự focus (dùng `AppDialog`), lỗi hiện dưới ô, hỗ trợ bàn phím và `aria-label`, ô bắt buộc đánh dấu sao đỏ (class `app-req`).

Đặt tên: bảng `snake_case` số nhiều; route `<module>.<resource>.<action>`; quyền `<nhóm>.<hành động>`.

## 4. Quy ước giao diện

- Layout: `Layouts/AppLayout.vue`, dùng `<AppLayout title="...">` trong từng trang (menu lấy từ `page.props.navigation`).
- CSS riêng `ui.css`, tiền tố class và biến **`app-`** (`.app-panel`, `--app-glass`); `.glass` là thẻ kính mờ. Màu chủ đạo do cài đặt Giao diện của cửa hàng quyết định (biến `--p-primary-*`, xem `useBranding`).
- Trang theo mẫu `Users/Index.vue`: `<Head>` + `<AppLayout>`, panel `app-panel app-fill`, `DataTable`, thêm/sửa trong `AppDialog`, `useForm`, xoá bằng `useConfirm`.
- Component dùng chung: `AppDialog` (dùng thay `Dialog`), `FieldStatus`, `PhoneInput`, `ImageInput`, `AvatarPicker`, `UserAvatar`, `BrandMark`. Toast và ConfirmDialog đã có sẵn trong layout.
- Cờ giao diện cá nhân (thanh bên ghim/tự thu, ảnh nền) lưu localStorage (`pos.ui`).

## 5. Xác thực & phân quyền

- **Nhân viên:** guard `web`, bảng `users`. Đăng nhập bằng tên đăng nhập / SĐT / email. Không đăng ký công khai; admin tạo tài khoản ở trang Người dùng.
- **Đổi mật khẩu lần đầu:** `users.must_change_password` bật khi tạo mới hoặc admin đặt lại mật khẩu; middleware chuyển về `/password/change` tới khi đổi xong. Tài khoản seed `admin` / `admin` cũng bị bắt đổi.
- **Quên mật khẩu:** không tự phục vụ; admin đặt lại ở trang Người dùng. Admin tự quên (không có SSH): dán mã băm vào bảng `users` bằng phpMyAdmin hoặc dùng helper tạm có token.
- **Vai trò mặc định** (`module.php` của Core): `admin` (toàn quyền qua `Gate::before`), `manager`, `cashier`, `warehouse`. `pos:sync` chỉ gán quyền mặc định cho vai trò mới tạo hoặc quyền mới thêm, không ghi đè phần đã chỉnh trên giao diện.
- **Cổng khách hàng (làm sau, module `Portal`, phụ thuộc `Customers`):** guard riêng `customer` trên bảng `customers` (cột mới `password`, `can_login`, `must_change_password`, `last_login_at`); đăng nhập ở `/portal/login` bằng SĐT + mật khẩu; admin tích "cho phép đăng nhập" ở trang Khách hàng -> đặt mật khẩu mặc định (cài đặt cửa hàng, ví dụ 123456) và bắt đổi ở lần đầu; quên mật khẩu = admin đặt lại. Quyền nhân viên không áp dụng cho khách. Giới hạn số lần đăng nhập sai, mọi truy vấn gắn với đúng khách đang đăng nhập.

## 6. Thành phần dùng lại & dữ liệu chung

Chi tiết ở `docs/modules/core.md`. Tóm tắt:
- Backend: `Setting` (key/value có cache: `shop_name`, `theme`, `logo_*`, `store_info`, `finance`, `numbering`), `DocumentNumber::next('invoice'|'sale_return'|'purchase'|'stock_check'|'cash_in'|'cash_out'|'shift'|'product'|'customer'|'supplier')`, `Money::round/format`, `ModuleRegistry`, trait `ManagesIdentity`.
- Frontend: `usePermission`, `useMoney`, `useNavigation`, `useBranding`, `useUiSettings`, `useUniqueCheck`.
- Inertia shared props: `auth.user {id,name,username,email,avatar_url,roles,permissions}`, `navigation`, `branding`, `finance`, `flash {success,error}`.
- Tên cửa hàng mặc định: **Pos** (đổi ở trang Cửa hàng / Giao diện).
- Ảnh tải lên lưu ở `public/uploads/{products,avatars,branding}` (không dùng `storage:link`).

## 7. Quy tắc nghiệp vụ cho Catalog / Kho / POS (kế thừa từ bản Almira)

- **Sản phẩm:** mỗi sản phẩm **một đơn vị tính** (`unit_id`), thuế chung (`tax_rate`). **Không có đơn vị quy đổi, không có biến thể** (muốn bán theo thùng thì tạo sản phẩm riêng).
- **Giá:** ở `product_prices` (mỗi bảng giá một dòng; bán lẻ = bảng giá mặc định, bắt buộc). POS luôn chọn thủ công được bảng giá. **Giá theo số lượng** (`products.qty_pricing`, mặc định tắt): bảng giá khác bán lẻ có `min_qty`; khi bật và số lượng dòng >= `min_qty` thì POS hỏi áp dụng giá đó (nhiều mức: lấy `min_qty` lớn nhất đã đạt).
- **Tồn kho:** `products.track_stock` (mặc định tắt) + `opening_stock`; chỉ sản phẩm bật mới bị trừ kho khi bán và **không cho tồn âm**. Module Kho ghi sổ bằng **phiếu nhập/xuất (`stock_movements`)**, `opening_stock` là phiếu nhập tồn đầu kỳ; tồn hiện tại = tổng movement, cache ở cột tồn trong cùng transaction.
- `products.cost_price` (mặc định 0) dành cho Nhập hàng; giá vốn bình quân gia quyền.
- **Hoá đơn:** snapshot tên, giá, giá vốn tại thời điểm bán. Bán hàng chạy trong transaction + `lockForUpdate` trên tồn; mỗi hoá đơn gắn **mã duy nhất do trình duyệt sinh (idempotency key)** để bấm đúp/mạng chập chờn không ra hai hoá đơn.
- **Khách hàng:** `customers.total_spent` do POS cộng dồn; giảm giá theo hạng: `CustomerTiers::discountFor($total_spent)` (Setting `customer_tiers`). SĐT 10 số, duy nhất.
- In hoá đơn bằng CSS `@media print` (khổ 58/80mm).

## 8. Sơ đồ module & tiến trình

```
Core (nền tảng)  <- mọi module
Catalog   <- Inventory, Purchasing, Sales
Inventory <- Purchasing, Sales
Customers <- Sales, Portal
Purchasing -> Inventory | Sales -> Catalog, Inventory, Customers | Reports chỉ đọc
```

- [x] 0. Khởi tạo dự án (Laravel, Inertia 3, PrimeVue 4, Tailwind 4, font, Pest, Pint, Larastan)
- [x] 1. **Core** (code đã viết, chờ bạn chạy thử): layout kính mờ, đăng nhập, đổi mật khẩu lần đầu, hồ sơ, người dùng, vai trò & quyền, cửa hàng (thông tin, tiền tệ & thuế, đánh số chứng từ), giao diện, khung module, `pos:sync`
- [ ] 0b. Thử deploy lên cPanel bằng workflow + `deploy-helper.php` (xác nhận PHP 8.4, zip, migrate, pos:sync)
- [ ] 2. Catalog (danh mục, đơn vị tính, bảng giá, sản phẩm) = module mẫu
- [ ] 3. Customers (khách hàng, hạng khách hàng, nhập/xuất Excel chỉ admin)
- [ ] 4. Inventory -> 5. Purchasing -> 6. Sales/POS (+ mẫu hoá đơn, phương thức thanh toán, chính sách giảm giá/hoàn trả) -> 7. Ca làm việc -> 8. Portal (cổng khách) -> 9. Báo cáo -> 10. Sao lưu, nhật ký hoạt động, tối ưu

## 9. Quyết định đã chốt

| # | Quyết định | Lý do |
|---|---|---|
| 1 | Tự tổ chức `app/Modules/`, không dùng laravel-modules; menu/quyền khai báo trong `module.php` của từng module | Nhẹ, thêm module không sửa file chung |
| 2 | Chuyển từ bản Almira (giữ logic + giao diện), bỏ Breeze/Sanctum/đăng ký/xác minh email/quên mật khẩu qua email | Đã chạy tốt; cPanel không chắc gửi được email |
| 3 | Không đơn vị quy đổi, không biến thể | Quyết định từ Almira, đơn giản hơn |
| 4 | Kho ghi sổ bằng phiếu nhập/xuất `stock_movements` | Truy vết được, tránh lệch số |
| 5 | Tiền là số nguyên VND, làm tròn theo cài đặt cửa hàng | Tránh lỗi làm tròn |
| 6 | Tiền tố CSS `app-` (thay `al-` của Almira) | Một tiền tố để khỏi trùng Tailwind/PrimeVue |
| 7 | Tailwind 4, PrimeVue 4, Inertia 3 | PrimeVue 5 yêu cầu key; Tailwind 4 khớp bộ khung Laravel 13 |
| 8 | Font tự host, chỉ giao diện sáng | Nhanh, ổn định; giảm phạm vi |
| 9 | Khách đăng nhập bằng guard `customer` riêng, `/portal/login` riêng | Tách hẳn quyền nhân viên |
| 10 | Deploy: GitHub Actions build -> FTP zip -> `deploy-helper.php` chạy migrate + `pos:sync` | cPanel không SSH/Node |
| 11 | Tên cửa hàng mặc định "Pos" | Theo yêu cầu |

## 10. Việc đang làm / bước tiếp theo

- Đang làm: chạy thử Core trên Laragon (đăng nhập `admin`/`admin` -> đổi mật khẩu -> các trang Core).
- Tiếp theo: 0b (deploy thử), rồi Catalog, Customers.
- Còn mở: chưa có nhật ký hoạt động (activitylog); `useUniqueCheck` còn nhắc route `customers.check`/`products.check` (sẽ có khi làm module tương ứng); bước tải FTP trong workflow đang dùng `--insecure` (bỏ kiểm tra chứng chỉ) nên đổi nếu host có chứng chỉ hợp lệ.

## 11. Làm việc với AI

**Module mới:** gửi `tom-tat.md` + `docs/modules/_template.md` + một module mẫu hoàn chỉnh (Core, sau này Catalog) + yêu cầu. Yêu cầu AI liệt kê bảng, route, quyền, API công khai dự kiến **trước khi code**. Sau khi xong: test Pest, `pint`, tạo `docs/modules/<ten>.md`, cập nhật mục 8, 9, 10.

**Sửa module cũ:** gửi `tom-tat.md` + `docs/modules/<ten>.md` + code `app/Modules/<Ten>/` + các file module khác gọi vào nó + yêu cầu và ràng buộc (không phá API công khai; đổi DB = migration mới).

**Giao code:** zip giữ nguyên đường dẫn từ thư mục gốc dự án (giải nén đè), kèm lệnh cần chạy (`composer dump-autoload`, `php artisan migrate`, `php artisan pos:sync`, `npm install ...`) và danh sách file cần xoá.

## 12. Deploy cPanel

1. Push lên nhánh `main` -> `.github/workflows/deploy.yml`: cài PHP 8.4, `composer install --no-dev`, `npm ci && npm run build`, nén `deploy.zip`, tải lên bằng FTP, tải `deploy-helper.php` vào `public/`.
2. Workflow gọi `POST /deploy-helper.php` (header `X-Deploy-Token`, khớp `DEPLOY_TOKEN` trong `.env` trên server): giải nén, xoá cache, `migrate --force`, `pos:sync`, rồi tự xoá.
3. Secrets GitHub: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `DEPLOY_TOKEN`, `APP_URL`.
4. Production `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_DRIVER=database` (hoặc file), `QUEUE_CONNECTION=database`/`sync`, `CACHE_STORE=file`.

## 13. Lưu ý đã gặp lỗi

- `bootstrap/app.php` phải có `$middleware->web(append: [HandleInertiaRequests::class, ...])`, thiếu thì mất toàn bộ shared props.
- Mật khẩu: model đã cast `hashed`, truyền chuỗi thường, không `bcrypt()`.
- Email cho phép rỗng; username 10 chữ số bị cấm (tránh nhầm số điện thoại).
- Sau khi thêm quyền ở `module.php` phải chạy `php artisan pos:sync` (deploy tự chạy).
