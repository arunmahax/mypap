<?php
// ── Bootstrap DB before any HTML output (needed for AJAX handlers) ────────────
include("includes/db_helper.php");

// ── Ensure license table exists ───────────────────────────────────────────────
mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS tbl_ls_licenses (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    device_id  VARCHAR(255) NOT NULL,
    order_id   VARCHAR(255) DEFAULT '',
    plan       VARCHAR(20)  DEFAULT 'annual',
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP    NULL,
    UNIQUE KEY uq_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Handle manual activation (AJAX) ──────────────────────────────────────────
if (isset($_POST['activate_device'])) {
    header('Content-Type: application/json');
    $dev_id = trim($_POST['activate_device']);
    $plan   = (isset($_POST['activate_plan']) && $_POST['activate_plan'] === 'lifetime') ? 'lifetime' : 'annual';
    if ($plan === 'annual' && !empty($_POST['activate_expiry'])) {
        $expires = date('Y-m-d H:i:s', strtotime($_POST['activate_expiry']));
    } else {
        $expires = ($plan === 'lifetime') ? null : date('Y-m-d H:i:s', strtotime('+1 year'));
    }
    if (empty($dev_id)) {
        echo json_encode(['success' => false, 'msg' => 'No device ID']);
        exit;
    }
    $order_id = 'MANUAL-' . time();
    $stmt = $mysqli->prepare(
        "INSERT INTO tbl_ls_licenses (device_id, order_id, plan, expires_at)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE order_id=VALUES(order_id), plan=VALUES(plan), expires_at=VALUES(expires_at)"
    );
    $stmt->bind_param('ssss', $dev_id, $order_id, $plan, $expires);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => (bool)$ok]);
    exit;
}

// ── Handle revoke license (AJAX) ──────────────────────────────────────────────
if (isset($_POST['revoke_device'])) {
    header('Content-Type: application/json');
    $dev_id = trim($_POST['revoke_device']);
    $stmt = $mysqli->prepare("DELETE FROM tbl_ls_licenses WHERE device_id = ?");
    $stmt->bind_param('s', $dev_id);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => (bool)$ok]);
    exit;
}

// ── Handle delete user (AJAX) ─────────────────────────────────────────────────
if (isset($_POST['delete_id'])) {
    header('Content-Type: application/json');
    $del_id = (int)$_POST['delete_id'];
    mysqli_query($mysqli, "DELETE FROM tbl_users WHERE id='$del_id'");
    echo json_encode(['success' => 1]);
    exit;
}

// ── Now output HTML ───────────────────────────────────────────────────────────
$page_title = "App Users";
include("includes/header.php");
require("includes/lb_helper.php");

$tableName = "tbl_users";
$targetpage = "manage_users.php";
$limit = 20;

// ── Pre-load all licenses into an associative array ───────────────────────────
$licenses = [];
$lic_res = mysqli_query($mysqli, "SELECT device_id, plan, expires_at FROM tbl_ls_licenses");
while ($lr = mysqli_fetch_assoc($lic_res)) {
    $licenses[$lr['device_id']] = $lr;
}

// Search
$keyword = '';
$whereClause = '';
if (isset($_GET['keyword']) && !empty(trim($_GET['keyword']))) {
    $keyword = mysqli_real_escape_string($mysqli, trim($_GET['keyword']));
    $whereClause = "WHERE (username LIKE '%$keyword%' OR server_url LIKE '%$keyword%')";
}

$query = "SELECT COUNT(*) as num FROM $tableName $whereClause";
$total_pages = mysqli_fetch_array(mysqli_query($mysqli, $query));
$total_pages = $total_pages['num'];

$stages = 3;
$page = 0;
if (isset($_GET['page'])) {
    $page = mysqli_real_escape_string($mysqli, $_GET['page']);
}
$start = $page ? ($page - 1) * $limit : 0;

