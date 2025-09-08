<?php
require_once __DIR__ . '/includes/header.php';

use S3Sync\Database;
use S3Sync\S3Manager;

$db = (new Database())->getConnection();
$s3Manager = new S3Manager();

// Get statistics
$stmt = $db->query("SELECT COUNT(*) as total, 
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running
                    FROM sync_jobs");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get recent jobs
$stmt = $db->query("SELECT j.*, s.name as settings_name 
                    FROM sync_jobs j 
                    LEFT JOIN s3_settings s ON j.s3_settings_id = s.id 
                    ORDER BY j.created_at DESC 
                    LIMIT 10");
$recentJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$activeSettings = $s3Manager->getActiveSettings();
?>

<h1>Dashboard</h1>

<div class="card">
    <h2>System Status</h2>
    <?php if ($activeSettings): ?>
        <div class="alert alert-success">
            Active S3 Configuration: <strong><?= htmlspecialchars($activeSettings['name']) ?></strong>
        </div>
    <?php else: ?>
        <div class="alert alert-danger">
            No active S3 configuration. Please <a href="/settings.php">configure S3 settings</a>.
        </div>
    <?php endif; ?>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
    <div class="card">
        <h3>Total Jobs</h3>
        <p style="font-size: 2rem; font-weight: bold;"><?= $stats['total'] ?></p>
    </div>
    <div class="card">
        <h3>Completed</h3>
        <p style="font-size: 2rem; font-weight: bold; color: #28a745;"><?= $stats['completed'] ?></p>
    </div>
    <div class="card">
        <h3>Failed</h3>
        <p style="font-size: 2rem; font-weight: bold; color: #dc3545;"><?= $stats['failed'] ?></p>
    </div>
    <div class="card">
        <h3>Running</h3>
        <p style="font-size: 2rem; font-weight: bold; color: #17a2b8;"><?= $stats['running'] ?></p>
    </div>
</div>

<div class="card">
    <h2>Recent Jobs</h2>
    <?php if (count($recentJobs) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Local Path</th>
                    <th>S3 Path</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentJobs as $job): ?>
                    <tr>
                        <td><?= $job['id'] ?></td>
                        <td><?= htmlspecialchars($job['name']) ?></td>
                        <td><?= htmlspecialchars($job['local_path']) ?></td>
                        <td><?= htmlspecialchars($job['s3_path']) ?></td>
                        <td>
                            <span class="status-badge status-<?= $job['status'] ?>">
                                <?= ucfirst($job['status']) ?>
                            </span>
                        </td>
                        <td><?= date('Y-m-d H:i', strtotime($job['created_at'])) ?></td>
                        <td>
                            <a href="/job-details.php?id=<?= $job['id'] ?>" class="btn btn-primary">View</a>
                            
                            <?php if ($job['status'] === 'running'): ?>
                                <button onclick="controlJob(<?= $job['id'] ?>, 'pause')" class="btn btn-secondary">Pause</button>
                                <button onclick="controlJob(<?= $job['id'] ?>, 'stop')" class="btn btn-danger">Stop</button>
                            <?php elseif ($job['status'] === 'paused'): ?>
                                <button onclick="controlJob(<?= $job['id'] ?>, 'resume')" class="btn btn-success">Resume</button>
                                <button onclick="controlJob(<?= $job['id'] ?>, 'stop')" class="btn btn-danger">Stop</button>
                            <?php elseif ($job['status'] === 'failed' || $job['status'] === 'completed' || $job['status'] === 'stopped'): ?>
                                <button onclick="controlJob(<?= $job['id'] ?>, 'restart')" class="btn btn-success">Restart</button>
                                <button onclick="deleteJob(<?= $job['id'] ?>)" class="btn btn-danger">Delete</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No jobs yet. <a href="/sync.php">Create your first sync job</a>.</p>
    <?php endif; ?>
</div>

<script>
function controlJob(jobId, action) {
    const actionText = action.charAt(0).toUpperCase() + action.slice(1);
    
    if (action === 'stop' || action === 'restart') {
        if (!confirm(`Are you sure you want to ${action} this job?`)) {
            return;
        }
    }
    
    const button = event.target;
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = `${actionText}ing...`;
    
    fetch('/api/job-control.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            job_id: jobId,
            action: action
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Refresh page to show updated status
            location.reload();
        } else {
            alert('Error: ' + data.message);
            button.disabled = false;
            button.textContent = originalText;
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
        button.disabled = false;
        button.textContent = originalText;
    });
}

function deleteJob(jobId) {
    if (!confirm('Are you sure you want to delete this job? This will permanently remove the job and all its logs.')) {
        return;
    }
    
    const button = event.target;
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = 'Deleting...';
    
    fetch('/api/delete-job.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            job_id: jobId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Refresh page to show updated job list
            location.reload();
        } else {
            alert('Error: ' + data.message);
            button.disabled = false;
            button.textContent = originalText;
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
        button.disabled = false;
        button.textContent = originalText;
    });
}

// Auto-refresh running jobs every 10 seconds
setInterval(function() {
    const runningJobs = document.querySelectorAll('.status-running, .status-paused');
    if (runningJobs.length > 0) {
        location.reload();
    }
}, 10000);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>