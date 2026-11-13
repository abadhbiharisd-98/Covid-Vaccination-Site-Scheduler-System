<?php
ob_start();
ini_set('date.timezone', 'Asia/Manila');
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once(__DIR__ . '/initialize.php');
require_once(__DIR__ . '/classes/DBConnection.php');
require_once(__DIR__ . '/classes/SystemSettings.php');

$db = new DBConnection();
$conn = $db->conn;

function redirect($url=''){
	if(!empty($url)){
        $target = base_url . ltrim($url, '/');
        if(!headers_sent()){
            header("Location: {$target}");
            echo '<script>location.href="'. $target .'";</script>';
            exit;
        } else {
            echo '<script>location.href="'. $target .'";</script>';
            exit;
        }
    }
}

function validate_image($file){
	if(!empty($file)){
		if(is_file(base_app . $file)){
			return base_url . $file;
		}else{
			return base_url . 'dist/img/no-image-available.png';
		}
	}else{
		return base_url . 'dist/img/no-image-available.png';
	}
}

function isMobileDevice(){
    $aMobileUA = array(
        '/iphone/i' => 'iPhone', 
        '/ipod/i' => 'iPod', 
        '/ipad/i' => 'iPad', 
        '/android/i' => 'Android', 
        '/blackberry/i' => 'BlackBerry', 
        '/webos/i' => 'Mobile'
    );

    foreach($aMobileUA as $sMobileKey => $sMobileOS){
        if(preg_match($sMobileKey, $_SERVER['HTTP_USER_AGENT'] ?? '')){
            return true;
        }
    }
    return false;
}
ob_end_flush();
?>
