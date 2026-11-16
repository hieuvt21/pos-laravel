# Module Inventory (Kho)

Sổ kho của cửa hàng: tồn hiện tại, nhập/xuất/kiểm kho bằng phiếu, giá vốn bình quân gia quyền. Một kho duy nhất. **Không** có huỷ phiếu (sai thì lập phiếu ngược hoặc kiểm kho), không đơn vị quy đổi, không lô/hạn dùng.

Tiến độ: **module hoàn chỉnh theo kế hoạch, kèm Nhà cung cấp (bản đơn giản).** (a) `StockService`, (b) Tồn kho + thẻ kho + công tắc tồn âm: đã chạy thử. (c) phiếu nhập/xuất: code xong. (d) kiểm kho và (e) huy hiệu "sắp hết" + nhập Excel tồn đầu kỳ + chuyển quyền giá vốn: code xong, chờ chạy thử.

## Thư mục
```
app/Modules/Inventory/
  module.php
  Database/migrations/2026_11_12_400001_create_inventory_tables.php
  routes.php
  Http/Controllers/StockController.php        (index, card, updateSettings)
  Http/Controllers/StockDocumentController.php (phiếu: index, show, create/store nhập, create/store xuất, productSearch)
  Http/Controllers/StockCountController.php  (kiểm kho: create, store, edit, update, confirm, destroy)
  Http/Controllers/StockImportController.php (nhập Excel: index, template, store)
  Http/Controllers/SupplierController.php    (nhà cung cấp: index, check, store, update, destroy)
  Exceptions/{StockException,InsufficientStockException,InvalidStockQuantityException,StockChangedSinceCountException}.php
  Models/{StockLevel,StockMovement,StockDocument,StockDocumentLine,Supplier}.php
  Services/StockService.php
  Services/StockDocumentService.php           (lập phiếu nhập/xuất, nội bộ Inventory)
  Services/StockCountService.php              (kiểm kho: nháp, lưu, xác nhận, xóa nháp)
  Support/{InventorySettings,StockLabels,StockAlerts}.php
  Resources/js/Pages/Inventory/inventory.css  (style dùng chung, mọi trang import)
  Resources/js/Pages/Inventory/Index.vue
  Resources/js/Pages/Inventory/Documents/{Index,Show,Form}.vue
  Resources/js/Pages/Inventory/Counts/{Create,Edit}.vue
  Resources/js/Pages/Inventory/Suppliers/Index.vue
  Resources/js/Pages/Inventory/Import.vue
  Resources/js/Pages/Inventory/Partials/StockCard.vue   (hộp thoại thẻ kho)
tests/Feature/Inventory/{StockServiceTest,StockPagesTest,StockDocumentTest,StockCountTest,StockImportTest,StockAlertsTest,SupplierTest}.php
```

## Bảng dữ liệu
| Bảng | Cột chính | Ghi chú |
|---|---|---|
| `stock_levels` | `product_id` (duy nhất), `on_hand` | Cache số tồn; dòng bị `lockForUpdate` khi ghi sổ. Tạo lúc có phát sinh đầu tiên |
| `stock_documents` | `code`, `type` (receipt/issue/count), `doc_date`, `reason`, `note`, `status` (draft/confirmed), `reference_*`, `created_by`, `confirmed_at` | Phiếu thủ công. `draft` chỉ có ở kiểm kho. Đã xác nhận thì không sửa, không xóa |
| `stock_document_lines` | `stock_document_id`, `product_id`, `qty`, `unit_cost`, `system_qty`, `diff_qty`, `note` | Kiểm kho: `qty` = số đếm, null = chưa đếm |
| `stock_movements` | `product_id`, `stock_document_id` null, `reference_*` null, `type` (receipt/issue/return/count), `reason`, `qty` (+/−), `balance_after`, `unit_cost`, `created_by`, `created_at` | Sổ cái, chỉ thêm. Bán/trả hàng/nhập từ NCC ghi thẳng vào đây (không tạo `stock_documents`) |

| `suppliers` | `code` (NCC-xxxx, tự cấp), `name`, `phone`, `address`, `note`, `is_active` | Nhà cung cấp, chỉ để ghi nguồn nhập (migration `..._500001`). Chưa có công nợ |

