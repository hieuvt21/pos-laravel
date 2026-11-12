# Module Catalog (Sản phẩm)

Phụ thuộc: Core. Port từ Almira, bỏ tồn đầu kỳ, thêm xóa mềm.

## Bảng
`categories`, `units` (có `is_default`, mỗi sản phẩm một đơn vị, không quy đổi), `price_lists` (một bảng mặc định "Bán lẻ"), `products` (xóa mềm; `code` SP-00001; `barcode` duy nhất, có thể trống; `track_stock`; `cost_price`; `qty_pricing`), `product_prices` (giá theo bảng giá, `min_qty` cho giá theo số lượng).
Migration tạo sẵn đơn vị "Cái" và bảng giá "Bán lẻ". Xóa sản phẩm: đặt `barcode = null` rồi xóa mềm; mã sản phẩm giữ lại cho lịch sử hóa đơn.

## API công khai
- `CatalogService::find($id)`, `search($term)`
- `CatalogService::findByBarcode($code)`: khớp mã vạch trước, không có thì khớp mã sản phẩm; chỉ sản phẩm đang bán.
- `CatalogService::priceFor($product, $qty, $priceListId = null)`: bảng giá chỉ định > giá theo số lượng (mức min_qty cao nhất đạt tới, khi `qty_pricing`) > giá bán lẻ.
- Cờ `products.track_stock`: Inventory/Sales dùng để biết có trừ kho hay không. Số tồn thật nằm ở Inventory (`stock_movements`).
- `products.cost_price`: ban đầu nhập tay; Inventory sẽ cập nhật bình quân khi có nhập hàng.

## Chặn trùng và phân trang
- Tên sản phẩm (chưa xóa), danh mục, đơn vị, bảng giá: không trùng, không phân biệt hoa thường nhưng phân biệt dấu ("Cá" khác "Ca"). Kiểm tra khi đang nhập qua `products.check` / `catalog.check` (dùng `useUniqueCheck('name', ...)`) và lại ở server (`Core\Support\UniqueName`). Migration `..._200002` đổi cột tên sang `utf8mb4_bin` để chỉ mục duy nhất phân biệt dấu.
- Số dòng mỗi trang: 10/15/30/50/100 (`per_page`, mặc định 15), nhớ ở `localStorage` khóa `pos.perPage`, dùng chung Khách hàng và Sản phẩm.
- Thẻ sản phẩm: mã + ảnh + trạng thái bên trái; tên, danh mục, đơn vị, tồn kho (không quản lý thì `-`), ghi chú bên phải; giá bán ở cuối, hover hiện mọi loại giá. Tồn kho hiện 0 cho tới khi có Inventory.

## Chi tiết sản phẩm và cảm ứng
- Bấm thẻ hoặc tên sản phẩm mở khung chi tiết (`Partials/ProductDetail.vue`): ảnh, trạng thái, mọi loại giá, giá vốn và lãi gộp (chỉ người có quyền sửa; server không gửi giá vốn cho người khác), tồn kho, mã vạch (sao chép), ngày tạo/sửa; nút Ngừng bán/Bán lại (`products.toggle`), Sao chép sản phẩm (mở form thêm với dữ liệu cũ, bỏ mã/mã vạch/ảnh), Sửa, Xóa.
- Quy ước cảm ứng: tooltip chỉ là phụ. Mọi thông tin trong tooltip phải xem được ở khung chi tiết, và có nút ⓘ chạm để mở Popover cùng nội dung.

## Thuế theo sản phẩm
Ô "Sản phẩm chịu thuế" trong form, mặc định tắt (`tax_rate = 0`). Bật thì chọn mức thuế (danh sách ở Cửa hàng > Tiền tệ & thuế, mặc định lấy `tax_default_rate`). Độc lập với công tắc thuế chung: module Sales sẽ quyết định có tính thuế lên hóa đơn hay không.

## Quyền
`products.view|create|update|delete`, `catalog.manage` (danh mục, đơn vị, bảng giá). Mặc định: manager đủ, warehouse xem/thêm/sửa, cashier xem.

## Việc để sau
Nhập/xuất Excel sản phẩm, in tem mã vạch, tự sinh mã vạch nội bộ.
