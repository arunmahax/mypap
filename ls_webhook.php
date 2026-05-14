<?php
/**
 * LemonSqueezy webhook for VeoPlayer sideloaded license activation.
 * Endpoint: https://panel.veoplayer.com/ls_webhook.php
 *
 * Events to enable in LemonSqueezy: order_created
 * Signing secret: set in Admin Panel → App Settings → Billing Plans
 */

// DB connection — must come before signature check so we can read the secret
require_once __DIR__ . '/includes/db_helper.php';

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ls_webhook_secret FROM tbl_settings WHERE id=1 LIMIT 1"));
$signing_secret = $row ? trim($row['ls_webhook_secret']) : '';

$payload   = file_get_contents('php://input');
$signature = isset($_SERVER['HTTP_X_SIGNATURE']) ? $_SERVER['HTTP_X_SIGNATURE'] : '';

// Reject if secret is not configured
if (empty($signing_secret)) {
    http_response_code(500);
    exit('Webhook secret not configured');
}

// Verify HMAC-SHA256 signature
$expected = hash_hmac('sha256', $payload, $signing_secret);
if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    exit('Invalid signature');
}

$data  = json_decode($payload, true);
$event = isset($data['meta']['event_name']) ? $data['meta']['event_name'] : '';

// Only handle order_created
if ($event !== 'order_created') {
    http_response_code(200);
    exit('ok');
}

$order  = isset($data['data']['attributes']) ? $data['data']['attributes'] : [];
$status = isset($order['status']) ? $order['status'] : '';

if ($status !== 'paid') {
    http_response_code(200);
    exit('ok');
}

$device_id    = isset($data['meta']['custom_data']['device_id']) ? trim($data['meta']['custom_data']['device_id']) : '';
$product_name = isset($order['first_order_item']['product_name'])  ? $order['first_order_item']['product_name'] : '';
$order_id     = isset($data['data']['id']) ? $data['data']['id'] : '';

if (empty($device_id)) {
    http_response_code(200);
    exit('no device_id');
}

$plan       = (stripos($product_name, 'lifetime') !== false) ? 'lifetime' : 'annual';
$expires_at = ($plan === 'lifetime') ? null : date('Y-m-d H:i:s', strtotime('+1 year'));

// Create table if not exists
mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS tbl_ls_licenses (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    device_id  VARCHAR(255) NOT NULL,
    order_id   VARCHAR(255) DEFAULT '',
    plan       VARCHAR(20)  DEFAULT 'annual',
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP    NULL,
    UNIQUE KEY uq_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$device_id_esc = mysqli_real_escape_string($mysqli, $device_id);
$order_id_esc  = mysqli_real_escape_string($mysqli, $order_id);
$plan_esc      = mysqli_real_escape_string($mysqli, $plan);
$expires_sql   = ($expires_at !== null)
    ? "'" . mysqli_real_escape_string($mysqli, $expires_at) . "'"
    : "NULL";

mysqli_query($mysqli, "INSERT INTO tbl_ls_licenses (device_id, order_id, plan, expires_at)
    VALUES ('$device_id_esc', '$order_id_esc', '$plan_esc', $expires_sql)
    ON DUPLICATE KEY UPDATE
        order_id   = '$order_id_esc',
        plan       = '$plan_esc',
        expires_at = $expires_sql");

http_response_code(200);
echo 'ok';
