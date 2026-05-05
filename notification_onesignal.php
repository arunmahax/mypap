<?php 
    $page_title='Send Notification';
    include("includes/header.php");
    require("includes/lb_helper.php");
    require("language/language.php");
    require_once("thumbnail_images.class.php");

    $notify_result = '';

    if(isset($_POST['submit'])){
        $notify_title = trim($_POST['notification_title']);
        $notify_msg   = trim($_POST['notification_msg']);

        // Load service account JSON from settings
        $fcm_key_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT fcm_server_key FROM tbl_settings WHERE id=1"));
        $fcm_service_account = $fcm_key_row ? trim($fcm_key_row['fcm_server_key']) : '';

        if (empty($fcm_service_account)) {
            $notify_result = '<div class="alert alert-warning mt-3">Firebase Service Account not configured. Go to <a href="settings_app.php">App Settings &rarr; Notification</a> and paste your Service Account JSON.</div>';
        } else {
            // Get all FCM tokens from tbl_users
            $tokens_result = mysqli_query($mysqli, "SELECT onesignal_player_id FROM tbl_users WHERE onesignal_player_id != '' AND onesignal_player_id IS NOT NULL");
            $valid_tokens = [];
            while ($row = mysqli_fetch_assoc($tokens_result)) {
                $t = trim($row['onesignal_player_id']);
                if (!empty($t)) $valid_tokens[] = $t;
            }

            if (empty($valid_tokens)) {
                $notify_result = '<div class="alert alert-warning mt-3">No FCM tokens found. Users need to log in with the updated app first.</div>';
            } else {
                // Build OAuth2 JWT and get access token
                $sa = json_decode($fcm_service_account, true);
                $project_id  = $sa['project_id'] ?? '';
                $access_token = null;

                if (!empty($sa['client_email']) && !empty($sa['private_key']) && !empty($project_id)) {
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
                    openssl_sign($sig_input, $signature, $sa['private_key'], 'SHA256');
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
                    $token_resp = curl_exec($ch);
                    curl_close($ch);
                    $token_data = json_decode($token_resp, true);
                    $access_token = $token_data['access_token'] ?? null;
                }

                if (!$access_token) {
                    $notify_result = '<div class="alert alert-danger mt-3">Failed to get FCM access token. Check your Service Account JSON in <a href="settings_app.php">App Settings &rarr; Notification</a>.</div>';
                } else {
                    $sent = 0; $failed = 0;
                    $fcm_url = 'https://fcm.googleapis.com/v1/projects/' . $project_id . '/messages:send';
                    foreach ($valid_tokens as $token) {
                        $body = json_encode([
                            'message' => [
                                'token'        => $token,
                                'notification' => [
                                    'title' => $notify_title,
                                    'body'  => $notify_msg,
                                ],
                                'android' => [
                                    'priority'     => 'high',
                                    'notification' => ['sound' => 'default'],
                                ],
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
                        if (isset($resp_arr['name'])) $sent++; else $failed++;
                    }
                    $notify_result = '<div class="alert alert-success mt-3">Notification sent to <strong>' . count($valid_tokens) . '</strong> users! Delivered: <strong>' . $sent . '</strong>, Failed: <strong>' . $failed . '</strong></div>';
                }
            }
        }
    }

?>

<!-- Start: main -->
<main id="nsofts_main">
    <div class="nsofts-container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb align-items-center">
                <li class="breadcrumb-item d-inline-flex"><a href="dashboard.php"><i class="ri-home-4-fill"></i></a></li>
                <li class="breadcrumb-item d-inline-flex active" aria-current="page"><?php echo (isset($page_title)) ? $page_title : "" ?></li>
            </ol>
        </nav>
            
        <div class="row g-4">
            <div class="col-12">
                <div class="card h-100">
                    <div class="card-body p-4">
                        <h5 class="mb-3"><?=$page_title ?></h5>
                        <?php echo $notify_result; ?>
                        <form action="" name="addeditone" method="POST" enctype="multipart/form-data">
                        
                            <div class="mb-3 row">
                                <label class="col-sm-2 col-form-label">Notification Title</label>
                                <div class="col-sm-10">
                                    <input type="text" name="notification_title" id="notification_title" value=""  class="form-control" required>
                                </div>
                            </div>
                            
                            <div class="mb-3 row">
                                <label class="col-sm-2 col-form-label">Notification Message</label>
                                <div class="col-sm-10">
                                    <textarea name="notification_msg" id="notification_msg" class="form-control" required></textarea>
                                </div>
                            </div>
                            
                            <div class="mb-3 row">
                                <label class="col-sm-2 col-form-label">Select Image</label>
                                <div class="col-sm-10">
                                    <input class="form-control" type="file"  name="big_picture"  accept=".png, .jpg, .JPG .PNG" onchange="fileValidation()" id="fileupload">
                                </div>
                            </div>
                            
                            <div class="mb-3 row">
                                <label class="col-sm-2 col-form-label">&nbsp;</label>
                                <div class="col-sm-10">
                                    <button type="submit" name="submit" class="btn btn-primary" style="min-width: 120px;">Send</button>
                                </div>
                            </div>
                            
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<!-- End: main -->
    
<?php include("includes/footer.php");?> 