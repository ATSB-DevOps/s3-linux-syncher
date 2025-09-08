<?php
require_once __DIR__ . '/includes/header.php';

use S3Sync\Database;
use S3Sync\S3Manager;

$db = (new Database())->getConnection();
$s3Manager = new S3Manager();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save') {
        $scheduleType = $_POST['schedule_type'] ?? 'custom';
        $cronExpression = '';
        
        // Generate cron expression based on schedule type
        switch ($scheduleType) {
            case 'daily':
                $cronExpression = '0 2 * * *'; // Daily at 2 AM
                break;
            case 'weekly':
                $cronExpression = '0 2 * * 0'; // Weekly on Sunday at 2 AM
                break;
            case 'monthly':
                $cronExpression = '0 2 1 * *'; // Monthly on 1st at 2 AM
                break;
            case 'custom':
                $cronExpression = $_POST['cron_expression'];
                break;
        }
        
        $overwriteFiles = isset($_POST['overwrite_files']) ? 1 : 0;
        $scheduledSelectionType = $_POST['scheduled_selection_type'] ?? 'single';
        
        // Handle multiple paths
        $selectedPaths = [];
        $invalidPaths = [];
        
        if ($scheduledSelectionType === 'single') {
            $localPath = $_POST['local_path'];
            if (!file_exists($localPath)) {
                $invalidPaths[] = $localPath;
            } else {
                $selectedPaths[] = [
                    'path' => $localPath,
                    'type' => is_file($localPath) ? 'file' : 'directory'
                ];
            }
        } else {
            // Multiple paths from textarea
            $multiPathsString = $_POST['multiple_paths'] ?? '';
            $pathArray = array_filter(array_map('trim', explode(';', $multiPathsString)));
            foreach ($pathArray as $path) {
                if (!file_exists($path)) {
                    $invalidPaths[] = $path;
                } else {
                    $selectedPaths[] = [
                        'path' => $path,
                        'type' => is_file($path) ? 'file' : 'directory'
                    ];
                }
            }
        }
        
        if (!empty($invalidPaths)) {
            $message = 'Invalid paths: ' . implode(', ', $invalidPaths);
            $messageType = 'danger';
        } elseif (empty($selectedPaths)) {
            $message = 'Please specify at least one valid file or directory';
            $messageType = 'danger';
        } else {
            // Use first path as main path for backward compatibility
            $mainPath = $selectedPaths[0]['path'];
            
            $stmt = $db->prepare("INSERT INTO scheduled_jobs (name, local_path, s3_path, s3_settings_id, cron_expression, schedule_preset, overwrite_files) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['name'],
                $mainPath,
                $_POST['s3_path'],
                $_POST['s3_settings_id'],
                $cronExpression,
                $scheduleType,
                $overwriteFiles
            ]);
            
            $scheduledJobId = $db->lastInsertId();
            
            // Store all selected paths
            foreach ($selectedPaths as $pathInfo) {
                $stmt = $db->prepare("INSERT INTO scheduled_job_paths (scheduled_job_id, local_path, path_type) VALUES (?, ?, ?)");
                $stmt->execute([$scheduledJobId, $pathInfo['path'], $pathInfo['type']]);
            }
            
            $pathCount = count($selectedPaths);
            $message = "Scheduled job created with {$pathCount} path(s) successfully";
            $messageType = 'success';
        }
    } elseif ($action === 'toggle') {
        $stmt = $db->prepare("UPDATE scheduled_jobs SET is_active = NOT is_active WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $message = 'Job status updated';
        $messageType = 'success';
    } elseif ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM scheduled_jobs WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $message = 'Scheduled job deleted';
        $messageType = 'success';
    }
}

