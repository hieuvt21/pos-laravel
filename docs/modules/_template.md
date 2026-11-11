# Module <Tên>

<Một đoạn: module làm gì, không làm gì.>

## Thư mục
```
app/Modules/<Ten>/
  module.php                # quyền, vai trò mặc định, menu, depends_on
  routes.php                # không dùng closure; middleware auth + can:<quyền>
  <Ten>ServiceProvider.php  # tùy chọn: chỉ khi cần đăng ký riêng
  Http/{Controllers,Requests}/  Models/  Services/  Policies/
  Database/migrations/      # chỉ thêm mới, không sửa migration đã chạy
  Resources/js/Pages/<Tên trang duy nhất>/...
tests/Feature/<Ten>/
```

## Bảng dữ liệu
| Bảng | Cột chính | Ghi chú |
|---|---|---|

## Quy tắc nghiệp vụ
-

## Route & màn hình
| Route name | URL | Quyền | Trang Vue |
|---|---|---|---|

## API công khai (module khác được gọi)
- `Services\XxxService::method()`: mô tả

## Phụ thuộc
- Dùng: ...
- Được dùng bởi: ...

## Lịch sử
- <ngày>: ...
