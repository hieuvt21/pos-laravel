# Module ServiceCatalog (Dịch vụ)

Danh mục dịch vụ của cửa hàng (giao hàng, photo, lắp đặt...) để Bán hàng bán chung hóa đơn với sản phẩm. Thư mục là `ServiceCatalog` (tránh `Services\Services`), giao diện hiển thị "Dịch vụ".

**Làm đơn giản có chủ đích:** không đơn vị tính, không tồn kho, **giá cố định** (một giá duy nhất, không bảng giá), không thuế riêng, không Excel. Dịch vụ có **hoa hồng cho nhân viên**, khai báo bằng đúng một ô "Định mức".

Tiến độ: **code xong, chờ chạy thử** (backend, 2 trang Vue, test). Bán hàng chưa có nên hoa hồng chưa được ghi nhận cho nhân viên nào; module này chỉ khai báo và cấp số liệu.

**Combo và Liệu trình (Bước A):** mẫu gói bán trước sử dụng sau, cũng nằm trong module này (xem mục "Combo và Liệu trình"). Gói **của khách** (số buổi còn lại, trừ buổi, hết hạn, bảo hành) là Bước B, module `Packages` mới, làm cùng Bán hàng.

## Thư mục
```
app/Modules/ServiceCatalog/
  module.php
  routes.php
  Database/migrations/2026_11_15_100001_create_service_catalog_tables.php
  Database/migrations/2026_11_18_100001_create_service_packages_tables.php
  Models/{Service,ServiceCategory,ServicePackage,ServicePackageItem}.php
  Services/ServiceCatalogService.php          (API công khai)
  Http/Controllers/{ServiceController,ServiceCategoryController,ServicePackageController}.php
  Resources/js/Pages/Services/{Index,Categories,Packages}.vue   (Packages.vue dùng chung cho Combo và Liệu trình)
  Resources/js/Pages/Services/services.css    (style dùng chung, mọi trang import)
tests/Feature/ServiceCatalog/{ServiceTest,ServiceCategoryTest,ServicePackageTest}.php
(tính hoa hồng dùng chung: `Core\Support\Commission` + `resources/js/Components/CommissionField.vue`, test ở `tests/Feature/Core/CommissionTest.php`)
```

## Bảng dữ liệu
| Bảng | Cột chính | Ghi chú |
|---|---|---|
| `service_categories` | `name`, `description`, `is_active` | Danh mục riêng, không dùng chung danh mục sản phẩm. Tên không trùng (không phân biệt hoa thường) |
| `services` | `code` (DV-xxxx, tự cấp), `name`, `service_category_id` (**bắt buộc** ở form và server; cột DB cho null để tương thích dữ liệu cũ), `price`, `commission_type`, `commission_value`, `note`, `is_active`, xóa mềm | Tên không trùng giữa các dịch vụ chưa xóa |
| `service_packages` | `code` (GOI-xxxx), `type` (`combo`/`course`, không đổi sau khi tạo), `name`, `service_category_id` (dùng chung danh mục dịch vụ, bắt buộc), `price`, `validity_days`, `warranty_days`, `sale_commission_type/value`, `usage_commission_mode`, `usage_commission_type/value`, `note`, `is_active`, xóa mềm | Tên không trùng giữa các gói chưa xóa (cả combo lẫn liệu trình) |
| `service_package_items` | `service_package_id`, `service_id`, `quantity` (null = không giới hạn), `commission_type/value` | Mỗi dịch vụ một dòng trong một gói (unique) |

**Số tiền hoa hồng không được lưu**: luôn tính từ `price` và định mức nên đổi giá thì hoa hồng tự theo.

## Hoa hồng và ô "Định mức"
Một ô nhập số có hai nút **₫ / %** gắn liền ngay bên phải ô (`commission_type` = `amount` | `percent`, giá trị ở `commission_value`). Ô **Hoa hồng** (chỉ đọc) nằm **cùng một dòng** với ô Định mức:

| Định mức | Hoa hồng | Ví dụ (giá 150.000) |
|---|---|---|
| ₫ (`amount`) | bằng đúng số tiền định mức | định mức 100.000 → hoa hồng 100.000 |
| % (`percent`) | % của giá dịch vụ | định mức 10% → hoa hồng 15.000 |