$sql_query = "SELECT * FROM $tableName $whereClause ORDER BY last_seen DESC LIMIT $start, $limit";
$result = mysqli_query($mysqli, $sql_query) or die(mysqli_error($mysqli));

// Handle send notification to selected users
$notify_result = '';
    if (isset($_POST['send_notification'])) {
        $fcm_tokens   = isset($_POST['player_ids']) ? $_POST['player_ids'] : [];
        $notify_title = isset($_POST['notify_title']) ? trim($_POST['notify_title']) : '';
        $notify_msg   = isset($_POST['notify_msg'])   ? trim($_POST['notify_msg'])   : '';
        $notify_url   = isset($_POST['notify_url'])   ? trim($_POST['notify_url'])   : '';

        // Load Firebase service account JSON from settings
        $fcm_key_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT fcm_server_key FROM tbl_settings WHERE id=1"));
        $fcm_service_account = $fcm_key_row ? fix_service_account_json($fcm_key_row['fcm_server_key']) : '';

        if (empty($fcm_service_account)) {
            $notify_result = '<div class="alert alert-warning mt-3">Firebase Service Account not configured. Go to <a href="settings_app.php">App Settings → Notification</a> and paste your Service Account JSON.</div>';
        } elseif (!empty($fcm_tokens) && !empty($notify_title) && !empty($notify_msg)) {
            $valid_tokens = array_values(array_filter($fcm_tokens, function($t) { return !empty(trim($t)); }));
            if (!empty($valid_tokens)) {
                // Parse service account and get OAuth2 access token for FCM V1 API
                $sa = json_decode($fcm_service_account, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $notify_result = '<div class="alert alert-danger mt-3">Service Account JSON is invalid: ' . htmlspecialchars(json_last_error_msg()) . '. Please re-paste it in <a href="settings_app.php">App Settings → Notification</a>.</div>';
                    goto end_notify;
                }
                $project_id = $sa['project_id'] ?? '';
                if (empty($sa['client_email']) || empty($sa['private_key']) || empty($project_id)) {
                    $notify_result = '<div class="alert alert-danger mt-3">Service Account JSON is missing required fields (project_id/client_email/private_key). Found keys: ' . htmlspecialchars(implode(', ', array_keys($sa ?? []))) . '</div>';
                    goto end_notify;
                }
                $access_token = null;

                if (!empty($sa['client_email']) && !empty($sa['private_key']) && !empty($project_id)) {
                    if (!function_exists('openssl_sign')) {
                        $notify_result = '<div class="alert alert-danger mt-3">PHP openssl extension is not installed on the server.</div>';
                        goto end_notify;
                    }
                    $b64 = function($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); };
                    $now = time();
                    $header  = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
                    $payload = $b64(json_encode([
                        'iss'   => $sa['client_email'],
                        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                        'aud'   => 'https://oauth2.googleapis.com/token',
                        'exp'   => $now + 3600,
                        'iat'   => $now,
                    ]));
                    $sig_input = $header . '.' . $payload;
                    $sign_ok = openssl_sign($sig_input, $signature, $sa['private_key'], 'SHA256');
                    if (!$sign_ok) {
                        $openssl_err = openssl_error_string();
                        $notify_result = '<div class="alert alert-danger mt-3">openssl_sign failed: ' . htmlspecialchars($openssl_err) . '</div>';
                        goto end_notify;
                    }
                    $jwt = $sig_input . '.' . $b64($signature);

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                        'assertion'  => $jwt,
                    ]));
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                    $token_resp   = curl_exec($ch);
                    $curl_err     = curl_error($ch);
                    $curl_http    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    $token_data   = json_decode($token_resp, true);
                    $access_token = $token_data['access_token'] ?? null;
                }

                if (!$access_token) {
                    $token_err = !empty($curl_err) 
                        ? 'cURL error: ' . htmlspecialchars($curl_err)
                        : (isset($token_data['error']) 
                            ? htmlspecialchars($token_data['error'] . ': ' . ($token_data['error_description'] ?? ''))
                            : 'HTTP ' . $curl_http . ' | Raw: ' . htmlspecialchars(substr($token_resp ?? '', 0, 500)));
                    $notify_result = '<div class="alert alert-danger mt-3">FCM token error: ' . $token_err . '</div>';
                } else {
                    $sent = 0; $failed = 0;
                    $fcm_url = 'https://fcm.googleapis.com/v1/projects/' . $project_id . '/messages:send';
                    foreach ($valid_tokens as $token) {
                        $body = json_encode([
                            'message' => [
                                'token'  => $token,
                                // data-only: ensures onMessageReceived() fires in all app states
                                'data'   => [
                                    'title' => $notify_title,
                                    'body'  => $notify_msg,
                                    'url'   => $notify_url,
                                ],
                                'android' => ['priority' => 'high'],
                            ],
                        ]);
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, $fcm_url);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Content-Type: application/json',
                            'Authorization: Bearer ' . $access_token,
                        ]);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        $response = curl_exec($ch);
                        curl_close($ch);
                        $resp_arr = json_decode($response, true);
                        if (isset($resp_arr['name'])) $sent++;
                        else $failed++;
                    }
                    $notify_result = '<div class="alert alert-success mt-3">Notification sent! Delivered: <strong>' . $sent . '</strong>, Failed: <strong>' . $failed . '</strong></div>';
                }
            } else {
                $notify_result = '<div class="alert alert-warning mt-3">No valid FCM tokens found for selected users. Ask users to update the app and log in again.</div>';
            }
        } else {
            $notify_result = '<div class="alert alert-warning mt-3">Please select users and fill in the notification title and message.</div>';
        }
        // Re-fetch after action
        end_notify:
        $result = mysqli_query($mysqli, $sql_query);
    }
