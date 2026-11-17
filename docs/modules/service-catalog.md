# Module ServiceCatalog (Dịch vụ)

Danh mục dịch vụ của cửa hàng (giao hàng, photo, lắp đặt...) để Bán hàng bán chung hóa đơn với sản phẩm. Thư mục là `ServiceCatalog` (tránh `Services\Services`), giao diện hiển thị "Dịch vụ".

**Làm đơn giản có chủ đích:** không đơn vị tính, không tồn kho, **giá cố định** (một giá duy nhất, không bảng giá), không thuế riêng, không Excel. Dịch vụ có **hoa hồng cho nhân viên**, khai báo bằng đúng một ô "Định mức".

Tiến độ: **code xong, chờ chạy thử** (backend, 2 trang Vue, test). Bán hàng chưa có nên hoa hồng chưa được ghi nhận cho nhân viên nào; module này chỉ khai báo và cấp số liệu.

## Thư mục
```
app/Modules/ServiceCatalog/
  module.php
  routes.php
  Database/migrations/2026_11_15_100001_create_service_catalog_tables.php
  Models/{Service,ServiceCategory}.php
  Services/ServiceCatalogService.php          (API công khai)
  Http/Controllers/{ServiceController,ServiceCategoryController}.php
  Resources/js/Pages/Services/{Index,Categories}.vue
  Resources/js/Pages/Services/services.css    (style dùng chung, mọi trang import)
tests/Feature/ServiceCatalog/{ServiceTest,ServiceCategoryTest}.php
(tính hoa hồng dùng chung: `Core\Support\Commission` + `resources/js/Components/CommissionField.vue`, test ở `tests/Feature/Core/CommissionTest.php`)
```

## Bảng dữ liệu
| Bảng | Cột chính | Ghi chú |
|---|---|---|
| `service_categories` | `name`, `description`, `is_active` | Danh mục riêng, không dùng chung danh mục sản phẩm. Tên không trùng (không phân biệt hoa thường) |
| `services` | `code` (DV-xxxx, tự cấp), `name`, `service_category_id` (**bắt buộc** ở form và server; cột DB cho null để tương thích dữ liệu cũ), `price`, `commission_type`, `commission_value`, `note`, `is_active`, xóa mềm | Tên không trùng giữa các dịch vụ chưa xóa |

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

## Quyền
`services.view|create|update|delete` và `service_categories.manage`. Mặc định: manager đủ; cashier chỉ `services.view`; warehouse không có.

## Route & màn hình
| Route name | URL | Quyền |
|---|---|---|
| services.index | GET /services | services.view |
| services.check | GET /services/check | services.view (kiểm tra trùng tên khi gõ) |
| services.store / update / destroy | POST /services, PUT, DELETE /services/{service} | create / update / delete |
| service_categories.index / check / store / update / destroy | /services/categories... | service_categories.manage |

- **Dịch vụ** (`Services/Index`): tìm theo tên/mã, lọc danh mục và trạng thái (Đang bán / Ngừng bán), cột Giá, Định mức, Hoa hồng. Thêm/sửa bằng hộp thoại, xóa có xác nhận (xóa mềm). Máy tính: bảng; máy tính bảng và điện thoại: thẻ. **Bắt buộc chọn danh mục** (không có nút bỏ chọn): chỉ chọn được danh mục đang sử dụng; dịch vụ đang sửa được giữ danh mục hiện tại dù đã ngừng sử dụng nhưng không chuyển sang danh mục ngừng khác. Chưa có danh mục nào thì form nhắc tạo danh mục trước.
- **Danh mục dịch vụ** (`Services/Categories`): tên, mô tả, trạng thái, số dịch vụ. Không xóa được danh mục còn dịch vụ (kể cả dịch vụ đã xóa mềm); ẩn bằng "Đang sử dụng".
- **Menu nhóm "Dịch vụ"** (nằm giữa Sản phẩm và Kho): Dịch vụ, Danh mục dịch vụ. Thứ tự nhóm: Khách hàng, Sản phẩm, Dịch vụ, Kho (`ModuleRegistry::GROUP_ORDER`).

## API công khai (module khác được gọi)
`Services\ServiceCatalogService`, trả mảng thuần (`id, code, name, category_id, category, price, commission_type, commission_value, commission, note, is_active`):
- `find($id)`: một dịch vụ, kể cả đã xóa mềm (xem lại hóa đơn cũ). Null nếu không có.
- `search($query = '', $categoryId = null, $onlyActive = true, $limit = 30)`: tìm theo tên/mã, mặc định chỉ dịch vụ đang bán.
- `Core\Support\Commission::amount($type, $value, $price)`: tính hoa hồng một lần bán.

Quy ước cho Bán hàng: số lượng dòng dịch vụ là số lần (số nguyên, không đơn vị); hóa đơn lưu **bản chụp** tên, giá, định mức và hoa hồng của dòng (`commission` × số lượng) để sửa dịch vụ sau không làm lệch hóa đơn cũ.

## Phụ thuộc
- Dùng: Core (`DocumentNumber` khóa `service`, `Setting::finance`, `UniqueName`, `Commission`, ô nhập `CommissionField.vue`).
- Được dùng bởi: Sales (sau này), Reports.

## Chưa làm (cố ý, thêm sau nếu cần)
Đơn vị tính / số lượng lẻ, kiểu giá "nhập giá khi bán", chi phí ước tính, thuế riêng cho dịch vụ, đánh dấu "hay dùng", nhập/xuất Excel, thu hộ, định mức vật tư, gói trả trước, báo cáo hoa hồng theo nhân viên (cần Bán hàng và Ca làm việc).

## Lịch sử
- 15/11/2026: tách `Commission` sang Core và ô nhập hoa hồng thành `CommissionField.vue` dùng chung để Sản phẩm cũng có định mức và hoa hồng. Trang Dịch vụ chuyển sang dùng ô chung (giao diện và quy tắc không đổi).
- 15/11/2026 (sửa): bắt buộc chọn danh mục dịch vụ; nút ₫/% gắn liền ô định mức, Định mức và Hoa hồng cùng một dòng, hoa hồng tính ngay khi gõ.
- 15/11/2026: tạo module Dịch vụ bản đơn giản: danh mục dịch vụ riêng, dịch vụ giá cố định, hoa hồng nhân viên tính từ một ô định mức (₫ hoặc %, tối đa 100%), `ServiceCatalogService`, 2 trang Vue, menu nhóm Dịch vụ, khóa số `service` (DV). Test `CommissionTest`, `ServiceTest`, `ServiceCategoryTest`.