- Ô "Hoa hồng" trong form **chỉ hiển thị, không sửa được**, **tự tính lại sau từng phím bấm** (lấy giá trị từ sự kiện `input` của PrimeVue InputNumber, không chờ rời ô) khi gõ giá hoặc định mức. Vượt giới hạn thì báo đỏ ngay và nút lưu bị khóa; server vẫn kiểm tra lại.
- **Không được vượt 100%**: định mức % tối đa 100 (tối đa 2 chữ số thập phân). Định mức tiền tối đa bằng giá dịch vụ (tương đương 100%), vượt thì báo "Hoa hồng không được lớn hơn giá dịch vụ".
- Sửa giá thấp hơn định mức tiền đang có thì phải sửa định mức lại (báo lỗi ở ô định mức).
- Nhập 0 nếu dịch vụ không có hoa hồng. Chuyển ₫ ↔ % sẽ xóa số đã nhập để tránh hiểu nhầm.
- Hoa hồng làm tròn theo số lẻ của tiền tệ (Cài đặt cửa hàng > Tiền tệ), không bao giờ vượt giá và không âm (`Core\Support\Commission::amount`, dùng chung với Sản phẩm).

## Combo và Liệu trình (mẫu gói bán trước)
Gói bán trước, khách trả tiền trước rồi dùng dần. **Combo** = đúng 1 dịch vụ dùng nhiều lần (ví dụ gội đầu 10 lần). **Liệu trình** = từ 2 dịch vụ trở lên, mỗi dịch vụ một số lần (ví dụ gội đầu 10 lần + matxa 5 lần). Hai loại dùng chung một bộ bảng và một controller, khác nhau ở cột `type`.

| Thuộc tính | Ý nghĩa |
|---|---|
| `price` | Một giá chung cho cả gói |
| `quantity` mỗi dịch vụ | Số lần dùng. Để trống = không giới hạn số lần |
| `validity_days` | Hạn sử dụng **tính từ ngày mua**. Để trống = không hạn. Hết hạn thì gói bị hủy dù chưa dùng hết và **không hoàn tiền** |
| `warranty_days` | Số ngày bảo hành tính từ lúc khách **dùng hết gói**. Để trống = không bảo hành. Mỗi lượt bảo hành vẫn ghi lịch sử (làm ở Bước B) |

**Quy tắc bắt buộc:**
- Gói phải có ít nhất một giới hạn: **không có hạn sử dụng thì mọi dịch vụ phải có số lần**. Có hạn thì được để một số dịch vụ không giới hạn số lần.
- Combo đúng 1 dịch vụ; liệu trình tối thiểu 2, tối đa 30; không chọn trùng dịch vụ trong một gói.
- Chỉ thêm được dịch vụ **đang bán**. Khi sửa, gói vẫn giữ dịch vụ đã ngừng bán.
- Dùng chung **danh mục dịch vụ** (không có danh mục riêng cho gói).
- Không xóa được dịch vụ đang nằm trong gói chưa xóa. Không xóa được danh mục còn gói (kể cả gói đã xóa mềm).

**Hai loại hoa hồng (không lưu số tiền, luôn tính từ giá và định mức):**
| Hoa hồng | Cột | Tính trên | Ghi chú |
|---|---|---|---|
| Người **bán** gói | `sale_commission_type/value` | Giá gói | % tối đa 100, tiền tối đa bằng giá gói |
| Người **thực hiện** khi dùng một lần dịch vụ | theo `usage_commission_mode` | **Giá lẻ của dịch vụ** (không phải giá gói) | Xem dưới |

`usage_commission_mode` (chỉ liệu trình có lựa chọn; combo luôn `per_item`):
- `per_item` (mặc định): mỗi dịch vụ một định mức riêng ở `service_package_items`, **tự điền sẵn theo định mức của dịch vụ** khi chọn dịch vụ, sửa được. Tiền tối đa bằng giá lẻ của dịch vụ đó.
- `common`: một định mức chung ở `usage_commission_*`, dùng cho mọi dịch vụ trong gói (% tính trên giá lẻ từng dịch vụ). Định mức tiền tối đa bằng giá dịch vụ thấp nhất trong gói. Định mức riêng của từng dòng được đặt về 0.