?>

<!-- Start: main -->
<main id="nsofts_main">
    <div class="nsofts-container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb align-items-center">
                <li class="breadcrumb-item d-inline-flex"><a href="dashboard.php"><i class="ri-home-4-fill"></i></a></li>
                <li class="breadcrumb-item d-inline-flex active" aria-current="page"><?php echo $page_title ?></li>
            </ol>
        </nav>

        <?php if ($notify_result) echo $notify_result; ?>

        <div class="card h-100">
            <div class="card-header d-md-inline-flex align-items-center justify-content-between py-3 px-4">
                <div class="d-flex align-items-center">
                    <i class="ri-smartphone-line text-primary me-2" style="font-size:20px;"></i>
                    <span class="fw-semibold"><?= $page_title ?> (<?= $total_pages ?>)</span>
                </div>
                <div class="d-flex align-items-center gap-2 mt-2 mt-md-0">
                    <button type="button" class="btn btn-sm btn-success" id="btn_activate_any"
                            title="Activate a license for any device by its Device ID">
                        <i class="ri-shield-keyhole-line me-1"></i> Activate by Device ID
                    </button>
                    <form method="GET" action="manage_users.php" class="d-flex">
                        <input type="text" name="keyword" class="form-control form-control-sm me-2" placeholder="Search username / server..." value="<?= htmlspecialchars($keyword) ?>">
                        <button type="submit" class="btn btn-sm btn-primary">Search</button>
                        <?php if ($keyword): ?>
                            <a href="manage_users.php" class="btn btn-sm btn-outline-secondary ms-1">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="card-body p-4">
                <?php if (mysqli_num_rows($result) > 0): ?>
                    <form method="POST" action="manage_users.php<?= $keyword ? '?keyword='.urlencode($keyword) : '' ?>" id="notifyForm">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle" style="font-size:13px;">
                                <thead class="table-light">
                                    <tr>
                                        <th><input type="checkbox" id="selectAll" title="Select All"></th>
                                        <th>#</th>
                                        <th>Username</th>
                                        <th>Password</th>
                                        <th>Server URL</th>
                                        <th>Expiry Date</th>
                                        <th>Last Seen</th>
                                        <th>App Version</th>
                                        <th>Device Type</th>
                                        <th>First Installed</th>
                                        <th>Device ID</th>
                                        <th>License</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $i = $start + 1; while ($row = mysqli_fetch_assoc($result)): ?>
                                        <?php
                                            // Expiry status
                                            $exp_ts = is_numeric($row['exp_date']) ? (int)$row['exp_date'] : strtotime($row['exp_date']);
                                            $exp_label = '';
                                            $exp_class = '';
                                            if ($exp_ts > 0) {
                                                $days_left = ceil(($exp_ts - time()) / 86400);
                                                if ($days_left < 0) {
                                                    $exp_label = 'Expired';
                                                    $exp_class = 'text-danger fw-bold';
                                                } elseif ($days_left <= 7) {
                                                    $exp_label = $days_left . ' days left';
                                                    $exp_class = 'text-warning fw-bold';
                                                } else {
                                                    $exp_label = date('Y-m-d', $exp_ts);
                                                    $exp_class = 'text-success';
                                                }
                                            } else {
                                                $exp_label = $row['exp_date'] ?: '—';
                                                $exp_class = '';
                                            }
                                        ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" name="player_ids[]" class="user-checkbox"
                                                    value="<?= htmlspecialchars($row['onesignal_player_id']) ?>">
                                            </td>
                                            <td><?= $i ?></td>
                                            <td><strong><?= htmlspecialchars($row['username']) ?></strong></td>
                                            <td>
                                                <span class="password-blur" data-pw="<?= htmlspecialchars($row['password']) ?>" style="cursor:pointer;filter:blur(4px);" onclick="this.style.filter='none'; this.textContent=this.dataset.pw;" title="Click to reveal">
                                                    <?= htmlspecialchars($row['password']) ?>
                                                </span>
                                            </td>
                                            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                                <a href="<?= htmlspecialchars($row['server_url']) ?>" target="_blank" title="<?= htmlspecialchars($row['server_url']) ?>">
                                                    <?= htmlspecialchars($row['server_url']) ?>
                                                </a>
                                            </td>
                                            <td class="<?= $exp_class ?>"><?= $exp_label ?></td>
                                            <td><?= $row['last_seen'] ? htmlspecialchars($row['last_seen']) : '—' ?></td>
                                            <td><?= htmlspecialchars($row['app_version'] ?: '—') ?></td>
                                            <?php
                                                $dev = $row['device_id'] ?? '';
                                            ?>
                                            <td>
                                                <?php
                                                    $dtype = strtolower(trim($row['device_type'] ?? ''));
                                                    if ($dtype === 'tv' || $dtype === 'android_tv' || $dtype === 'androidtv') {
                                                        echo '<span class="badge bg-info text-dark"><i class="ri-tv-2-line me-1"></i>TV</span>';
                                                    } elseif ($dtype === 'tablet') {
                                                        echo '<span class="badge bg-secondary"><i class="ri-tablet-line me-1"></i>Tablet</span>';
                                                    } elseif ($dtype !== '') {
                                                        echo '<span class="badge bg-primary"><i class="ri-smartphone-line me-1"></i>Phone</span>';
                                                    } else {
                                                        echo '<span class="text-muted">—</span>';
                                                    }
                                                ?>
                                            </td>
                                            <td><?= !empty($row['first_seen']) ? htmlspecialchars($row['first_seen']) : '<span class="text-muted">—</span>' ?></td>
                                            <td>
                                                <?php if (!empty($dev)): ?>
                                                    <span title="<?= htmlspecialchars($dev) ?>" style="cursor:pointer;font-family:monospace;font-size:11px;" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($dev) ?>')" class="text-muted"><?= htmlspecialchars(substr($dev,0,10)).'…' ?></span>
                                                <?php else: ?>
                                                    <span class="text-danger small">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php
                                                $lic = isset($licenses[$dev]) ? $licenses[$dev] : null;
                                                $lic_badge = '<span class="badge bg-secondary">None</span>';
                                                $lic_active = false;
                                                if ($lic) {
                                                    if ($lic['plan'] === 'lifetime') {
                                                        $lic_badge = '<span class="badge bg-success">Lifetime</span>';
                                                        $lic_active = true;
                                                    } else {
                                                        $exp_ts = $lic['expires_at'] ? strtotime($lic['expires_at']) : 0;
                                                        if ($exp_ts > time()) {
                                                            $lic_badge = '<span class="badge bg-primary">Annual</span><br><small class="text-muted">'.date('Y-m-d', $exp_ts).'</small>';
                                                            $lic_active = true;
                                                        } else {
                                                            $lic_badge = '<span class="badge bg-danger">Expired</span>';
                                                        }
                                                    }
                                                }
                                            ?>
                                            <td><?= $lic_badge ?></td>
                                            <td>
                                                <a href="javascript:void(0)" class="btn btn-outline-success btn-sm btn_activate_user me-1"
                                                    data-device="<?= htmlspecialchars($dev) ?>"
                                                    data-username="<?= htmlspecialchars($row['username']) ?>"
                                                    title="Activate License">
                                                    <i class="ri-shield-keyhole-line"></i>
                                                </a>
                                                <?php if ($lic_active): ?>
                                                <a href="javascript:void(0)" class="btn btn-outline-warning btn-sm btn_revoke_user me-1"
                                                    data-device="<?= htmlspecialchars($dev) ?>"
                                                    title="Revoke License">
                                                    <i class="ri-shield-cross-line"></i>
                                                </a>
                                                <?php endif; ?>
                                                <a href="javascript:void(0)" class="btn btn-outline-danger btn-sm btn_delete_user" data-id="<?= $row['id'] ?>" title="Delete">
                                                    <i class="ri-delete-bin-line"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php $i++; endwhile; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php include("pagination.php"); ?>

                        <hr>
                        <div class="card p-3 mt-3" style="background:#f8f9fa;">
                            <h6 class="mb-3"><i class="ri-notification-3-line me-1"></i> Send Push Notification to Selected Users</h6>
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <input type="text" name="notify_title" class="form-control" placeholder="Notification Title" required>
                                </div>
                                <div class="col-md-4">
                                    <input type="text" name="notify_msg" class="form-control" placeholder="Notification Message" required>
                                </div>
                                <div class="col-md-3">
                                    <input type="url" name="notify_url" class="form-control" placeholder="Renew URL (optional, e.g. https://...)">
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" name="send_notification" class="btn btn-primary w-100">
                                        <i class="ri-send-plane-line me-1"></i> Send
                                    </button>
                                </div>
                            </div>
                            <small class="text-muted mt-2 d-block">Select users using the checkboxes above. Leave Renew URL empty to hide the Renew button in the popup.</small>
                        </div>
                    </form>

                <?php else: ?>
                    <div class="text-center p-5">
                        <i class="ri-smartphone-line" style="font-size:50px;color:#ccc;"></i>
                        <h5 class="mt-3 text-muted">No users registered yet</h5>
                        <p class="text-muted">Users will appear here after they log in to the app.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<!-- End: main -->

