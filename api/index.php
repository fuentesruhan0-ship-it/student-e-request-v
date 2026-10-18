<?php
/**
 * Vercel entry point — forwards every request to the front controller in the
 * project root. Vercel's PHP runtime only picks up files under api/ so we
 * bridge to the real app here.
 */

$root = dirname(__DIR__);
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($uri === '/' || $uri === '') {
    require $root . '/index.php';
    return;
}

$target = realpath($root . $uri);
if ($target !== false && str_starts_with($target, $root) && is_file($target)) {
    $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
    if ($ext === 'php') {
        chdir(dirname($target));
        require $target;
        return;
    }
    if (in_array($ext, ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'map', 'woff2', 'woff', 'ttf'], true)) {
        $mimeTypes = [
            'css'   => 'text/css; charset=utf-8',
            'js'    => 'application/javascript; charset=utf-8',
            'json'  => 'application/json; charset=utf-8',
            'png'   => 'image/png',
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'gif'   => 'image/gif',
            'webp'  => 'image/webp',
            'svg'   => 'image/svg+xml',
            'ico'   => 'image/x-icon',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf'   => 'font/ttf',
        ];
        header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=31536000');
        readfile($target);
        return;
    }
}

$phpFile = rtrim($root . $uri, '/') . '.php';
if (is_file($phpFile)) {
    chdir(dirname($phpFile));
    require $phpFile;
    return;
}

http_response_code(404);
require $root . '/index.php';