`stock_documents` có thêm `supplier_id` (null được, chỉ phiếu nhập dùng).

Số lượng `decimal(15,3)`, giá `decimal(15,2)`. Catalog thêm (migration `..._300001`): `units.allow_decimal` (mặc định tắt), `products.min_stock` (mặc định 0). Tồn đầu kỳ **không** nằm ở form sản phẩm: nhập bằng phiếu nhập kho.

## Quy tắc nghiệp vụ
- Không sửa trực tiếp số tồn: mọi thay đổi là một dòng `stock_movements`; `on_hand` luôn bằng tổng `qty` của sổ.
- Chỉ sản phẩm bật `track_stock` có sổ kho; không bật thì mọi hàm ghi sổ trả `null` và không làm gì.
- Tồn âm: Setting `inventory.allow_negative` (mặc định tắt). Tắt thì xuất/bán quá tồn ném `InsufficientStockException`. Bật thì POS phải cảnh báo.
- Số lượng tối đa 3 chữ số thập phân; số lẻ chỉ nhận khi `units.allow_decimal`.
- Giá vốn bình quân: `(tồn cũ × giá vốn cũ + số nhập × giá nhập) / (tồn cũ + số nhập)`, làm tròn 2 số lẻ, ghi vào `products.cost_price` qua `CatalogService::setCostPrice`. Tồn cũ ≤ 0 thì lấy giá nhập. Xuất, bán, trả hàng, kiểm kho không đổi giá vốn.
- Trả hàng nhập lại theo giá vốn đã snapshot trên dòng hóa đơn gốc, kể cả sản phẩm đã xóa mềm.
- Kiểm kho: lưu nháp được; dòng chưa đếm (`qty` null) bị bỏ qua; xác nhận thì so với tồn **hiện tại** (qua `StockService::adjustToCount`) và báo nếu tồn đã đổi từ lúc lưu nháp; sau khi xác nhận không sửa, không xóa. Mã KK cấp ngay khi tạo nháp, xóa nháp để lại một khoảng trống số (chấp nhận được). Dòng của sản phẩm đã tắt quản lý tồn sau khi lập nháp: xóa số đếm của dòng đó mới xác nhận được.
- Quyền giá vốn: cột giá vốn ở trang Sản phẩm (Catalog) và mọi giá trị tồn chỉ hiện/sửa được với `inventory.cost`; người chỉ có `products.update` thì sửa sản phẩm bình thường nhưng giá vốn giữ nguyên (sản phẩm mới: 0).
- Ngày phiếu chỉ để hiển thị, không tính lại số dư hay giá vốn.
- Lập phiếu là một transaction: một dòng lỗi (không đủ tồn, số lẻ sai, sản phẩm không quản lý tồn) thì không ghi gì, kể cả số phiếu. Thông báo lỗi có tên sản phẩm. Phiếu đã lập **không sửa, không huỷ**; ghi theo thứ tự `product_id` tăng dần để tránh deadlock.
- Mã phiếu: nhập NK, xuất XK (khóa `stock_in`, `stock_out` của `DocumentNumber`).
- Bật `track_stock` thì tồn bắt đầu từ 0 (cần phiếu nhập đầu kỳ); tắt thì giữ sổ và ẩn tồn.
- Trạng thái: "hết" khi tồn ≤ 0, "sắp hết" khi tồn ≤ `min_stock` và `min_stock` > 0.
- Ghi nhiều dòng thì gọi theo thứ tự `product_id` tăng dần (tránh deadlock).

## Quyền
`inventory.view|receive|issue|count|cost|settings`. Mặc định: manager đủ; warehouse đủ trừ `settings`; cashier chỉ `view`. `inventory.cost` điều khiển việc xem giá vốn và giá trị tồn.

## Route & màn hình
Đã làm:

| Route name | URL | Quyền | Ghi chú |
|---|---|---|---|
| inventory.index | GET /inventory | view | Trang Tồn kho (`Inventory/Index`) |
| inventory.card | GET /inventory/{productId}/card | view | JSON thẻ kho, 20 dòng/trang, mới nhất trước |
| inventory.settings | PUT /inventory/settings | settings | Công tắc tồn âm |
| inventory.documents.index | GET /inventory/documents | view | Danh sách phiếu: lọc loại, mã, từ/đến ngày |
| inventory.documents.show | GET /inventory/documents/{document} | view | Chi tiết phiếu |
| inventory.receipts.create / store | GET, POST /inventory/receipts | receive | Lập phiếu nhập (`Documents/Form`, `kind=receipt`) |
| inventory.issues.create / store | GET, POST /inventory/issues | issue | Lập phiếu xuất (`Documents/Form`, `kind=issue`) |
| inventory.products.search | GET /inventory/products/search?q= | view | JSON tìm sản phẩm quản lý tồn (tên/mã/mã vạch), tối đa 15, khớp chính xác mã/mã vạch lên đầu |
| inventory.counts.create / store | GET, POST /inventory/counts | count | Chọn phạm vi (tất cả hoặc một danh mục), tạo phiếu nháp nạp sẵn sản phẩm kèm tồn máy; liệt kê phiếu nháp để làm tiếp |
| inventory.counts.edit / update | GET, PUT /inventory/counts/{document} | count | Nhập số đếm, lưu nháp (phiếu đã xác nhận thì mở trang xem phiếu) |
| inventory.counts.confirm | POST /inventory/counts/{document}/confirm | count | Lưu số đếm rồi xác nhận; `acknowledge=1` để đồng ý khi tồn đã đổi |
| inventory.counts.destroy | DELETE /inventory/counts/{document} | count | Xóa phiếu nháp (đã xác nhận thì không xóa được) |
| inventory.import.index / template / store | GET, GET, POST /inventory/import | receive | Trang nhập Excel, tải file mẫu, nhập file |
| inventory.suppliers.index / check / store / update / destroy | GET, GET, POST, PUT, DELETE /inventory/suppliers | receive | Danh sách + hộp thoại thêm/sửa, kiểm tra trùng tên khi gõ, xóa có xác nhận |

**Trang Tồn kho:** tìm theo tên/mã/mã vạch, lọc danh mục, sắp xếp (tên, tồn thấp→cao, tồn cao→thấp, giá trị tồn cao→thấp khi có `inventory.cost`), chip lọc tình trạng (Tất cả / Còn hàng / Sắp hết / Hết hàng, kèm số lượng; số liệu theo kết quả tìm/danh mục, không theo chip), tổng giá trị tồn (chỉ khi có `inventory.cost`), nút Nhập kho / Xuất kho / Cài đặt theo quyền. Máy tính: bảng; máy tính bảng và điện thoại: thẻ. Tìm/lọc/sắp xếp/phân trang xử lý ở server, danh sách lấy từ `CatalogService::trackedProducts()` rồi ghép tồn bằng `onHandMany()` (đủ cho cửa hàng nhỏ vài nghìn mặt hàng). Giá vốn và giá trị tồn **không được gửi** xuống trình duyệt nếu thiếu quyền `inventory.cost`.

**Thẻ kho:** hộp thoại tải bằng `fetch`, hiện thời gian, nghiệp vụ (loại, lý do, mã phiếu), số lượng +/−, tồn sau, giá vốn (nếu có quyền), người lập.

**Lập phiếu nhập/xuất (một form dùng chung):** ngày phiếu (không quá hôm nay), lý do, ghi chú, danh sách sản phẩm. Thêm sản phẩm bằng ô tìm: gõ tên/mã hoặc quét mã vạch rồi Enter (khớp chính xác hoặc chỉ có một kết quả thì thêm luôn; quét lại cùng sản phẩm thì cộng thêm 1). Mỗi dòng: số lượng (số lẻ theo đơn vị), phiếu nhập có thêm đơn giá nhập (để trống = giữ giá vốn; người có `inventory.cost` được điền sẵn giá vốn hiện tại). Hiện tổng giá trị nhập khi có đơn giá. Lý do phiếu nhập: Tồn đầu kỳ, Nhập hàng, Khác. Lý do phiếu xuất: Hàng hỏng, Hết hạn, Dùng nội bộ, Biếu tặng, Khác. Một sản phẩm chỉ một dòng trong phiếu, tối đa 200 dòng.

