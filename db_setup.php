<?php
/**
 * 1-Click Aiven Cloud MySQL Database Setup & Health Check Tool
 * Covid Vaccination Scheduler System
 */

session_start();
require_once(__DIR__ . '/initialize.php');
require_once(__DIR__ . '/classes/DBConnection.php');

$db = new DBConnection();
$conn = $db->conn;
$conn_error = $db->connect_error;
$raw_conn = ($conn instanceof SafeMySQLConnection) ? $conn->raw : $conn;

$expected_tables = array(
    'individuals'     => 'Registered Citizens / Patients',
    'individual_meta' => 'Citizen Demographic & Contact Metadata',
    'location'        => 'Vaccination Centers & Daily Limits',
    'schedules'       => 'Appointment Bookings & Schedule Dates',
    'system_info'     => 'System Settings & Branding Information',
    'users'           => 'Administrator & Staff Accounts'
);

$sql_file = __DIR__ . '/database/scheduler.sql';
$action_msg = '';
$action_type = '';

// Smart SQL Parser function that respects quoted semicolons (HTML entities like &quot;, &lt;, etc.)
function split_sql_queries($sql_content) {
    $statements = array();
    $current = '';
    $in_single = false;
    $in_double = false;
    $len = strlen($sql_content);
    $i = 0;

    while ($i < $len) {
        $char = $sql_content[$i];

        // Skip comments outside strings
        if (!$in_single && !$in_double) {
            // Single-line comment --
            if ($char === '-' && $i + 1 < $len && $sql_content[$i + 1] === '-') {
                while ($i < $len && $sql_content[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            // Single-line comment #
            if ($char === '#') {
                while ($i < $len && $sql_content[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            // Multi-line comment /* ... */
            if ($char === '/' && $i + 1 < $len && $sql_content[$i + 1] === '*') {
                $i += 2;
                while ($i + 1 < $len && !($sql_content[$i] === '*' && $sql_content[$i + 1] === '/')) {
                    $i++;
                }
                $i += 2;
                continue;
            }
        }

        // Single quote toggle
        if ($char === "'" && !$in_double) {
            if (!$in_single) {
                $in_single = true;
            } else {
                // Check if escaped with odd number of backslashes
                $bs_count = 0;
                $k = $i - 1;
                while ($k >= 0 && $sql_content[$k] === '\\') {
                    $bs_count++;
                    $k--;
                }
                if ($bs_count % 2 === 0) {
                    // Check double single quote escaping ''
                    if ($i + 1 < $len && $sql_content[$i + 1] === "'") {
                        $current .= $char;
                        $i++;
                        $char = $sql_content[$i];
                    } else {
                        $in_single = false;
                    }
                }
            }
        }
        // Double quote toggle
        elseif ($char === '"' && !$in_single) {
            if (!$in_double) {
                $in_double = true;
            } else {
                $bs_count = 0;
                $k = $i - 1;
                while ($k >= 0 && $sql_content[$k] === '\\') {
                    $bs_count++;
                    $k--;
                }
                if ($bs_count % 2 === 0) {
                    $in_double = false;
                }
            }
        }
        // Semicolon statement boundary outside quotes
        elseif ($char === ';' && !$in_single && !$in_double) {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            $i++;
            continue;
        }

        $current .= $char;
        $i++;
    }

    $last = trim($current);
    if ($last !== '') {
        $statements[] = $last;
    }
    return $statements;
}

function run_database_import($conn, $sql_file, &$action_msg, &$action_type) {
    $raw = ($conn instanceof SafeMySQLConnection) ? $conn->raw : $conn;
    if (!$raw || !($raw instanceof mysqli) || @$raw->connect_errno) {
        $action_msg = "Database connection unavailable. Cannot perform import.";
        $action_type = "danger";
        return;
    }

    // Set Aiven session flags
    @$raw->query("SET SESSION sql_require_primary_key = 0");
    @$raw->query("SET FOREIGN_KEY_CHECKS = 0");
    @$raw->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
    @$raw->query("SET AUTOCOMMIT = 1");

    if (!file_exists($sql_file)) {
        $action_msg = "Database dump file not found: " . htmlspecialchars(basename($sql_file));
        $action_type = "danger";
        return;
    }

    $sql_content = file_get_contents($sql_file);
    $queries = split_sql_queries($sql_content);

    $executed = 0;
    $errors = array();

    foreach ($queries as $st) {
        $res = @$raw->query($st);
        if ($res) {
            $executed++;
        } else {
            // Ignore benign duplicate or existing errors
            $err = $raw->error;
            if (stripos($err, "already exists") === false && stripos($err, "Duplicate entry") === false) {
                $errors[] = substr($st, 0, 50) . " ... -> " . $err;
            }
        }
    }

    if (count($errors) === 0) {
        $action_msg = "Database successfully imported! ({$executed} statements executed). All tables and seed data created.";
        $action_type = "success";
    } else {
        $action_msg = "Database imported with minor notices ({$executed} queries passed, " . count($errors) . " errors): " . implode("<br>", array_slice($errors, 0, 5));
        $action_type = "warning";
    }
}

// Handle Import Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'import' || $action === 'reimport') {
        if ($action === 'reimport' && $raw_conn && ($raw_conn instanceof mysqli)) {
            @$raw_conn->query("SET FOREIGN_KEY_CHECKS = 0");
            foreach (array_keys($expected_tables) as $tbl) {
                @$raw_conn->query("DROP TABLE IF EXISTS `{$tbl}`");
            }
        }
        run_database_import($raw_conn, $sql_file, $action_msg, $action_type);
    }
}

// Check database table status
$table_statuses = array();
$all_tables_exist = true;
$admin_exists = false;
$ssl_cipher = 'None';
$mysql_version = 'Unknown';

if ($raw_conn && ($raw_conn instanceof mysqli) && !@$raw_conn->connect_errno) {
    $mysql_version = $raw_conn->server_info;
    $cipher_res = @$raw_conn->query("SHOW STATUS LIKE 'Ssl_cipher'");
    if ($cipher_res && $row = $cipher_res->fetch_assoc()) {
        if (!empty($row['Value'])) {
            $ssl_cipher = $row['Value'];
        }
    }

    foreach ($expected_tables as $table => $desc) {
        $check_tbl = @$raw_conn->query("SHOW TABLES LIKE '{$table}'");
        if ($check_tbl && $check_tbl->num_rows > 0) {
            $cnt_res = @$raw_conn->query("SELECT COUNT(*) as cnt FROM `{$table}`");
            $cnt = ($cnt_res && $crow = $cnt_res->fetch_assoc()) ? $crow['cnt'] : 0;
            $table_statuses[$table] = array('exists' => true, 'count' => $cnt, 'desc' => $desc);
        } else {
            $table_statuses[$table] = array('exists' => false, 'count' => 0, 'desc' => $desc);
            $all_tables_exist = false;
        }
    }

    // Check admin account
    $chk_admin = @$raw_conn->query("SELECT * FROM `users` WHERE `username` = 'admin'");
    if ($chk_admin && $chk_admin->num_rows > 0) {
        $admin_exists = true;
    }
} else {
    $all_tables_exist = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup &amp; Health Check - Covid Vaccination Scheduler</title>
    <link rel="stylesheet" href="<?php echo base_url; ?>plugins/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="<?php echo base_url; ?>dist/css/adminlte.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .setup-card {
            background: #1e293b;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5), 0 10px 10px -5px rgba(0, 0, 0, 0.4);
        }
        .stat-badge {
            font-size: 0.85rem;
            padding: 6px 12px;
            border-radius: 20px;
        }
        .table-custom {
            background: #0f172a;
            border-radius: 8px;
            overflow: hidden;
        }
        .table-custom th {
            background: #1e293b;
            border-top: none;
            color: #94a3b8;
            font-weight: 600;
        }
        .table-custom td {
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            color: #e2e8f0;
        }
    </style>
</head>
<body class="py-5">
<div class="container">
    <div class="card setup-card mb-4">
        <div class="card-header bg-transparent border-bottom border-secondary py-3 d-flex justify-content-between align-items-center flex-wrap">
            <h3 class="mb-0 text-white font-weight-bold">
                <i class="fas fa-database text-info mr-2"></i> Covid Vaccination Scheduler - Database Setup
            </h3>
            <span class="badge badge-primary py-2 px-3 mt-2 mt-sm-0">PHP 8.2 &bull; Aiven Cloud MySQL</span>
        </div>
        <div class="card-body p-4">
            <?php if (!empty($action_msg)): ?>
                <div class="alert alert-<?php echo $action_type; ?> alert-dismissible fade show mb-4 shadow" role="alert">
                    <i class="fas fa-info-circle mr-2"></i> <?php echo $action_msg; ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            <?php endif; ?>

            <!-- Connection Status Cards -->
            <div class="row mb-4">
                <div class="col-md-4 mb-3 mb-md-0">
                    <div class="p-3 rounded bg-dark border border-secondary h-100">
                        <small class="text-muted text-uppercase font-weight-bold d-block">MySQL Connection</small>
                        <?php if ($raw_conn && ($raw_conn instanceof mysqli) && !@$raw_conn->connect_errno): ?>
                            <span class="text-success font-weight-bold h5 d-block mt-2">
                                <i class="fas fa-check-circle mr-1"></i> Connected Successfully
                            </span>
                            <small class="text-muted">Host: <code><?php echo DB_SERVER; ?>:<?php echo DB_PORT; ?></code></small>
                        <?php else: ?>
                            <span class="text-danger font-weight-bold h5 d-block mt-2">
                                <i class="fas fa-times-circle mr-1"></i> Connection Failed
                            </span>
                            <small class="text-danger">Error: <?php echo htmlspecialchars($conn_error ?: 'Could not reach server'); ?></small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-md-4 mb-3 mb-md-0">
                    <div class="p-3 rounded bg-dark border border-secondary h-100">
                        <small class="text-muted text-uppercase font-weight-bold d-block">SSL / Encryption</small>
                        <?php if ($ssl_cipher !== 'None'): ?>
                            <span class="text-info font-weight-bold h5 d-block mt-2">
                                <i class="fas fa-lock mr-1"></i> TLS/SSL Active
                            </span>
                            <small class="text-muted">Cipher: <code><?php echo htmlspecialchars($ssl_cipher); ?></code></small>
                        <?php else: ?>
                            <span class="text-warning font-weight-bold h5 d-block mt-2">
                                <i class="fas fa-unlock mr-1"></i> Plaintext Connection
                            </span>
                            <small class="text-muted">SSL not active</small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="p-3 rounded bg-dark border border-secondary h-100">
                        <small class="text-muted text-uppercase font-weight-bold d-block">Target Database</small>
                        <span class="text-white font-weight-bold h5 d-block mt-2">
                            <i class="fas fa-server mr-1"></i> <code><?php echo DB_NAME; ?></code>
                        </span>
                        <small class="text-muted">MySQL Version: <?php echo htmlspecialchars($mysql_version); ?></small>
                    </div>
                </div>
            </div>

            <!-- Table Status Overview -->
            <h5 class="text-white mb-3 font-weight-bold">
                <i class="fas fa-table mr-2 text-primary"></i> Required Database Tables
            </h5>
            <div class="table-responsive table-custom mb-4">
                <table class="table table-dark mb-0">
                    <thead>
                        <tr>
                            <th>Table Name</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Record Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expected_tables as $table => $desc): 
                            $info = $table_statuses[$table] ?? array('exists' => false, 'count' => 0, 'desc' => $desc);
                        ?>
                        <tr>
                            <td><code><?php echo $table; ?></code></td>
                            <td><?php echo htmlspecialchars($desc); ?></td>
                            <td>
                                <?php if ($info['exists']): ?>
                                    <span class="badge badge-success"><i class="fas fa-check mr-1"></i> Ready</span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><i class="fas fa-times mr-1"></i> Missing</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo $info['count']; ?></strong> rows</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Admin Credentials Card -->
            <div class="card bg-dark border border-secondary mb-4">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div>
                            <span class="badge badge-info mr-2">Default Admin Account</span>
                            <strong>Username:</strong> <code>admin</code> &bull; 
                            <strong>Password:</strong> <code>admin123</code>
                        </div>
                        <div class="mt-2 mt-md-0">
                            <?php if ($admin_exists): ?>
                                <span class="text-success small font-weight-bold"><i class="fas fa-user-check mr-1"></i> Admin Account Verified</span>
                            <?php else: ?>
                                <span class="text-warning small font-weight-bold"><i class="fas fa-user-slash mr-1"></i> Admin Not Found (Run Import)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Controls -->
            <div class="mt-4 pt-2 border-top border-secondary d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <?php if (!$all_tables_exist): ?>
                        <form method="POST" style="display:inline-block;">
                            <input type="hidden" name="action" value="import">
                            <button type="submit" class="btn btn-primary btn-lg px-4 font-weight-bold shadow">
                                <i class="fas fa-cloud-upload-alt mr-2"></i> 1-Click Import Database (scheduler.sql)
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="POST" style="display:inline-block;" onsubmit="return confirm('Warning: Re-importing will reset all database tables to initial state. Are you sure?');">
                            <input type="hidden" name="action" value="reimport">
                            <button type="submit" class="btn btn-outline-warning">
                                <i class="fas fa-redo-alt mr-1"></i> Reset &amp; Re-import Database
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="mt-3 mt-md-0">
                    <a href="<?php echo base_url; ?>index.php" class="btn btn-outline-light mr-2">
                        <i class="fas fa-globe mr-1"></i> Go to Website
                    </a>
                    <a href="<?php echo base_url; ?>admin/login.php" class="btn btn-success btn-lg px-4 font-weight-bold shadow">
                        <i class="fas fa-sign-in-alt mr-2"></i> Admin Login
                    </a>
                </div>
            </div>
        </div>
        <!-- Card Footer -->
        <div class="card-footer bg-transparent border-top border-secondary text-center text-muted small py-3">
            Covid Vaccination Scheduler &bull; Render Cloud Deploy &bull; Aiven MySQL Connector v2.0
        </div>
    </div>
</div>

<script src="<?php echo base_url; ?>plugins/jquery/jquery.min.js"></script>
<script src="<?php echo base_url; ?>plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>

