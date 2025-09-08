<?php
require_once __DIR__ . '/../vendor/autoload.php';

use S3Sync\Auth;

$auth = new Auth();
$auth->requireAuth();

header('Content-Type: application/json');

$path = $_GET['path'] ?? '/';
$path = realpath($path) ?: '/';

$response = [
    'current' => $path,
    'parent' => dirname($path) !== $path ? dirname($path) : null,
    'items' => []
];

if (is_dir($path) && is_readable($path)) {
    $items = scandir($path);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        
        $fullPath = $path . '/' . $item;
        $fullPath = str_replace('//', '/', $fullPath);
        
        $response['items'][] = [
            'name' => $item,
            'path' => $fullPath,
            'type' => is_dir($fullPath) ? 'dir' : 'file'
        ];
    }
}

echo json_encode($response);