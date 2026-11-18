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
- Component dùng chung: `AppDialog` (dùng thay `Dialog`), `FieldStatus`, `PhoneInput`, `ImageInput`, `AvatarPicker`, `UserAvatar`, `BrandMark`, `CommissionField` (ô định mức hoa hồng), `HelpTip` và `FieldLabel` (chú thích). Toast và ConfirmDialog đã có sẵn trong layout.
- **Chú thích ẩn sau biểu tượng ?:** mọi lời giải thích (hướng dẫn nhập, ý nghĩa tùy chọn) dùng `HelpTip` (bấm biểu tượng ? thì hiện khung nội dung, bấm ra ngoài hoặc Esc để đóng); với ô nhập dùng `<FieldLabel for="id">Tên<template #help>Nội dung</template></FieldLabel>` (biểu tượng nằm ngoài thẻ `<label>`). **Không** viết thêm dòng `field-hint` dưới ô nhập. Vẫn để hiện sẵn: lỗi, cảnh báo cần thấy ngay (ví dụ "Màu hơi sáng"), trạng thái (Đang tải, Mặc định) và dữ liệu phụ.
- Cờ giao diện cá nhân (thanh bên ghim/tự thu, ảnh nền) lưu localStorage (`pos.ui`).
- **Trường bắt buộc:** dấu sao đỏ ngay sau nhãn: `<span class="app-req" aria-hidden="true">*</span>`. Trường tùy chọn thì KHÔNG ghi "không bắt buộc".
- **Tự viết hoa chữ cái đầu** ở mọi ô nhập chữ (`utils/autoCapitalize.js`, cài ở `app.js`). Tên người (khách, người dùng, chủ cửa hàng) thêm `data-cap="words"` để viết hoa đầu mỗi từ. Ô mã/mã vạch/tên đăng nhập/email/số/mật khẩu/tìm kiếm tự được bỏ qua; ô khác muốn tắt thì thêm `data-nocap`. Ô mới phải đặt `id` không chứa `code|barcode|username|login|email|phone|tax|hex` nếu là ô mã.
- **Menu:** mỗi module một nhóm riêng trong `module.php` (Khách hàng, Sản phẩm...), không gộp chung "Bán hàng".
- **Cảm ứng:** không để thông tin chỉ nằm trong tooltip (hover). Thông tin đó phải có ở khung chi tiết, và thêm nút ⓘ chạm để mở `Popover`.
- **Responsive:** mọi trang phải dùng tốt trên máy tính bảng và điện thoại: lưới `minmax(min(100%, Xrem), 1fr)`, bảng cuộn ngang, nút/ô nhập cao tối thiểu 44px, chữ ô nhập >= 16px, dialog ~96vw. Breakpoint dùng: 1023 / 767 / 480px (cuối `ui.css`).
- **Giao diện vs Cửa hàng:** trang Giao diện = tên hiển thị (tối đa 12 ký tự, hiện trên thanh bên/tab), logo, màu chủ đề (17 màu hoặc tự nhập mã hex). Trang Cửa hàng = tên đầy đủ, logo, địa chỉ... in trên hóa đơn (độc lập hoàn toàn, `store_info.name` + setting `store_logo`).
- **Hạng thành viên:** menu Hệ thống > Hạng thành viên; `CustomerTiers::for($tongChiTieu)` / `discountFor()`; component `TierBadge`. Setting key `customer_tiers`.

## 5. Xác thực & phân quyền

