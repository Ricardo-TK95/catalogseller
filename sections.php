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

$erro = '';
$ok = '';
$flash_ok = '';
if (!empty($_SESSION['flash_ok'])) { $flash_ok = (string)$_SESSION['flash_ok']; unset($_SESSION['flash_ok']); }

function normalize_order($v){ $v = trim((string)$v); return ($v === '' ? null : max(0, (int)$v)); }

// Handle POST actions: create/update/delete/toggle
$company_id = 1; // fixed for now (same approach as sales_reps.php)

// Buscar logo da company
$logo_dir = __DIR__ . '/img_logo';
$logo_url = 'logo.png'; // fallback padrão
$pattern = $logo_dir . '/logo_' . $company_id . '.*';
$existing_files = glob($pattern);
if (!empty($existing_files) && is_file($existing_files[0])) {
    $logo_file = basename($existing_files[0]);
    $logo_url = 'img_logo/' . $logo_file;
}

$action = isset($_POST['action']) ? $_POST['action'] : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST[$csrf_name]) || !hash_equals($_SESSION[$csrf_name], (string)$_POST[$csrf_name])) {
        $erro = 'Sessão expirada. Recarregue a página.';
    } else {
        if ($action === 'create' || $action === 'update') {
            $description = isset($_POST['description']) ? trim((string)$_POST['description']) : '';
            $is_active = isset($_POST['is_active']) && $_POST['is_active'] === '1' ? 1 : 0;
            $display_order = normalize_order(isset($_POST['display_order']) ? $_POST['display_order'] : '');
            if ($description === '') { $erro = 'Section é obrigatória.'; }
            if ($erro === '') {
                if ($action === 'create') {
                    $sql = 'INSERT INTO sections (company_id, description, is_active, display_order, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())';
                    if ($stmt = $conn->prepare($sql)) {
                        $stmt->bind_param('isii', $company_id, $description, $is_active, $display_order);
                        if ($stmt->execute()) { $_SESSION['flash_ok'] = 'Section created successfully.'; header('Location: sections.php'); exit; } else { $erro = 'Falha ao criar.'; }
                        $stmt->close();
                    } else { $erro = 'Falha ao preparar criação.'; }
                } else {
                    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
                    $sql = 'UPDATE sections SET description=?, is_active=?, display_order=?, updated_at=NOW() WHERE id=? AND company_id=?';
                    if ($stmt = $conn->prepare($sql)) {
                        $stmt->bind_param('siiii', $description, $is_active, $display_order, $id, $company_id);
                        if ($stmt->execute()) { $_SESSION['flash_ok'] = 'Section updated.'; header('Location: sections.php'); exit; } else { $erro = 'Falha ao atualizar.'; }
                        $stmt->close();
                    } else { $erro = 'Falha ao preparar atualização.'; }
                }
            }
        }
 elseif ($action === 'toggle') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $to = isset($_POST['to']) && $_POST['to'] === '1' ? 1 : 0;
            $sql = 'UPDATE sections SET is_active=?, updated_at=NOW() WHERE id=? AND company_id=?';
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param('iii', $to, $id, $company_id);
                if ($stmt->execute()) { header('Location: sections.php'); exit; } else { $erro = 'Falha ao atualizar status.'; }
                $stmt->close();
            } else { $erro = 'Falha ao preparar status.'; }
        } elseif ($action === 'delete') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $sql = 'DELETE FROM sections WHERE id=? AND company_id=?';
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param('ii', $id, $company_id);
                if ($stmt->execute()) { $_SESSION['flash_ok'] = 'Section removed.'; header('Location: sections.php'); exit; } else { $erro = 'Falha ao remover.'; }
                $stmt->close();
            } else { $erro = 'Falha ao preparar remoção.'; }
        } elseif ($action === 'delete_all') {
            $sql = 'DELETE FROM sections WHERE company_id=?';
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param('i', $company_id);
                if ($stmt->execute()) { $_SESSION['flash_ok'] = 'All sections removed.'; header('Location: sections.php'); exit; } else { $erro = 'Falha ao remover todas as seções.'; }
                $stmt->close();
            } else { $erro = 'Falha ao preparar remoção.'; }
        }
    }
}

