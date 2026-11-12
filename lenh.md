php artisan migrate : thay đổi database
php artisan pos:sync : thay đổi quyền
php artisan optimize:clear : xoá cache


Quên mật khẩu: 

sql -> user -> Sửa cột password thành chuỗi này, tương ứng mật khẩu 123456:
$2y$12$0ZR7Y9cPgAZHRjKeK5KlvOu6MAn7P4tLh3JtyDnvDmBOWi6yzW1Oa
Đặt must_change_password = 1