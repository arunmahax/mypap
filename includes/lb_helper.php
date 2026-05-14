<?php if(count(get_included_files()) == 1) exit("No direct script access allowed");
/**
 * Copyright 2017 nemosofts.
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may
 * not use this file except in compliance with the License. You may obtain
 * a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
 * License for the specific language governing permissions and limitations
 * under the License.
 */

# Fix control characters in service account JSON (actual newlines inside string values)
function fix_service_account_json(string $raw): string {
    $raw = trim($raw);
    if (json_decode($raw) !== null) return $raw;
    // Replace actual newline/CR characters inside JSON string values with \n escape
    $fixed = preg_replace_callback('/"((?:[^"\\\\]|\\\\.)*)"/s', function($m) {
        return '"' . str_replace(["\r\n", "\r", "\n"], '\\n', $m[1]) . '"';
    }, $raw);
    return $fixed ?? $raw;
}

# Insert Data
function Insert(string $table, array $data){
    global $mysqli;

    //print_r($data);

    $fields = array_keys($data);
    $values = array_map(array($mysqli, 'real_escape_string'), array_values($data));

    //echo "INSERT INTO $table(".implode(",",$fields).") VALUES ('".implode("','", $values )."');";
    //exit;  
    mysqli_query($mysqli, "INSERT INTO $table(" . implode(",", $fields) . ") VALUES ('" . implode("','", $values) . "');") or die(mysqli_error($mysqli));
}

// Update Data, Where clause is left optional
function Update(string $table_name, array $form_data, string $where_clause = ''){
    global $mysqli;
    // check for optional where clause
    $whereSQL = '';
    if (!empty($where_clause)) {
        // check to see if the 'where' keyword exists
        if (substr(strtoupper(trim($where_clause)), 0, 5) != 'WHERE') {
            // not found, add key word
            $whereSQL = " WHERE " . $where_clause;
        } else {
            $whereSQL = " " . trim($where_clause);
        }
    }
    // start the actual SQL statement
    $sql = "UPDATE " . $table_name . " SET ";

    // loop and build the column /
    $sets = array();
    foreach ($form_data as $column => $value) {
        $sets[] = "`" . $column . "` = '" . $value . "'";
    }
    $sql .= implode(', ', $sets);

    // append the where statement
    $sql .= $whereSQL;

    // run and return the query result
    return mysqli_query($mysqli, $sql);
}

//Delete Data, the where clause is left optional incase the user wants to delete every row!
function Delete(string $table_name, string $where_clause = ''){
    global $mysqli;
    // check for optional where clause
    $whereSQL = '';
    if (!empty($where_clause)) {
        // check to see if the 'where' keyword exists
        if (substr(strtoupper(trim($where_clause)), 0, 5) != 'WHERE') {
            // not found, add keyword
            $whereSQL = " WHERE " . $where_clause;
        } else {
            $whereSQL = " " . trim($where_clause);
        }
    }
    // build the query
    $sql = "DELETE FROM " . $table_name . $whereSQL;

    // run and return the query result resource
    return mysqli_query($mysqli, $sql);
}

function compress_image(string $source_url, string $destination_url, int $quality){

    $info = getimagesize($source_url);

    if ($info['mime'] == 'image/jpeg'){
        $image = imagecreatefromjpeg($source_url);
    } else if ($info['mime'] == 'image/gif'){
        $image = imagecreatefromgif($source_url);
    } else if ($info['mime'] == 'image/png'){
        $image = imagecreatefrompng($source_url);
    } else {
        $image = imagecreatefromjpeg($source_url);
    }
    
    imagejpeg($image, $destination_url, $quality);
    return $destination_url;
}

