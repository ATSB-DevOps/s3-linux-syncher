<?php
require_once __DIR__ . '/includes/header.php';

use S3Sync\Database;
use S3Sync\S3Manager;

$db = (new Database())->getConnection();
$s3Manager = new S3Manager();
$activeSettings = $s3Manager->getActiveSettings();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $activeSettings) {
    $jobName = $_POST['job_name'];
    $s3Path = $_POST['s3_path'];
    $overwriteFiles = isset($_POST['overwrite_files']) ? 1 : 0;
    $selectionType = $_POST['selection_type'] ?? 'single';
    
    $selectedPaths = [];
    $invalidPaths = [];
    
    if ($selectionType === 'single') {
        // Single path selection (backward compatibility)
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
        // Multiple path selection
        $multiPaths = $_POST['selected_paths'] ?? [];
        foreach ($multiPaths as $path) {
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
        $message = 'Please select at least one file or directory';
        $messageType = 'danger';
    } else {
        // Create sync job (use first path as main path for backward compatibility)
        $mainPath = $selectedPaths[0]['path'];
        $stmt = $db->prepare("INSERT INTO sync_jobs (name, local_path, s3_path, s3_settings_id, overwrite_files, status) 
                              VALUES (?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$jobName, $mainPath, $s3Path, $activeSettings['id'], $overwriteFiles]);
        $jobId = $db->lastInsertId();
        
        // Store all selected paths
        foreach ($selectedPaths as $pathInfo) {
            $stmt = $db->prepare("INSERT INTO job_paths (job_id, local_path, path_type) VALUES (?, ?, ?)");
            $stmt->execute([$jobId, $pathInfo['path'], $pathInfo['type']]);
        }
        
        // Execute sync in background
        $workerPath = __DIR__ . '/worker.php';
        $logPath = __DIR__ . '/data/logs/worker_' . $jobId . '.log';
        
        // Ensure log directory exists
        $logDir = dirname($logPath);
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Execute worker with error logging using default php command
        $command = sprintf('php %s %d >> %s 2>&1 &', 
            escapeshellarg($workerPath), 
            $jobId,
            escapeshellarg($logPath)
        );
        exec($command, $output, $returnCode);
        
        $pathCount = count($selectedPaths);
        $message = "Sync job created with {$pathCount} path(s) and started in background";
        $messageType = 'success';
    }
}
?>

<h1>Create New Sync Job</h1>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<?php if (!$activeSettings): ?>
    <div class="alert alert-danger">
        Please <a href="<?= appUrl('settings.php') ?>">configure and activate S3 settings</a> before creating sync jobs.
    </div>
<?php else: ?>
    <div class="card">
        <h2>Sync Configuration</h2>
        <form method="POST" id="syncForm">
            <div class="form-group">
                <label>Job Name</label>
                <input type="text" name="job_name" required placeholder="e.g., Daily Backup">
            </div>
            
            <div class="form-group">
                <label>Selection Mode</label>
                <div class="radio-group">
                    <div class="form-radio">
                        <label>
                            <input type="radio" name="selection_type" value="single" checked onchange="toggleSelectionMode()">
                            <span>Single Path</span>
                        </label>
                    </div>
                    <div class="form-radio">
                        <label>
                            <input type="radio" name="selection_type" value="multiple" onchange="toggleSelectionMode()">
                            <span>Multiple Paths</span>
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="form-group" id="single_path_group">
                <label>Local Path (File or Folder)</label>
                <input type="text" name="local_path" id="local_path" placeholder="/path/to/folder">
                <button type="button" onclick="browseFolders()" class="btn btn-secondary" style="margin-top: 0.5rem;">Browse</button>
            </div>
            
            <div class="form-group" id="multiple_paths_group" style="display: none;">
                <label>Selected Paths</label>
                <div id="selected_paths_list" style="background: #f8f9fa; padding: 1rem; border-radius: 4px; margin-bottom: 1rem; min-height: 100px;">
                    <p style="color: #666; margin: 0;">No paths selected. Use the browser below to select files and directories.</p>
                </div>
                <button type="button" onclick="clearSelection()" class="btn btn-secondary">Clear All</button>
                <input type="hidden" name="selected_paths[]" id="hidden_paths">
            </div>
            
            <div class="form-group">
                <label>S3 Path (prefix)</label>
                <input type="text" name="s3_path" required placeholder="backups/folder/">
                <div id="s3_path_help">
                    <small style="color: #666;">
                        The path in your S3 bucket where files will be uploaded<br>
                        <em>Example: "backup/daily/" → files go to s3://bucket/backup/daily/filename</em>
                    </small>
                </div>
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
            
            <div class="alert alert-info">
                <strong>Active S3 Configuration:</strong> <?= htmlspecialchars($activeSettings['name']) ?><br>
                <strong>Bucket:</strong> <?= htmlspecialchars($activeSettings['bucket']) ?>
            </div>
            
            <button type="submit" class="btn btn-primary">Start Sync</button>
        </form>
    </div>
    
    <div class="card" style="margin-top: 2rem;">
        <h2>Browse Local Filesystem</h2>
        <div id="file-browser">
            <div id="current-path" style="margin-bottom: 1rem; font-weight: bold;"></div>
            <div id="selection-actions" style="margin-bottom: 1rem; display: none;">
                <button type="button" onclick="selectAllVisible()" class="btn btn-secondary">Select All Visible</button>
                <button type="button" onclick="deselectAllVisible()" class="btn btn-secondary">Deselect All Visible</button>
                <span id="selection-count" style="margin-left: 1rem; font-weight: bold;"></span>
            </div>
            <div id="folder-list"></div>
        </div>
    </div>
    
    <script>
    let currentPath = '/';
    let selectedPaths = new Set();
    let selectionMode = 'single';
    
    function toggleSelectionMode() {
        const singleMode = document.querySelector('input[name="selection_type"][value="single"]').checked;
        selectionMode = singleMode ? 'single' : 'multiple';
        
        document.getElementById('single_path_group').style.display = singleMode ? 'block' : 'none';
        document.getElementById('multiple_paths_group').style.display = singleMode ? 'none' : 'block';
        document.getElementById('selection-actions').style.display = singleMode ? 'none' : 'block';
        
        // Update help text based on mode
        updateS3PathHelp(singleMode);
        
        // Update form validation
        const localPathInput = document.getElementById('local_path');
        localPathInput.required = singleMode;
        
        if (singleMode) {
            selectedPaths.clear();
            updateSelectedPathsDisplay();
        }
        
        // Refresh browser to show/hide checkboxes
        browseFolders(currentPath);
    }
    
    function updateS3PathHelp(singleMode) {
        const helpDiv = document.getElementById('s3_path_help');
        if (singleMode) {
            helpDiv.innerHTML = '<small style="color: #666;">The path in your S3 bucket where files will be uploaded<br><em>Example: "backup/daily/" → files go to s3://bucket/backup/daily/filename</em></small>';
        } else {
            helpDiv.innerHTML = '<small style="color: #666;"><strong>Multiple Paths:</strong> Each selected path preserves its structure under this prefix<br><em>Examples:</em><br>• Prefix "backup/" + "/home/docs/file.txt" → "backup/docs/file.txt"<br>• Prefix "backup/" + "/var/logs/" → "backup/logs/..."</small>';
        }
    }
    
    function browseFolders(path = '/') {
        currentPath = path;
        const basePath = '<?= appUrl('') ?>';
        fetch(basePath + '/api/browse.php?path=' + encodeURIComponent(path))
            .then(response => response.json())
            .then(data => {
                document.getElementById('current-path').innerHTML = '<strong>Current Path:</strong> ' + data.current;
                
                let html = '<table style="width: 100%;"><thead><tr>';
                if (selectionMode === 'multiple') {
                    html += '<th style="width: 40px;">Select</th>';
                }
                html += '<th>Name</th><th>Type</th><th>Actions</th></tr></thead><tbody>';
                
                if (data.parent) {
                    html += '<tr>';
                    if (selectionMode === 'multiple') {
                        html += '<td></td>';
                    }
                    html += '<td>📁 ..</td><td>directory</td><td><button onclick="browseFolders(\'' + data.parent + '\')" class="btn btn-secondary">Open</button></td></tr>';
                }
                
                data.items.forEach(item => {
                    const icon = item.type === 'dir' ? '📁' : '📄';
                    const isSelected = selectedPaths.has(item.path);
                    
                    html += '<tr>';
                    if (selectionMode === 'multiple') {
                        html += '<td><input type="checkbox" class="table-checkbox" onchange="togglePathSelection(\'' + item.path + '\', \'' + item.type + '\')" ' + (isSelected ? 'checked' : '') + '></td>';
                    }
                    html += '<td>' + icon + ' ' + item.name + '</td>';
                    html += '<td>' + item.type + '</td>';
                    html += '<td>';
                    if (item.type === 'dir') {
                        html += '<button onclick="browseFolders(\'' + item.path + '\')" class="btn btn-secondary">Open</button> ';
                    }
                    if (selectionMode === 'single') {
                        html += '<button onclick="selectPath(\'' + item.path + '\')" class="btn btn-primary">Select</button>';
                    } else {
                        html += '<button onclick="quickSelect(\'' + item.path + '\', \'' + item.type + '\')" class="btn btn-primary">Quick Select</button>';
                    }
                    html += '</td>';
                    html += '</tr>';
                });
                
                html += '</tbody></table>';
                document.getElementById('folder-list').innerHTML = html;
                updateSelectionCount();
            });
    }
    
    function selectPath(path) {
        document.getElementById('local_path').value = path;
        window.scrollTo(0, 0);
    }
    
    function togglePathSelection(path, type) {
        if (selectedPaths.has(path)) {
            selectedPaths.delete(path);
        } else {
            selectedPaths.add(path);
        }
        updateSelectedPathsDisplay();
        updateSelectionCount();
    }
    
    function quickSelect(path, type) {
        selectedPaths.add(path);
        updateSelectedPathsDisplay();
        browseFolders(currentPath); // Refresh to show checkbox as checked
    }
    
    function updateSelectedPathsDisplay() {
        const container = document.getElementById('selected_paths_list');
        const hiddenInput = document.getElementById('hidden_paths');
        
        if (selectedPaths.size === 0) {
            container.innerHTML = '<p style="color: #666; margin: 0;">No paths selected. Use the browser below to select files and directories.</p>';
            hiddenInput.value = '';
        } else {
            let html = '<div style="max-height: 200px; overflow-y: auto;">';
            selectedPaths.forEach(path => {
                const type = path.includes('.') && !path.endsWith('/') ? 'file' : 'directory';
                const icon = type === 'directory' ? '📁' : '📄';
                html += '<div style="display: flex; justify-content: space-between; align-items: center; padding: 0.25rem 0; border-bottom: 1px solid #eee;">';
                html += '<span>' + icon + ' ' + path + '</span>';
                html += '<button type="button" onclick="removePathSelection(\'' + path + '\')" class="btn btn-danger" style="padding: 0.1rem 0.3rem; font-size: 0.8rem;">Remove</button>';
                html += '</div>';
            });
            html += '</div>';
            container.innerHTML = html;
            
            // Update hidden input with array of paths
            const pathsArray = Array.from(selectedPaths);
            // Create individual hidden inputs for each path
            let hiddenInputs = '';
            pathsArray.forEach(path => {
                hiddenInputs += '<input type="hidden" name="selected_paths[]" value="' + path + '">';
            });
            hiddenInput.outerHTML = hiddenInputs;
        }
        updateSelectionCount();
    }
    
    function removePathSelection(path) {
        selectedPaths.delete(path);
        updateSelectedPathsDisplay();
        browseFolders(currentPath); // Refresh to show checkbox as unchecked
    }
    
    function clearSelection() {
        selectedPaths.clear();
        updateSelectedPathsDisplay();
        browseFolders(currentPath); // Refresh to show all checkboxes as unchecked
    }
    
    function selectAllVisible() {
        const checkboxes = document.querySelectorAll('#folder-list input[type="checkbox"]');
        checkboxes.forEach(checkbox => {
            if (!checkbox.checked) {
                checkbox.click();
            }
        });
    }
    
    function deselectAllVisible() {
        const checkboxes = document.querySelectorAll('#folder-list input[type="checkbox"]');
        checkboxes.forEach(checkbox => {
            if (checkbox.checked) {
                checkbox.click();
            }
        });
    }
    
    function updateSelectionCount() {
        const countElement = document.getElementById('selection-count');
        if (selectionMode === 'multiple') {
            countElement.textContent = selectedPaths.size + ' path(s) selected';
        }
    }
    
    // Form validation
    document.getElementById('syncForm').addEventListener('submit', function(e) {
        if (selectionMode === 'multiple' && selectedPaths.size === 0) {
            e.preventDefault();
            alert('Please select at least one file or directory');
            return false;
        }
    });
    
    browseFolders();
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>