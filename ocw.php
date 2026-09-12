<?php
/**
 * OpenCart Order Stats - Web version (PHP 5.4+)
 * Shows order counts (status > 0) by status and by country.
 * Place next to config.php in the OpenCart web root and open:
 *   http://yourshop/ocw.php?days=7
 *
 * Optional access control: set ACCESS_TOKEN to a random string,
 * then access requires ?token=xxx. Leave empty to disable.
 * Add &debug=1 to display PHP errors.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
mysqli_report(MYSQLI_REPORT_OFF); // handle errors manually, no exceptions

// ---------- Access control (optional) ----------
$ACCESS_TOKEN = '';

if (isset($_GET['debug'])) {
    ini_set('display_errors', '1');
}

if ($ACCESS_TOKEN !== '' && (!isset($_GET['token']) || $_GET['token'] !== $ACCESS_TOKEN)) {
    http_response_code(403);
    exit('Forbidden');
}

// ---------- 1. Load OpenCart config ----------
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('config.php not found at ' . $configFile . ' - place this file next to it');
}
require $configFile;

foreach (array('DB_HOSTNAME', 'DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE') as $k) {
    if (!defined($k)) {
        http_response_code(500);
        exit("Constant $k missing in config.php (PHP version: " . PHP_VERSION . ")");
    }
}

$prefix = defined('DB_PREFIX') ? DB_PREFIX : 'oc_';
$days   = isset($_GET['days']) ? (int)$_GET['days'] : 7;
if ($days < 1)   { $days = 1; }
if ($days > 365) { $days = 365; }
$langId = isset($_GET['lang']) ? (int)$_GET['lang'] : 1;

// ---------- 2. Connect ----------
$db = @new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE);
if ($db->connect_errno) {
    http_response_code(500);
    exit('DB connection failed: ' . htmlspecialchars($db->connect_error)
        . ' (host=' . DB_HOSTNAME . ', db=' . DB_DATABASE . ')');
}
if (!$db->set_charset('utf8mb4')) { $db->set_charset('utf8'); }

$order       = $prefix . 'order';
$statusTable = $prefix . 'order_status';

function q($db, $sql) {
    $res = $db->query($sql);
    if ($res === false) {
        http_response_code(500);
        exit('SQL error: ' . htmlspecialchars($db->error) . '<br>SQL: ' . htmlspecialchars($sql));
    }
    $rows = array();
    if ($res !== true) {
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    }
    return $rows;
}

// ---------- 3. Queries (counts only) ----------
$totalRows = q($db,
    "SELECT COUNT(*) AS cnt
     FROM `{$order}`
     WHERE order_status_id > 0 AND date_added >= NOW() - INTERVAL {$days} DAY");
$total = $totalRows[0];

$byStatus = q($db,
    "SELECT o.order_status_id, COALESCE(os.name,'(unknown)') AS status_name,
            COUNT(*) AS cnt
     FROM `{$order}` o
     LEFT JOIN `{$statusTable}` os
            ON os.order_status_id = o.order_status_id AND os.language_id = {$langId}
     WHERE o.order_status_id > 0 AND o.date_added >= NOW() - INTERVAL {$days} DAY
     GROUP BY o.order_status_id, os.name
     ORDER BY cnt DESC");

$byCountry = q($db,
    "SELECT COALESCE(NULLIF(payment_country,''),'(empty)') AS country,
            COUNT(*) AS cnt
     FROM `{$order}`
     WHERE order_status_id > 0 AND date_added >= NOW() - INTERVAL {$days} DAY
     GROUP BY payment_country
     ORDER BY cnt DESC");

$db->close();

// ---------- 4. Output ----------
header('Content-Type: text/html; charset=utf-8');

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function bar($cnt, $max) { return round($cnt / $max * 100); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Order Stats - last <?php echo (int)$days; ?> days</title>
<style>
  body { font-family: system-ui, "Segoe UI", Arial, sans-serif; margin: 2rem; background: #f6f7f9; color: #222; }
  h1 { font-size: 1.3rem; }
  h2 { font-size: 1.05rem; margin: 0; }
  .card { background: #fff; border-radius: 8px; padding: 1rem 1.5rem; margin: 1rem 0; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
  .summary b { font-size: 1.6rem; }
  table { border-collapse: collapse; width: 100%; margin-top: .5rem; }
  th, td { padding: .45rem .8rem; text-align: left; border-bottom: 1px solid #eee; }
  th { background: #fafafa; font-weight: 600; }
  td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
  form { display: inline-flex; gap: .5rem; align-items: center; }
  input, button { padding: .35rem .7rem; border: 1px solid #ccc; border-radius: 6px; }
  button { background: #2563eb; color: #fff; border-color: #2563eb; cursor: pointer; }
  .bar { background: #2563eb; height: 10px; border-radius: 3px; min-width: 2px; }
</style>
</head>
<body>

<h1>OpenCart order stats (status &gt; 0, last <?php echo (int)$days; ?> days)</h1>

<form method="get">
  <label>Last <input type="number" name="days" value="<?php echo (int)$days; ?>" min="1" max="365" style="width:5rem"> days</label>
  <label>Language ID <input type="number" name="lang" value="<?php echo (int)$langId; ?>" min="1" style="width:4rem"></label>
  <button type="submit">Query</button>
</form>

<div class="card summary">
  Valid orders: <b><?php echo number_format((float)$total['cnt']); ?></b>
</div>

<div class="card">
  <h2>By order status</h2>
  <table>
    <tr><th>Status</th><th class="num">Orders</th><th style="width:50%">&nbsp;</th></tr>
    <?php
    $maxCnt = 1;
    foreach ($byStatus as $r) { if ((int)$r['cnt'] > $maxCnt) $maxCnt = (int)$r['cnt']; }
    foreach ($byStatus as $r) {
        echo '<tr><td>' . h($r['status_name']) . '</td>'
           . '<td class="num">' . number_format((float)$r['cnt']) . '</td>'
           . '<td><div class="bar" style="width:' . bar((int)$r['cnt'], $maxCnt) . '%"></div></td></tr>';
    }
    if (!$byStatus) { echo '<tr><td colspan="3">No data</td></tr>'; }
    ?>
  </table>
</div>

<div class="card">
  <h2>By country (payment country)</h2>
  <table>
    <tr><th>Country</th><th class="num">Orders</th><th style="width:50%">&nbsp;</th></tr>
    <?php
    $maxCnt = 1;
    foreach ($byCountry as $r) { if ((int)$r['cnt'] > $maxCnt) $maxCnt = (int)$r['cnt']; }
    foreach ($byCountry as $r) {
        echo '<tr><td>' . h($r['country']) . '</td>'
           . '<td class="num">' . number_format((float)$r['cnt']) . '</td>'
           . '<td><div class="bar" style="width:' . bar((int)$r['cnt'], $maxCnt) . '%"></div></td></tr>';
    }
    if (!$byCountry) { echo '<tr><td colspan="3">No data</td></tr>'; }
    ?>
  </table>
</div>

<p style="color:#999;font-size:.8rem">PHP <?php echo PHP_VERSION; ?></p>

</body>
</html>