function compress_image_app(string $source_url, string $destination_url, int $quality){

    $info = getimagesize($source_url);
    $exif = exif_read_data($source_url);
    
    if ($info['mime'] == 'image/jpeg'){
        $imageResource = imagecreatefromjpeg($source_url);
    } else if ($info['mime'] == 'image/gif'){
        $imageResource = imagecreatefromgif($source_url);
    } else if ($info['mime'] == 'image/png'){
        $imageResource = imagecreatefrompng($source_url);
    } else {
        $imageResource = imagecreatefromjpeg($source_url);
    }

    //Image Orientation
    if (!empty($exif['Orientation'])) {
    
        if($exif['Orientation'] == 3){
            $image = imagerotate($imageResource, 180, 0);
        } else if($exif['Orientation'] == 6){
            $image = imagerotate($imageResource, -90, 0);
        } else if($exif['Orientation'] == 8){
            $image = imagerotate($imageResource, 90, 0);
        } else {
            $image = $imageResource;
        }
        
    } else {
        $image = $imageResource;
    }
    imagejpeg($image, $destination_url, $quality);
    return $destination_url;
}

function get_api_data(string $data_info): array {
    
    $API_NAME = 'NEMOSOFTS_APP';

    $data_json = $data_info;
    $data_arr = json_decode(urldecode(base64_decode($data_json)), true);
    if($data_arr['application_id']==PACKAGE_NAME){
        if (!file_exists('api.php')){
            $set[$API_NAME][] = array('success' => '-1', "MSG" => 'API File Missing!');   
            header( 'Content-Type: application/json; charset=utf-8' );
            echo $val= str_replace('\/', '/', json_encode($set,JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            exit(); 
        }
    } else{
        //$data['data'] = array('success' => '-1', "MSG" => "Invalid.");
        $set[$API_NAME][] = array('success' => '-1', "MSG" => 'Invalid Package Name');   
        header( 'Content-Type: application/json; charset=utf-8' );
        echo $val= str_replace('\\/', '/', json_encode($set,JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        exit();
    }
    return $data_arr;
}

function user_info(string $user_id, string $field_name): string {
    global $mysqli;
    $qry_user="SELECT * FROM tbl_users WHERE id='".$user_id."'";
    $query1=mysqli_query($mysqli,$qry_user);
    $row_user = mysqli_fetch_array($query1);
    $num_rows1 = mysqli_num_rows($query1);
    if ($num_rows1 > 0){     
        // return the result
        return $row_user[$field_name];
    }else{
      return "";
    }
}

function cleanInput(string $inputText): string {
    return addslashes(trim($inputText));
}

function thousandsNumberFormat(int $num): string {
    if ($num > 1000) {
        $x = round($num);
        $x_number_format = number_format($x);
        $x_array = explode(',', $x_number_format);
        $x_parts = array(' K', ' M', ' B', ' T');
        $x_count_parts = count($x_array) - 1;
        $x_display = $x;
        $x_display = $x_array[0] . ((int) $x_array[1][0] !== 0 ? '.' . $x_array[1][0] : '');
        $x_display .= $x_parts[$x_count_parts - 1];
        return $x_display;
    }
    return $num;
}

function calculate_time_span(int $post_time, bool $flag = false): string {
    if ($post_time != '') {
        $seconds = time() - $post_time;
        $year = floor($seconds / 31556926);
        $months = floor($seconds / 2629743);
        $week = floor($seconds / 604800);
        $day = floor($seconds / 86400);
        $hours = floor($seconds / 3600);
        $mins = floor(($seconds - ($hours * 3600)) / 60);
        $secs = floor($seconds % 60);

        if ($seconds < 60) $time = $secs . " sec ago";
        else if ($seconds < 3600) $time = ($mins == 1) ? $mins . " min ago" : $mins . " mins ago";
        else if ($seconds < 86400) $time = ($hours == 1) ? $hours . " hour ago" : $hours . " hours ago";
        else if ($seconds < 604800) $time = ($day == 1) ? $day . " day ago" : $day . " days ago";
        else if ($seconds < 2629743) $time = ($week == 1) ? $week . " week ago" : $week . " weeks ago";
        else if ($seconds < 31556926) $time = ($months == 1) ? $months . " month ago" : $months . " months ago";
        else $time = ($year == 1) ? $year . " year ago" : $year . " years ago";

        if ($flag) {
            if ($day > 1) {
                $time = date('d-m-Y', $post_time);
            }
        }
        return $time;
    } else {
        return 'not available';
    }
}

function calculate_end_days(string $days, int $endDay): string {
    $date_plus_days = new DateTime($days);
    $date_plus_days->modify("+$endDay days");
    return $date_plus_days->format("Y-m-d");
}

function LastID($table_name){   
    global $mysqli;
    return mysqli_insert_id($mysqli);
}

function call_api(array $data): string {
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_POST, 1);
    if($data){
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
    }
	curl_setopt($curl, CURLOPT_URL, "https://api.nemosofts.com/v5/api_helper.php");
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30); 
	curl_setopt($curl, CURLOPT_TIMEOUT, 30);
	$result = curl_exec($curl);
	curl_close($curl);
	return $result;
}

function get_latest_version(string $item_id): array {
    return [
        'product_name' => 'Veoplayer',
        'version'      => '1.0',
        'status'       => true
    ];
}

function activate_license(string $license, string $client, string $item_id, bool $create_lic = true): array {
	$response = ['status' => true, 'message' => 'Valid license', 'lic_response' => 'lic_response'];
	$current_path = realpath(__DIR__);
	$license_file = $current_path.'/.lic';
	if(!empty($create_lic)){
		if($response['status']){
			$licfile = trim($response['lic_response']);
			file_put_contents($license_file, $licfile, LOCK_EX);
		} else {
			@chmod($license_file, 0777);
			if(is_writeable($license_file)){
				unlink($license_file);
			}
		}
	}
	return $response;
}

function deactivate_license(string $deactivate_password): array {
    $current_path = realpath(__DIR__);
	$license_file = $current_path.'/.lic';
	$data_array =  array(
	    'method_name' => "deactivate_license",
	    "deactivate_password" => $deactivate_password
	);
	$get_data = call_api($data_array);
	$response = json_decode($get_data, true);
	if($response['status']){
		@chmod($license_file, 0777);
		if(is_writeable($license_file)){
			unlink($license_file);
		}
	}
	return $response;
}

function verify_license_android(string $license, string $api_key, string $package_name): array {
    return ['status' => true, 'message' => 'Valid license - CodeGood.Net - CoderMarket.Net'];
    $get_base_url = getBaseUrl();
    $data_array =  array(
        'method_name' => 'android_app',
        'envato_purchase_code' => $license,
        'api_key' => $api_key,
        'buyer_admin_url' => $get_base_url,
        'package_name' => $package_name
    );
    $get_data = call_api($data_array);
    $response = json_decode($get_data, true);
    return $response;
}

function verify_envato_purchase_code(string $license, string $item_id): array {
    return ['status' => true, 'message' => 'Valid license - CodeGood.Net - CoderMarket.Net'];
    $data_array =  array(
        'method_name' => "envato_purchase_code",
        "item_id"  => $item_id,
		"license_code" => $license,
	);
    $get_data = call_api($data_array);
    $response = json_decode($get_data, true);
    return $response;
}

function check_update(string $item_id): array {
	$data_array =  array(
	    "item_id"  => $item_id
	);
	$get_data = call_api($data_array);
	$response = json_decode($get_data, true);
	return $response;
}

function get_ip_from_third_party(){
	$curl = curl_init ();
	curl_setopt($curl, CURLOPT_URL, "http://ipecho.net/plain");
	curl_setopt($curl, CURLOPT_HEADER, 0);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10); 
	curl_setopt($curl, CURLOPT_TIMEOUT, 10);
	$response = curl_exec($curl);
	curl_close($curl);
	return $response;
}

function generateStrong(int $length = 4, string $available_sets = 'ld'): string {
	$sets = array();
	if(strpos($available_sets, 'l') !== false)
		$sets[] = 'abcdefghijklmnopqrstuvwxyz';

	if(strpos($available_sets, 'd') !== false)
		$sets[] = '23456789';

	$all = '';
	$password = '';
	foreach($sets as $set){
		$password .= $set[array_rand(str_split($set))];
		$all .= $set;
	}

	$all = str_split($all);
	for($i = 0; $i < $length - count($sets); $i++)
		$password .= $all[array_rand($all)];

	$password = str_shuffle($password);
    return $password;
}

function generateStrongPassword(){
	$key = generateStrong(8)."-".generateStrong(4,"d")."-".generateStrong()."-".generateStrong()."-".generateStrong(12);
	return $key;
}

function getBaseUrl(bool $array = false): string|array {

    $protocol = "http";
    $host = "";
    $port = "";
    $dir = "";

    // Get protocol
    if (array_key_exists("HTTPS", $_SERVER) && $_SERVER["HTTPS"] != "") {
        if ($_SERVER["HTTPS"] == "on") {
            $protocol = "https";
        } else {
            $protocol = "http";
        }
    } elseif (array_key_exists("REQUEST_SCHEME", $_SERVER) && $_SERVER["REQUEST_SCHEME"] != "") {
        $protocol = $_SERVER["REQUEST_SCHEME"];
    }

    // Get host
    if (array_key_exists("HTTP_X_FORWARDED_HOST", $_SERVER) && $_SERVER["HTTP_X_FORWARDED_HOST"] != "") {
        $forwarded_hosts = explode(',', $_SERVER["HTTP_X_FORWARDED_HOST"]);
        $host = trim(end($forwarded_hosts));
    } elseif (array_key_exists("SERVER_NAME", $_SERVER) && $_SERVER["SERVER_NAME"] != "") {
        $host = $_SERVER["SERVER_NAME"];
    } elseif (array_key_exists("HTTP_HOST", $_SERVER) && $_SERVER["HTTP_HOST"] != "") {
        $host = $_SERVER["HTTP_HOST"];
    } elseif (array_key_exists("SERVER_ADDR", $_SERVER) && $_SERVER["SERVER_ADDR"] != "") {
        $host = $_SERVER["SERVER_ADDR"];
    }
    //elseif(array_key_exists("SSL_TLS_SNI", $_SERVER) && $_SERVER["SSL_TLS_SNI"] != "") { $host = $_SERVER["SSL_TLS_SNI"]; }

    // Get port
    if (array_key_exists("SERVER_PORT", $_SERVER) && $_SERVER["SERVER_PORT"] != "") {
        $port = $_SERVER["SERVER_PORT"];
    } elseif (stripos($host, ":") !== false) {
        $port = substr($host, (stripos($host, ":") + 1));
    }
    // Remove port from host
    $host = preg_replace("/:\d+$/", "", $host);

    // Get dir
    if (array_key_exists("SCRIPT_NAME", $_SERVER) && $_SERVER["SCRIPT_NAME"] != "") {
        $dir = $_SERVER["SCRIPT_NAME"];
    } elseif (array_key_exists("PHP_SELF", $_SERVER) && $_SERVER["PHP_SELF"] != "") {
        $dir = $_SERVER["PHP_SELF"];
    } elseif (array_key_exists("REQUEST_URI", $_SERVER) && $_SERVER["REQUEST_URI"] != "") {
        $dir = $_SERVER["REQUEST_URI"];
    }
    // Shorten to main dir
    if (stripos($dir, "/") !== false) {
        $dir = substr($dir, 0, (strripos($dir, "/") + 1));
    }

    // Create return value
    if (!$array) {
        if ($port == "80" || $port == "443" || $port == "") {
            $port = "";
        } else {
            $port = ":" . $port;
        }
        return htmlspecialchars($protocol . "://" . $host . $port . $dir, ENT_QUOTES);
    } else {
        return ["protocol" => $protocol, "host" => $host, "port" => $port, "dir" => $dir];
    }
}
?>