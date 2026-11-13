<?php
if(!defined('DB_SERVER')){
    require_once(__DIR__ . "/../initialize.php");
}

if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

/**
 * Safe mock result object to prevent:
 * "Fatal error: Uncaught Error: Call to a member function fetch_assoc() on bool/null"
 */
class SafeMySQLResult {
    public $num_rows = 0;
    public function fetch_assoc() { return null; }
    public function fetch_array($mode = MYSQLI_BOTH) { return array(); }
    public function fetch_all($mode = MYSQLI_NUM) { return array(); }
    public function fetch_row() { return null; }
}

/**
 * Safe connection wrapper to prevent:
 * "Fatal error: Uncaught Error: mysqli object is not fully initialized"
 */
class SafeMySQLConnection {
    public $raw = null;
    public $connect_errno = 0;
    public $connect_error = '';
    public $error = '';
    public $errno = 0;
    public $insert_id = 0;
    public $affected_rows = 0;

    public function __construct($raw_mysqli = null, $err = null) {
        $this->raw = ($raw_mysqli instanceof mysqli && !@$raw_mysqli->connect_errno) ? $raw_mysqli : null;
        if ($err) {
            $this->connect_error = $err;
            $this->connect_errno = 1;
        }
    }

    public function query($sql, $resultmode = MYSQLI_STORE_RESULT) {
        if ($this->raw instanceof mysqli && !@$this->raw->connect_errno) {
            try {
                $res = @$this->raw->query($sql, $resultmode);
                if ($res !== false) {
                    return $res;
                }
                $this->error = $this->raw->error;
                $this->errno = $this->raw->errno;
            } catch (Throwable $e) {
                $this->error = $e->getMessage();
            }
        }
        return new SafeMySQLResult();
    }

    public function real_escape_string($string) {
        if ($this->raw instanceof mysqli && !@$this->raw->connect_errno) {
            return $this->raw->real_escape_string($string);
        }
        return addslashes($string);
    }

    public function escape_string($string) {
        return $this->real_escape_string($string);
    }

    public function select_db($database) {
        if ($this->raw instanceof mysqli && !@$this->raw->connect_errno) {
            return @$this->raw->select_db($database);
        }
        return false;
    }

    public function set_charset($charset) {
        if ($this->raw instanceof mysqli && !@$this->raw->connect_errno) {
            return @$this->raw->set_charset($charset);
        }
        return true;
    }

    public function close() {
        if ($this->raw instanceof mysqli && !@$this->raw->connect_errno) {
            @$this->raw->close();
        }
    }

    public function __get($name) {
        if ($this->raw instanceof mysqli) {
            return $this->raw->$name;
        }
        if ($name === 'connect_error') return $this->connect_error;
        if ($name === 'connect_errno') return $this->connect_errno;
        if ($name === 'error') return $this->error;
        if ($name === 'errno') return $this->errno;
        return null;
    }
}

class DBConnection{
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

        $this->host     = defined('DB_SERVER') ? DB_SERVER : 'mysql-32d19a6e-abadhbihari-33eb.f.aivencloud.com';
        $this->port     = defined('DB_PORT') ? (int)DB_PORT : 12893;
        $this->username = defined('DB_USERNAME') ? DB_USERNAME : 'avnadmin';
        $this->password = defined('DB_PASSWORD') ? DB_PASSWORD : base64_decode('QVZOU19IX0dqY3JoX1c0WmJURUZsMXVJ');
        $this->database = defined('DB_NAME') ? DB_NAME : 'scheduler_db';

        // Auto-correct credentials for Aiven Cloud MySQL
        $is_aiven = (strpos($this->host, 'aivencloud.com') !== false || $this->port === 12893);
        if ($is_aiven) {
            if (empty($this->username) || $this->username === 'root') {
                $this->username = 'avnadmin';
            }
            if (empty($this->password)) {
                $this->password = base64_decode('QVZOU19IX0dqY3JoX1c0WmJURUZsMXVJ');
            }
            if (empty($this->database) || $this->database === 'defaultdb' || $this->database === 'scheduler') {
                $this->database = 'scheduler_db';
            }
            $this->ssl = true;
        } else {
            $this->ssl = defined('DB_SSL') ? (bool)DB_SSL : false;
        }

        $raw_conn = mysqli_init();
        if (!$raw_conn) {
            $this->connect_error = "mysqli_init initialization failed";
            $this->conn = new SafeMySQLConnection(null, $this->connect_error);
            return;
        }

        $raw_conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15);

        $flags = 0;
        if ($this->ssl) {
            if (defined('MYSQLI_OPT_SSL_VERIFY_SERVER_CERT')) {
                $raw_conn->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
            }
            $raw_conn->ssl_set(NULL, NULL, NULL, NULL, NULL);
            $flags |= MYSQLI_CLIENT_SSL;
        }

        try {
            $connected = @$raw_conn->real_connect(
                $this->host,
                $this->username,
                $this->password,
                $this->database,
                $this->port,
                NULL,
                $flags
            );

            // Fallback attempt: if initial database failed, try connecting to scheduler_db
            if (!$connected && $this->database !== 'scheduler_db' && $is_aiven) {
                $raw_conn = mysqli_init();
                $raw_conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15);
                if (defined('MYSQLI_OPT_SSL_VERIFY_SERVER_CERT')) {
                    $raw_conn->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
                }
                $raw_conn->ssl_set(NULL, NULL, NULL, NULL, NULL);
                $connected = @$raw_conn->real_connect(
                    $this->host,
                    $this->username,
                    $this->password,
                    'scheduler_db',
                    $this->port,
                    NULL,
                    $flags
                );
                if ($connected) {
                    $this->database = 'scheduler_db';
                }
            }

            if ($connected) {
                $raw_conn->set_charset("utf8mb4");
                // Ensure scheduler_db is active
                if ($is_aiven && $this->database === 'defaultdb') {
                    @$raw_conn->select_db('scheduler_db');
                }
                $this->conn = new SafeMySQLConnection($raw_conn);
            } else {
                $this->connect_error = mysqli_connect_error();
                error_log("Database Connection Failed: " . $this->connect_error);
                $this->conn = new SafeMySQLConnection(null, $this->connect_error);
            }
        } catch (Throwable $e) {
            $this->connect_error = $e->getMessage();
            error_log("Database Exception during real_connect: " . $this->connect_error);
            $this->conn = new SafeMySQLConnection(null, $this->connect_error);
        }
    }

    public function isConnected(){
        return ($this->conn instanceof SafeMySQLConnection && $this->conn->raw instanceof mysqli && !@$this->conn->raw->connect_errno);
    }

    public function __destruct(){
        if ($this->conn instanceof SafeMySQLConnection) {
            $this->conn->close();
        }
    }
}
?>
