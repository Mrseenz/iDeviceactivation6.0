<?php

declare(strict_types=1);

define('DB_FILE_MANAGE', __DIR__ . '/activation_simulator.sqlite'); // Use a distinct constant name

function get_db_connection_manage(): PDO
{
    static $pdo_manage = null;
    if ($pdo_manage === null) {
        try {
            $pdo_manage = new PDO('sqlite:' . DB_FILE_MANAGE);
            $pdo_manage->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo_manage->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    return $pdo_manage;
}

// Ensure table exists (simple init, activator2.0.php does more comprehensive init)
try {
    $pdo = get_db_connection_manage();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS devices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            udid TEXT UNIQUE NOT NULL,
            serial_number TEXT UNIQUE,
            imei TEXT,
            product_type TEXT,
            is_simulated_locked INTEGER NOT NULL DEFAULT 0,
            simulated_lock_message TEXT,
            activation_record_xml TEXT,
            notes TEXT,
            first_seen_timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_activation_attempt_timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (PDOException $e) {
    die("Database initialization failed: " . $e->getMessage());
}


$message = '';
$devices = [];
$search_term = '';

// Handle form submission for updating lock status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['udid'])) {
    $pdo = get_db_connection_manage();
    $udid = trim($_POST['udid']);
    $action = $_POST['action'];

    if (empty($udid)) {
        $message = "<p style='color:red;'>UDID cannot be empty.</p>";
    } else {
        if ($action === 'lock' || $action === 'unlock') {
            $is_locked = ($action === 'lock') ? 1 : 0;
            $lock_message = ($action === 'lock' && isset($_POST['lock_message'])) ? trim($_POST['lock_message']) : null;

            // Check if device exists
            $stmt_check = $pdo->prepare("SELECT udid FROM devices WHERE udid = :udid");
            $stmt_check->execute(['udid' => $udid]);
            if (!$stmt_check->fetch()) {
                 $message = "<p style='color:red;'>Device with UDID '$udid' not found. It must attempt activation via activator2.0.php first.</p>";
            } else {
                $stmt = $pdo->prepare("UPDATE devices SET is_simulated_locked = :is_locked, simulated_lock_message = :lock_message WHERE udid = :udid");
                try {
                    $stmt->execute([
                        'is_locked' => $is_locked,
                        'lock_message' => $lock_message,
                        'udid' => $udid
                    ]);
                    $message = "<p style='color:green;'>Device UDID '$udid' has been " . ($is_locked ? "LOCKED" : "UNLOCKED") . " (simulated).</p>";
                } catch (PDOException $e) {
                    $message = "<p style='color:red;'>Error updating device: " . $e->getMessage() . "</p>";
                }
            }
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM devices WHERE udid = :udid");
            try {
                $stmt->execute(['udid' => $udid]);
                if ($stmt->rowCount() > 0) {
                    $message = "<p style='color:green;'>Device UDID '$udid' has been DELETED from the simulation.</p>";
                } else {
                    $message = "<p style='color:orange;'>Device UDID '$udid' not found for deletion.</p>";
                }
            } catch (PDOException $e) {
                $message = "<p style='color:red;'>Error deleting device: " . $e->getMessage() . "</p>";
            }
        }
    }
}

// Fetch devices for display
$pdo = get_db_connection_manage();
if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
    $search_term = trim($_GET['search']);
    $stmt = $pdo->prepare("SELECT udid, serial_number, product_type, is_simulated_locked, simulated_lock_message, last_activation_attempt_timestamp FROM devices WHERE udid LIKE :term OR serial_number LIKE :term ORDER BY last_activation_attempt_timestamp DESC");
    $stmt->execute(['term' => '%' . $search_term . '%']);
} else {
    $stmt = $pdo->prepare("SELECT udid, serial_number, product_type, is_simulated_locked, simulated_lock_message, last_activation_attempt_timestamp FROM devices ORDER BY last_activation_attempt_timestamp DESC LIMIT 50"); // Limit for manageability
    $stmt->execute();
}
$devices = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Simulated Device Locks</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f4f4f4; color: #333; }
        h1, h2 { color: #333; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; background-color: #fff; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background-color: #e9e9e9; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        form { margin-bottom: 20px; padding: 15px; background-color: #fff; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        label { display: block; margin-bottom: 5px; }
        input[type="text"], input[type="submit"], select {
            padding: 8px; margin-bottom: 10px; border-radius: 4px; border: 1px solid #ccc;
            width: calc(100% - 18px); max-width: 300px;
        }
        input[type="submit"] { background-color: #5cb85c; color: white; cursor: pointer; width: auto; }
        input[type="submit"].delete { background-color: #d9534f; }
        input[type="submit"].lock { background-color: #f0ad4e; }
        input[type="submit"].unlock { background-color: #5bc0de; }
        .message { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .search-form { margin-bottom: 20px; }
    </style>
</head>
<body>
    <h1>Manage Simulated Device Activation Lock Status</h1>
    <p>This page allows you to manage the <strong>simulated</strong> activation lock status for devices known to <code>activator2.0.php</code>.</p>
    <p>A device record is created in the database when it first attempts activation via <code>activator2.0.php</code>.</p>

    <?php if ($message): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <h2>Set Device Lock Status</h2>
    <form method="POST" action="manage_lock.php">
        <div>
            <label for="udid">Device UDID (or Serial Number to find UDID):</label>
            <input type="text" id="udid" name="udid" required placeholder="Enter UDID of the device">
        </div>
        <div>
            <label for="lock_message">Simulated Lock Message (optional, if locking):</label>
            <input type="text" id="lock_message" name="lock_message" placeholder="e.g., Locked to example@icloud.com">
        </div>
        <div>
            <input type="submit" name="action" value="lock" class="lock">
            <input type="submit" name="action" value="unlock" class="unlock">
            <input type="submit" name="action" value="delete" class="delete" onclick="return confirm('Are you sure you want to delete this device record from the simulation?');">
        </div>
    </form>

    <h2>Search Devices</h2>
    <form method="GET" action="manage_lock.php" class="search-form">
        <input type="text" name="search" placeholder="Search by UDID or Serial..." value="<?= htmlspecialchars($search_term) ?>">
        <input type="submit" value="Search">
         <a href="manage_lock.php" style="margin-left: 10px;">Clear Search</a>
    </form>

    <h2>Known Devices (<?= count($devices) ?> found)</h2>
    <?php if (empty($devices)): ?>
        <p>No devices found or matching search. Devices appear here after their first activation attempt via <code>activator2.0.php</code>.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>UDID</th>
                    <th>Serial Number</th>
                    <th>Product Type</th>
                    <th>Simulated Lock Status</th>
                    <th>Simulated Lock Message</th>
                    <th>Last Activation Attempt</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($devices as $device): ?>
                    <tr>
                        <td><?= htmlspecialchars($device['udid']) ?></td>
                        <td><?= htmlspecialchars($device['serial_number'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($device['product_type'] ?? 'N/A') ?></td>
                        <td style="color: <?= $device['is_simulated_locked'] ? 'red' : 'green' ?>;">
                            <?= $device['is_simulated_locked'] ? 'LOCKED' : 'UNLOCKED' ?>
                        </td>
                        <td><?= htmlspecialchars($device['simulated_lock_message'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($device['last_activation_attempt_timestamp']) ?></td>
                        <td>
                            <form method="POST" action="manage_lock.php" style="display:inline;">
                                <input type="hidden" name="udid" value="<?= htmlspecialchars($device['udid']) ?>">
                                <?php if ($device['is_simulated_locked']): ?>
                                    <input type="submit" name="action" value="unlock" class="unlock" title="Simulate Unlock">
                                <?php else: ?>
                                    <input type="submit" name="action" value="lock" class="lock" title="Simulate Lock (add message in form above if desired)">
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>