// Read for list
$rows = [];
// Filters
$filter_name = isset($_GET['filter_name']) ? trim((string)$_GET['filter_name']) : '';
$filter_active = isset($_GET['filter_active']) ? trim((string)$_GET['filter_active']) : '';
// Sorting
$sort = isset($_GET['sort']) ? strtolower((string)$_GET['sort']) : '';
$dir  = isset($_GET['dir'])  ? strtolower((string)$_GET['dir'])  : 'asc';
$dir  = ($dir === 'desc') ? 'DESC' : 'ASC';
$allowed = [ 'id' => 'id', 'description' => 'description', 'display_order' => 'display_order', 'is_active' => 'is_active' ];
$orderSql = 'COALESCE(display_order, 999999) ASC, id ASC';
if (isset($allowed[$sort])) {
  $col = $allowed[$sort];
  if ($col === 'display_order') { $orderSql = "COALESCE(display_order, 999999) $dir, id ASC"; }
  else { $orderSql = "$col $dir, id ASC"; }
}
$sqlList = 'SELECT id, description, is_active, COALESCE(display_order, 999999) AS ord, display_order FROM sections WHERE company_id=?';
$types = 'i';
$params = [$company_id];
if ($filter_name !== '') { $sqlList .= ' AND UPPER(description) LIKE ?'; $types .= 's'; $params[] = '%' . strtoupper($filter_name) . '%'; }
if ($filter_active !== '') { $sqlList .= ' AND is_active = ?'; $types .= 'i'; $params[] = ($filter_active === '1') ? 1 : 0; }
$sqlList .= ' ORDER BY ' . $orderSql;
if ($stmt = $conn->prepare($sqlList)) {
    $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $res->free();
    }
    $stmt->close();
}

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Catalog Seller - Sections</title>
  <style>
    :root { --bg:#0f172a; --muted:#94a3b8; --text:#e2e8f0; --pri:#22c55e; --pri2:#16a34a; }
    *{box-sizing:border-box}
    body{margin:0; font-family: system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,"Helvetica Neue",Arial; background: radial-gradient(1200px 800px at 80% -10%, #1e293b, transparent), var(--bg); color:var(--text); min-height:100vh;}
    .container{max-width:1000px; margin:24px auto; padding:0 24px}
    .nav{display:flex; align-items:center; justify-content:space-between; gap:16px; padding:12px 16px; border:1px solid rgba(148,163,184,.14); border-radius:12px; background: rgba(255,255,255,.02); box-shadow: 0 4px 12px rgba(0,0,0,.18)}
    .nav-left{display:flex; align-items:center; gap:10px; flex:0 0 auto}
    .nav-center{display:flex; align-items:center; justify-content:center; flex:1}
    .nav-right{display:flex; align-items:center; gap:10px; flex:0 0 auto}
    .brandRow{display:flex; align-items:center; gap:10px}
    .logo{display:block; height:20px; width:auto; background:#fff; padding:2px; border-radius:6px; border:1px solid rgba(148,163,184,.25)}
    .logo-company{display:block; height:32px; width:auto; background:#fff; padding:3px; border-radius:6px; border:1px solid rgba(148,163,184,.25)}
    .card{margin-top:22px; padding:18px; border:1px solid rgba(148,163,184,.16); border-radius:14px; background: rgba(255,255,255,.02); box-shadow: 0 6px 16px rgba(0,0,0,.20)}
    .grid2{display:grid; grid-template-columns: 1fr; gap:12px}
    .rowForm{display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap}
    input[type=text], input[type=number]{padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,.22); background:#0b1220; color:var(--text)}
    .btn{appearance:none; display:inline-flex; align-items:center; justify-content:center; gap:6px; border:1px solid #3b4252; background:#0f172a; color:#e5e7eb; font-weight:600; font-size:13px; height:32px; padding:0 12px; border-radius:8px; cursor:pointer; box-shadow:none; text-decoration:none; line-height:1.2}
    .btn:hover{background:#0b1220}
    .btn-outline{background:transparent}
    .btn-danger{border-color:#5b616e; background:#141a23; color:#e5e7eb}
    .btn-danger:hover{background:#0f141c}
    .muted{color:var(--muted)}
    .tableWrap{width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch}
    table{width:100%; min-width: 760px; border-collapse:separate; border-spacing:0 8px}
    th, td{padding:10px 12px; text-align:left}
    thead th{font-size:12px; color:var(--muted)}
    tbody tr{background: rgba(255,255,255,.02); box-shadow: 0 2px 8px rgba(0,0,0,.14); border:1px solid rgba(148,163,184,.14);}
    tbody tr:hover{background: rgba(255,255,255,.03)}
    tbody tr td:first-child{border-top-left-radius:12px; border-bottom-left-radius:12px}
    tbody tr td:last-child{border-top-right-radius:12px; border-bottom-right-radius:12px}
    .toggle{position:relative; width:44px; height:24px; display:inline-block}
    .toggle input{opacity:0; width:0; height:0}
    .slider{position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background:#0f172a; border:1px solid rgba(148,163,184,.25); border-radius:999px; transition:.15s}
    .slider:before{content:""; position:absolute; height:20px; width:20px; left:1px; top:1px; background:#475569; border-radius:999px; transition:.15s}
    .toggle input:checked + .slider{background:linear-gradient(180deg, #22c55e, #16a34a); border-color:rgba(34,197,94,.6)}
    .toggle input:checked + .slider:before{transform:translateX(20px); background:#052e16}
    .actions{display:flex; gap:8px; align-items:center}
    /* toast styles */
    .msg{margin:8px 0 0; font-size:14px}
    .error{color:#ef4444}
    .ok{color:#22c55e}
    .toast-overlay{position:fixed; inset:0; display:flex; align-items:center; justify-content:center; background:rgba(2,6,23,.55); backdrop-filter: blur(4px); z-index:9999}
    .toast{min-width:300px; max-width:92vw; padding:16px 18px; border-radius:14px; border:1px solid rgba(148,163,184,.18); background: linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02)); color:var(--text); box-shadow: 0 10px 26px rgba(0,0,0,.35)}
    .toast.success{border-color:rgba(34,197,94,.55)}
    .toast.error{border-color:rgba(239,68,68,.55)}
    .toast-head{display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:6px; font-weight:700}
    .toast-close{appearance:none; border:0; background:transparent; color:var(--text); font-size:18px; line-height:1; cursor:pointer; padding:2px 6px; border-radius:8px}
    .toast-close:hover{background:rgba(255,255,255,.06)}
    /* Uppercase input */
    input.uppercase{ text-transform: uppercase }
    /* Quick actions (Import) */
    .quick-actions{position:absolute; right:20px; top:22px; display:flex; flex-direction:row; gap:12px; align-items:center}
    .linkAccent{color:#facc15; text-decoration:none; font-size:14px}
    .linkAccent:hover{text-decoration:underline}
    .linkBlue{color:#38bdf8; text-decoration:none; font-size:14px}
    .linkBlue:hover{text-decoration:none}
    /* Custom confirmation modal */
    .confirm-modal-overlay{position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(2,6,23,.75); backdrop-filter: blur(4px); z-index:10000}
    .confirm-modal-overlay.show{display:flex}
    .confirm-modal{min-width:400px; max-width:90vw; padding:0; border-radius:14px; border:1px solid rgba(148,163,184,.18); background: linear-gradient(180deg, rgba(22,163,74,.95), rgba(20,83,45,.95)); color:#fff; box-shadow: 0 10px 26px rgba(0,0,0,.5); overflow:hidden}
    .confirm-modal-header{padding:18px 20px; border-bottom:1px solid rgba(255,255,255,.15); font-weight:700; font-size:16px}
    .confirm-modal-body{padding:20px; font-size:14px; line-height:1.5}
    .confirm-modal-footer{padding:16px 20px; border-top:1px solid rgba(255,255,255,.15); display:flex; gap:10px; justify-content:flex-end}
    .confirm-modal-btn{appearance:none; padding:8px 16px; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; border:1px solid rgba(255,255,255,.2); transition:all 0.2s}
    .confirm-modal-btn-cancel{background:rgba(255,255,255,.1); color:#fff; border-color:rgba(255,255,255,.3)}
    .confirm-modal-btn-cancel:hover{background:rgba(255,255,255,.2)}
    .confirm-modal-btn-ok{background:rgba(255,255,255,.25); color:#fff; border-color:rgba(255,255,255,.4)}
    .confirm-modal-btn-ok:hover{background:rgba(255,255,255,.35)}
  </style>
</head>
<body>
  <!-- Custom confirmation modal -->
  <div id="confirmModal" class="confirm-modal-overlay">
    <div class="confirm-modal">
      <div class="confirm-modal-header" id="confirmModalTitle">catalogseller.com says</div>
      <div class="confirm-modal-body" id="confirmModalMessage"></div>
      <div class="confirm-modal-footer">
        <button type="button" class="confirm-modal-btn confirm-modal-btn-cancel" id="confirmModalCancel" autofocus>Cancel</button>
        <button type="button" class="confirm-modal-btn confirm-modal-btn-ok" id="confirmModalOk">OK</button>
      </div>
    </div>
  </div>
  <!-- Add Section Modal -->
  <div id="addSecModal" class="confirm-modal-overlay" aria-hidden="true" style="display:none; align-items:center; justify-content:center; background:rgba(2,6,23,.75);">
    <div class="confirm-modal" style="min-width:420px; background:linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02)); color:var(--text); border-color:rgba(148,163,184,.18)">
      <div class="confirm-modal-header">Add Section</div>
      <form method="post" id="addSecForm">
        <div class="confirm-modal-body">
          <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="action" value="create">
          <div class="rowForm">
            <div style="flex:1 1 320px">
              <label class="muted" for="sec_desc_new">Section</label>
              <input id="sec_desc_new" class="uppercase" type="text" name="description" placeholder="Ex.: New Arrivals" required style="width:100%">
            </div>
            <div>
              <label class="muted" for="sec_order_new">Display order</label>
              <input id="sec_order_new" type="number" name="display_order" min="0" placeholder="0" value="100">
            </div>
            <div style="display:flex; flex-direction:column; gap:6px">
              <label class="muted">Active</label>
              <label class="toggle">
                <input type="checkbox" name="is_active" value="1" checked>
                <span class="slider"></span>
              </label>
            </div>
          </div>
        </div>
        <div class="confirm-modal-footer">
          <button type="button" class="confirm-modal-btn confirm-modal-btn-cancel" id="btnCancelAddSec">Cancel</button>
          <button type="submit" class="confirm-modal-btn confirm-modal-btn-ok">Save</button>
        </div>
      </form>
    </div>
  </div>
  <div class="container">
    <header class="nav">
      <div class="nav-left">
        <div class="brandRow"><img src="logo.png" alt="Catalog Seller logo" class="logo"><div style="font-weight:800">Catalog Seller</div></div>
      </div>
      <div class="nav-center">
        <img src="<?php echo htmlspecialchars($logo_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Company logo" class="logo-company">
      </div>
      <div class="nav-right">
        <a href="dashboard.php" class="btn" style="padding:8px 12px">Back</a>
      </div>
    </header>

    <section class="card" style="position:relative">
      <h2 style="margin:0 0 10px 0">Sections</h2>
      <div class="quick-actions">
        <a href="#" class="linkBlue" id="lnkAddSection">Add Section</a>
        <a class="linkAccent" href="import_sections.php">Import Sections</a>
        <form method="post" id="deleteAllForm" style="display:inline; margin:0">
          <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="action" value="delete_all">
          <button type="button" class="linkAccent" id="deleteAllBtn" style="background:none; border:none; padding:0; cursor:pointer; font-size:14px; color:#ef4444">Delete All</button>
        </form>
      </div>
      <?php if ($erro): ?><div class="msg error" style="color:#ef4444; margin-bottom:8px"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="msg ok" style="color:#22c55e; margin-bottom:8px"><?php echo htmlspecialchars($ok, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

      <!-- Filtros -->
      <form method="get" class="rowForm" style="margin-bottom:16px; padding:12px; background:rgba(255,255,255,.02); border:1px solid rgba(148,163,184,.1); border-radius:8px">
        <div style="flex:1 1 300px">
          <label class="muted" for="filter_name">Filter by Name</label>
          <input id="filter_name" class="uppercase" type="text" name="filter_name" placeholder="" value="<?php echo htmlspecialchars($filter_name, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div style="flex:0 0 180px">
          <label class="muted" for="filter_active">Filter by Active</label>
          <select id="filter_active" name="filter_active" style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,.22); background:#0b1220; color:var(--text)">
            <option value="">All</option>
            <option value="1" <?php echo ($filter_active === '1' ? 'selected' : ''); ?>>Active</option>
            <option value="0" <?php echo ($filter_active === '0' ? 'selected' : ''); ?>>Inactive</option>
          </select>
        </div>
        <div style="display:flex; align-items:flex-end; gap:8px">
          <button class="btn" type="submit">Filter</button>
          <?php if ($filter_name !== '' || $filter_active !== ''): ?>
            <a href="sections.php<?php echo ($sort !== '' ? '?sort=' . htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') . '&dir=' . htmlspecialchars(strtolower($dir), ENT_QUOTES, 'UTF-8') : ''); ?>" class="btn btn-outline">Clear</a>
          <?php endif; ?>
        </div>
        <?php if ($sort !== ''): ?>
          <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="dir" value="<?php echo htmlspecialchars(strtolower($dir), ENT_QUOTES, 'UTF-8'); ?>">
        <?php endif; ?>
      </form>

      <!-- Removed inline add; use Add Section link (modal) -->

      <!-- List -->
      <div class="grid2">
        <div class="tableWrap">
        <table>
          <thead>
            <?php 
              $q = function($s) use ($sort, $dir){ $next = ($sort===$s && $dir==='asc')?'desc':'asc'; return 'sections.php?sort='.$s.'&dir='.$next; };
              $arrow = function($s) use ($sort, $dir){ if($sort!==$s) return ''; return $dir==='ASC'?' ▲':' ▼'; };
            ?>
            <tr>
              <th style="width:56px"><a href="<?php echo htmlspecialchars($q('id'), ENT_QUOTES, 'UTF-8'); ?>" class="muted" style="text-decoration:none">ID<?php echo $arrow('id'); ?></a></th>
              <th><a href="<?php echo htmlspecialchars($q('description'), ENT_QUOTES, 'UTF-8'); ?>" class="muted" style="text-decoration:none">Section<?php echo $arrow('description'); ?></a></th>
              <th style="width:140px"><a href="<?php echo htmlspecialchars($q('display_order'), ENT_QUOTES, 'UTF-8'); ?>" class="muted" style="text-decoration:none">Display order<?php echo $arrow('display_order'); ?></a></th>
              <th style="width:120px"><a href="<?php echo htmlspecialchars($q('is_active'), ENT_QUOTES, 'UTF-8'); ?>" class="muted" style="text-decoration:none">Active<?php echo $arrow('is_active'); ?></a></th>
              <th style="width:180px">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): $isEditing = ($editId === (int)$r['id']); $formId = 'fsec'.(int)$r['id']; ?>
            <?php if ($isEditing): ?><form id="<?php echo $formId; ?>" method="post"></form><?php endif; ?>
            <tr>
              <?php if ($isEditing): ?>
                  <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>" form="<?php echo $formId; ?>">
                  <input type="hidden" name="action" value="update" form="<?php echo $formId; ?>">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>" form="<?php echo $formId; ?>">
                  <td><?php echo (int)$r['id']; ?></td>
                  <td><input type="text" class="uppercase" name="description" value="<?php echo htmlspecialchars($r['description'], ENT_QUOTES, 'UTF-8'); ?>" required form="<?php echo $formId; ?>"></td>
                  <td><input type="number" name="display_order" min="0" value="<?php echo htmlspecialchars($r['display_order'], ENT_QUOTES, 'UTF-8'); ?>" form="<?php echo $formId; ?>"></td>
                  <td>
                    <input type="hidden" name="is_active" value="0" form="<?php echo $formId; ?>">
                    <label class="toggle">
                      <input type="checkbox" name="is_active" value="1" <?php echo ($r['is_active'] ? 'checked' : ''); ?> aria-label="Toggle active" form="<?php echo $formId; ?>">
                      <span class="slider"></span>
                    </label>
                  </td>
                  <td class="actions">
                    <button class="btn" type="submit" form="<?php echo $formId; ?>">Save</button>
                    <a class="btn btn-outline" href="sections.php">Cancel</a>
                  </td>
              <?php else: ?>
                <td><?php echo (int)$r['id']; ?></td>
                <td><?php echo htmlspecialchars($r['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($r['display_order'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                  <form method="post" style="margin:0; display:inline-flex; align-items:center; gap:8px" class="toggle-form">
                    <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="to" value="<?php echo ($r['is_active'] ? '1' : '0'); ?>">
                    <label class="toggle">
                      <input type="checkbox" <?php echo ($r['is_active'] ? 'checked' : ''); ?> aria-label="Toggle active">
                      <span class="slider"></span>
                    </label>
                  </form>
                </td>
                <td class="actions">
                  <a class="btn" href="sections.php?edit=<?php echo (int)$r['id']; ?>">Edit</a>
                  <form method="post" onsubmit="return confirm('Remove this section?');" style="display:inline">
                    <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <button type="submit" class="btn btn-danger">Delete</button>
                  </form>
                </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
            <tr>
              <td colspan="5" class="muted">No sections found.</td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>
        </div>
      </div>
    </section>
  </div>
<script>
// Toast overlay using existing .msg elements
(function(){
  var existing = document.querySelector('.msg.ok, .msg.error');
  if(!existing) return;
  var text = existing.textContent.trim();
  if(!text) return;
  existing.style.display='none';
  var overlay = document.createElement('div');
  overlay.className='toast-overlay';
  var box = document.createElement('div');
  box.className='toast ' + (existing.classList.contains('error')?'error':'success');
  var head=document.createElement('div'); head.className='toast-head'; head.innerHTML='<span>'+(existing.classList.contains('error')?'Erro':'Sucesso')+'</span>';
  var btn=document.createElement('button'); btn.className='toast-close'; btn.setAttribute('aria-label','Fechar'); btn.innerHTML='\u00D7';
  var body=document.createElement('div'); body.textContent=text;
  function close(){ if(!overlay) return; overlay.remove(); overlay=null; }
  btn.addEventListener('click', close);
  overlay.addEventListener('click', function(e){ if(e.target===overlay) close(); });
  document.addEventListener('keydown', function(ev){ if(ev.key==='Escape') close(); });
  head.appendChild(btn); box.appendChild(head); box.appendChild(body); overlay.appendChild(box); document.body.appendChild(overlay);
  setTimeout(close, 3000);
})();
</script>
<script>
  (function(){
    document.querySelectorAll('.toggle-form').forEach(function(form){
      var cb = form.querySelector('input[type="checkbox"]');
      var to = form.querySelector('input[name="to"]');
      if (!cb || !to) return;
      cb.addEventListener('change', function(){
        to.value = cb.checked ? '1' : '0';
        form.submit();
      });
    });
  })();
</script>
<script>
// Force uppercase while typing for section description inputs
document.addEventListener('input', function(ev){
  var el = ev.target;
  if (el && el.matches('input.uppercase')) {
    var start = el.selectionStart, end = el.selectionEnd;
    var up = (el.value || '').toUpperCase();
    if (el.value !== up) { el.value = up; try { el.setSelectionRange(start, end); } catch(e){} }
  }
});
// Custom confirmation function with Cancel as default
function customConfirm(message, title) {
  return new Promise(function(resolve) {
    var modal = document.getElementById('confirmModal');
    var titleEl = document.getElementById('confirmModalTitle');
    var messageEl = document.getElementById('confirmModalMessage');
    var cancelBtn = document.getElementById('confirmModalCancel');
    var okBtn = document.getElementById('confirmModalOk');
    
    titleEl.textContent = title || 'catalogseller.com says';
    messageEl.textContent = message;
    modal.classList.add('show');
    
    // Focus Cancel button (default)
    setTimeout(function() { cancelBtn.focus(); }, 100);
    
    var cleanup = function() {
      modal.classList.remove('show');
      cancelBtn.onclick = null;
      okBtn.onclick = null;
      cancelBtn.onkeydown = null;
      okBtn.onkeydown = null;
    };
    
    cancelBtn.onclick = function() {
      cleanup();
      resolve(false);
    };
    
    okBtn.onclick = function() {
      cleanup();
      resolve(true);
    };
    
    // Handle Enter key - Cancel is default, so Enter cancels
    cancelBtn.onkeydown = function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        cancelBtn.click();
      }
    };
    
    okBtn.onkeydown = function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        okBtn.click();
      }
    };
    
    // Handle Escape key to cancel
    var escapeHandler = function(e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        cleanup();
        document.removeEventListener('keydown', escapeHandler);
        resolve(false);
      }
    };
    document.addEventListener('keydown', escapeHandler);
  });
}

// Double confirmation to delete all sections
document.addEventListener('DOMContentLoaded', function(){
  var btn = document.getElementById('deleteAllBtn');
  var form = document.getElementById('deleteAllForm');
  if (!btn || !form) return;
  btn.addEventListener('click', async function(e){
    e.preventDefault();
    // First confirmation
    var first = await customConfirm('WARNING: Do you really want to delete ALL sections? This action cannot be undone.', 'catalogseller.com says');
    if (!first) return;
    // Second confirmation
    var second = await customConfirm('FINAL CONFIRMATION: Are you absolutely sure? All sections will be permanently deleted.', 'catalogseller.com says');
    if (second) {
      form.submit();
    }
  });
});
</script>
<script>
// Open/close Add Section modal
document.addEventListener('DOMContentLoaded', function(){
  var link = document.getElementById('lnkAddSection');
  var modal = document.getElementById('addSecModal');
  var cancel = document.getElementById('btnCancelAddSec');
  var input = document.getElementById('sec_desc_new');
  function open(){ if(modal){ modal.style.display='flex'; modal.setAttribute('aria-hidden','false'); setTimeout(function(){ try{ input && input.focus(); }catch(e){} }, 100);} }
  function close(){ if(modal){ modal.style.display='none'; modal.setAttribute('aria-hidden','true'); } }
  if (link) link.addEventListener('click', function(e){ e.preventDefault(); open(); });
  if (cancel) cancel.addEventListener('click', close);
  if (modal) modal.addEventListener('click', function(e){ if(e.target===modal) close(); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') close(); });
});
</script>
</body>
</html>
