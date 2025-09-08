<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../vendor/autoload.php';

use S3Sync\JobController;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// This is a dangerous operation, require explicit confirmation
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || $input['confirm'] !== 'KILL_ALL_JOBS') {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => 'Confirmation required. Send {"confirm": "KILL_ALL_JOBS"} to proceed.'
    ]);
    exit;
}

$controller = new JobController();

try {
    $result = $controller->killAllJobs();
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}