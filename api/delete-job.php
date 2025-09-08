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

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || (!isset($input['job_id']) && !isset($input['job_ids']))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Job ID(s) required']);
    exit;
}

$controller = new JobController();

try {
    // Handle single job deletion
    if (isset($input['job_id'])) {
        $result = $controller->deleteJob($input['job_id']);
    }
    // Handle multiple job deletion
    elseif (isset($input['job_ids']) && is_array($input['job_ids'])) {
        $result = $controller->deleteMultipleJobs($input['job_ids']);
    }
    else {
        throw new Exception('Invalid request format');
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}