- **Nhân viên:** guard `web`, bảng `users`. Đăng nhập bằng tên đăng nhập / SĐT / email. Mật khẩu tối thiểu **6 ký tự** (không bắt buộc phức tạp). Không đăng ký công khai; admin tạo tài khoản ở trang Người dùng.
- **Đổi mật khẩu lần đầu:** `users.must_change_password` bật khi tạo mới hoặc admin đặt lại mật khẩu; middleware chuyển về `/password/change` tới khi đổi xong; màn này **không hỏi mật khẩu cũ** (nhưng mật khẩu mới phải khác mật khẩu được cấp). Đổi mật khẩu tự nguyện ở trang Hồ sơ vẫn hỏi mật khẩu hiện tại. Tài khoản seed `admin` / `admin` cũng bị bắt đổi.
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
- **Tồn kho:** `products.track_stock` (mặc định tắt); chỉ sản phẩm bật mới bị trừ kho khi bán. **Mặc định không cho tồn âm**, có công tắc cho phép (Setting `inventory.allow_negative`, bật thì POS cảnh báo). Module Inventory ghi sổ bằng `stock_movements` (chỉ thêm), tồn hiện tại cache ở bảng `stock_levels` của Inventory (không ghi vào bảng Catalog). Tồn đầu kỳ nhập bằng phiếu nhập kho (không có ô ở form sản phẩm). **Không có huỷ phiếu**: sai thì lập phiếu ngược hoặc kiểm kho. Số lượng tối đa 3 chữ số thập phân; số lẻ chỉ khi `units.allow_decimal`. `products.min_stock` là ngưỡng "sắp hết".
- `products.cost_price` (mặc định 0): Inventory cập nhật bình quân gia quyền khi nhập có giá (qua `CatalogService::setCostPrice`); xuất/bán/kiểm kho không đổi giá vốn. Trả hàng nhập lại theo giá vốn snapshot trên hóa đơn gốc. Giá vốn chỉ hiện và sửa được với quyền `inventory.cost` (kể cả ô giá vốn ở form sản phẩm).
- **Hoá đơn:** snapshot tên, giá, giá vốn tại thời điểm bán. Bán hàng chạy trong transaction + `lockForUpdate` trên tồn; mỗi hoá đơn gắn **mã duy nhất do trình duyệt sinh (idempotency key)** để bấm đúp/mạng chập chờn không ra hai hoá đơn.
- **Khách hàng:** `customers.total_spent` do POS cộng dồn; giảm giá theo hạng: `CustomerTiers::discountFor($total_spent)` (Setting `customer_tiers`). SĐT 10 số, duy nhất.
- In hoá đơn bằng CSS `@media print` (khổ 58/80mm).

## 8. Sơ đồ module & tiến trình

```
Core (nền tảng)  <- mọi module
Catalog   <- Inventory, Purchasing, Sales
Inventory <- Purchasing, Sales
Customers <- Sales, Portal
ServiceCatalog <- Packages (Bước B), Sales
Purchasing -> Inventory | Sales -> Catalog, Inventory, Customers | Reports chỉ đọc
```

