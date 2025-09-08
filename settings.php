<?php
require_once __DIR__ . '/includes/header.php';

use S3Sync\S3Manager;

$s3Manager = new S3Manager();
$message = '';
$messageType = '';

// Store form data to preserve it after test
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Preserve form data for all actions except activate/delete
    if ($action === 'save' || $action === 'test') {
        $formData = [
            'name' => $_POST['name'] ?? '',
            'endpoint' => $_POST['endpoint'] ?? '',
            'region' => $_POST['region'] ?? '',
            'access_key' => $_POST['access_key'] ?? '',
            'secret_key' => $_POST['secret_key'] ?? '',
            'bucket' => $_POST['bucket'] ?? ''
        ];
    }
    
    if ($action === 'save') {
        $data = [
            'name' => $_POST['name'],
            'endpoint' => $_POST['endpoint'],
            'region' => $_POST['region'],
            'access_key' => $_POST['access_key'],
            'secret_key' => $_POST['secret_key'],
            'bucket' => $_POST['bucket']
        ];
        
        if (!empty($_POST['id'])) {
            $s3Manager->updateSettings($_POST['id'], $data);
            $message = 'Settings updated successfully';
        } else {
            $id = $s3Manager->saveSettings($data);
            if (empty($s3Manager->getActiveSettings())) {
                $s3Manager->setActive($id);
            }
            $message = 'Settings saved successfully';
        }
        $messageType = 'success';
        // Clear form after successful save
        $formData = [];
    } elseif ($action === 'test') {
        $data = [
            'endpoint' => $_POST['endpoint'],
            'region' => $_POST['region'],
            'access_key' => $_POST['access_key'],
            'secret_key' => $_POST['secret_key'],
            'bucket' => $_POST['bucket']
        ];
        
        $result = $s3Manager->testConnection($data);
        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'danger';
        // Keep form data after test
    } elseif ($action === 'activate') {
        $s3Manager->setActive($_POST['id']);
        $message = 'Settings activated';
        $messageType = 'success';
    } elseif ($action === 'delete') {
        $s3Manager->deleteSettings($_POST['id']);
        $message = 'Settings deleted';
        $messageType = 'success';
    }
}

$allSettings = $s3Manager->getSettings();
$editId = $_GET['edit'] ?? null;
$editSettings = $editId ? $s3Manager->getSettings($editId) : null;

// Merge form data with edit settings for display
$displayData = [];
if (!empty($formData)) {
    // Use form data if available (after test)
    $displayData = $formData;
} elseif ($editSettings) {
    // Use edit settings if editing
    $displayData = $editSettings;
}
?>

<h1>S3 Settings</h1>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div class="card">
    <h2><?= $editSettings ? 'Edit Settings' : 'Add New S3 Configuration' ?></h2>
    <form method="POST">
        <input type="hidden" name="action" value="save">
        <?php if ($editSettings): ?>
            <input type="hidden" name="id" value="<?= $editSettings['id'] ?>">
        <?php endif; ?>
        
        <div class="form-group">
            <label>Configuration Name</label>
            <input type="text" name="name" value="<?= htmlspecialchars($displayData['name'] ?? '') ?>" required>
        </div>
        
        <div class="form-group">
            <label>Endpoint URL (optional, for S3-compatible services)</label>
            <input type="url" name="endpoint" value="<?= htmlspecialchars($displayData['endpoint'] ?? '') ?>" 
                   placeholder="e.g., https://nyc3.digitaloceanspaces.com">
        </div>
        
        <div class="form-group">
            <label>Region</label>
            <input type="text" name="region" value="<?= htmlspecialchars($displayData['region'] ?? 'us-east-1') ?>" required>
        </div>
        
        <div class="form-group">
            <label>Access Key</label>
            <input type="text" name="access_key" value="<?= htmlspecialchars($displayData['access_key'] ?? '') ?>" required>
        </div>
        
        <div class="form-group">
            <label>Secret Key</label>
            <input type="password" name="secret_key" value="<?= htmlspecialchars($displayData['secret_key'] ?? '') ?>" required>
        </div>
        
        <div class="form-group">
            <label>Bucket Name</label>
            <input type="text" name="bucket" value="<?= htmlspecialchars($displayData['bucket'] ?? '') ?>" required>
        </div>
        
        <button type="submit" class="btn btn-primary">Save Settings</button>
        <button type="submit" class="btn btn-secondary" onclick="this.form.action.value='test'">Test Connection</button>
        <?php if ($editSettings): ?>
                <a href="<?= appUrl('settings.php') ?>" class="btn btn-secondary">Cancel</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <h2>Configuration Help</h2>
    <h3>S3-Compatible Services</h3>
    
    <h4>AWS S3</h4>
    <ul>
        <li><strong>Endpoint</strong>: Leave empty</li>
        <li><strong>Region</strong>: us-east-1, eu-west-1, etc.</li>
        <li><strong>Access Key</strong>: Your AWS Access Key ID</li>
        <li><strong>Secret Key</strong>: Your AWS Secret Access Key</li>
    </ul>
    
    <h4>Cloudflare R2</h4>
    <ul>
        <li><strong>Endpoint</strong>: https://[account-id].r2.cloudflarestorage.com</li>
        <li><strong>Region</strong>: auto</li>
        <li><strong>Access Key</strong>: R2 Token ID</li>
        <li><strong>Secret Key</strong>: R2 Token Secret</li>
        <li><strong>Bucket</strong>: Your R2 bucket name</li>
    </ul>
    
    <h4>DigitalOcean Spaces</h4>
    <ul>
        <li><strong>Endpoint</strong>: https://[region].digitaloceanspaces.com</li>
        <li><strong>Region</strong>: nyc3, sfo3, ams3, etc.</li>
        <li><strong>Access Key</strong>: Spaces Access Key</li>
        <li><strong>Secret Key</strong>: Spaces Secret Key</li>
    </ul>
    
    <p><em>Note: Always test your connection before saving to ensure credentials are correct.</em></p>
</div>

<div class="card">
    <h2>Saved Configurations</h2>
    <?php if (count($allSettings) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Endpoint</th>
                    <th>Region</th>
                    <th>Bucket</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allSettings as $setting): ?>
                    <tr>
                        <td><?= htmlspecialchars($setting['name']) ?></td>
                        <td><?= htmlspecialchars($setting['endpoint'] ?: 'AWS S3') ?></td>
                        <td><?= htmlspecialchars($setting['region']) ?></td>
                        <td><?= htmlspecialchars($setting['bucket']) ?></td>
                        <td>
                            <?php if ($setting['is_active']): ?>
                                <span class="status-badge status-completed">Active</span>
                            <?php else: ?>
                                <span class="status-badge status-pending">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="?edit=<?= $setting['id'] ?>" class="btn btn-primary">Edit</a>
                            <?php if (!$setting['is_active']): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="activate">
                                    <input type="hidden" name="id" value="<?= $setting['id'] ?>">
                                    <button type="submit" class="btn btn-success">Activate</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this configuration?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $setting['id'] ?>">
                                <button type="submit" class="btn btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No configurations saved yet.</p>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>