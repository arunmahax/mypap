<?php
/**
 * License check endpoint for sideloaded VeoPlayer installs.
 * Called by the app to verify if a device_id has a valid LemonSqueezy license.
 *
 * GET https://panel.veoplayer.com/ls_license_check.php?device_id=XXXXX
 * Returns JSON: {"licensed": true/false, "plan": "annual"|"lifetime"|""}
 */

// Must be first — suppress PHP warnings/notices that would corrupt JSON output
error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json');

$device_id = isset($_GET['device_id']) ? trim($_GET['device_id']) : '';

if (empty($device_id)) {
    echo json_encode(['licensed' => false, 'plan' => '']);
    exit;
}

require_once __DIR__ . '/includes/db.php';

// Table may not exist yet (before first webhook)
mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS tbl_ls_licenses (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    device_id  VARCHAR(255) NOT NULL,
    order_id   VARCHAR(255) DEFAULT '',
    plan       VARCHAR(20)  DEFAULT 'annual',
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP    NULL,
    UNIQUE KEY uq_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$stmt = $mysqli->prepare("SELECT plan, expires_at FROM tbl_ls_licenses WHERE device_id = ? LIMIT 1");
$stmt->bind_param('s', $device_id);
$stmt->execute();
$result = $stmt->get_result();
$row    = $result->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['licensed' => false, 'plan' => '']);
    exit;
}

$plan    = $row['plan'];
$expires = $row['expires_at'];

// Lifetime = always valid; Annual = valid while expires_at is in the future
$licensed = ($plan === 'lifetime')
    || ($expires === null)
    || (strtotime($expires) > time());

// expires_ts: Unix timestamp for annual plans (0 = lifetime/unlimited)
$expires_ts = ($plan === 'lifetime' || $expires === null) ? 0 : (int) strtotime($expires);

echo json_encode(['licensed' => $licensed, 'plan' => $plan, 'expires_ts' => $expires_ts]);
