<?php
/**
 * OpenCart 订单快速查询 - Web 版
 * 放到 OpenCart 站点目录(与 config.php 同级,如站点根目录或 admin/)即可访问:
 *   http://yourshop/oc_order_stats_web.php?days=7
 *
 * 可选简单访问控制:在下面 ACCESS_TOKEN 里填一个随机串,
 * 之后必须带 ?token=xxx 访问;留空则不校验(仅建议放 admin/ 下并配合后台登录)。
 */

// ---------- 访问控制(可选) ----------
define('ACCESS_TOKEN', ''); // 例: bin2hex(random_bytes(16)) 生成的串

if (ACCESS_TOKEN !== '' && ($_GET['token'] ?? '') !== ACCESS_TOKEN) {
    http_response_code(403);
    exit('Forbidden');
}

// ---------- 1. 加载 OpenCart 配置(与本文件同目录的 config.php) ----------
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('找不到 config.php,请将本文件放到 OpenCart 站点根目录或 admin/ 目录下');
}
require $configFile;

foreach (['DB_HOSTNAME', 'DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE'] as $k) {
    if (!defined($k)) {
        http_response_code(500);
        exit("config.php 缺少常量 $k");
    }
}

$prefix = defined('DB_PREFIX') ? DB_PREFIX : 'oc_';
$days   = max(1, min(365, (int)($_GET['days'] ?? 7)));
$langId = (int)($_GET['lang'] ?? 1); // 状态名语言,中文站一般传 &lang=2

// ---------- 2. 连接 ----------
$db = @new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE);
if ($db->connect_errno) {
    http_response_code(500);
    exit('数据库连接失败: ' . htmlspecialchars($db->connect_error));
}
$db->set_charset('utf8mb4');

$order  = $prefix . 'order';
$status = $prefix . 'order_status';

function q(mysqli $db, string $sql): array {
    $res = $db->query($sql);
    if ($res === false) { http_response_code(500); exit('SQL 错误: ' . htmlspecialchars($db->error)); }
    return $res->fetch_all(MYSQLI_ASSOC);
}

// ---------- 3. 查询 ----------
$total = q($db,
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(total),0) AS amount
     FROM `{$order}`
     WHERE order_status_id > 0 AND date_added >= NOW() - INTERVAL {$days} DAY")[0];

$byStatus = q($db,
    "SELECT o.order_status_id, COALESCE(os.name,'(未知)') AS status_name,
            COUNT(*) AS cnt, COALESCE(SUM(o.total),0) AS amount
     FROM `{$order}` o
     LEFT JOIN `{$status}` os ON os.order_status_id = o.order_status_id AND os.language_id = {$langId}
     WHERE o.order_status_id > 0 AND o.date_added >= NOW() - INTERVAL {$days} DAY
     GROUP BY o.order_status_id, os.name
     ORDER BY cnt DESC");

$byCountry = q($db,
    "SELECT COALESCE(NULLIF(payment_country,''),'(空)') AS country,
            COUNT(*) AS cnt, COALESCE(SUM(o.total),0) AS amount
     FROM `{$order}`
     WHERE order_status_id > 0 AND date_added >= NOW() - INTERVAL {$days} DAY
     GROUP BY payment_country
     ORDER BY cnt DESC");

$db->close();

// ---------- 4. 输出 HTML ----------
header('Content-Type: text/html; charset=utf-8');
$fmt = fn($v) => htmlspecialchars((string)$v);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>订单统计 - 最近 <?= $days ?> 天</title>
<style>
  body { font-family: system-ui, "Segoe UI", "Microsoft YaHei", sans-serif; margin: 2rem; background: #f6f7f9; color: #222; }
  h1 { font-size: 1.3rem; }
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

<h1>OpenCart 订单统计(状态 &gt; 0,最近 <?= $days ?> 天)</h1>

<form method="get">
  <label>最近 <input type="number" name="days" value="<?= $days ?>" min="1" max="365" style="width:5rem"> 天</label>
  <label>语言ID <input type="number" name="lang" value="<?= $langId ?>" min="1" style="width:4rem"></label>
  <button type="submit">查询</button>
</form>

<div class="card summary">
  有效订单:<b><?= number_format((float)$total['cnt']) ?></b> 笔,
  合计金额:<b><?= number_format((float)$total['amount'], 2) ?></b>
</div>

<div class="card">
  <h2>按订单状态</h2>
  <table>
    <tr><th>状态</th><th class="num">笔数</th><th class="num">金额</th><th style="width:40%">&nbsp;</th></tr>
    <?php $maxCnt = max(1, ...array_column($byStatus, 'cnt')) ?: 1; foreach ($byStatus as $r): ?>
    <tr>
      <td><?= $fmt($r['status_name']) ?></td>
      <td class="num"><?= number_format((float)$r['cnt']) ?></td>
      <td class="num"><?= number_format((float)$r['amount'], 2) ?></td>
      <td><div class="bar" style="width: <?= round($r['cnt'] / $maxCnt * 100) ?>%"></div></td>
    </tr>
    <?php endforeach; if (!$byStatus) echo '<tr><td colspan="4">无数据</td></tr>'; ?>
  </table>
</div>

<div class="card">
  <h2>按国家(支付国家)</h2>
  <table>
    <tr><th>国家</th><th class="num">笔数</th><th class="num">金额</th><th style="width:40%">&nbsp;</th></tr>
    <?php $maxCnt = max(1, ...array_column($byCountry, 'cnt')) ?: 1; foreach ($byCountry as $r): ?>
    <tr>
      <td><?= $fmt($r['country']) ?></td>
      <td class="num"><?= number_format((float)$r['cnt']) ?></td>
      <td class="num"><?= number_format((float)$r['amount'], 2) ?></td>
      <td><div class="bar" style="width: <?= round($r['cnt'] / $maxCnt * 100) ?>%"></div></td>
    </tr>
    <?php endforeach; if (!$byCountry) echo '<tr><td colspan="4">无数据</td></tr>'; ?>
  </table>
</div>

</body>
</html>