<?php include("includes/footer.php"); ?>

<!-- Activation Modal -->
<div class="modal fade" id="activateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ri-shield-keyhole-line me-2 text-success"></i>Activate License</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1">User: <strong id="activate_username_label"></strong></p>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Device ID <small class="text-muted">(Android ID from App Users table)</small></label>
                    <div class="input-group input-group-sm">
                        <input type="text" id="activate_device_input" class="form-control font-monospace" placeholder="Paste device ID here if empty…">
                        <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.readText().then(t=>document.getElementById('activate_device_input').value=t)" title="Paste from clipboard"><i class="ri-clipboard-line"></i></button>
                    </div>
                    <small class="text-muted">Copy the Device ID from the Device ID column in the table above, or get it from the user's app Profile screen.</small>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Plan</label>
                    <select id="activate_plan" class="form-select">
                        <option value="annual">Annual (1 Year)</option>
                        <option value="lifetime">Lifetime (Forever)</option>
                    </select>
                </div>
                <div class="mb-3" id="expiry_row">
                    <label class="form-label fw-semibold">Expiry Date <small class="text-muted">(leave blank = +1 year from today)</small></label>
                    <input type="date" id="activate_expiry" class="form-control" min="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="btn_confirm_activate">
                    <i class="ri-shield-check-line me-1"></i> Activate
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Select all checkbox
document.getElementById('selectAll')?.addEventListener('change', function() {
    document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = this.checked);
});

