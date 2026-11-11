<?php
/**
 * deploy-helper.php (bản hoàn chỉnh)
 *
 * Được GitHub Actions upload vào thư mục public rồi gọi bằng header X-Deploy-Token.
 * Việc làm:
 *   1. Kiểm tra DEPLOY_TOKEN (đọc từ .env trên server)
 *   2. Giải nén deploy.zip nhưng BỎ QUA các file/thư mục không cần cho production
 *   3. Dọn các file thừa đã lỡ tải lên từ những lần deploy trước
 *   4. Tạo lại các thư mục storage cần thiết, chạy migrate + pos:sync
 *   5. Tự xóa chính nó (kể cả khi có lỗi, miễn là đã qua bước xác thực)
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
@set_time_limit(300);
@ini_set('memory_limit', '512M');
header('Content-Type: application/json; charset=utf-8');

// ============================ CẤU HÌNH ============================

// Thư mục gốc của Laravel (cha của thư mục public chứa file này)
$root = dirname(__DIR__);

// Thư mục ở gốc dự án KHÔNG giải nén lên server.
// (Chỉ kiểm tra ở cấp gốc, nên vendor/.../tests vẫn được giữ nguyên.)
$skipDirs = ['.git', '.github', 'node_modules', 'tests', 'docs', 'storage'];

// File ở gốc dự án KHÔNG giải nén (ngoài ra mọi file *.md ở gốc cũng bị bỏ qua).
// Lưu ý: .env và storage được bỏ qua để không ghi đè cấu hình / ảnh upload trên server.
$skipFiles = [
    '.env', '.env.example', '.gitignore', '.gitattributes', '.editorconfig', '.npmrc',
    'phpunit.xml', 'phpstan.neon', 'pint.json',
    'package.json', 'package-lock.json', 'vite.config.js',
    'deploy.zip',
];

// Dọn file thừa đã có sẵn trên server từ các lần deploy cũ (true/false)
$cleanupLeftovers = true;
// Thư mục / file sẽ bị xóa nếu còn tồn tại ở gốc dự án
$cleanupDirs  = ['.git', '.github', 'node_modules', 'tests', 'docs'];
$cleanupFiles = array_diff($skipFiles, ['.env', 'deploy.zip']); // tuyệt đối không xóa .env

// Cache cấu hình/route/view cho production (true nếu code KHÔNG dùng env() ngoài thư mục config)
$optimize = false;

// ============================ HÀM HỖ TRỢ ============================

$authenticated = false;

// Sau khi qua xác thực: dù thành công hay lỗi đều xóa file helper khỏi server.
register_shutdown_function(function () use (&$authenticated) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo json_encode([
            'error' => 'Lỗi nghiêm trọng: ' . $e['message'],
            'at'    => basename($e['file']) . ':' . $e['line'],
            'php'   => PHP_VERSION,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if ($authenticated) {
        @unlink(__FILE__);
    }
});

function fail(int $code, string $message, array $extra = []): void
{
    global $authenticated;
    http_response_code($code);
    // Chưa xác thực thì không tiết lộ thông tin máy chủ.
    $base = ['error' => $message] + ($authenticated ? ['php' => PHP_VERSION] : []);
    echo json_encode($base + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Trả true nếu entry trong ZIP không được giải nén. */
function shouldSkip(string $entry, array $skipDirs, array $skipFiles): bool
{
    $entry = str_replace('\\', '/', $entry);
    $entry = preg_replace('#^(\./)+#', '', $entry);
    if ($entry === '' || $entry[0] === '/') {
        return true; // đường dẫn tuyệt đối: bỏ qua
    }

    $segments = explode('/', trim($entry, '/'));
    if (in_array('..', $segments, true)) {
        return true; // chống zip-slip
    }

    $top = $segments[0];

    if (count($segments) > 1) {
        if (in_array($top, $skipDirs, true)) {
            return true;
        }
        // Cache của Laravel sinh ra ở máy CI: để server tự tạo lại.
        if ($top === 'bootstrap' && $segments[1] === 'cache' && substr($entry, -4) === '.php') {
            return true;
        }
        return false;
    }

    // Mục nằm ngay ở gốc (file, hoặc entry thư mục dạng "tests/")
    return in_array($top, $skipDirs, true)
        || in_array($top, $skipFiles, true)
        || strtolower(pathinfo($top, PATHINFO_EXTENSION)) === 'md';
}

/** Xóa file / thư mục (không đi theo symlink). */
function removePath(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item->isLink() || $item->isFile()) {
            @unlink($item->getPathname());
        } else {
            @rmdir($item->getPathname());
        }
    }
    @rmdir($path);
}

// ============================ 1. XÁC THỰC ============================

$deployToken = '';
$envFile = $root . '/.env';
if (is_file($envFile) && preg_match('/^DEPLOY_TOKEN=(.*)$/m', (string) file_get_contents($envFile), $m)) {
    $deployToken = trim($m[1], " \t\n\r\0\x0B\"'");
}
$headerToken = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';
if ($deployToken === '' || !hash_equals($deployToken, $headerToken)) {
    usleep(500000); // làm chậm việc dò token
    fail(401, 'Unauthorized. Sai hoặc thiếu DEPLOY_TOKEN trong .env.');
}
$authenticated = true;