Chọn hoa hồng theo giá lẻ của dịch vụ (quyết định #22) để dòng "không giới hạn số lần" không cần phân bổ giá gói. Việc phân bổ giá gói cho từng buổi (để hoàn tiền buổi còn lại) làm ở Bước B.

## Quyền
`services.view|create|update|delete`, `service_categories.manage` và `service_packages.view|create|update|delete` (Combo và Liệu trình dùng chung). Mặc định: manager đủ; cashier chỉ `services.view` và `service_packages.view`; warehouse không có.

## Route & màn hình
| Route name | URL | Quyền |
|---|---|---|
| services.index | GET /services | services.view |
| services.check | GET /services/check | services.view (kiểm tra trùng tên khi gõ) |
| services.store / update / destroy | POST /services, PUT, DELETE /services/{service} | create / update / delete |
| service_categories.index / check / store / update / destroy | /services/categories... | service_categories.manage |
| service_combos.index | GET /services/combos | service_packages.view |
| service_courses.index | GET /services/courses | service_packages.view |
| service_packages.check | GET /services/packages/check | service_packages.view (kiểm tra trùng tên khi gõ) |
| service_packages.store / update / destroy | POST /services/packages, PUT, DELETE /services/packages/{package} | create / update / delete |

`service_combos.index` và `service_courses.index` cùng một action, loại gói lấy từ giá trị mặc định `type` của route. Loại gói gửi kèm khi tạo (`type`), không sửa được khi cập nhật.

- **Dịch vụ** (`Services/Index`): tìm theo tên/mã, lọc danh mục và trạng thái (Đang bán / Ngừng bán), cột Giá, Định mức, Hoa hồng. Thêm/sửa bằng hộp thoại, xóa có xác nhận (xóa mềm). Máy tính: bảng; máy tính bảng và điện thoại: thẻ. **Bắt buộc chọn danh mục** (không có nút bỏ chọn): chỉ chọn được danh mục đang sử dụng; dịch vụ đang sửa được giữ danh mục hiện tại dù đã ngừng sử dụng nhưng không chuyển sang danh mục ngừng khác. Chưa có danh mục nào thì form nhắc tạo danh mục trước.
- **Danh mục dịch vụ** (`Services/Categories`): tên, mô tả, trạng thái, số dịch vụ. Không xóa được danh mục còn dịch vụ (kể cả dịch vụ đã xóa mềm); ẩn bằng "Đang sử dụng".
- **Combo / Liệu trình** (`Services/Packages`, prop `type`): tìm theo tên/mã, lọc danh mục và trạng thái. Cột: mã, tên kèm tóm tắt dịch vụ (`Gội đầu × 10, ...`), danh mục, giá gói, hạn dùng, bảo hành, hoa hồng bán. Hộp thoại thêm/sửa: tên, danh mục, danh sách dịch vụ (mỗi dòng: dịch vụ, số lần, định mức hoa hồng; combo cố định 1 dòng, liệu trình thêm/bớt dòng), chọn "Theo từng dịch vụ" hoặc "Hoa hồng chung" (liệu trình), giá gói kèm gợi ý "tổng giá lẻ / tiết kiệm", hạn sử dụng, bảo hành, hoa hồng người bán, ghi chú, trạng thái.
- **Menu nhóm "Dịch vụ"** (nằm giữa Sản phẩm và Kho): Dịch vụ, Danh mục dịch vụ, Combo, Liệu trình. Thứ tự nhóm: Khách hàng, Sản phẩm, Dịch vụ, Kho (`ModuleRegistry::GROUP_ORDER`).

## API công khai (module khác được gọi)
`Services\ServiceCatalogService`, trả mảng thuần (`id, code, name, category_id, category, price, commission_type, commission_value, commission, note, is_active`):
- `find($id)`: một dịch vụ, kể cả đã xóa mềm (xem lại hóa đơn cũ). Null nếu không có.
- `search($query = '', $categoryId = null, $onlyActive = true, $limit = 30)`: tìm theo tên/mã, mặc định chỉ dịch vụ đang bán.
- `Core\Support\Commission::amount($type, $value, $price)`: tính hoa hồng một lần bán.

Gói (cho Bước B và Bán hàng):
- `findPackage($id)`: một combo / liệu trình, kể cả đã xóa mềm. Null nếu không có.
- `searchPackages($query = '', $type = null, $onlyActive = true, $limit = 30)`: tìm theo tên/mã; `$type` = `combo` | `course` | null (cả hai).
- `packageArray(ServicePackage $p)`: mảng thuần `id, code, type, name, category_id, category, price, validity_days, warranty_days, sale_commission_type, sale_commission_value, sale_commission, usage_commission_mode, usage_commission_type, usage_commission_value, note, is_active, retail_total, items[]`. Mỗi dòng `items[]`: `service_id, service_code, service_name, service_price, service_active, quantity, commission_type, commission_value, commission` (hoa hồng người thực hiện cho **một lần** dùng, đã áp dụng định mức chung nếu có). `retail_total` là null khi có dòng không giới hạn số lần.
- Khi bán gói, Bán hàng/`Packages` phải lưu **bản chụp** tên, giá, hạn, bảo hành, số lần và định mức để sửa mẫu gói sau không làm lệch gói khách đã mua.

Quy ước cho Bán hàng: số lượng dòng dịch vụ là số lần (số nguyên, không đơn vị); hóa đơn lưu **bản chụp** tên, giá, định mức và hoa hồng của dòng (`commission` × số lượng) để sửa dịch vụ sau không làm lệch hóa đơn cũ.

## Phụ thuộc
- Dùng: Core (`DocumentNumber` khóa `service` và `service_package`, `Setting::finance`, `UniqueName`, `Commission`, ô nhập `CommissionField.vue`).
- Được dùng bởi: Packages (Bước B), Sales (sau này), Reports.

## Chưa làm (cố ý, thêm sau nếu cần)
Đơn vị tính / số lượng lẻ, kiểu giá "nhập giá khi bán", chi phí ước tính, thuế riêng cho dịch vụ, đánh dấu "hay dùng", nhập/xuất Excel, thu hộ, định mức vật tư, báo cáo hoa hồng theo nhân viên (cần Bán hàng và Ca làm việc).

**Bước B (module `Packages`, làm cùng Bán hàng), đã chốt thiết kế:** bảng `customer_packages`, `customer_package_items` và sổ `customer_package_uses` chỉ thêm (`use` / `reverse` / `warranty`, có idempotency key, ghi trong transaction + `lockForUpdate`). Trạng thái `active` → `completed` → `warranty` → `closed`, ngoài ra `expired` và `cancelled`; hết hạn **tính khi đọc** (không cron vì cPanel). Không hoàn tiền khi hết hạn. Quản lý được gia hạn / tặng thêm buổi, ghi lý do vào sổ. Lượt bảo hành không trừ buổi và mặc định không tính hoa hồng. Phân bổ giá gói cho từng buổi theo tỷ lệ giá lẻ × số lượng, chụp lại lúc bán.

## Lịch sử
- 18/11/2026: thêm Combo và Liệu trình (mẫu gói bán trước, Bước A): migration `..._100001` (`service_packages`, `service_package_items`), `ServicePackage`, `ServicePackageItem`, `ServicePackageController`, trang `Services/Packages.vue` dùng chung, 2 mục menu, quyền `service_packages.*`, khóa số `service_package` (GOI), API `findPackage` / `searchPackages` / `packageArray`. Chặn xóa dịch vụ đang nằm trong gói và danh mục còn gói. Test `ServicePackageTest`; `ServiceTest` cập nhật kiểm tra menu.
- 15/11/2026: tách `Commission` sang Core và ô nhập hoa hồng thành `CommissionField.vue` dùng chung để Sản phẩm cũng có định mức và hoa hồng. Trang Dịch vụ chuyển sang dùng ô chung (giao diện và quy tắc không đổi).
- 15/11/2026 (sửa): bắt buộc chọn danh mục dịch vụ; nút ₫/% gắn liền ô định mức, Định mức và Hoa hồng cùng một dòng, hoa hồng tính ngay khi gõ.
- 15/11/2026: tạo module Dịch vụ bản đơn giản: danh mục dịch vụ riêng, dịch vụ giá cố định, hoa hồng nhân viên tính từ một ô định mức (₫ hoặc %, tối đa 100%), `ServiceCatalogService`, 2 trang Vue, menu nhóm Dịch vụ, khóa số `service` (DV). Test `CommissionTest`, `ServiceTest`, `ServiceCategoryTest`.
