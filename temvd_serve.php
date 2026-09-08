<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(403);
    exit;
}

$filename = $_GET['f'] ?? '';
if (!$filename || !preg_match('/^[a-zA-Z0-9_.\-]+$/', $filename)) {
    http_response_code(400);
    exit;
}

$path = __DIR__ . '/../temvd/' . $filename;
if (!file_exists($path)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$mimeTypes = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'mp4' => 'video/mp4',
    'mov' => 'video/quicktime',
    'avi' => 'video/x-msvideo',
    'mkv' => 'video/x-matroska',
    'webm' => 'video/webm'
];

$mime = $mimeTypes[$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-cache');
readfile($path);
