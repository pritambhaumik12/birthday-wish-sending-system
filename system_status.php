<?php
// system_status.php

// 1. Initialize Session
session_start();

// 2. Security Check
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: index.php");
    exit;
}

require_once "config.php";
mysqli_query($link, "SET time_zone = '+05:30'");

// ==========================
// DATA GATHERING
// ==========================

// A. Server & PHP Metrics
$start_sq = microtime(true);
$db_status = mysqli_ping($link);
$latency = round((microtime(true) - $start_sq) * 1000, 2); // ms

$memory_usage = round(memory_get_usage() / 1024 / 1024, 2); // MB
$max_memory = ini_get('memory_limit');

// System Load (Linux/Unix only, fallback for Windows)
$load = "N/A";
if (function_exists('sys_getloadavg')) {
    $load_avg = sys_getloadavg();
    if ($load_avg) {
        $load = $load_avg[0] . " / " . $load_avg[1] . " / " . $load_avg[2];
    }
}

// B. Overall Health Calculation
$health_score = 100;
$health_label = "Healthy";
$health_color = "success";
$recommendations = [];

if (!$db_status) { 
    $health_score = 0; $health_label = "Critical Failure"; $health_color = "danger"; 
    $recommendations[] = "Database connection failed. Check credentials in config.php.";
} else {
    if ($latency > 500) { 
        $health_score -= 20; 
        $recommendations[] = "High Database Latency ({$latency}ms). Optimize queries or check server load.";
    }
    if ($memory_usage > 128) { 
        $health_score -= 10;
        $recommendations[] = "High Memory Usage ({$memory_usage} MB). Check for memory leaks.";
    }
}
if ($health_score < 50) { $health_label = "Critical"; $health_color = "danger"; }
elseif ($health_score < 80) { $health_label = "Warning"; $health_color = "warning"; }

// C. Graph Data (Last 24 Hours Activity & Busyness)
$graph_labels = [];
$data_submissions = []; // Submission Activity
$data_busyness = [];    // Total Activity (Emails + Subs)
$data_status = [];      // Calculated Health (100 - failures)

$now = new DateTime(); 
$hours_map = [];

// Initialize last 24h
for($i=23; $i>=0; $i--) {
    $d = (clone $now)->modify("-$i hour")->format('H:00');
    $graph_labels[] = $d;
    $hours_map[$d] = ['subs' => 0, 'emails' => 0, 'failures' => 0];
}

// 1. Fetch Submissions (Submissions/hr)
$sql_subs = "SELECT DATE_FORMAT(submission_date, '%H:00') as hour_key, COUNT(*) as cnt 
             FROM student_submissions 
             WHERE submission_date >= NOW() - INTERVAL 24 HOUR 
             GROUP BY hour_key";
if($res = mysqli_query($link, $sql_subs)) {
    while($row = mysqli_fetch_assoc($res)) {
        if(isset($hours_map[$row['hour_key']])) $hours_map[$row['hour_key']]['subs'] = $row['cnt'];
    }
}

// 2. Fetch Email Logs (Sent = Busyness, Failed = Health)
// Determining current log table dynamically (e.g., current year)
$current_yr = date('y');
$log_table = "email_logs_" . $current_yr;
$check_log = mysqli_query($link, "SHOW TABLES LIKE '$log_table'");

if(mysqli_num_rows($check_log) > 0) {
    $sql_logs = "SELECT DATE_FORMAT(sent_at, '%H:00') as hour_key, 
                 SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) as sent_cnt,
                 SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as fail_cnt
                 FROM `$log_table`
                 WHERE sent_at >= NOW() - INTERVAL 24 HOUR 
                 GROUP BY hour_key";
    if($res = mysqli_query($link, $sql_logs)) {
        while($row = mysqli_fetch_assoc($res)) {
            if(isset($hours_map[$row['hour_key']])) {
                $hours_map[$row['hour_key']]['emails'] = $row['sent_cnt'];
                $hours_map[$row['hour_key']]['failures'] = $row['fail_cnt'];
            }
        }
    }
}

// 3. Prepare Final Datasets
foreach ($graph_labels as $hour) {
    $s = $hours_map[$hour]['subs'];
    $e = $hours_map[$hour]['emails'];
    $f = $hours_map[$hour]['failures'];
    
    $data_submissions[] = $s;
    $data_busyness[] = $s + $e; // Total actions
    
    // Status Logic: Start at 100, subtract 5 for every failure
    $health = 100 - ($f * 5); 
    if($health < 0) $health = 0;
    $data_status[] = $health;
}

