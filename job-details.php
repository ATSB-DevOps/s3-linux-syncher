<?php
require_once __DIR__ . '/includes/header.php';

use S3Sync\Database;

$db = (new Database())->getConnection();

$jobId = $_GET['id'] ?? 0;

// Get job details
$stmt = $db->prepare("SELECT j.*, s.name as settings_name, s.bucket 
                      FROM sync_jobs j 
                      LEFT JOIN s3_settings s ON j.s3_settings_id = s.id 
                      WHERE j.id = ?");
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

// Get all paths for this job
$jobPaths = [];
if ($job) {
    $stmt = $db->prepare("SELECT local_path, path_type FROM job_paths WHERE job_id = ?");
    $stmt->execute([$jobId]);
    $jobPaths = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (!$job) {
    echo '<div class="alert alert-danger">Job not found</div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Get job logs
$stmt = $db->prepare("SELECT * FROM job_logs WHERE job_id = ? ORDER BY created_at DESC");
$stmt->execute([$jobId]);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h1>Job Details #<?= $job['id'] ?></h1>

<div class="card">
    <h2>Job Information</h2>
    <table>
        <tr>
            <td><strong>Name:</strong></td>
            <td><?= htmlspecialchars($job['name']) ?></td>
        </tr>
        <tr>
            <td><strong>Status:</strong></td>
            <td>
                <span class="status-badge status-<?= $job['status'] ?>">
                    <?= ucfirst($job['status']) ?>
                </span>
            </td>
        </tr>
        <tr>
            <td><strong>Local Path(s):</strong></td>
            <td>
                <?php if (!empty($jobPaths)): ?>
                    <?php foreach ($jobPaths as $pathInfo): ?>
                        <div style="margin-bottom: 0.25rem;">
                            <?= $pathInfo['path_type'] === 'file' ? '📄' : '📁' ?> 
                            <?= htmlspecialchars($pathInfo['local_path']) ?>
                            <small style="color: #666;">(<?= $pathInfo['path_type'] ?>)</small>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?= htmlspecialchars($job['local_path']) ?> <small style="color: #666;">(single path)</small>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td><strong>S3 Path:</strong></td>
            <td><?= htmlspecialchars($job['bucket']) ?>/<?= htmlspecialchars($job['s3_path']) ?></td>
        </tr>
        <tr>
            <td><strong>S3 Configuration:</strong></td>
            <td><?= htmlspecialchars($job['settings_name']) ?></td>
        </tr>
        <tr>
            <td><strong>Created:</strong></td>
            <td><?= $job['created_at'] ?></td>
        </tr>
        <?php if ($job['started_at']): ?>
        <tr>
            <td><strong>Started:</strong></td>
            <td><?= $job['started_at'] ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($job['completed_at']): ?>
        <tr>
            <td><strong>Completed:</strong></td>
            <td><?= $job['completed_at'] ?></td>
        </tr>
        <tr>
            <td><strong>Duration:</strong></td>
            <td>
                <?php
                $start = strtotime($job['started_at']);
                $end = strtotime($job['completed_at']);
                $duration = $end - $start;
                echo gmdate('H:i:s', $duration);
                ?>
            </td>
        </tr>
        <?php endif; ?>
        <tr>
            <td><strong>Files Synced:</strong></td>
            <td><?= $job['files_synced'] ?></td>
        </tr>
        <tr>
            <td><strong>Files Failed:</strong></td>
            <td><?= $job['files_failed'] ?></td>
        </tr>
        <tr>
            <td><strong>Files Skipped:</strong></td>
            <td><?= $job['files_skipped'] ?? 0 ?></td>
        </tr>
        <tr>
            <td><strong>Overwrite Files:</strong></td>
            <td><?= $job['overwrite_files'] ? 'Yes' : 'No (Skip existing)' ?></td>
        </tr>
        <?php if ($job['error_message']): ?>
        <tr>
            <td><strong>Error:</strong></td>
            <td style="color: red;"><?= htmlspecialchars($job['error_message']) ?></td>
        </tr>
        <?php endif; ?>
    </table>
    
    <?php if ($job['status'] === 'failed' || $job['status'] === 'completed'): ?>
        <form method="POST" action="/retry-job.php" style="margin-top: 1rem;">
            <input type="hidden" name="job_id" value="<?= $job['id'] ?>">
            <button type="submit" class="btn btn-primary">Retry This Job</button>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <h2>File Logs</h2>
    
    <?php if (count($logs) > 0): ?>
        <div style="max-height: 500px; overflow-y: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>File</th>
                        <th>Status</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= date('H:i:s', strtotime($log['created_at'])) ?></td>
                            <td title="<?= htmlspecialchars($log['file_path']) ?>">
                                <?= htmlspecialchars(basename($log['file_path'])) ?>
                            </td>
                            <td>
                                <?php if ($log['status'] === 'success'): ?>
                                    <span style="color: green;">✅ Success</span>
                                <?php elseif ($log['status'] === 'skipped'): ?>
                                    <span style="color: orange;">⏭️ Skipped</span>
                                <?php else: ?>
                                    <span style="color: red;">❌ Failed</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($log['message']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p>No logs available for this job.</p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>