- [x] 0. Khởi tạo dự án (Laravel, Inertia 3, PrimeVue 4, Tailwind 4, font, Pest, Pint, Larastan)
- [x] 1. **Core** (code đã viết, chờ bạn chạy thử): layout kính mờ, đăng nhập, đổi mật khẩu lần đầu, hồ sơ, người dùng, vai trò & quyền, cửa hàng (thông tin, tiền tệ & thuế, đánh số chứng từ), giao diện, khung module, `pos:sync`
- [ ] 0b. Thử deploy lên cPanel bằng workflow + `deploy-helper.php` (xác nhận PHP 8.4, zip, migrate, pos:sync)
- [x] 2. Catalog (danh mục, đơn vị tính, bảng giá, sản phẩm) = module mẫu - code xong, chờ chạy thử
- [x] 3. Customers (khách hàng, giới tính, nhập/xuất Excel theo quyền) - code xong, chờ chạy thử
- [ ] 4. Inventory: **(a) `StockService`, (b) Tồn kho + thẻ kho: đã chạy thử. (c) phiếu nhập/xuất, (d) kiểm kho, (e) huy hiệu "sắp hết" + nhập Excel tồn đầu kỳ: code xong, chờ chạy thử.** Module hoàn chỉnh theo kế hoạch. Xem `docs/modules/inventory.md`
- [ ] Dịch vụ (module `ServiceCatalog`, làm trước Bán hàng): **code xong, chờ chạy thử.** Danh mục dịch vụ riêng, dịch vụ giá cố định (không đơn vị, không tồn kho), hoa hồng nhân viên tính từ một ô định mức (₫ hoặc %, tối đa 100%). Xem `docs/modules/service-catalog.md`
- [ ] Combo và Liệu trình (Bước A, trong module `ServiceCatalog`): **mẫu gói bán trước, code xong, chờ chạy thử.** Combo = 1 dịch vụ nhiều lần, Liệu trình = nhiều dịch vụ; giới hạn theo số lần và/hoặc hạn sử dụng (tính từ ngày mua); bảo hành sau khi dùng hết gói. Xem `docs/modules/service-catalog.md`. **Bước B** (module `Packages`: gói của khách, trừ buổi, hết hạn, bảo hành, gia hạn/tặng buổi) làm cùng Bán hàng.
- [ ] 5. Purchasing -> 6. Sales/POS (+ mẫu hoá đơn, phương thức thanh toán, chính sách giảm giá/hoàn trả) -> 7. Ca làm việc -> 8. Portal (cổng khách) -> 9. Báo cáo -> 10. Sao lưu, nhật ký hoạt động, tối ưu

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
| 12 | Inventory: bỏ "huỷ phiếu"; sai thì lập phiếu ngược hoặc kiểm kho | Huỷ phiếu kéo theo tồn âm, lệch giá vốn bình quân; shop nhỏ hiếm dùng |
| 13 | Số tồn cache ở `stock_levels` (Inventory), không thêm cột vào `products` | Giữ ranh giới module; khóa dòng tồn không khóa dòng sản phẩm |
| 14 | Cho phép số lẻ theo đơn vị tính (`units.allow_decimal`), không theo công tắc chung | Cửa hàng vừa bán cân (kg) vừa bán cái |
| 15 | Tồn âm: công tắc `inventory.allow_negative`, mặc định tắt | Chặn bán quá tồn; cần thì bật và POS cảnh báo |
| 16 | Nhà cung cấp làm đơn giản, là mục con của Kho (không tách module), dùng quyền `inventory.receive`; chỉ ghi nguồn của phiếu nhập, chưa có công nợ | Cửa hàng chỉ cần biết hàng nhập từ đâu; công nợ để Purchasing nếu sau này cần |
| 17 | Dịch vụ là module riêng `ServiceCatalog`, làm đơn giản: giá cố định, không đơn vị, không tồn kho, không thuế riêng, không Excel | Cửa hàng vừa bán sản phẩm vừa cung cấp dịch vụ; Bán hàng sau này bán chung một hóa đơn |
| 18 | Hoa hồng dịch vụ khai báo bằng một ô "định mức" (₫ hoặc %); số tiền hoa hồng không lưu mà tính từ giá và định mức; % tối đa 100, định mức tiền tối đa bằng giá | Đổi giá thì hoa hồng tự theo; hoa hồng không thể vượt giá dịch vụ |
| 19 | Dịch vụ bắt buộc thuộc một danh mục dịch vụ đang sử dụng | Báo cáo và Bán hàng sau này nhóm theo danh mục; tránh dịch vụ "không phân loại" |
| 20 | Sản phẩm cũng có hoa hồng nhân viên bằng ô "định mức" (₫ hoặc %) như Dịch vụ; hoa hồng hiển thị tính trên giá bán lẻ, khi bán tính lại theo giá bán thực tế | Sản phẩm có nhiều bảng giá nên cần một giá chuẩn để hiển thị; số tiền không lưu |
| 21 | Tính hoa hồng dùng chung: `Core\Support\Commission` (PHP) và `Components/CommissionField.vue` (giao diện) | Catalog không phụ thuộc module Dịch vụ; một quy tắc duy nhất cho cả hai |
| 22 | Combo và Liệu trình dùng chung một mô hình (`service_packages` + `service_package_items`, cột `type`), dùng chung danh mục dịch vụ; mẫu gói ở `ServiceCatalog`, gói của khách ở module `Packages` (Bước B) | Combo chỉ là gói 1 dịch vụ; tách mẫu và gói đã bán để đúng ranh giới module |
| 23 | Gói phải có ít nhất một giới hạn: không có hạn sử dụng thì mọi dịch vụ phải có số lần; có hạn thì được không giới hạn số lần. Hạn tính từ ngày mua, hết hạn không hoàn tiền | Gói không giới hạn cả hai thì vô nghĩa; hạn tính từ ngày mua rõ ràng nhất |
| 24 | Bảo hành tính theo số ngày từ lúc dùng hết gói; mỗi lượt bảo hành vẫn ghi lịch sử, không trừ buổi, mặc định không hoa hồng | Theo yêu cầu cửa hàng; vẫn theo dõi được |
| 25 | Hoa hồng gói có hai loại: người BÁN (tính trên giá gói) và người THỰC HIỆN khi dùng (tính trên giá lẻ của dịch vụ). Liệu trình chọn "theo từng dịch vụ" (mặc định theo định mức dịch vụ) hoặc "hoa hồng chung"; combo luôn theo dịch vụ | Tính trên giá lẻ để dòng không giới hạn số lần không cần phân bổ giá gói |
| 26 | Hết hạn tính khi đọc (so `expires_at` với hiện tại), không dùng cron; sửa sai bằng dòng đảo trong sổ chỉ thêm, không xóa | cPanel không SSH/cron chắc chắn; giống quyết định #12 của kho |

