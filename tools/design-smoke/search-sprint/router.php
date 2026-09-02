<?php
/**
 * Dev router: comps HTML + Tasks admin.css from public/assets.
 * php -S 127.0.0.1:PORT -t . router.php  (cwd = this directory)
 */
declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicAssets = realpath(__DIR__ . '/../../../public/assets');

if (str_starts_with($uri, '/assets/') && $publicAssets) {
    $rel = substr($uri, strlen('/assets/'));
    $rel = str_replace(['..', '\\'], '', $rel);
    $file = $publicAssets . '/' . $rel;
    if (is_file($file)) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $types = [
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'woff2' => 'font/woff2',
        ];
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        readfile($file);
        return true;
    }
    http_response_code(404);
    echo 'asset not found';
    return true;
}

if ($uri === '/' || $uri === '/index.html') {
    return false;
}

http_response_code(404);
echo 'not found';
return true;