// Delete user row via AJAX
document.querySelectorAll('.btn_delete_user').forEach(btn => {
    btn.addEventListener('click', function() {
        if (!confirm('Delete this user record?')) return;
        const id = this.dataset.id;
        const row = this.closest('tr');
        fetch('manage_users.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'delete_id=' + id
        }).then(r => r.json()).then(data => {
            if (data.success) row.remove();
        });
    });
});

// Revoke license
document.querySelectorAll('.btn_revoke_user').forEach(btn => {
    btn.addEventListener('click', function() {
        const dev = this.dataset.device;
        if (!confirm('Revoke license for this user?')) return;
        const row = this.closest('tr');
        fetch('manage_users.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'revoke_device=' + encodeURIComponent(dev)
        }).then(r => r.json()).then(data => {
            if (data.success) location.reload();
        });
    });
});

// Toggle expiry date field based on plan
document.getElementById('activate_plan')?.addEventListener('change', function() {
    document.getElementById('expiry_row').style.display = this.value === 'lifetime' ? 'none' : '';
});

// Open activate modal from a user row
let currentDevice = '';
document.querySelectorAll('.btn_activate_user').forEach(btn => {
    btn.addEventListener('click', function() {
        currentDevice = this.dataset.device;
        document.getElementById('activate_username_label').textContent = this.dataset.username;
        document.getElementById('activate_device_input').value = currentDevice;
        document.getElementById('activate_plan').value = 'annual';
        document.getElementById('activate_expiry').value = '';
        document.getElementById('expiry_row').style.display = '';
        new bootstrap.Modal(document.getElementById('activateModal')).show();
    });
});