## 10. Việc đang làm / bước tiếp theo

- Vừa xong (chờ chạy thử): Combo và Liệu trình, Bước A (mẫu gói) trong `ServiceCatalog`: migration `2026_11_18_100001` (`service_packages`, `service_package_items`), `ServicePackage`, `ServicePackageItem`, `ServicePackageController` (route `service_combos.index`, `service_courses.index`, `service_packages.check|store|update|destroy`), trang `Services/Packages.vue` dùng chung (prop `type`), menu Combo và Liệu trình trong nhóm Dịch vụ, quyền `service_packages.*`, khóa số `service_package` (GOI), `ServiceCatalogService::findPackage|searchPackages|packageArray`. Chặn xóa dịch vụ đang nằm trong gói và danh mục còn gói (sửa `ServiceController::destroy`, `ServiceCategoryController::destroy`). Test mới `ServicePackageTest`; `ServiceTest` (menu) đã cập nhật. Chưa chạy được Pest, Pint, `npm run build` ở môi trường soạn code (chỉ kiểm cú pháp PHP bằng `php -l`; file Vue chưa biên dịch thử).

- Vừa xong (chờ chạy thử): chú thích gom vào biểu tượng ? (`HelpTip`, `FieldLabel`) ở mọi form: sản phẩm, hoa hồng, tồn kho, cài đặt kho, giao diện, cửa hàng, hạng thành viên, khách hàng, người dùng, vai trò, hồ sơ, đổi mật khẩu, avatar, nhà cung cấp, danh mục dịch vụ. Form sản phẩm: mục Thuế (bỏ tiêu đề "Thuế") dời xuống dưới "Tự động chuyển giá theo số lượng" trong khối Giá bán.

- Vừa xong (chờ chạy thử): hoa hồng cho Sản phẩm: migration `..._200001` (`products.commission_type`, `commission_value`), mục "Hoa hồng nhân viên" trong form sản phẩm (ô định mức ₫/% + ô hoa hồng cùng dòng, tính ngay khi gõ), hoa hồng ở chi tiết sản phẩm và danh sách (tính trên giá bán lẻ), kiểm tra % ≤ 100 và tiền ≤ giá bán lẻ. Chuyển `Commission` sang Core, tách ô nhập thành `CommissionField.vue` dùng chung (Dịch vụ cũng dùng). Test mới `ProductCommissionTest`; `CommissionTest` chuyển sang `tests/Feature/Core`.

- Vừa xong (chờ chạy thử): chỉnh Dịch vụ: bắt buộc chọn danh mục; nút ₫/% gắn liền ô định mức, Định mức và Hoa hồng cùng một dòng, hoa hồng tính lại ngay khi gõ (`@input`); thanh cuộn của menu bên trái mảnh (5px), ẩn hẳn và CHỈ hiện khi rê chuột vào vùng menu (`.app-nav` trong `ui.css`, Chrome/Edge/Safari dùng `::-webkit-scrollbar`, Firefox dùng `scrollbar-color`). Không dùng `:focus-within` vì sau khi bấm một mục menu, mục đó giữ focus làm thanh cuộn hiện mãi.

- Vừa xong (chờ chạy thử): module `ServiceCatalog` (Dịch vụ): migration `..._100001` (`service_categories`, `services`), `Service` (xóa mềm, `commission` tự tính), `Commission::amount`, `ServiceCatalogService` (`find`, `search`), `ServiceController` + `ServiceCategoryController` (route `services.*`, `service_categories.*`), trang `Services/Index.vue` (ô định mức ₫/% + ô hoa hồng chỉ đọc) và `Services/Categories.vue`, menu nhóm Dịch vụ (giữa Sản phẩm và Kho, `GROUP_ORDER` 25), khóa số `service` (DV). Test: `CommissionTest`, `ServiceTest`, `ServiceCategoryTest`.

- Vừa xong (chờ chạy thử): Nhà cung cấp (bản đơn giản) trong Kho: migration `..._500001` (bảng `suppliers`, `stock_documents.supplier_id`), `Supplier`, `SupplierController` (route `inventory.suppliers.*`), trang `Suppliers/Index.vue`, ô chọn nhà cung cấp ở phiếu nhập và nhập Excel, cột và bộ lọc nhà cung cấp ở danh sách phiếu, hiện ở chi tiết phiếu và thẻ kho, mục menu "Nhà cung cấp". Test `SupplierTest`.