// Get all scheduled jobs
$stmt = $db->query("SELECT sj.*, s.name as settings_name 
                    FROM scheduled_jobs sj 
                    LEFT JOIN s3_settings s ON sj.s3_settings_id = s.id 
                    ORDER BY sj.created_at DESC");
$scheduledJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Function to get schedule description
function getScheduleDescription($preset, $cronExpression) {
    switch ($preset) {
        case 'daily':
            return 'Daily at 2:00 AM';
        case 'weekly':
            return 'Weekly (Sunday at 2:00 AM)';
        case 'monthly':
            return 'Monthly (1st at 2:00 AM)';
        default:
            return $cronExpression;
    }
}

$allSettings = $s3Manager->getSettings();
?>

<h1>Scheduled Jobs</h1>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div class="card">
    <h2>Add Scheduled Job</h2>
    <form method="POST">
        <input type="hidden" name="action" value="save">
        
        <div class="form-group">
            <label>Job Name</label>
            <input type="text" name="name" required placeholder="e.g., Daily Backup">
        </div>
        
        <div class="form-group">
            <label>Selection Mode</label>
            <div class="radio-group">
                <div class="form-radio">
                    <label>
                        <input type="radio" name="scheduled_selection_type" value="single" checked onchange="toggleScheduledSelectionMode()">
                        <span>Single Path</span>
                    </label>
                </div>
                <div class="form-radio">
                    <label>
                        <input type="radio" name="scheduled_selection_type" value="multiple" onchange="toggleScheduledSelectionMode()">
                        <span>Multiple Paths</span>
                    </label>
                </div>
            </div>
        </div>
        
        <div class="form-group" id="scheduled_single_path_group">
            <label>Local Path</label>
            <input type="text" name="local_path" required placeholder="/path/to/folder">
        </div>
        
        <div class="form-group" id="scheduled_multiple_paths_group" style="display: none;">
            <label>Selected Paths</label>
            <div id="scheduled_selected_paths_list" style="background: #f8f9fa; padding: 1rem; border-radius: 4px; margin-bottom: 1rem; min-height: 80px;">
                <p style="color: #666; margin: 0;">No paths selected. Enter paths manually separated by semicolons (;)</p>
            </div>
            <textarea name="multiple_paths" id="scheduled_paths_input" placeholder="/path/to/folder1;/path/to/file1;/path/to/folder2" style="width: 100%; height: 100px;"></textarea>
            <small style="color: #666;">Enter multiple paths separated by semicolons (;)</small>
        </div>
        
        <div class="form-group">
            <label>S3 Path</label>
            <input type="text" name="s3_path" required placeholder="backups/daily/">
            <div id="scheduled_s3_path_help">
                <small style="color: #666;">
                    The path in your S3 bucket where files will be uploaded<br>
                    <em>Example: "backup/daily/" → files go to s3://bucket/backup/daily/filename</em>
                </small>
            </div>
        </div>
        
        <div class="form-group">
            <label>S3 Configuration</label>
            <select name="s3_settings_id" required>
                <option value="">Select Configuration</option>
                <?php foreach ($allSettings as $setting): ?>
                    <option value="<?= $setting['id'] ?>"><?= htmlspecialchars($setting['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>Schedule Type</label>
            <div class="radio-group">
                <div class="form-radio">
                    <label>
                        <input type="radio" name="schedule_type" value="daily" checked onchange="toggleCronInput()">
                        <span>Daily (2:00 AM)</span>
                    </label>
                </div>
                <div class="form-radio">
                    <label>
                        <input type="radio" name="schedule_type" value="weekly" onchange="toggleCronInput()">
                        <span>Weekly (Sunday 2:00 AM)</span>
                    </label>
                </div>
                <div class="form-radio">
                    <label>
                        <input type="radio" name="schedule_type" value="monthly" onchange="toggleCronInput()">
                        <span>Monthly (1st at 2:00 AM)</span>
                    </label>
                </div>
                <div class="form-radio">
                    <label>
                        <input type="radio" name="schedule_type" value="custom" onchange="toggleCronInput()">
                        <span>Custom Cron Expression</span>
                    </label>
                </div>
            </div>
        </div>
        
        <div class="form-group" id="custom_cron" style="display: none;">
            <label>Custom Cron Expression</label>
            <input type="text" name="cron_expression" placeholder="0 2 * * *">
            <small style="color: #666;">
                Examples:<br>
                0 2 * * * = Daily at 2:00 AM<br>
                0 */6 * * * = Every 6 hours<br>
                0 0 * * 0 = Weekly on Sunday at midnight<br>
                */15 * * * * = Every 15 minutes
            </small>
        </div>
        
        <div class="form-group">
            <div class="checkbox-group">
                <div class="form-checkbox">
                    <label>
                        <input type="checkbox" name="overwrite_files" value="1" checked>
                        <span>Overwrite existing files</span>
                    </label>
                </div>
            </div>
            <small style="color: #666;">If unchecked, files that already exist in S3 will be skipped</small>
        </div>
        
        <button type="submit" class="btn btn-primary">Create Scheduled Job</button>
    </form>
</div>

<div class="card">
    <h2>Scheduled Jobs</h2>
    
    <?php if (count($scheduledJobs) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Local Path</th>
                    <th>S3 Path</th>
                    <th>Schedule</th>
                    <th>S3 Config</th>
                    <th>Overwrite</th>
                    <th>Status</th>
                    <th>Last Run</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($scheduledJobs as $job): ?>
                    <tr>
                        <td><?= htmlspecialchars($job['name']) ?></td>
                        <td title="<?= htmlspecialchars($job['local_path']) ?>">
                            <?= htmlspecialchars(substr($job['local_path'], 0, 20)) ?>...
                        </td>
                        <td><?= htmlspecialchars($job['s3_path']) ?></td>
                        <td><?= htmlspecialchars(getScheduleDescription($job['schedule_preset'], $job['cron_expression'])) ?></td>
                        <td><?= htmlspecialchars($job['settings_name']) ?></td>
                        <td>
                            <?php if ($job['overwrite_files']): ?>
                                <span style="color: #dc3545;">Yes</span>
                            <?php else: ?>
                                <span style="color: #28a745;">Skip</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($job['is_active']): ?>
                                <span class="status-badge status-completed">Active</span>
                            <?php else: ?>
                                <span class="status-badge status-pending">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $job['last_run'] ? date('Y-m-d H:i', strtotime($job['last_run'])) : 'Never' ?>
                        </td>
                        <td>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= $job['id'] ?>">
                                <button type="submit" class="btn btn-secondary">
                                    <?= $job['is_active'] ? 'Disable' : 'Enable' ?>
                                </button>
                            </form>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this scheduled job?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $job['id'] ?>">
                                <button type="submit" class="btn btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No scheduled jobs configured.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Cron Setup</h2>
    <p>To enable scheduled jobs, add this line to your crontab:</p>
    <pre style="background: #f4f4f4; padding: 1rem; border-radius: 4px;">
* * * * * <?= PHP_BINARY ?> <?= __DIR__ ?>/cron.php >> <?= __DIR__ ?>/data/logs/cron.log 2>&1
    </pre>
    <p>To edit your crontab, run: <code>crontab -e</code></p>
</div>

<script>
function toggleCronInput() {
    const scheduleTypeRadio = document.querySelector('input[name="schedule_type"]:checked');
    const scheduleType = scheduleTypeRadio ? scheduleTypeRadio.value : 'daily';
    const customCron = document.getElementById('custom_cron');
    const cronInput = document.querySelector('input[name="cron_expression"]');
    
    if (scheduleType === 'custom') {
        customCron.style.display = 'block';
        cronInput.required = true;
    } else {
        customCron.style.display = 'none';
        cronInput.required = false;
        cronInput.value = '';
    }
}

function toggleScheduledSelectionMode() {
    const singleMode = document.querySelector('input[name="scheduled_selection_type"][value="single"]').checked;
    
    document.getElementById('scheduled_single_path_group').style.display = singleMode ? 'block' : 'none';
    document.getElementById('scheduled_multiple_paths_group').style.display = singleMode ? 'none' : 'block';
    
    // Update help text based on mode
    updateScheduledS3PathHelp(singleMode);
    
    // Update form validation
    const localPathInput = document.querySelector('input[name="local_path"]');
    const multiPathsInput = document.querySelector('textarea[name="multiple_paths"]');
    
    if (singleMode) {
        localPathInput.required = true;
        multiPathsInput.required = false;
    } else {
        localPathInput.required = false;
        multiPathsInput.required = true;
    }
}

function updateScheduledS3PathHelp(singleMode) {
    const helpDiv = document.getElementById('scheduled_s3_path_help');
    if (singleMode) {
        helpDiv.innerHTML = '<small style="color: #666;">The path in your S3 bucket where files will be uploaded<br><em>Example: "backup/daily/" → files go to s3://bucket/backup/daily/filename</em></small>';
    } else {
        helpDiv.innerHTML = '<small style="color: #666;"><strong>Multiple Paths:</strong> Each selected path preserves its structure under this prefix<br><em>Examples:</em><br>• Prefix "backup/" + "/home/docs/file.txt" → "backup/docs/file.txt"<br>• Prefix "backup/" + "/var/logs/" → "backup/logs/..."</small>';
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>