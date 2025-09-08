<?php
require_once __DIR__ . '/../vendor/autoload.php';

use S3Sync\Auth;
use S3Sync\JobController;

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$jobId = $input['job_id'] ?? 0;

if (!$jobId) {
    echo json_encode(['success' => false, 'message' => 'Job ID is required']);
    exit;
}

$jobController = new JobController();

switch ($action) {
    case 'stop':
        $result = $jobController->stopJob($jobId);
        break;
        
    case 'pause':
        $result = $jobController->pauseJob($jobId);
        break;
        
    case 'resume':
        $result = $jobController->resumeJob($jobId);
        break;
        
    case 'restart':
        $result = $jobController->restartJob($jobId);
        break;
        
    case 'status':
        $result = $jobController->getJobStatus($jobId);
        break;
        
    default:
        $result = ['success' => false, 'message' => 'Invalid action'];
        break;
}

echo json_encode($result);