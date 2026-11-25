<?php
// موجّه خادم PHP المدمج للاختبارات: الملفات الموجودة في public تُخدم كما هي، وما عداها عبر index.php
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = realpath(__DIR__ . '/../../public' . $path);
if ($path !== '/' && $file !== false && is_file($file) && str_starts_with($file, realpath(__DIR__ . '/../../public')) && !str_ends_with($file, '.php')) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/../../public/index.php';
