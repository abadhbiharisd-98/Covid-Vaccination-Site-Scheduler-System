<?php
if(!defined('DB_SERVER')){
    require_once(__DIR__ . "/../initialize.php");
}

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

class DBConnection{
    // PHP 8.x safe property declarations without function calls
    private $host;
    private $username;
    private $password;
    private $database;
    private $port;
    private $ssl;
    
    public $conn;
    public $connect_error = null;
    
    public function __construct(){
        if (function_exists('mysqli_report')) {
            mysqli_report(MYSQLI_REPORT_OFF);
        }

        // Initialize properties safely inside constructor (PHP 8.x compliant)
        $this->host     = defined('DB_SERVER') ? DB_SERVER : (getenv('DB_SERVER') ?: 'localhost');
        $this->username = defined('DB_USERNAME') ? DB_USERNAME : (getenv('DB_USERNAME') ?: 'root');
        $this->password = defined('DB_PASSWORD') ? DB_PASSWORD : (getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '');
        $this->database = defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: 'scheduler_db');
        $this->port     = defined('DB_PORT') ? (int)DB_PORT : (int)(getenv('DB_PORT') ?: 3306);
        
        $sslEnv = defined('DB_SSL') ? DB_SSL : getenv('DB_SSL');
        if ($sslEnv !== false && $sslEnv !== '') {
            $this->ssl = filter_var($sslEnv, FILTER_VALIDATE_BOOLEAN) || strtolower((string)$sslEnv) === 'require' || $sslEnv === '1' || $sslEnv === 'true';
        } else {
            // Auto-detect Aiven Cloud or custom port
            $this->ssl = ($this->port !== 3306 || strpos($this->host, 'aivencloud.com') !== false || ($this->host !== 'localhost' && $this->host !== '127.0.0.1'));
        }

        if (!isset($this->conn) || !$this->conn) {
            $this->conn = mysqli_init();
            if (!$this->conn) {
                $this->connect_error = "mysqli_init initialization failed";
                error_log($this->connect_error);
                return;
            }

            // Connection timeout to avoid hanging behind reverse proxies
            $this->conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15);

            $flags = 0;
            // Aiven Cloud enforces SSL (require_secure_transport=ON)
            if ($this->ssl) {
                if (defined('MYSQLI_OPT_SSL_VERIFY_SERVER_CERT')) {
                    $this->conn->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
                }
                $this->conn->ssl_set(NULL, NULL, NULL, NULL, NULL);
                $flags |= MYSQLI_CLIENT_SSL;
            }

            try {
                $connected = @$this->conn->real_connect(
                    $this->host,
                    $this->username,
                    $this->password,
                    $this->database,
                    $this->port,
                    NULL,
                    $flags
                );

                // Fallback attempt without SSL if connection failed on localhost
                if (!$connected && $this->ssl && ($this->host === 'localhost' || $this->host === '127.0.0.1')) {
                    $this->conn = mysqli_init();
                    $connected = @$this->conn->real_connect(
                        $this->host,
                        $this->username,
                        $this->password,
                        $this->database,
                        $this->port
                    );
                }

                if (!$connected) {
                    $this->connect_error = mysqli_connect_error();
                    error_log("Database Connection Failed: " . $this->connect_error);
                } else {
                    $this->conn->set_charset("utf8mb4");
                }
            } catch (Throwable $e) {
                $this->connect_error = $e->getMessage();
                error_log("Database Exception during real_connect: " . $this->connect_error);
            }
        }    
    }

    public function isConnected(){
        return ($this->conn instanceof mysqli && empty($this->connect_error) && !@$this->conn->connect_errno);
    }

    public function __destruct(){
        if ($this->conn instanceof mysqli && !@$this->conn->connect_errno) {
            @$this->conn->close();
        }
    }
}
?>
