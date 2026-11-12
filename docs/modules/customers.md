# Module Customers (Khách hàng)

Phụ thuộc: Core. Port từ Almira, thêm xóa mềm, giới tính và cột đăng nhập cho cổng khách (Portal, làm sau).

## Bảng
`customers`: code (KH-00001), name, sort_name, gender (male|female), avatar, phone (duy nhất, 10 số, có thể trống), address, occupation, note, birth_date / birth_year, total_spent, password, can_login, must_change_password, last_login_at, soft delete.
Xóa khách (chỉ khi chưa có chi tiêu) sẽ đặt `phone = null` rồi xóa mềm để giải phóng số.

## API công khai (module khác chỉ gọi qua đây)
- `CustomerService::find($id)`, `search($term, $limit)`
- `CustomerService::addSpent($id, $amount)` / `subtractSpent($id, $amount)` (trong transaction hóa đơn / trả hàng)
- Hạng và % giảm: `Core\Support\CustomerTiers::for($total_spent)`, `discountFor()`
- `Customer::purchaseHistory()` hiện trả rỗng; module Sales thay phần thân.

## Quyền
`customers.view|create|update|delete|import`. Mặc định: manager đủ, cashier xem/thêm/sửa.

## Giao diện
- Danh sách bảng (máy tính) / thẻ (tablet, điện thoại), tìm theo tên-SĐT-mã / địa chỉ-ghi chú / năm sinh, lọc hạng, sắp xếp (kể cả sinh nhật sắp tới).
- Chọn giới tính: Nam gán ảnh `nam`, Nữ gán ảnh `nu` (`public/avatars/nam.svg`, `nu.svg`); chọn ảnh/tải ảnh khác thì không bị ghi đè. Bấm lại để bỏ chọn.
- Nhập/xuất Excel (.xlsx, .csv) cần quyền `customers.import`.

## Việc để sau
Ô "cho phép đăng nhập" + mật khẩu mặc định và cổng `/portal/login` (module Portal). Cột dữ liệu đã có sẵn.
