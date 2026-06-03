<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
redirect_if_not_authorized('admin');

// Handle user creation/updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add user management logic here
}

// Fetch all users
$users = $conn->query("SELECT id, username, role, created_at, last_login FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
?>

<h2>User Management</h2>

<!-- Add user form -->
<form method="post">
    <input type="hidden" name="action" value="add_user">
    <div class="form-row">
        <div class="form-group">
            <label for="new_username">Username</label>
            <input type="text" name="username" id="new_username" required>
        </div>
        <div class="form-group">
            <label for="new_password">Password</label>
            <input type="password" name="password" id="new_password" required>
        </div>
        <div class="form-group">
            <label for="new_role">Role</label>
            <select name="role" id="new_role" required>
                <option value="marketing">Marketing</option>
                <option value="admin">Admin</option>
                <option value="editor">Editor</option>
                <option value="viewer" selected>Viewer</option>
            </select>
        </div>
        <div class="form-group">
            <button type="submit" class="btn btn-primary">Add User</button>
        </div>
    </div>
</form>

<!-- Users table -->
<table class="table">
    <thead>
        <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Role</th>
            <th>Created</th>
            <th>Last Login</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($users as $user): ?>
        <tr>
            <td><?= $user['id'] ?></td>
            <td><?= htmlspecialchars($user['username']) ?></td>
            <td><?= ucfirst($user['role']) ?></td>
            <td><?= date('Y-m-d H:i', strtotime($user['created_at'])) ?></td>
            <td><?= $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : 'Never' ?></td>
            <td>
                <!-- Add edit/delete buttons here -->
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php';
?>