$js_labels = json_encode($graph_labels);
$js_subs = json_encode($data_submissions);
$js_busy = json_encode($data_busyness);
$js_stat = json_encode($data_status);


// D. Sensitive Logs (Deletions/Trash)
$sensitive_logs = [];

function fetch_trash_logs($link, $table, $type) {
    global $sensitive_logs;
    $check = mysqli_query($link, "SHOW TABLES LIKE '$table'");
    if (mysqli_num_rows($check) > 0) {
        $col_check = mysqli_query($link, "SHOW COLUMNS FROM `$table` LIKE 'deleted_at'");
        $order = (mysqli_num_rows($col_check) > 0) ? "ORDER BY deleted_at DESC" : "";
        
        $sql = "SELECT * FROM `$table` $order LIMIT 5";
        if ($res = mysqli_query($link, $sql)) {
            while ($row = mysqli_fetch_assoc($res)) {
                $time = isset($row['deleted_at']) ? $row['deleted_at'] : "N/A";
                $name = isset($row['student_name']) ? $row['student_name'] : (isset($row['title']) ? $row['title'] : 'Unknown Item');
                $sensitive_logs[] = [
                    'type' => $type,
                    'msg' => "Deleted $type: <strong>$name</strong>",
                    'time' => $time,
                    'alert' => 'danger'
                ];
            }
        }
    }
}

fetch_trash_logs($link, 'trash_students', 'Student');
fetch_trash_logs($link, 'trash_wishes', 'Wish Template');

if (empty($sensitive_logs)) {
    $sensitive_logs[] = ['type' => 'System', 'msg' => 'No sensitive deletions detected recently.', 'time' => date('Y-m-d H:i:s'), 'alert' => 'success'];
}

usort($sensitive_logs, function($a, $b) {
    return strtotime($b['time']) - strtotime($a['time']);
});

mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System Status | Birthday System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="BWU_logo.png" type="image/png">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; padding-bottom: 50px; }
        .hero-section {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white; padding: 40px 0 80px 0; margin-bottom: -50px;
        }
        .status-card {
            border: none; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            background: #fff; transition: transform 0.3s; height: 100%; overflow: hidden;
        }
        .status-card:hover { transform: translateY(-5px); }
        .card-header-custom { background: transparent; border-bottom: 1px solid #f0f0f0; padding: 20px; font-weight: 700; color: #444; }
        .metric-value { font-size: 2rem; font-weight: 800; color: #333; }
        .metric-label { font-size: 0.85rem; color: #888; text-transform: uppercase; letter-spacing: 1px; }
        
        .health-badge {
            font-size: 1rem; padding: 8px 15px; border-radius: 30px;
            background: rgba(255,255,255,0.2); backdrop-filter: blur(5px);
        }
        
        /* Log Styling */
        .log-item { border-left: 4px solid transparent; margin-bottom: 10px; background: #fafafa; padding: 10px 15px; border-radius: 4px; }
        .log-item.danger { border-left-color: #dc3545; }
        .log-item.success { border-left-color: #28a745; }
        .log-time { font-size: 0.75rem; color: #999; float: right; }
        
        .rec-item { font-size: 0.9rem; color: #555; padding: 5px 0; }
        .rec-item i { width: 25px; color: #ffc107; }
    </style>
</head>
<body>

<div class="hero-section">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h2 class="font-weight-bold"><i class="fas fa-server mr-2"></i>System Status</h2>
                <p class="mb-0 opacity-75">Real-time health monitoring & performance metrics</p>
            </div>
            <div>
                <span class="health-badge border border-light">
                    <i class="fas fa-circle text-<?php echo $health_color; ?> mr-2"></i> <?php echo $health_label; ?>
                </span>
                <a href="dashboard.php" class="btn btn-outline-light rounded-pill ml-3 px-4">Dashboard</a>
            </div>
        </div>
    </div>
</div>

<div class="container" style="margin-top: -30px;">
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="status-card p-4 text-center">
                <i class="fas fa-tachometer-alt fa-2x text-primary mb-3 opacity-50"></i>
                <div class="metric-value"><?php echo $latency; ?><small style="font-size:1rem">ms</small></div>
                <div class="metric-label">DB Latency</div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="status-card p-4 text-center">
                <i class="fas fa-memory fa-2x text-info mb-3 opacity-50"></i>
                <div class="metric-value"><?php echo $memory_usage; ?><small style="font-size:1rem">MB</small></div>
                <div class="metric-label">Memory Usage</div>
                <small class="text-muted">Max: <?php echo $max_memory; ?></small>
            </div>
        </div>
        
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="status-card p-4 text-center">
                <i class="fas fa-cogs fa-2x text-warning mb-3 opacity-50"></i>
                <div class="metric-value" style="font-size: 1.5rem; padding-top: 10px;"><?php echo $load; ?></div>
                <div class="metric-label">Server Load (1m/5m/15m)</div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6 mb-3">
            <div class="status-card p-4 text-center">
                <i class="fas fa-microchip fa-2x text-secondary mb-3 opacity-50"></i>
                <div class="metric-value">PHP</div>
                <div class="metric-label">v<?php echo phpversion(); ?></div>
                <small class="text-success"><i class="fas fa-check"></i> Running</small>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8 mb-4">
            <div class="status-card">
                <div class="card-header-custom d-flex justify-content-between">
                    <span><i class="fas fa-chart-line mr-2"></i>24-Hour System Busyness & Health</span>
                    <small class="text-muted">Updated: <?php echo date("H:i:s"); ?></small>
                </div>
                <div class="card-body">
                    <canvas id="activityChart" style="height: 350px; width: 100%;"></canvas>
                </div>
            </div>
        </div>

        <div class="col-lg-4 mb-4">
            <div class="status-card mb-4" style="height: auto;">
                <div class="card-header-custom bg-light">
                    <i class="fas fa-clipboard-check mr-2"></i>Recommendations
                </div>
                <div class="card-body">
                    <?php if(empty($recommendations)): ?>
                        <div class="text-success"><i class="fas fa-check-circle mr-2"></i>All systems operational. No actions needed.</div>
                    <?php else: ?>
                        <?php foreach($recommendations as $rec): ?>
                            <div class="rec-item"><i class="fas fa-exclamation-triangle"></i> <?php echo $rec; ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="status-card" style="height: auto;">
                <div class="card-header-custom bg-light text-danger">
                    <i class="fas fa-user-shield mr-2"></i>Sensitive Activity Log
                </div>
                <div class="card-body p-0">
                    <div style="max-height: 250px; overflow-y: auto; padding: 15px;">
                        <?php foreach($sensitive_logs as $log): ?>
                            <div class="log-item <?php echo $log['alert']; ?>">
                                <div class="d-flex justify-content-between">
                                    <strong style="font-size: 0.9rem;"><?php echo $log['type']; ?></strong>
                                    <span class="log-time"><?php echo $log['time']; ?></span>
                                </div>
                                <div style="font-size: 0.85rem; margin-top: 2px;"><?php echo $log['msg']; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="text-center text-muted small mt-4 mb-5">
        Server: <?php echo $_SERVER['SERVER_SOFTWARE']; ?> | 
        Client IP: <?php echo $_SERVER['REMOTE_ADDR']; ?> | 
        Max Upload: <?php echo ini_get('upload_max_filesize'); ?>
    </div>
</div>

<script>
    // Chart.js Initialization
    const ctx = document.getElementById('activityChart').getContext('2d');
    const activityChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo $js_labels; ?>,
            datasets: [
                {
                    label: 'Overall System Status (%)',
                    data: <?php echo $js_stat; ?>,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    borderWidth: 2,
                    yAxisID: 'y_status',
                    tension: 0.1,
                    pointRadius: 0
                },
                {
                    label: 'Busyness (Total Ops)',
                    data: <?php echo $js_busy; ?>,
                    backgroundColor: 'rgba(255, 193, 7, 0.5)',
                    borderColor: 'rgba(255, 193, 7, 1)',
                    borderWidth: 1,
                    type: 'bar',
                    yAxisID: 'y_activity'
                },
                {
                    label: 'Submissions',
                    data: <?php echo $js_subs; ?>,
                    borderColor: '#007bff',
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    yAxisID: 'y_activity',
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y_activity: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: { display: true, text: 'Operations Count' },
                    grid: { display: false }
                },
                y_status: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    min: 0,
                    max: 100,
                    title: { display: true, text: 'System Health %' },
                    grid: { borderDash: [5, 5] }
                },
                x: { grid: { display: false } }
            },
            plugins: {
                legend: { position: 'bottom' },
                tooltip: { mode: 'index', intersect: false }
            }
        }
    });
</script>

</body>
</html>