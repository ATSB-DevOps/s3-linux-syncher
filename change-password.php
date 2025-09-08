<?php
require_once __DIR__ . '/includes/header.php';

use S3Sync\Auth;

$auth = new Auth();
$auth->requireAuth();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Validate form data
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $message = 'All fields are required';
        $messageType = 'danger';
    } elseif ($newPassword !== $confirmPassword) {
        $message = 'New passwords do not match';
        $messageType = 'danger';
    } elseif (strlen($newPassword) < 8) {
        $message = 'New password must be at least 8 characters long';
        $messageType = 'danger';
    } else {
        $result = $auth->changePassword($currentPassword, $newPassword);
        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'danger';
    }
}

$currentUser = $auth->getCurrentUser();
?>

<h1>Change Password</h1>

<?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<div class="card">
    <h2>Change Password for <?= htmlspecialchars($currentUser['username']) ?></h2>
    <form method="POST" id="passwordForm">
        <div class="form-group">
            <label>Current Password</label>
            <input type="password" name="current_password" required>
        </div>
        
        <div class="form-group">
            <label>New Password</label>
            <input type="password" name="new_password" required minlength="8">
            <small style="color: #666;">Password must be at least 8 characters long</small>
        </div>
        
        <div class="form-group">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" required minlength="8">
        </div>
        
        <button type="submit" class="btn btn-primary">Change Password</button>
        <a href="<?= appUrl('index.php') ?>" class="btn btn-secondary">Cancel</a>
    </form>
</div>

<div class="card">
    <h2>Password Requirements</h2>
    <ul>
        <li>Must be at least 8 characters long</li>
        <li>Should contain a mix of letters, numbers, and special characters</li>
        <li>Should not be easily guessable</li>
        <li>Should be different from your current password</li>
    </ul>
</div>

<script>
document.getElementById('passwordForm').addEventListener('submit', function(e) {
    const newPassword = document.querySelector('input[name="new_password"]').value;
    const confirmPassword = document.querySelector('input[name="confirm_password"]').value;
    
    if (newPassword !== confirmPassword) {
        e.preventDefault();
        alert('New passwords do not match');
        return false;
    }
    
    if (newPassword.length < 8) {
        e.preventDefault();
        alert('Password must be at least 8 characters long');
        return false;
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>