**Chi tiết phiếu:** thông tin phiếu và các dòng; đơn giá, thành tiền và tổng giá trị chỉ hiện khi có `inventory.cost`. Đã sẵn cột Tồn máy / Số đếm / Chênh lệch cho phiếu kiểm kho (bước d).

**Kiểm kho:** tạo phiếu nháp (phạm vi: tất cả sản phẩm quản lý tồn hoặc một danh mục) nạp sẵn mọi dòng kèm tồn máy lúc tạo. Trang nhập số đếm: lọc/tìm/quét mã vạch (Enter nhảy tới ô số đếm của dòng duy nhất còn lại), chip Tất cả / Chưa đếm / Đã đếm / Có chênh lệch, hiện từng 40 dòng (nút "Hiện thêm"), xem trước chênh lệch từng dòng, thêm sản phẩm ngoài danh sách, lưu nháp, xóa nháp, xác nhận (hộp thoại tóm tắt). Khi xác nhận mà tồn máy đã đổi từ lúc lưu nháp (có bán/nhập), hệ thống không ghi mà liệt kê các sản phẩm đã đổi (tồn lúc nháp, tồn hiện tại, số đếm); người dùng chọn "Vẫn xác nhận" thì chênh lệch tính theo tồn hiện tại. Số đếm luôn được lưu nháp trước khi xác nhận nên không mất khi có lỗi.

**Nhập Excel:** trang riêng với hướng dẫn, nút tải file mẫu (cột Mã sản phẩm hoặc mã vạch, Số lượng, Đơn giá nhập không bắt buộc), chọn lý do (mặc định Tồn đầu kỳ). Nhận .xlsx và .csv (BOM, dấu , hoặc ;), tối đa 2.000 dòng và 5 MB, đọc số kiểu Việt (1.500, 12.000,5, 2,5). Tra sản phẩm theo mã hoặc mã vạch, không phân biệt hoa thường. **Cả file là một phiếu nhập NK; có bất kỳ dòng lỗi nào thì không nhập gì**, bảng báo lỗi theo số dòng trong file (tối đa 100 dòng hiện ra).

**Huy hiệu cảnh báo:** mục "Tồn kho" trên menu hiện số sản phẩm sắp hết + hết hàng (chỉ tính sản phẩm đang bán, đang quản lý tồn). Kết quả cache 5 phút (`Cache` key `inventory.alerts`) và bị xóa ngay mỗi khi có dòng sổ kho mới, nên cập nhật gần như tức thì. Đổi `min_stock` hoặc bật/tắt quản lý tồn ở Catalog thì chờ tối đa 5 phút (hoặc lần có phát sinh kho kế tiếp).

**Nhà cung cấp (bản đơn giản):** một mục trong menu Kho, quản lý bằng quyền `inventory.receive` (ai nhập kho được thì quản lý được). Mỗi nhà cung cấp có mã NCC tự cấp, tên (không trùng, không phân biệt hoa thường), điện thoại, địa chỉ, ghi chú, trạng thái. Danh sách hiện số phiếu nhập và ngày nhập gần nhất. Dùng ở: ô "Nhà cung cấp" (không bắt buộc, có ô tìm) của phiếu nhập và trang nhập Excel; hiện ở danh sách phiếu (cột + bộ lọc), chi tiết phiếu và thẻ kho (dòng nhập ghi "NCC: ..."). Chỉ phiếu nhập có nhà cung cấp; phiếu xuất và kiểm kho bỏ qua. Nhà cung cấp "ngừng sử dụng" không hiện trong ô chọn nhưng vẫn hiện ở các phiếu cũ và bộ lọc. Đã có phiếu nhập thì không xóa được (chuyển sang ngừng sử dụng). Chưa làm: công nợ phải trả, giá nhập theo nhà cung cấp, trả hàng nhà cung cấp.

**Menu nhóm "Kho":** Tồn kho (`view`, có huy hiệu), Phiếu kho (`view`), Nhập kho (`receive`), Xuất kho (`issue`), Kiểm kho (`count`), Nhà cung cấp (`receive`).

