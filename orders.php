<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$arquivoConfig = __DIR__ . '/config.php';
if (!file_exists($arquivoConfig)) { die('config.php não encontrado'); }
require_once $arquivoConfig;

$csrf_name = '_csrf';
if (empty($_SESSION[$csrf_name])) { $_SESSION[$csrf_name] = bin2hex(random_bytes(16)); }
$csrf_token = $_SESSION[$csrf_name];

$sellerEmail = isset($_SESSION['user_email']) ? (string)$_SESSION['user_email'] : '';
if ($sellerEmail === '') { die('Sessão inválida.'); }

// Buscar logo da company (mesma lógica das outras páginas)
$company_id = 1; // fixo por enquanto
$logo_dir = __DIR__ . '/img_logo';
$logo_url = 'logo.png'; // fallback
$pattern = $logo_dir . '/logo_' . $company_id . '.*';
$existing_files = glob($pattern);
if (!empty($existing_files) && is_file($existing_files[0])) {
  $logo_file = basename($existing_files[0]);
  $logo_url = 'img_logo/' . $logo_file;
}

// Filtros simples (opcional)
$erro = '';
$rows = [];
$itemsByOrder = [];

$startRaw = isset($_GET['start']) ? trim((string)$_GET['start']) : '';
$endRaw   = isset($_GET['end'])   ? trim((string)$_GET['end'])   : '';
$clientQ  = isset($_GET['client'])? trim((string)$_GET['client']): '';

$startOk = ($startRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startRaw));
$endOk   = ($endRaw   !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endRaw));
$startTs = $startOk ? ($startRaw.' 00:00:00') : '';
$endTs   = $endOk   ? ($endRaw.' 23:59:59') : '';
// sem toggle: sempre filtra pelo vendedor logado

// DB migrate: ensure orders.store_id and store_cod exist
@mysqli_report(MYSQLI_REPORT_OFF);
@($conn->query("ALTER TABLE `orders` ADD COLUMN `store_id` BIGINT(20) UNSIGNED NULL"));
@($conn->query("ALTER TABLE `orders` ADD KEY `idx_store_id` (`store_id`)"));
@($conn->query("ALTER TABLE `orders` ADD COLUMN `store_cod` VARCHAR(40) NULL"));
@($conn->query("ALTER TABLE `orders` ADD KEY `idx_store_cod` (`store_cod`)"));

// (Link store feature removed by request) - no AJAX/POST handlers

// Listar pedidos do vendedor logado com filtros opcionais
// Campos disponíveis em orders: id, created_at, seller_name, seller_email, client_name, client_phone, client_email, company_slug, order_total, send_status, send_error
{
  $where = [];
  $types = '';
  $vals  = [];
  $where[] = 'seller_email = ?'; $types .= 's'; $vals[] = $sellerEmail;
  if ($clientQ !== '') { $where[] = 'client_name LIKE ?'; $types .= 's'; $vals[] = '%'.$clientQ.'%'; }
  if ($startTs !== '' && $endTs !== '') { $where[] = 'created_at BETWEEN ? AND ?'; $types .= 'ss'; $vals[] = $startTs; $vals[] = $endTs; }
  elseif ($startTs !== '') { $where[] = 'created_at >= ?'; $types .= 's'; $vals[] = $startTs; }
  elseif ($endTs !== '') { $where[] = 'created_at <= ?'; $types .= 's'; $vals[] = $endTs; }

  $sql = 'SELECT o.id, o.created_at, o.seller_name, o.seller_email, o.client_name, o.client_phone, o.client_email, o.company_slug, o.order_total, o.send_status
          FROM `orders` o';
  if (!empty($where)) { $sql .= ' WHERE ' . implode(' AND ', $where); }
  $sql .= ' ORDER BY o.created_at DESC, o.id DESC';

  $stmt = $conn->prepare($sql);
  if ($stmt) {
    if (!empty($types)) {
      $bind = [];
      $bind[] = & $types;
      foreach ($vals as $k => $v) { $bind[] = & $vals[$k]; }
      call_user_func_array([$stmt, 'bind_param'], $bind);
    }
    if ($stmt->execute()) {
      $res = $stmt->get_result();
      while ($r = $res->fetch_assoc()) { $rows[] = $r; }
      $res->free();
    } else { $erro = 'Falha ao buscar pedidos.'; }
    $stmt->close();
  } else { $erro = 'Falha ao preparar busca de pedidos.'; }
}

