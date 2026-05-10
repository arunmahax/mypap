<?php
include("includes/db_helper.php");

// ── Brute-force protection ────────────────────────────────────────────────────
// Lock out after 5 failed attempts for 15 minutes
$max_attempts  = 5;
$lockout_secs  = 15 * 60;
$now           = time();

if (!isset($_SESSION['login_attempts']))  $_SESSION['login_attempts']  = 0;
if (!isset($_SESSION['lockout_until']))   $_SESSION['lockout_until']   = 0;

if ($now < $_SESSION['lockout_until']) {
    $wait = ceil(($_SESSION['lockout_until'] - $now) / 60);
    $_SESSION['class'] = "error";
    $_SESSION['msg']   = "Too many failed attempts. Try again in {$wait} minute(s).";
    header("Location:index.php");
    exit;
}

// ── Input validation ──────────────────────────────────────────────────────────
$username = trim(filter_input(INPUT_POST, 'user_login',            FILTER_DEFAULT) ?? '');
$password = trim(filter_input(INPUT_POST, 'nsofts_password_input', FILTER_DEFAULT) ?? '');

if ($username === "") {
    $_SESSION['class'] = "error";
    $_SESSION['msg']   = "1";
    header("Location:index.php");
    exit;
}

if ($password === "") {
    $_SESSION['class'] = "error";
    $_SESSION['msg']   = "2";
    header("Location:index.php");
    exit;
}

// ── Lookup user with prepared statement (prevents SQL injection) ──────────────
$stmt = $mysqli->prepare("SELECT id, username, password, admin_type, status FROM tbl_admin WHERE username = ?");
$stmt->bind_param('s', $username);
$stmt->execute();
$result = $stmt->get_result();
$row    = $result->fetch_assoc();
$stmt->close();

if (!$row) {
    // Generic error — don't reveal whether username exists
    $_SESSION['login_attempts']++;
    if ($_SESSION['login_attempts'] >= $max_attempts) {
        $_SESSION['lockout_until']  = $now + $lockout_secs;
        $_SESSION['login_attempts'] = 0;
    }
    $_SESSION['class'] = "error";
    $_SESSION['msg']   = "4";
    header("Location:index.php");
    exit;
}

if ($row['status'] == 0) {
    $_SESSION['class'] = "error";
    $_SESSION['msg']   = "approve_admin";
    header("Location:index.php");
    exit;
}

// ── Password verification — supports both bcrypt and legacy MD5 ───────────────
$password_ok = false;
if (strlen($row['password']) === 32 && ctype_xdigit($row['password'])) {
    // Legacy MD5 — verify then upgrade to bcrypt on the fly
    if (hash_equals($row['password'], md5($password))) {
        $password_ok  = true;
        $new_hash     = password_hash($password, PASSWORD_BCRYPT);
        $upd          = $mysqli->prepare("UPDATE tbl_admin SET password = ? WHERE id = ?");
        $upd->bind_param('si', $new_hash, $row['id']);
        $upd->execute();
        $upd->close();
    }
} else {
    // Modern bcrypt
    $password_ok = password_verify($password, $row['password']);
}

if ($password_ok) {
    // Reset brute-force counters on success
    $_SESSION['login_attempts'] = 0;
    $_SESSION['lockout_until']  = 0;

    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);

    $_SESSION['id']         = $row['id'];
    $_SESSION['admin_name'] = $row['username'];
    $_SESSION['admin_type'] = $row['admin_type'];
    $_SESSION['class']      = "success";
    $_SESSION['msg']        = "17";
    header("Location:dashboard.php");
    exit;
} else {
    $_SESSION['login_attempts']++;
    if ($_SESSION['login_attempts'] >= $max_attempts) {
        $_SESSION['lockout_until']  = $now + $lockout_secs;
        $_SESSION['login_attempts'] = 0;
    }
    $_SESSION['class'] = "error";
    $_SESSION['msg']   = "4";
    header("Location:index.php");
    exit;
}
?>