// Open activate modal standalone (no user row needed — admin enters device ID manually)
document.getElementById('btn_activate_any')?.addEventListener('click', function() {
    currentDevice = '';
    document.getElementById('activate_username_label').textContent = 'Manual Activation';
    document.getElementById('activate_device_input').value = '';
    document.getElementById('activate_plan').value = 'annual';
    document.getElementById('activate_expiry').value = '';
    document.getElementById('expiry_row').style.display = '';
    new bootstrap.Modal(document.getElementById('activateModal')).show();
});

// Confirm activation
document.getElementById('btn_confirm_activate')?.addEventListener('click', function() {
    const deviceId = document.getElementById('activate_device_input').value.trim();
    if (!deviceId) {
        alert('Device ID is required. Copy it from the Device ID column or ask the user to share their Device ID from their Profile screen.');
        return;
    }
    const plan   = document.getElementById('activate_plan').value;
    const expiry = document.getElementById('activate_expiry').value;
    const body   = 'activate_device=' + encodeURIComponent(deviceId)
                 + '&activate_plan='   + encodeURIComponent(plan)
                 + '&activate_expiry=' + encodeURIComponent(expiry);

    this.disabled = true;
    this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

    fetch('manage_users.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body
    }).then(r => r.json()).then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('activateModal')).hide();
            location.reload();
        } else {
            alert('Activation failed. Please try again.');
            this.disabled = false;
            this.innerHTML = '<i class="ri-shield-check-line me-1"></i> Activate';
        }
    });
});
</script>