<?php $page_title="App Users";
    include("includes/header.php");
    require("includes/lb_helper.php");

    $tableName = "tbl_users";
    $targetpage = "manage_users.php";
    $limit = 20;

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

    // Handle AJAX delete
    if (isset($_POST['delete_id'])) {
        $del_id = (int)$_POST['delete_id'];
        mysqli_query($mysqli, "DELETE FROM tbl_users WHERE id='$del_id'");
        echo json_encode(['success' => 1]);
        exit();
    }

    // Handle send notification to selected users
    $notify_result = '';
    if (isset($_POST['send_notification'])) {
        $player_ids = isset($_POST['player_ids']) ? $_POST['player_ids'] : [];
        $notify_title = isset($_POST['notify_title']) ? trim($_POST['notify_title']) : '';
        $notify_msg   = isset($_POST['notify_msg'])   ? trim($_POST['notify_msg'])   : '';

        if (!empty($player_ids) && !empty($notify_title) && !empty($notify_msg)) {
            // Filter valid player IDs
            $valid_ids = array_filter($player_ids, function($id) { return !empty(trim($id)); });
            if (!empty($valid_ids)) {
                $fields = array(
                    'app_id'             => ONESIGNAL_APP_ID,
                    'include_player_ids' => array_values($valid_ids),
                    'headings'           => array('en' => $notify_title),
                    'contents'           => array('en' => $notify_msg),
                );
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://onesignal.com/api/v1/notifications');
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json; charset=utf-8',
                    'Authorization: Basic ' . ONESIGNAL_REST_KEY
                ));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $response = curl_exec($ch);
                curl_close($ch);
                $resp_arr = json_decode($response, true);
                if (isset($resp_arr['id'])) {
                    $notify_result = '<div class="alert alert-success mt-3">Notification sent successfully to ' . count($valid_ids) . ' device(s).</div>';
                } else {
                    $notify_result = '<div class="alert alert-danger mt-3">Failed: ' . htmlspecialchars($response) . '</div>';
                }
            } else {
                $notify_result = '<div class="alert alert-warning mt-3">No valid OneSignal Player IDs found for selected users.</div>';
            }
        } else {
            $notify_result = '<div class="alert alert-warning mt-3">Please select users and fill in the notification title and message.</div>';
        }
        // Re-fetch after action
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
                <form method="GET" action="manage_users.php" class="d-flex mt-2 mt-md-0">
                    <input type="text" name="keyword" class="form-control form-control-sm me-2" placeholder="Search username / server..." value="<?= htmlspecialchars($keyword) ?>">
                    <button type="submit" class="btn btn-sm btn-primary">Search</button>
                    <?php if ($keyword): ?>
                        <a href="manage_users.php" class="btn btn-sm btn-outline-secondary ms-1">Clear</a>
                    <?php endif; ?>
                </form>
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
                                            <td>
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
                                <div class="col-md-4">
                                    <input type="text" name="notify_title" class="form-control" placeholder="Notification Title" required>
                                </div>
                                <div class="col-md-6">
                                    <input type="text" name="notify_msg" class="form-control" placeholder="Notification Message" required>
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" name="send_notification" class="btn btn-primary w-100">
                                        <i class="ri-send-plane-line me-1"></i> Send
                                    </button>
                                </div>
                            </div>
                            <small class="text-muted mt-2 d-block">Select users using the checkboxes above. Only users with a OneSignal Player ID will receive the notification.</small>
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
</script>

<?php include("includes/footer.php"); ?>