## API công khai (module khác được gọi)
Tất cả trong `Services\StockService`, mỗi hàm tự chạy trong transaction và khóa `stock_levels`. Tham số `$reference` là Model nguồn (hóa đơn, phiếu nhập...), lưu vào `reference_type/id`. Trả về `StockMovement` (có `unit_cost` để Sales snapshot) hoặc `null` nếu sản phẩm không quản lý tồn.
- `receive($productId, $qty, ?$unitCost, $reason, $reference = null)`: nhập, cập nhật giá vốn bình quân.
- `issue($productId, $qty, $reason, $reference = null)`: xuất/bán theo giá vốn hiện tại; quá tồn thì ném `InsufficientStockException`.
- `returnToStock($productId, $qty, ?$unitCost, $reason, $reference = null)`: trả hàng, theo giá vốn gốc, không đổi giá vốn.
- `adjustToCount($productId, $counted, $reason, ...)`: kiểm kho, ghi dòng chênh lệch (null nếu không chênh).
- `onHand($productId)`, `onHandMany($ids)`: tồn hiện tại (0 nếu chưa có phát sinh).
- Số lượng sai thì ném `InvalidStockQuantityException`; mọi lỗi kho kế thừa `StockException` (thông báo tiếng Việt).
- Không có hàm huỷ phiếu / `reverse`.

Inventory gọi vào Catalog chỉ qua `CatalogService` (trả mảng, không trả Model): `stockProfile($id)` (gồm cả sản phẩm xóa mềm), `setCostPrice($id, $cost)`, `trackedProducts($search, $categoryId)`, `productBrief($id)`, `briefs($ids)`, `lookupByCodes($keys)`, `categoryOptions()`.

Module khác dùng được: `StockAlerts::status($qty, $min)` (`out`/`low`/`ok`), `StockAlerts::counts()`, `StockAlerts::badge()`.

## Phụ thuộc
- Dùng: Core (`Setting`, `DocumentNumber` khóa `stock_in` NK, `stock_out` XK, `stock_check` KK), Catalog.
- Được dùng bởi: Purchasing, Sales, Reports.

## Lịch sử
- 14/11/2026: Nhà cung cấp bản đơn giản: bảng `suppliers`, `stock_documents.supplier_id`, `SupplierController`, trang `Suppliers/Index`, chọn nhà cung cấp ở phiếu nhập và nhập Excel, hiện ở danh sách/chi tiết phiếu và thẻ kho, mục menu Nhà cung cấp. Test `SupplierTest`.
- 13/11/2026: bước (d) kiểm kho và (e) huy hiệu "sắp hết" + nhập Excel tồn đầu kỳ (`StockCountService`, `StockCountController`, `StockImportController`, `StockAlerts`, 3 trang Vue mới, huy hiệu menu trong `ModuleRegistry` và `SidebarNav`). Chuyển quyền xem/sửa giá vốn ở Catalog sang `inventory.cost`. Sửa lỗi thiếu dấu ngoặc kép đóng ở `Inventory/Index.vue` (nút Cài đặt) từ gói bước (c). Tests: `StockCountTest`, `StockImportTest`, `StockAlertsTest`.
- 13/11/2026: bước (c): `StockDocumentService`, `StockDocumentController`, route phiếu và tìm sản phẩm, 3 trang `Documents/{Index,Show,Form}`, mục menu Phiếu kho / Nhập kho / Xuất kho, tách style chung `inventory.css`, `CatalogService::briefs`, thứ tự nhóm menu Khách hàng → Sản phẩm → Kho, sửa khoảng cách đơn vị ở cột Tồn. Test `StockDocumentTest`.
- 12/11/2026: bước (b): trang Tồn kho, thẻ kho, cài đặt tồn âm, menu Kho, `StockLabels`, 3 hàm mới ở `CatalogService`, test `StockPagesTest`.
- 12/11/2026: bước (a): migration Catalog (`allow_decimal`, `min_stock`) và Inventory, `StockService`, quyền, test Pest. Bỏ "huỷ phiếu" khỏi phạm vi.
