<?php 
include("includes/db_helper.php");
include("includes/lb_helper.php"); 
include("language/api_language.php"); 

error_reporting(0);

$file_path = getBaseUrl();

/** @var mysqli $mysqli */
$mysqli->set_charset('utf8mb4');

// Ensure device_id column exists in tbl_users.
// NOTE: "ADD COLUMN IF NOT EXISTS" is MariaDB-only — MySQL 8 needs information_schema check.
$col_chk = $mysqli->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbl_users' AND COLUMN_NAME = 'device_id'");
if ($col_chk && $col_chk->fetch_row()[0] == 0) {
    $mysqli->query("ALTER TABLE tbl_users ADD COLUMN device_id VARCHAR(64) NOT NULL DEFAULT ''");
}

date_default_timezone_set("Asia/Colombo");

/** @var array $settings_details */
define("PACKAGE_NAME",$settings_details['envato_package_name']);

// For Api header
$API_NAME = 'NEMOSOFTS_APP';

// Purchase code verification removed - self-hosted deployment

$get_helper = get_api_data($_POST['data']);

// App details
if($get_helper['helper_name']=="app_details"){
    
    $jsonObj= array();
	$data_arr= array();
    
    $sql="SELECT * FROM tbl_settings WHERE id='1'";
    $result = mysqli_query($mysqli, $sql);
    while($data = mysqli_fetch_assoc($result)){
        
        // App Details
        $data_arr['app_email'] = $data['app_email'];
        $data_arr['app_author'] = $data['app_author'];
        $data_arr['app_contact'] = $data['app_contact'];
        $data_arr['app_website'] = $data['app_website'];
        $data_arr['app_description'] = $data['app_description'];
        $data_arr['app_developed_by'] = $data['app_developed_by'];
        
        // Envato
        $data_arr['envato_api_key'] = $data['envato_api_key'];
        
        // is_
        $data_arr['is_rtl'] = $data['is_rtl'];
        $data_arr['is_maintenance'] = $data['is_maintenance'];
        $data_arr['is_screenshot'] = $data['is_screenshot'];
        $data_arr['is_apk'] = $data['is_apk'];
        $data_arr['is_vpn'] = $data['is_vpn'];
        $data_arr['is_xui_dns'] = $data['is_xui_dns'];
        
        // AppUpdate
        $data_arr['app_update_status'] = $data['app_update_status'];
        $data_arr['app_new_version'] = $data['app_new_version'];
        $data_arr['app_update_desc'] = $data['app_update_desc'];
        $data_arr['app_redirect_url'] = $data['app_redirect_url'];
        
        // Custom Ads
        $data_arr['custom_ads'] = $data['custom_ads'];
        $data_arr['custom_ads_clicks'] = $data['custom_ads_clicks'];
        
        // App Themes
        $data_arr['is_theme'] = $data['is_theme'];

        // Billing Plans
        $data_arr['plan_annual_enabled']   = $data['plan_annual_enabled']   ?? 'true';
        $data_arr['plan_lifetime_enabled'] = $data['plan_lifetime_enabled'] ?? 'true';
        
        array_push($jsonObj,$data_arr);
    }
    $row['details'] = $jsonObj;
    
    mysqli_free_result($result);
	$jsonObj = array();
	$data_arr = array();
	
	$sql="SELECT * FROM tbl_xui_dns WHERE tbl_xui_dns.status='1' ORDER BY tbl_xui_dns.id DESC";
    $result = mysqli_query($mysqli, $sql);
    while ($data = mysqli_fetch_assoc($result)){
        
        $data_arr['id'] = $data['id'];
        $data_arr['dns_title'] = $data['dns_title'];
        $data_arr['dns_base'] = $data['dns_base'];
        
		array_push($jsonObj, $data_arr);
	}
	$row['xui_dns'] = $jsonObj;

    mysqli_free_result($result);
	$jsonObj = array();
	$data_arr = array();
	
	$sql="SELECT * FROM tbl_custom_ads WHERE tbl_custom_ads.status='1' AND tbl_custom_ads.ads_type ='popup' ORDER BY RAND() DESC LIMIT 1";
    $result = mysqli_query($mysqli, $sql);
    while ($data = mysqli_fetch_assoc($result)){
        
        $data_arr['ads_type'] = $data['ads_type'];
        $data_arr['ads_title'] = $data['ads_title'];
        $data_arr['ads_image'] =  $file_path.'images/'.$data['ads_image'];
        $data_arr['ads_redirect_type'] = $data['ads_redirect_type'];
        $data_arr['ads_redirect_url'] = $data['ads_redirect_url'];
        
		array_push($jsonObj, $data_arr);
	}
	$row['popup_ads'] = $jsonObj;
	
    $set[$API_NAME] = $row;
	header( 'Content-Type: application/json; charset=utf-8' );
    echo $val= str_replace('\\/', '/', json_encode($set,JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
	die();
}

else if($get_helper['helper_name']=="get_interstitial") {

	$jsonObj= array();	

    $sql="SELECT * FROM tbl_custom_ads WHERE tbl_custom_ads.status='1' AND tbl_custom_ads.ads_type ='interstitial' ORDER BY RAND() DESC LIMIT 1";
	$result = mysqli_query($mysqli,$sql) or die(mysqli_error($mysqli));
	while($data = mysqli_fetch_assoc($result)){
	    
      	$row['ads_image'] = $file_path.'images/'.$data['ads_image'];
      	$row['ads_redirect_type'] = $data['ads_redirect_type'];
      	$row['ads_redirect_url'] = $data['ads_redirect_url'];
		
		array_push($jsonObj,$row);
	}
	   
	$set[$API_NAME] = $jsonObj;
	header( 'Content-Type: application/json; charset=utf-8' );
    echo $val= str_replace('\\/', '/', json_encode($set,JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
	die();
}
else if($get_helper['helper_name']=="register_device") {

    $device_id           = isset($get_helper['device_id'])           ? cleanInput($get_helper['device_id'])           : '';
    $onesignal_player_id = isset($get_helper['onesignal_player_id']) ? cleanInput($get_helper['onesignal_player_id']) : '';
    $server_url          = isset($get_helper['server_url'])          ? cleanInput($get_helper['server_url'])          : '';
    $username            = isset($get_helper['username'])            ? cleanInput($get_helper['username'])            : '';
    $password            = isset($get_helper['password'])            ? cleanInput($get_helper['password'])            : '';
    $exp_date            = isset($get_helper['exp_date'])            ? cleanInput($get_helper['exp_date'])            : '';
    $app_version         = isset($get_helper['app_version'])         ? cleanInput($get_helper['app_version'])         : '';
    $device_type         = isset($get_helper['device_type'])         ? cleanInput($get_helper['device_type'])         : '';

    // Detect real client IP (Cloudflare + proxy aware)
    $ip_address = trim(explode(',',
        $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '')[0]);

    // Fetch country name from ip-api.com (free, no key, 3s timeout)
    function fetchCountryFromIp(string $ip): string {
        if (empty($ip) || $ip === '127.0.0.1' || substr($ip, 0, 8) === '192.168.') return '';
        $ch = curl_init("http://ip-api.com/json/{$ip}?fields=country");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($resp, true);
        return $data['country'] ?? '';
    }

    if (!empty($device_id)) {
        // Fetch existing row to compare IP and reuse country if IP unchanged
        $check = $mysqli->prepare("SELECT id, ip_address, country FROM tbl_users WHERE device_id = ? LIMIT 1");
        $check->bind_param('s', $device_id);
        $check->execute();
        $check->bind_result($existing_id, $old_ip, $old_country);
        $check->fetch();
        $exists = !empty($existing_id);
        $check->close();

        if ($exists) {
            // Only re-fetch country if IP changed
            $country = ($ip_address !== $old_ip) ? fetchCountryFromIp($ip_address) : $old_country;

            $stmt = $mysqli->prepare(
                "UPDATE tbl_users SET
                   onesignal_player_id = IF(? != '', ?, onesignal_player_id),
                   server_url          = IF(? != '', ?, server_url),
                   username            = IF(? != '', ?, username),
                   password            = IF(? != '', ?, password),
                   exp_date            = IF(? != '', ?, exp_date),
                   app_version         = IF(? != '', ?, app_version),
                   device_type         = IF(? != '', ?, device_type),
                   ip_address          = ?,
                   country             = IF(? != '', ?, country),
                   last_seen           = NOW()
                 WHERE device_id = ?"
            );
            $stmt->bind_param('sssssssssssssssssss',
                $onesignal_player_id, $onesignal_player_id,
                $server_url,          $server_url,
                $username,            $username,
                $password,            $password,
                $exp_date,            $exp_date,
                $app_version,         $app_version,
                $device_type,         $device_type,
                $ip_address,
                $country,             $country,
                $device_id
            );
        } else {
            // New device — fetch country and insert with first_seen
            $country = fetchCountryFromIp($ip_address);
            $stmt = $mysqli->prepare(
                "INSERT INTO tbl_users (device_id, onesignal_player_id, server_url, username, password, exp_date, app_version, device_type, ip_address, country, first_seen, last_seen)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
            );
            $stmt->bind_param('ssssssssss', $device_id, $onesignal_player_id, $server_url, $username, $password, $exp_date, $app_version, $device_type, $ip_address, $country);
        }
        $stmt->execute();
        $stmt->close();
        $set[$API_NAME][] = array('success' => '1', 'MSG' => 'Device registered');
    } else {
        $set[$API_NAME][] = array('success' => '-1', 'MSG' => 'Invalid device ID');
    }
    header('Content-Type: application/json; charset=utf-8');
    echo str_replace('\\/', '/', json_encode($set, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    die();
}

// Log event — app sends error/event logs
else if($get_helper['helper_name']=="log_event") {
    $device_id = isset($get_helper['device_id']) ? cleanInput($get_helper['device_id']) : '';
    $log_type  = isset($get_helper['log_type'])  ? cleanInput($get_helper['log_type'])  : 'info';
    $message   = isset($get_helper['message'])   ? cleanInput($get_helper['message'])   : '';

    if (!empty($device_id) && !empty($message)) {
        $stmt = $mysqli->prepare("INSERT INTO tbl_user_logs (device_id, log_type, message) VALUES (?, ?, ?)");
        $stmt->bind_param('sss', $device_id, $log_type, $message);
        $stmt->execute();
        $stmt->close();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([$API_NAME => [['success' => '1']]]);
    die();
}

// Heartbeat — called every 60s by the app while open, updates last_seen only
else if($get_helper['helper_name']=="heartbeat") {
    $device_id = isset($get_helper['device_id']) ? cleanInput($get_helper['device_id']) : '';
    if (!empty($device_id)) {
        $stmt = $mysqli->prepare("UPDATE tbl_users SET last_seen = NOW() WHERE device_id = ?");
        $stmt->bind_param('s', $device_id);
        $stmt->execute();
        $stmt->close();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([$API_NAME => [['success' => '1']]]);
    die();
}
