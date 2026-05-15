<?php
include("includes/db_helper.php");

// Handle delete log (AJAX)
if (isset($_POST['delete_log_id'])) {
    header('Content-Type: application/json');
    $del_id = (int)$_POST['delete_log_id'];
    mysqli_query($mysqli, "DELETE FROM tbl_user_logs WHERE id='$del_id'");
    echo json_encode(['success' => 1]);
    exit;
}

// Handle clear all logs (AJAX)
if (isset($_POST['clear_all_logs'])) {
    header('Content-Type: application/json');
    $device_filter = isset($_POST['device_id']) ? trim($_POST['device_id']) : '';
    if (!empty($device_filter)) {
        $stmt = $mysqli->prepare("DELETE FROM tbl_user_logs WHERE device_id = ?");
        $stmt->bind_param('s', $device_filter);
        $stmt->execute();
        $stmt->close();
    } else {
        mysqli_query($mysqli, "DELETE FROM tbl_user_logs");
    }
    echo json_encode(['success' => 1]);
    exit;
}

$page_title = "App Logs";
include("includes/header.php");

// Filters
$filter_device = isset($_GET['device_id']) ? trim($_GET['device_id']) : '';
$filter_type   = isset($_GET['log_type'])   ? trim($_GET['log_type'])   : '';
$filter_date   = isset($_GET['date'])        ? trim($_GET['date'])        : '';

// Pagination
$limit      = 50;
$page       = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset     = ($page - 1) * $limit;

$where_parts = [];
$params      = [];
$types       = '';

if (!empty($filter_device)) {
    $where_parts[] = 'device_id = ?';
    $params[]      = $filter_device;
    $types        .= 's';
}
if (!empty($filter_type)) {
    $where_parts[] = 'log_type = ?';
    $params[]      = $filter_type;
    $types        .= 's';
}
if (!empty($filter_date)) {
    $where_parts[] = 'DATE(created_at) = ?';
    $params[]      = $filter_date;
    $types        .= 's';
}