// ============================ 2. GIẢI NÉN (CÓ LỌC) ============================

$zipPath = $root . '/deploy.zip';
if (!is_file($zipPath)) {
    fail(404, 'Không tìm thấy file deploy.zip trên máy chủ.', ['looked_in' => $zipPath]);
}
if (!class_exists('ZipArchive')) {
    fail(500, 'PHP trên cPanel chưa bật extension zip (MultiPHP INI Editor / Select PHP Version).');
}

$zip = new ZipArchive;
$opened = $zip->open($zipPath);
if ($opened !== true) {
    fail(500, 'Không mở được deploy.zip (file có thể tải lên chưa đủ hoặc bị hỏng).', [
        'zip_error_code' => $opened,
        'zip_size_bytes' => filesize($zipPath),
    ]);
}

$toExtract = [];
$skipped = 0;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if ($name === false) {
        continue;
    }
    if (shouldSkip($name, $skipDirs, $skipFiles)) {
        $skipped++;
        continue;
    }
    $toExtract[] = $name;
}

if (!$toExtract) {
    $zip->close();
    fail(500, 'deploy.zip không có file nào hợp lệ để giải nén.', ['skipped' => $skipped]);
}

if (!$zip->extractTo($root, $toExtract)) {
    $zip->close();
    fail(500, 'Giải nén thất bại (thường do không đủ quyền ghi hoặc hết dung lượng).', [
        'files' => count($toExtract),
        'root'  => $root,
    ]);
}
$zip->close();
@unlink($zipPath);

if (function_exists('opcache_reset')) {
    @opcache_reset();
}

// ============================ 3. DỌN FILE THỪA CŨ ============================

$cleaned = [];
if ($cleanupLeftovers) {
    foreach ($cleanupDirs as $d) {
        if (file_exists("$root/$d") || is_link("$root/$d")) {
            removePath("$root/$d");
            $cleaned[] = $d . '/';
        }
    }
    foreach ($cleanupFiles as $f) {
        if (is_file("$root/$f")) {
            @unlink("$root/$f");
            $cleaned[] = $f;
        }
    }
    foreach (glob($root . '/*.md') ?: [] as $md) {
        @unlink($md);
        $cleaned[] = basename($md);
    }
}

// ============================ 4. THƯ MỤC CẦN GHI ============================
// storage không nằm trong ZIP nên đảm bảo các thư mục Laravel cần luôn tồn tại.
foreach ([
    'storage/app/public',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
    'bootstrap/cache',
] as $dir) {
    if (!is_dir("$root/$dir")) {
        @mkdir("$root/$dir", 0755, true);
    }
}

// ============================ 5. ARTISAN ============================

if (!is_file($root . '/vendor/autoload.php')) {
    fail(500, 'Thiếu thư mục vendor trên server. Hãy chắc chắn workflow KHÔNG loại vendor khỏi ZIP.');
}

$steps = [];
$migrate = '';

try {
    require $root . '/vendor/autoload.php';
    $app = require_once $root . '/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

    // Chạy một lệnh Artisan. $critical = true thì lỗi sẽ dừng toàn bộ quá trình deploy.
    $run = function (string $cmd, array $args = [], bool $critical = false) use ($kernel, &$steps): string {
        try {
            $code = $kernel->call($cmd, $args);
            $out = trim($kernel->output());
            if ($code !== 0) {
                throw new RuntimeException("Lệnh $cmd trả về mã $code. " . $out);
            }
            $steps[$cmd] = 'ok';
            return $out;
        } catch (\Throwable $e) {
            $steps[$cmd] = 'lỗi: ' . $e->getMessage();
            if ($critical) {
                throw $e;
            }
            return '';
        }
    };

    $run('package:discover');
    $run('config:clear');
    $run('route:clear');
    $run('view:clear');
    $run('cache:clear');
    $migrate = $run('migrate', ['--force' => true], true);
    // Đồng bộ quyền, vai trò mặc định, tài khoản admin từ các module (an toàn khi chạy lại)
    $run('pos:sync', [], true);

    if (!file_exists($root . '/public/storage')) {
        $run('storage:link');
    }

    if ($optimize) {
        $run('config:cache');
        $run('route:cache');
        $run('view:cache');
    }

    if (class_exists(\Spatie\Permission\PermissionRegistrar::class)) {
        $app[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Lỗi khi chạy lệnh Artisan: ' . $e->getMessage(),
        'type'  => get_class($e),
        'at'    => basename($e->getFile()) . ':' . $e->getLine(),
        'steps' => $steps,
        'php'   => PHP_VERSION,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit; // shutdown handler sẽ tự xóa file helper
}

echo json_encode([
    'success' => true,
    'message' => 'Triển khai thành công!',
    'files'   => count($toExtract),
    'skipped' => $skipped,
    'cleaned' => $cleaned,
    'steps'   => $steps,
    'migrate' => $migrate,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