// Carregar itens em lote
if (empty($erro) && !empty($rows)) {
  $ids = array_map(function($r){ return (int)$r['id']; }, $rows);
  $ids = array_values(array_unique(array_filter($ids)));
  if (!empty($ids)) {
    // Montar placeholders seguros para IN
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sqlItems = 'SELECT order_id, qty, product_code, product_description, unit_value, (qty * unit_value) AS line_total FROM `order_items` WHERE order_id IN (' . $placeholders . ') ORDER BY order_id ASC, id ASC';
    $stmt2 = $conn->prepare($sqlItems);
    if ($stmt2) {
      // bind_param requer referências
      $bindParams = [];
      $bindParams[] = & $types;
      foreach ($ids as $k => $v) { $bindParams[] = & $ids[$k]; }
      call_user_func_array([$stmt2, 'bind_param'], $bindParams);
      if ($stmt2->execute()) {
        $res2 = $stmt2->get_result();
        while ($it = $res2->fetch_assoc()) {
          $oid = (int)$it['order_id'];
          if (!isset($itemsByOrder[$oid])) { $itemsByOrder[$oid] = []; }
          $itemsByOrder[$oid][] = $it;
        }
        $res2->free();
      }
      $stmt2->close();
    }
  }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Catalog Seller - Orders</title>
  <style>
    :root { --bg:#0f172a; --muted:#94a3b8; --text:#e2e8f0; --pri:#22c55e; --pri2:#16a34a; }
    *{box-sizing:border-box}
    body{margin:0; font-family: system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,"Helvetica Neue",Arial; background: radial-gradient(1200px 800px at 80% -10%, #1e293b, transparent), var(--bg); color:var(--text); min-height:100vh;}
    .container{max-width:1100px; margin:24px auto; padding:0 24px}
    .nav{display:flex; align-items:center; justify-content:space-between; gap:16px; padding:12px 16px; border:1px solid rgba(148,163,184,.14); border-radius:12px; background: rgba(255,255,255,.02); box-shadow: 0 4px 12px rgba(0,0,0,.18)}
    .nav-left{display:flex; align-items:center; gap:10px; flex:0 0 auto}
    .nav-center{display:flex; align-items:center; justify-content:center; flex:1}
    .nav-right{display:flex; align-items:center; gap:10px; flex:0 0 auto}
    .brandRow{display:flex; align-items:center; gap:10px}
    .logo{display:block; height:20px; width:auto; background:#fff; padding:2px; border-radius:6px; border:1px solid rgba(148,163,184,.25)}
    .logo-company{display:block; height:32px; width:auto; background:#fff; padding:3px; border-radius:6px; border:1px solid rgba(148,163,184,.25)}

    .card{margin-top:22px; padding:18px; border:1px solid rgba(148,163,184,.16); border-radius:14px; background: rgba(255,255,255,.02); box-shadow: 0 6px 16px rgba(0,0,0,.20)}
    .tableWrap{width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch}
    table{width:100%; min-width: 860px; border-collapse:separate; border-spacing:0 8px}
    th, td{padding:10px 12px; text-align:left}
    table td{font-size:13px}
    thead th{font-size:13px; color:var(--muted)}
    tbody tr{background: rgba(255,255,255,.02); box-shadow: 0 2px 8px rgba(0,0,0,.14); border:1px solid rgba(148,163,184,.14);}
    tbody tr:hover{background: rgba(255,255,255,.03)}
    tbody tr td:first-child{border-top-left-radius:12px; border-bottom-left-radius:12px}
    tbody tr td:last-child{border-top-right-radius:12px; border-bottom-right-radius:12px}
    .btn{appearance:none; display:inline-flex; align-items:center; justify-content:center; gap:6px; border:1px solid #3b4252; background:#0f172a; color:#e5e7eb; font-weight:600; font-size:13px; height:30px; padding:0 10px; border-radius:8px; cursor:pointer; text-decoration:none}
    .btn:hover{background:#0b1220}
    .btn-sm{height:28px; padding:0 10px; font-size:13px; min-width:92px}
    .btn.btn-secondary:hover{background:rgba(255,255,255,.05)}
    .actions-cell{display:flex; flex-direction:column; align-items:flex-start; gap:8px; justify-content:center}
    .muted{color:var(--muted); font-size:13px}
    .sub{font-size:13px; color:var(--muted)}
    .nowrap{white-space:nowrap}
    .items{padding:10px 12px 16px 12px}
    .items table{min-width: 760px}
    .items table td{font-size:13px}
    .items table tbody tr{background: rgba(234,179,8,.06); border:1px solid rgba(234,179,8,.22)}
    .items table tbody tr:hover{background: rgba(234,179,8,.10)}
    /* Right align price columns */
    .items table th:nth-child(4),
    .items table td:nth-child(4),
    .items table th:nth-child(5),
    .items table td:nth-child(5){ text-align:right }
    .td-date{font-size:13px}
    .tag{display:inline-block; padding:2px 8px; border-radius:999px; font-size:13px; border:1px solid rgba(148,163,184,.28)}
    .td-total{font-size:13px}
    .tag.pending{color:#f59e0b; border-color:rgba(245,158,11,.45)}
    .tag.sent{color:#22c55e; border-color:rgba(34,197,94,.45)}
    .tag.error{color:#ef4444; border-color:rgba(239,68,68,.45)}

    /* Filters UI */
    .filters{display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin:8px 0 14px 0; padding:10px; border:1px solid rgba(148,163,184,.14); border-radius:12px; background:rgba(255,255,255,.03)}
    .filters .field{display:flex; flex-direction:column}
    .filters input[type=date], .filters input[type=text]{height:34px; padding:8px 10px; border-radius:10px; border:1px solid rgba(148,163,184,.22); background:#0b1220; color:var(--text); font-size:13px}
    .filters .btn{height:34px; font-size:13px; padding:0 12px}
    .quick{display:flex; gap:8px; align-items:center; flex-wrap:wrap}
    .chip{appearance:none; border:1px solid rgba(148,163,184,.28); background:transparent; color:var(--text); border-radius:999px; padding:4px 10px; font-size:13px; cursor:pointer}
    .chip:hover{background:rgba(255,255,255,.05)}
    .btn-secondary{background:transparent}
  </style>
</head>
<body>
  <div class="container">
    <header class="nav">
      <div class="nav-left">
        <div class="brandRow"><img src="logo.png" alt="Catalog Seller logo" class="logo"><div style="font-weight:800">Catalog Seller</div></div>
      </div>

  

      <div class="nav-center">
        <img src="<?php echo htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Company logo" class="logo-company">
      </div>
      <div class="nav-right">
        <a href="dashboard.php" class="btn">Back</a>
      </div>
    </header>

    <section class="card">
      <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap">
        <h2 style="margin:0 0 8px 0">Orders</h2>
      </div>

      <form method="get" class="filters">
        <div class="field">
          <label class="muted" for="start">Start</label>
          <input id="start" type="date" name="start" value="<?php echo htmlspecialchars($startRaw, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="field">
          <label class="muted" for="end">End</label>
          <input id="end" type="date" name="end" value="<?php echo htmlspecialchars($endRaw, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="field" style="min-width:220px; flex:1 1 240px">
          <label class="muted" for="client">Client</label>
          <input id="client" type="text" name="client" placeholder="Client name" value="<?php echo htmlspecialchars($clientQ, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="quick" style="margin-left:auto">
          <button type="button" class="chip" data-range="today">Today</button>
          <button type="button" class="chip" data-range="7">Last 7d</button>
          <button type="button" class="chip" data-range="30">Last 30d</button>
          <button type="button" class="chip" data-range="month">This month</button>
          <button type="button" class="chip" data-range="clear">Clear dates</button>
        </div>
        <div style="display:flex; gap:8px; margin-left:auto">
          <button class="btn" type="submit">Apply</button>
          <a class="btn btn-secondary" href="orders.php">Clear</a>
        </div>
      </form>
      <?php if ($erro): ?><div class="muted" style="color:#ef4444; margin-bottom:8px"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

      <div class="tableWrap">
        <table>
          <thead>
            <tr>
              <th style="width:60px">ID</th>
              <th class="col-date">Date</th>
              <th>Client</th>
              <th style="width:160px">Contact</th>
              <th style="width:160px; text-align:center" class="nowrap">Total</th>
              <th style="width:100px; text-align:center">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!empty($rows)): ?>
            <?php foreach ($rows as $r): $oid=(int)$r['id']; $items = $itemsByOrder[$oid] ?? []; ?>
            <tr data-oid="<?php echo $oid; ?>">
              <td class="nowrap">#<?php echo $oid; ?></td>
              <td class="nowrap td-date"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($r['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
              <td>
                <div class="ellipsis"><?php echo htmlspecialchars($r['client_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="sub ellipsis">Company: <?php echo htmlspecialchars((string)$r['company_slug'], ENT_QUOTES, 'UTF-8'); ?></div>
                
              </td>
              <td>
                <div class="sub ellipsis"><?php echo htmlspecialchars($r['client_email'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="sub ellipsis"><?php echo htmlspecialchars($r['client_phone'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></div>
              </td>
              <td class="nowrap td-total" style="text-align:center">$ <?php echo number_format((float)$r['order_total'], 2); ?></td>
              <td class="actions-cell">
                <button class="btn btn-sm" type="button" data-toggle="<?php echo $oid; ?>">View items</button>
              </td>
            </tr>
            <tr class="items" id="items-<?php echo $oid; ?>" style="display:none">
              <td colspan="6">
                <?php if (empty($items)): ?>
                  <div class="sub">No items.</div>
                <?php else: ?>
                <div class="tableWrap">
                  <table>
                    <thead>
                      <tr>
                        <th style="width:100px">Code</th>
                        <th>Product</th>
                        <th style="width:100px">Qty</th>
                        <th style="width:120px">Unit price</th>
                        <th style="width:120px">Subtotal</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php $sum=0.0; foreach ($items as $it): $sum += (float)$it['line_total']; ?>
                      <tr>
                        <td class="nowrap"><?php echo htmlspecialchars($it['product_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($it['product_description'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="nowrap"><?php echo (int)$it['qty']; ?></td>
                        <td class="nowrap">$ <?php echo number_format((float)$it['unit_value'], 2); ?></td>
                        <td class="nowrap">$ <?php echo number_format((float)$it['line_total'], 2); ?></td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="6" class="muted">No orders found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

<script>
(function(){
  function fmt(d){ var p=n=>String(n).padStart(2,'0'); return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate()); }
  var start = document.getElementById('start');
  var end = document.getElementById('end');
  document.querySelectorAll('.chip[data-range]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var r = btn.getAttribute('data-range');
      var today = new Date();
      var s = '', e = '';
      if (r === 'today') { s = fmt(today); e = fmt(today); }
      else if (r === '7') { var d = new Date(today); d.setDate(d.getDate()-6); s = fmt(d); e = fmt(today); }
      else if (r === '30') { var d2 = new Date(today); d2.setDate(d2.getDate()-29); s = fmt(d2); e = fmt(today); }
      else if (r === 'month') { var m = new Date(today.getFullYear(), today.getMonth(), 1); s = fmt(m); e = fmt(today); }
      else if (r === 'clear') { s=''; e=''; }
      if (start) start.value = s;
      if (end) end.value = e;
    });
  });
})();
</script>

<script>
  document.addEventListener('click', function(e){
    var btn = e.target.closest('button[data-toggle]');
    if (!btn) return;
    var id = btn.getAttribute('data-toggle');
    var row = document.getElementById('items-'+id);
    if (!row) return;
    var visible = row.style.display !== 'none';
    row.style.display = visible ? 'none' : '';
    btn.textContent = visible ? 'View items' : 'Hide items';
  });
  
</script>
</body>
</html>
