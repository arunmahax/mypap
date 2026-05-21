<?php
/**
 * License check endpoint for sideloaded VeoPlayer installs.
 * Every request MUST include a valid HMAC-SHA256 signature.
 * Requests without a valid sig, or with a timestamp older than 5 minutes,
 * are rejected with HTTP 403 — no license info is leaked.
 *
 * GET https://panel.veoplayer.com/ls_license_check.php
 *     ?device_id=XXXXX&ts=UNIX_TS&sig=HMAC_HEX
 */

error_reporting(0);
ini_set('display_errors', '0');

require_once __DIR__ . '/includes/db_helper.php';
ob_end_clean();
header('Content-Type: application/json');

// ── Shared secret (must match AppSecret.java in the Android app) ──────────
define('APP_SECRET', 'V3oP!@#aYeR$2026%SecK3y&xLm*9ZqB');

// ── Max allowed clock skew between app and server (seconds) ───────────────
define('TS_WINDOW', 300); // 5 minutes

// ── Read & validate inputs ────────────────────────────────────────────────
$device_id = isset($_GET['device_id']) ? trim($_GET['device_id']) : '';
$ts        = isset($_GET['ts'])        ? (int)$_GET['ts']         : 0;
$sig       = isset($_GET['sig'])       ? trim($_GET['sig'])        : '';

if (empty($device_id) || empty($sig) || $ts === 0) {
    http_response_code(403);
    echo json_encode(['licensed' => false]);
    exit;
}

// ── Reject stale or future timestamps (replay attack prevention) ──────────
if (abs(time() - $ts) > TS_WINDOW) {
    http_response_code(403);
    echo json_encode(['licensed' => false]);
    exit;
}

// ── Verify HMAC-SHA256 signature ─────────────────────────────────────────
// Payload must match exactly what LicenseManager.java signs: "deviceId:ts"
$expected = hash_hmac('sha256', $device_id . ':' . $ts, APP_SECRET);

if (!hash_equals($expected, $sig)) {
    http_response_code(403);
    echo json_encode(['licensed' => false]);
    exit;
}

// ── All checks passed — look up license ──────────────────────────────────
mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS tbl_ls_licenses (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    device_id  VARCHAR(255) NOT NULL,
    order_id   VARCHAR(255) DEFAULT '',
    plan       VARCHAR(20)  DEFAULT 'annual',
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP    NULL,
    UNIQUE KEY uq_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$stmt = $mysqli->prepare(
    "SELECT plan, expires_at FROM tbl_ls_licenses WHERE device_id = ? LIMIT 1");
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

$licensed = ($plan === 'lifetime')
    || ($expires === null)
    || (strtotime($expires) > time());

$expires_ts = ($plan === 'lifetime' || $expires === null)
    ? 0
    : (int) strtotime($expires);

echo json_encode([
    'licensed'   => $licensed,
    'plan'       => $plan,
    'expires_ts' => $expires_ts,
]);
