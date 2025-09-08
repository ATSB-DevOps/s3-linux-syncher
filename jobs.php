<?php
require_once __DIR__ . '/includes/header.php';

use S3Sync\Database;

$db = (new Database())->getConnection();

$status = $_GET['status'] ?? '';
$where = '';
$params = [];

if ($status) {
    $where = "WHERE j.status = ?";
    $params[] = $status;
}

$stmt = $db->prepare("SELECT j.*, s.name as settings_name 
                      FROM sync_jobs j 
                      LEFT JOIN s3_settings s ON j.s3_settings_id = s.id 
                      $where
                      ORDER BY j.created_at DESC");
$stmt->execute($params);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h1>Sync Jobs</h1>

<div class="card">
    <div style="margin-bottom: 1rem;">
        <a href="?status=" class="btn <?= !$status ? 'btn-primary' : 'btn-secondary' ?>">All</a>
        <a href="?status=pending" class="btn <?= $status === 'pending' ? 'btn-primary' : 'btn-secondary' ?>">Pending</a>
        <a href="?status=running" class="btn <?= $status === 'running' ? 'btn-primary' : 'btn-secondary' ?>">Running</a>
        <a href="?status=completed" class="btn <?= $status === 'completed' ? 'btn-primary' : 'btn-secondary' ?>">Completed</a>
        <a href="?status=failed" class="btn <?= $status === 'failed' ? 'btn-primary' : 'btn-secondary' ?>">Failed</a>
    </div>
    
    <?php if (count($jobs) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Local Path</th>
                    <th>S3 Path</th>
                    <th>Status</th>
                    <th>Files</th>
                    <th>Created</th>
                    <th>Duration</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td><?= $job['id'] ?></td>
                        <td><?= htmlspecialchars($job['name']) ?></td>
                        <td title="<?= htmlspecialchars($job['local_path']) ?>">
                            <?= htmlspecialchars(substr($job['local_path'], 0, 30)) ?>...
                        </td>
                        <td><?= htmlspecialchars($job['s3_path']) ?></td>
                        <td>
                            <span class="status-badge status-<?= $job['status'] ?>">
                                <?= ucfirst($job['status']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($job['status'] === 'completed' || $job['status'] === 'failed'): ?>
                                ✅ <?= $job['files_synced'] ?> / ❌ <?= $job['files_failed'] ?>
                                <?php if ($job['files_skipped'] > 0): ?>
                                    / ⏭️ <?= $job['files_skipped'] ?>
                                <?php endif; ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?= date('Y-m-d H:i', strtotime($job['created_at'])) ?></td>
                        <td>
                            <?php if ($job['started_at'] && $job['completed_at']): ?>
                                <?php
                                $start = strtotime($job['started_at']);
                                $end = strtotime($job['completed_at']);
                                $duration = $end - $start;
                                echo gmdate('H:i:s', $duration);
                                ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="/job-details.php?id=<?= $job['id'] ?>" class="btn btn-primary">View</a>
                            
                            <?php if ($job['status'] === 'running'): ?>
                                <button onclick="controlJob(<?= $job['id'] ?>, 'pause')" class="btn btn-secondary">Pause</button>
                                <button onclick="controlJob(<?= $job['id'] ?>, 'stop')" class="btn btn-danger">Stop</button>
                            <?php elseif ($job['status'] === 'paused'): ?>
                                <button onclick="controlJob(<?= $job['id'] ?>, 'resume')" class="btn btn-success">Resume</button>
                                <button onclick="controlJob(<?= $job['id'] ?>, 'stop')" class="btn btn-danger">Stop</button>
                            <?php elseif ($job['status'] === 'failed' || $job['status'] === 'completed' || $job['status'] === 'stopped'): ?>
                                <form method="POST" action="/retry-job.php" style="display: inline;">
                                    <input type="hidden" name="job_id" value="<?= $job['id'] ?>">
                                    <button type="submit" class="btn btn-secondary">Retry</button>
                                </form>
                                <button onclick="controlJob(<?= $job['id'] ?>, 'restart')" class="btn btn-success">Restart</button>
                                <button onclick="deleteJob(<?= $job['id'] ?>)" class="btn btn-danger" style="margin-left: 5px;">Delete</button>
                            <?php else: ?>
                                <span class="text-muted">No actions available</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No jobs found.</p>
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

// Auto-refresh running jobs every 10 seconds
setInterval(function() {
    const runningJobs = document.querySelectorAll('.status-running, .status-paused');
    if (runningJobs.length > 0) {
        location.reload();
    }
}, 10000);

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
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>