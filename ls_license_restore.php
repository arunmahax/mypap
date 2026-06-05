<?php
/**
 * License restore by email — called when a user reinstalls and loses their device ID.
 * Looks up the license by customer email, re-links it to the new device ID.
 *
 * GET https://panel.veoplayer.com/ls_license_restore.php
 *     ?email=USER_EMAIL&device_id=NEW_ID&ts=UNIX_TS&sig=HMAC_HEX
 *
 * HMAC payload: "email:device_id:ts"
 */

error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/includes/db_helper.php';
ob_end_clean();
header('Content-Type: application/json');

define('APP_SECRET_R', 'V3oP!@#aYeR$2026%SecK3y&xLm*9ZqB');
define('TS_WINDOW_R',  300);

$device_id = isset($_GET['device_id']) ? trim($_GET['device_id'])                    : '';
$email     = isset($_GET['email'])     ? strtolower(trim($_GET['email']))             : '';
$ts        = isset($_GET['ts'])        ? (int)$_GET['ts']                            : 0;
$sig       = isset($_GET['sig'])       ? trim($_GET['sig'])                          : '';

if (empty($device_id) || empty($email) || empty($sig) || $ts === 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'msg' => 'Missing parameters']);
    exit;
}

// Reject stale / future timestamps
if (abs(time() - $ts) > TS_WINDOW_R) {
    http_response_code(403);
    echo json_encode(['success' => false, 'msg' => 'Request expired']);
    exit;
}

// Verify HMAC — payload: email:device_id:ts
$expected = hash_hmac('sha256', $email . ':' . $device_id . ':' . $ts, APP_SECRET_R);
if (!hash_equals($expected, $sig)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'msg' => 'Invalid signature']);
    exit;
}

// Look up license by email
$stmt = $mysqli->prepare(
    "SELECT id, plan, expires_at FROM tbl_ls_licenses WHERE customer_email = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['success' => false, 'msg' => 'No license found for this email']);
    exit;
}

$plan    = $row['plan'];
$expires = $row['expires_at'];

$licensed = ($plan === 'lifetime')
         || ($expires === null)
         || (strtotime($expires) > time());

if (!$licensed) {
    echo json_encode(['success' => false, 'msg' => 'License has expired']);
    exit;
}

// Re-link license to the new device ID
$upd = $mysqli->prepare(
    "UPDATE tbl_ls_licenses SET device_id = ? WHERE id = ?");
$upd->bind_param('si', $device_id, $row['id']);
$upd->execute();
$upd->close();

$expires_ts = ($plan === 'lifetime' || $expires === null)
    ? 0
    : (int) strtotime($expires);

echo json_encode([
    'success'    => true,
    'licensed'   => true,
    'plan'       => $plan,
    'expires_ts' => $expires_ts,
]);
