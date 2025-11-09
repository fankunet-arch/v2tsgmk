<?php
/**
 * KDS Image Fetcher (relative-only, inline)
 * 脚本位置: /store/store_html/html/kds/api/get_image.php
 * 目标目录: /store/store_html/store_images/kds
 * 兜底图片: /store/store_html/store_images/noimg.png
 * 要求: 仅显示( inline )、不下载；二进制流式输出，完整不截断。
 */

declare(strict_types=1);

// —— 防截断设置 ——
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@set_time_limit(0);
ignore_user_abort(true);

// —— 计算相对路径 ——
// __DIR__ = /.../store/store_html/html/kds/api
$store_html_root = rtrim(dirname(__DIR__, 3), '/'); // -> /.../store/store_html
$kds_dir         = $store_html_root . '/store_images/kds';
$fallback_png    = $store_html_root . '/store_images/noimg.png';

// —— 输入与白名单 ——
$req = $_GET['file'] ?? '';
$req = trim((string)$req);
$req = ltrim($req, "/\\");     // 去掉开头分隔符
$req = basename($req);         // 防目录穿越（仅保留文件名）

$allowed = ['png','jpg','jpeg','gif','webp','svg'];
$ext = strtolower(pathinfo($req, PATHINFO_EXTENSION));
$want_target = ($req !== '' && in_array($ext, $allowed, true));

// —— 解析目标文件（只用计算出的相对目录，不使用任何绝对前缀） ——
$serve_path = null;
$source     = 'embedded'; // file | fallback | embedded

if ($want_target) {
    $candidate = $kds_dir . '/' . $req; // /.../store/store_html/store_images/kds/<file>
    if (@is_file($candidate) && @is_readable($candidate)) {
        $serve_path = $candidate;
        $source = 'file';
    }
}

// 找不到 -> 回退到 noimg.png（/.../store/store_html/store_images/noimg.png）
if (!$serve_path) {
    if (@is_file($fallback_png) && @is_readable($fallback_png)) {
        $serve_path = $fallback_png;
        $source = 'fallback';
    }
}

// 最终兜底：内置 1x1 PNG（确保 <img> 一定显示）
$embedded_png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/wwAAgMBg4Tq7n8AAAAASUVORK5CYII=');

// —— MIME 判定（避免 application/octet-stream 导致下载） ——
function guess_mime(string $path, ?string $extHint = null): string {
    $ext = $extHint ?: strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg',
        'gif'=>'image/gif','webp'=>'image/webp','svg'=>'image/svg+xml'
    ];
    if (isset($map[$ext])) return $map[$ext];

    $fh = @fopen($path, 'rb');
    if ($fh) {
        $sig = @fread($fh, 16) ?: '';
        @fclose($fh);
        if (strncmp($sig, "\x89PNG", 4) === 0) return 'image/png';
        if (strncmp($sig, "\xFF\xD8\xFF", 3) === 0) return 'image/jpeg';
        if (strncmp($sig, "GIF8", 4) === 0) return 'image/gif';
        if (strncmp($sig, "RIFF", 4) === 0 && strpos($sig, "WEBP") !== false) return 'image/webp';
    }
    return 'image/png';
}

// —— 清空缓冲，开始输出（仅 inline 显示，不下载） ——
while (ob_get_level() > 0) { @ob_end_clean(); }

// 诊断信息（仅响应头，便于你在 DevTools → Network 中核查）
header('X-KDS-StoreHtml-Root: ' . $store_html_root);
header('X-KDS-KdsDir: ' . $kds_dir);
header('X-KDS-Fallback: ' . $fallback_png);

if ($serve_path) {
    $mime = guess_mime($serve_path, $ext ?: null);
    $size = @filesize($serve_path);
    if ($size === false) { $size = 0; }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)$size);
    header('Content-Disposition: inline; filename="' . rawurlencode(basename($serve_path)) . '"');
    header('Cache-Control: public, max-age=86400, immutable');
    header('Accept-Ranges: none');
    header('Content-Encoding: identity');
    header('X-KDS-Image-Source: ' . $source);

    $fp = @fopen($serve_path, 'rb');
    if ($fp !== false) {
        $chunk = 1048576; // 1MB
        @stream_set_read_buffer($fp, $chunk);
        while (!feof($fp)) {
            $buf = fread($fp, $chunk);
            if ($buf === false) break;
            echo $buf;
            flush();
            if (connection_aborted()) break;
        }
        @fclose($fp);
        exit;
    }
}

// 走到这里：连 fallback 都打不开 -> 内置 PNG（仍然 inline）
header('Content-Type: image/png');
header('Content-Length: ' . strlen($embedded_png));
header('Content-Disposition: inline; filename="noimg.png"');
header('Cache-Control: public, max-age=86400, immutable');
header('Accept-Ranges: none');
header('Content-Encoding: identity');
header('X-KDS-Image-Source: embedded');
echo $embedded_png;
exit;