$where_sql = !empty($where_parts) ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// Count total rows
$count_sql = "SELECT COUNT(*) FROM tbl_user_logs $where_sql";
if (!empty($params)) {
    $stmt = $mysqli->prepare($count_sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->bind_result($total_rows);
    $stmt->fetch();
    $stmt->close();
} else {
    $total_rows = (int)$mysqli->query($count_sql)->fetch_row()[0];
}

$total_pages = max(1, ceil($total_rows / $limit));

// Fetch rows
$data_sql = "SELECT id, device_id, log_type, message, created_at FROM tbl_user_logs $where_sql ORDER BY id DESC LIMIT ? OFFSET ?";
$page_params = $params;
$page_types  = $types . 'ii';
$page_params[] = $limit;
$page_params[] = $offset;

$stmt = $mysqli->prepare($data_sql);
$stmt->bind_param($page_types, ...$page_params);
$stmt->execute();
$result = $stmt->get_result();

// Build query string for pagination links (preserve filters)
function buildPageUrl($p, $fd, $ft, $fdate) {
    $q = ['page' => $p];
    if (!empty($fd))    $q['device_id'] = $fd;
    if (!empty($ft))    $q['log_type']  = $ft;
    if (!empty($fdate)) $q['date']      = $fdate;
    return 'manage_logs.php?' . http_build_query($q);
}
?>

<!-- Start: main -->
<main id="nsofts_main">
    <div class="nsofts-container">

        <!-- Breadcrumb -->
        <div class="nsofts-breadcrumb">
            <h2 class="nsofts-breadcrumb__title">App Logs</h2>
            <ul class="nsofts-breadcrumb__list">
                <li><a href="dashboard.php">Dashboard</a></li>
                <li>App Logs</li>
            </ul>
        </div>

        <!-- Filters -->
        <div class="nsofts-card mb-3">
            <div class="nsofts-card__body">
                <form method="GET" action="manage_logs.php" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Device ID</label>
                        <input type="text" name="device_id" class="form-control form-control-sm"
                               placeholder="Android ID..." value="<?php echo htmlspecialchars($filter_device); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label mb-1">Log Type</label>
                        <select name="log_type" class="form-select form-select-sm">
                            <option value="">All types</option>
                            <?php
                            $log_types = ['login_error', 'playback_error', 'network_error', 'license_error', 'app_error'];
                            foreach ($log_types as $lt) {
                                $sel = ($filter_type === $lt) ? 'selected' : '';
                                echo "<option value=\"$lt\" $sel>$lt</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label mb-1">Date</label>
                        <input type="date" name="date" class="form-control form-control-sm"
                               value="<?php echo htmlspecialchars($filter_date); ?>">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                        <a href="manage_logs.php" class="btn btn-secondary btn-sm ms-1">Reset</a>
                    </div>
                    <div class="col-auto ms-auto">
                        <button type="button" class="btn btn-danger btn-sm" id="btnClearLogs">
                            <?php echo !empty($filter_device) ? 'Clear Device Logs' : 'Clear All Logs'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Table -->
        <div class="nsofts-card">
            <div class="nsofts-card__body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="text-muted" style="font-size:13px;">
                        <?php echo number_format($total_rows); ?> log<?php echo $total_rows != 1 ? 's' : ''; ?> found
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle" style="font-size:13px;">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Device ID</th>
                                <th>Type</th>
                                <th>Message</th>
                                <th>Date / Time</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo (int)$row['id']; ?></td>
                                <td>
                                    <code style="font-size:11px;"><?php echo htmlspecialchars(substr($row['device_id'], 0, 16)); ?>…</code>
                                    <a href="manage_logs.php?device_id=<?php echo urlencode($row['device_id']); ?>"
                                       title="Filter by this device" class="ms-1 text-muted" style="font-size:11px;">
                                        <i class="ri-filter-line"></i>
                                    </a>
                                    <a href="manage_users.php" title="View user" class="ms-1 text-muted" style="font-size:11px;">
                                        <i class="ri-user-line"></i>
                                    </a>
                                </td>
                                <td>
                                    <?php
                                    $badge = [
                                        'login_error'    => 'danger',
                                        'playback_error' => 'warning',
                                        'network_error'  => 'info',
                                        'license_error'  => 'dark',
                                        'app_error'      => 'secondary',
                                    ];
                                    $color = $badge[$row['log_type']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $color; ?>"><?php echo htmlspecialchars($row['log_type']); ?></span>
                                </td>
                                <td style="max-width:380px; word-break:break-word;">
                                    <?php echo htmlspecialchars($row['message']); ?>
                                </td>
                                <td style="white-space:nowrap;"><?php echo htmlspecialchars($row['created_at']); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-outline-danger btn-delete-log"
                                            data-id="<?php echo (int)$row['id']; ?>">
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No logs found.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <nav>
                    <ul class="pagination pagination-sm justify-content-center mt-3">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?php echo buildPageUrl($page - 1, $filter_device, $filter_type, $filter_date); ?>">«</a>
                            </li>
                        <?php endif; ?>
                        <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                            <li class="page-item <?php echo ($p == $page) ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo buildPageUrl($p, $filter_device, $filter_type, $filter_date); ?>"><?php echo $p; ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?php echo buildPageUrl($page + 1, $filter_device, $filter_type, $filter_date); ?>">»</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <?php endif; ?>

            </div>
        </div>

    </div>
</main>
<!-- End: main -->

<?php include("includes/footer.php"); ?>

<script>
// Delete single log
document.querySelectorAll('.btn-delete-log').forEach(function(btn) {
    btn.addEventListener('click', function() {
        if (!confirm('Delete this log entry?')) return;
        var id = this.dataset.id;
        fetch('manage_logs.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'delete_log_id=' + id
        }).then(function() { location.reload(); });
    });
});

// Clear all / device logs
document.getElementById('btnClearLogs').addEventListener('click', function() {
    var deviceId = '<?php echo addslashes($filter_device); ?>';
    var msg = deviceId ? 'Clear all logs for this device?' : 'Clear ALL logs? This cannot be undone.';
    if (!confirm(msg)) return;
    fetch('manage_logs.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'clear_all_logs=1&device_id=' + encodeURIComponent(deviceId)
    }).then(function() { location.reload(); });
});
</script>