- Vừa xong (chờ chạy thử): Inventory bước (d) và (e): `StockCountService` + `StockCountController` (kiểm kho: nháp, lưu, xác nhận, xóa nháp; báo khi tồn đã đổi từ lúc lưu nháp), `StockImportController` (nhập Excel/CSV tồn đầu kỳ, cả file một phiếu, có lỗi thì không nhập gì), `StockAlerts` (huy hiệu sắp hết/hết hàng trên menu, cache 5 phút, xóa khi có phát sinh kho), trang `Counts/{Create,Edit}.vue`, `Import.vue`, huy hiệu menu (`ModuleRegistry` khóa `badge`, `SidebarNav.vue`, `ui.css`), `CatalogService::lookupByCodes`. Chuyển quyền xem/sửa giá vốn ở Catalog sang `inventory.cost` (thiếu quyền thì giữ nguyên giá vốn cũ). Sửa lỗi thiếu dấu ngoặc kép đóng ở nút Cài đặt của `Inventory/Index.vue` (có trong gói bước c). Test mới: `StockCountTest`, `StockImportTest`, `StockAlertsTest`. Các file Vue đã biên dịch thử bằng `@vue/compiler-sfc` ở môi trường soạn code; Pest, Pint và `npm run build` thì chưa chạy được ở đó.

- Đã xong code, chờ chạy thử: Inventory bước (c): `StockDocumentService`, `StockDocumentController`, route `inventory.documents.index|show`, `inventory.receipts.create|store`, `inventory.issues.create|store`, `inventory.products.search`, trang `Documents/{Index,Show,Form}.vue`, menu Phiếu kho/Nhập kho/Xuất kho, `inventory.css` dùng chung, `CatalogService::briefs`, thứ tự nhóm menu Khách hàng → Sản phẩm → Kho (`ModuleRegistry::GROUP_ORDER`), test `StockDocumentTest`. Chưa chạy được Pest/Pint/build ở môi trường soạn code.

- Đã chạy thử: Inventory bước (b): `StockController` (index, card, updateSettings), route `inventory.index|card|settings`, trang `Inventory/Index.vue` + `StockCard.vue`, menu Kho, `CatalogService::trackedProducts/productBrief/categoryOptions`, test `StockPagesTest`. Chưa chạy được Pest/Pint ở môi trường soạn code.

- Đã chạy thử: Inventory bước (a) (52 test pass, Pint sạch):  migration Catalog (`units.allow_decimal`, `products.min_stock`) và Inventory (`stock_levels`, `stock_documents`, `stock_document_lines`, `stock_movements`), `StockService`, quyền `inventory.*`, khóa số phiếu `stock_in` (NK)/`stock_out` (XK) ở Core, ô "Cho phép số lẻ" (đơn vị) và "Tồn tối thiểu" (sản phẩm). Chưa có route/menu. Xem `docs/modules/inventory.md`. Chưa chạy được Pest/Pint ở môi trường soạn code, cần chạy `pest` và `pint` trên máy bạn.

- Vừa xong (chờ chạy thử): module Catalog (sản phẩm, danh mục, đơn vị, bảng giá, giá theo số lượng, `CatalogService`). Xem `docs/modules/catalog.md`. Chưa có nhập/xuất Excel sản phẩm.

- Vừa xong (chờ chạy thử): module Customers (danh sách, thêm/sửa/xóa mềm, chọn giới tính -> ảnh nam/nữ, nhập/xuất Excel, `CustomerService`). Xem `docs/modules/customers.md`.

- Đang làm: chạy thử Core trên Laragon (đăng nhập `admin`/`admin` -> đổi mật khẩu -> các trang Core).
- Tiếp theo: chạy thử Dịch vụ, Combo/Liệu trình và Nhà cung cấp (`php artisan migrate`, `pos:sync`, `npm run build`, `pest`, `pint`), 0b (deploy thử), rồi Bán hàng/POS (bán chung sản phẩm, dịch vụ và gói; ghi hoa hồng nhân viên theo dòng dịch vụ) cùng với Bước B module `Packages` (gói của khách, trừ buổi, hết hạn, bảo hành, gia hạn/tặng buổi; thiết kế ở `docs/modules/service-catalog.md`).
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
