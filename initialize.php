<?php
$dev_data = array(
    'id' => '-1',
    'firstname' => 'Developer',
    'lastname' => '',
    'username' => 'dev_oretnom',
    'password' => '5da283a2d990e8d8512cf967df5bc0d0',
    'last_login' => '',
    'date_updated' => '',
    'date_added' => ''
);

if(!defined('base_app')) define('base_app', str_replace('\\', '/', __DIR__) . '/');
if(!defined('dev_data')) define('dev_data', $dev_data);

// Dynamic Base URL detection with BASE_URL environment variable override
if(!defined('base_url')){
    $env_base_url = getenv('BASE_URL');
    if(!empty($env_base_url)){
        define('base_url', rtrim($env_base_url, '/') . '/');
    } else {
        $proto = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
                 (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        
        $host = !empty($_SERVER['HTTP_X_FORWARDED_HOST']) ? $_SERVER['HTTP_X_FORWARDED_HOST'] : 
               (!empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost');
        
        // Strip internal container port (e.g. :10000) if leaked into HTTP_HOST behind reverse proxy
        if (strpos($host, ':10000') !== false) {
            $host = str_replace(':10000', '', $host);
        }

        $doc_root = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']) : '';
        $app_dir  = str_replace('\\', '/', __DIR__);
        $sub_dir  = '';
        if(!empty($doc_root) && strpos($app_dir, $doc_root) === 0){
            $sub_dir = trim(substr($app_dir, strlen($doc_root)), '/');
        }
        $base_path = empty($sub_dir) ? '/' : '/' . $sub_dir . '/';
        define('base_url', $proto . '://' . $host . $base_path);
    }
}

// Database configuration supporting Aiven Cloud MySQL and standard MySQL
$db_server   = getenv('DB_SERVER') ?: (getenv('DB_HOST') ?: 'localhost');
$db_port     = getenv('DB_PORT') ? (int)getenv('DB_PORT') : 3306;
$db_username = getenv('DB_USERNAME') ?: (getenv('DB_USER') ?: 'root');
$db_password = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
$db_name     = getenv('DB_NAME') ?: (getenv('DB_DATABASE') ?: 'scheduler_db');

// SSL detection: default enabled for Aiven / non-default ports, or explicitly via DB_SSL env
$db_ssl_env = getenv('DB_SSL');
if ($db_ssl_env !== false && $db_ssl_env !== '') {
    $db_ssl = filter_var($db_ssl_env, FILTER_VALIDATE_BOOLEAN) || strtolower((string)$db_ssl_env) === 'require' || $db_ssl_env === '1' || $db_ssl_env === 'true';
} else {
    $db_ssl = ($db_port !== 3306 || strpos($db_server, 'aivencloud.com') !== false || ($db_server !== 'localhost' && $db_server !== '127.0.0.1'));
}

if(!defined('DB_SERVER'))   define('DB_SERVER', $db_server);
if(!defined('DB_PORT'))     define('DB_PORT', $db_port);
if(!defined('DB_USERNAME')) define('DB_USERNAME', $db_username);
if(!defined('DB_PASSWORD')) define('DB_PASSWORD', $db_password);
if(!defined('DB_NAME'))     define('DB_NAME', $db_name);
if(!defined('DB_SSL'))      define('DB_SSL', $db_ssl);
?>
