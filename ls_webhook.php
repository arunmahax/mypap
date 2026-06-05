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

$device_id      = isset($data['meta']['custom_data']['device_id']) ? trim($data['meta']['custom_data']['device_id']) : '';
$customer_email = isset($order['user_email']) ? strtolower(trim($order['user_email'])) : '';
$product_name   = isset($order['first_order_item']['product_name']) ? $order['first_order_item']['product_name'] : '';
$order_id       = isset($data['data']['id']) ? $data['data']['id'] : '';

if (empty($device_id)) {
    http_response_code(200);
    exit('no device_id');
}

$plan       = (stripos($product_name, 'lifetime') !== false) ? 'lifetime' : 'annual';
$expires_at = ($plan === 'lifetime') ? null : date('Y-m-d H:i:s', strtotime('+1 year'));

// Create table if not exists (with customer_email column)
mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS tbl_ls_licenses (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    device_id      VARCHAR(255) NOT NULL,
    customer_email VARCHAR(255) DEFAULT '',
    order_id       VARCHAR(255) DEFAULT '',
    plan           VARCHAR(20)  DEFAULT 'annual',
    created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    expires_at     TIMESTAMP    NULL,
    UNIQUE KEY uq_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add customer_email column if missing (existing installs)
$col = $mysqli->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tbl_ls_licenses' AND COLUMN_NAME='customer_email'");
if ($col && $col->fetch_row()[0] == 0) {
    $mysqli->query("ALTER TABLE tbl_ls_licenses ADD COLUMN customer_email VARCHAR(255) DEFAULT '' AFTER device_id");
}

$stmt = $mysqli->prepare(
    "INSERT INTO tbl_ls_licenses (device_id, customer_email, order_id, plan, expires_at)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
         customer_email = IF(? != '', ?, customer_email),
         order_id       = ?,
         plan           = ?,
         expires_at     = ?"
);
$expires_val = $expires_at;
$stmt->bind_param('sssssssssss',
    $device_id, $customer_email, $order_id, $plan, $expires_at,
    $customer_email, $customer_email,
    $order_id, $plan, $expires_val
);
$stmt->execute();
$stmt->close();

http_response_code(200);
echo 'ok';
