<?php
include("includes/db_helper.php");

// ── Rate-limit config ─────────────────────────────────────────────────────────
define('RL_MAX_ATTEMPTS', 5);        // failed attempts before lockout
define('RL_LOCKOUT_SECS', 15 * 60); // 15-minute lockout
define('RL_WINDOW_SECS',  10 * 60); // reset attempt counter after 10 min of no activity

// ── Ensure rate-limit table exists ───────────────────────────────────────────
$mysqli->query("CREATE TABLE IF NOT EXISTS tbl_login_rate (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45)  NOT NULL,
    attempts     TINYINT      NOT NULL DEFAULT 0,
    locked_until DATETIME     NULL,
    last_attempt DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ip (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Get real client IP (Cloudflare → X-Forwarded-For → REMOTE_ADDR) ──────────
function get_client_ip(): string {
    // Cloudflare sets CF-Connecting-IP reliably (can't be spoofed by the client)
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
    }
    // Fallback for non-Cloudflare / local dev
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

$ip  = get_client_ip();
$now = date('Y-m-d H:i:s');

// ── Load current rate-limit record for this IP ────────────────────────────────
$rl_stmt = $mysqli->prepare(
    "SELECT attempts, locked_until, last_attempt FROM tbl_login_rate WHERE ip = ? LIMIT 1");
$rl_stmt->bind_param('s', $ip);
$rl_stmt->execute();
$rl = $rl_stmt->get_result()->fetch_assoc();
$rl_stmt->close();

// ── Check if IP is currently locked out ──────────────────────────────────────
if ($rl && $rl['locked_until'] && strtotime($rl['locked_until']) > time()) {
    $wait = ceil((strtotime($rl['locked_until']) - time()) / 60);
    $_SESSION['class'] = "error";
    $_SESSION['msg']   = "Too many failed attempts. Try again in {$wait} minute(s).";
    header("Location:index.php");
    exit;
}

// ── Reset stale attempt counter (no activity for RL_WINDOW_SECS) ─────────────
if ($rl && $rl['last_attempt']
        && (time() - strtotime($rl['last_attempt'])) > RL_WINDOW_SECS) {
    $mysqli->query("DELETE FROM tbl_login_rate WHERE ip = '" . $mysqli->real_escape_string($ip) . "'");
    $rl = null;
}

// ── Input validation ──────────────────────────────────────────────────────────
$username = trim(filter_input(INPUT_POST, 'user_login',            FILTER_DEFAULT) ?? '');
$password = trim(filter_input(INPUT_POST, 'nsofts_password_input', FILTER_DEFAULT) ?? '');

if ($username === '') {
    $_SESSION['class'] = "error";
    $_SESSION['msg']   = "1";
    header("Location:index.php");
    exit;
}
if ($password === '') {
    $_SESSION['class'] = "error";
    $_SESSION['msg']   = "2";
    header("Location:index.php");
    exit;
}

// ── Lookup user ───────────────────────────────────────────────────────────────
$stmt = $mysqli->prepare(
    "SELECT id, username, password, admin_type, status FROM tbl_admin WHERE username = ?");
$stmt->bind_param('s', $username);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── Helper: record a failed attempt for this IP ───────────────────────────────
function record_fail(mysqli $db, string $ip, string $now): void {
    $new_attempts = 1;
    $locked_until = null;

    // Read current count
    $s = $db->prepare("SELECT attempts FROM tbl_login_rate WHERE ip = ? LIMIT 1");
    $s->bind_param('s', $ip);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();

    if ($row) {
        $new_attempts = (int)$row['attempts'] + 1;
    }

    if ($new_attempts >= RL_MAX_ATTEMPTS) {
        $locked_until = date('Y-m-d H:i:s', time() + RL_LOCKOUT_SECS);
        $new_attempts = 0; // reset counter after triggering lockout
    }

    $db->query("INSERT INTO tbl_login_rate (ip, attempts, locked_until, last_attempt)
                VALUES ('{$db->real_escape_string($ip)}', {$new_attempts},
                        " . ($locked_until ? "'{$locked_until}'" : "NULL") . ", '{$now}')
                ON DUPLICATE KEY UPDATE
                    attempts     = {$new_attempts},
                    locked_until = " . ($locked_until ? "'{$locked_until}'" : "NULL") . ",
                    last_attempt = '{$now}'");
}

// ── Helper: clear rate-limit record on successful login ───────────────────────
function record_success(mysqli $db, string $ip): void {
    $s = $db->prepare("DELETE FROM tbl_login_rate WHERE ip = ?");
    $s->bind_param('s', $ip);
    $s->execute();
    $s->close();
}

// ── User not found ────────────────────────────────────────────────────────────
if (!$row) {
    record_fail($mysqli, $ip, $now);
    $_SESSION['class'] = "error";
    $_SESSION['msg']   = "4"; // generic — don't reveal if username exists
    header("Location:index.php");
    exit;
}

if ($row['status'] == 0) {
    $_SESSION['class'] = "error";
    $_SESSION['msg']   = "approve_admin";
    header("Location:index.php");
    exit;
}

// ── Password verification — bcrypt preferred, MD5 upgraded on the fly ─────────
$password_ok = false;
if (strlen($row['password']) === 32 && ctype_xdigit($row['password'])) {
    // Legacy MD5 — verify then upgrade to bcrypt
    if (hash_equals($row['password'], md5($password))) {
        $password_ok = true;
        $new_hash    = password_hash($password, PASSWORD_BCRYPT);
        $upd         = $mysqli->prepare("UPDATE tbl_admin SET password = ? WHERE id = ?");
        $upd->bind_param('si', $new_hash, $row['id']);
        $upd->execute();
        $upd->close();
    }
} else {
    $password_ok = password_verify($password, $row['password']);
}

// ── Login result ──────────────────────────────────────────────────────────────
if ($password_ok) {
    record_success($mysqli, $ip);

    session_regenerate_id(true);

    $_SESSION['id']         = $row['id'];
    $_SESSION['admin_name'] = $row['username'];
    $_SESSION['admin_type'] = $row['admin_type'];
    $_SESSION['class']      = "success";
    $_SESSION['msg']        = "17";
    header("Location:dashboard.php");
    exit;
} else {
    record_fail($mysqli, $ip, $now);
    $_SESSION['class'] = "error";
    $_SESSION['msg']   = "4";
    header("Location:index.php");
    exit;
}
?>
