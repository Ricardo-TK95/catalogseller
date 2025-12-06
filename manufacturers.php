<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$arquivoConfig = __DIR__ . '/config.php';
if (!file_exists($arquivoConfig)) { die('config.php not found'); }
require_once $arquivoConfig;

$csrf_name = '_csrf';
if (empty($_SESSION[$csrf_name])) { $_SESSION[$csrf_name] = bin2hex(random_bytes(16)); }
$csrf_token = $_SESSION[$csrf_name];

$erro = '';
$ok = '';
// flash message support
$flash_ok = '';
if (!empty($_SESSION['flash_ok'])) { $flash_ok = (string)$_SESSION['flash_ok']; unset($_SESSION['flash_ok']); }
// fixed company for now (same pattern as sections/sales_reps)
$company_id = 1;

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
    $erro = 'Session expired. Please reload the page.';
  } else {
    if ($action === 'create' || $action === 'update') {
      $description = isset($_POST['description']) ? trim((string)$_POST['description']) : '';
      $description = mb_strtoupper($description);
      $is_active = isset($_POST['is_active']) && $_POST['is_active'] === '1' ? 1 : 0;
      if ($description === '') { $erro = 'Description is required.'; }
      if ($erro === '') {
        if ($action === 'create') {
          // prevent duplicate (case-insensitive) within company
          $exists = 0;
          if ($stmt = $conn->prepare('SELECT 1 FROM manufacturers WHERE company_id=? AND UPPER(TRIM(description))=UPPER(TRIM(?)) LIMIT 1')) {
            $stmt->bind_param('is', $company_id, $description);
            if ($stmt->execute()) { $stmt->store_result(); $exists = $stmt->num_rows; }
            $stmt->close();
          }
          if ($exists > 0) {
            $erro = 'Manufacturer already exists.';
          } else {
            $sql = 'INSERT INTO manufacturers (company_id, description, is_active, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())';
            if ($stmt = $conn->prepare($sql)) {
              $stmt->bind_param('isi', $company_id, $description, $is_active);
              if ($stmt->execute()) { $_SESSION['flash_ok'] = 'Manufacturer created successfully.'; header('Location: manufacturers.php'); exit; } else { $erro = 'Failed to create manufacturer.'; }
              $stmt->close();
            } else { $erro = 'Failed to prepare insert.'; }
          }
        } else {
          $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
          // prevent duplicate on update (excluding current id)
          $exists = 0;
          if ($stmt = $conn->prepare('SELECT 1 FROM manufacturers WHERE company_id=? AND UPPER(TRIM(description))=UPPER(TRIM(?)) AND id<>? LIMIT 1')) {
            $stmt->bind_param('isi', $company_id, $description, $id);
            if ($stmt->execute()) { $stmt->store_result(); $exists = $stmt->num_rows; }
            $stmt->close();
          }
          if ($exists > 0) {
            $erro = 'Manufacturer already exists.';
          } else {
            $sql = 'UPDATE manufacturers SET description=?, is_active=?, updated_at=NOW() WHERE id=? AND company_id=?';
            if ($stmt = $conn->prepare($sql)) {
              $stmt->bind_param('siii', $description, $is_active, $id, $company_id);
              if ($stmt->execute()) { $_SESSION['flash_ok'] = 'Manufacturer updated.'; header('Location: manufacturers.php'); exit; } else { $erro = 'Failed to update manufacturer.'; }
              $stmt->close();
            } else { $erro = 'Failed to prepare update.'; }
          }
        }
      }
    } elseif ($action === 'toggle') {
      $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
      $to = isset($_POST['to']) && $_POST['to'] === '1' ? 1 : 0;
      $sql = 'UPDATE manufacturers SET is_active=?, updated_at=NOW() WHERE id=? AND company_id=?';
      if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('iii', $to, $id, $company_id);
        if ($stmt->execute()) { 
          // Preservar filtros e ordenação na URL ao redirecionar
          $filter_name = isset($_POST['filter_name']) ? trim((string)$_POST['filter_name']) : (isset($_GET['filter_name']) ? trim((string)$_GET['filter_name']) : '');
          $filter_active = isset($_POST['filter_active']) ? trim((string)$_POST['filter_active']) : (isset($_GET['filter_active']) ? trim((string)$_GET['filter_active']) : '');
          $sort = isset($_POST['sort']) ? trim((string)$_POST['sort']) : (isset($_GET['sort']) ? trim((string)$_GET['sort']) : '');
          $dir = isset($_POST['dir']) ? trim((string)$_POST['dir']) : (isset($_GET['dir']) ? trim((string)$_GET['dir']) : '');
          
          $redirectUrl = 'manufacturers.php';
          $params = [];
          if ($filter_name !== '') $params[] = 'filter_name=' . urlencode($filter_name);
          if ($filter_active !== '') $params[] = 'filter_active=' . urlencode($filter_active);
          if ($sort !== '') {
            $params[] = 'sort=' . urlencode($sort);
            $params[] = 'dir=' . urlencode($dir);
          }
          if (!empty($params)) {
            $redirectUrl .= '?' . implode('&', $params);
          }
          header('Location: ' . $redirectUrl); 
          exit; 
        } else { $erro = 'Failed to update status.'; }
        $stmt->close();
      } else { $erro = 'Failed to prepare status update.'; }
    } elseif ($action === 'delete') {
      $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
      
      // Verificar se há produtos usando este fabricante
      $hasProducts = false;
      if ($stmt = $conn->prepare('SELECT COUNT(*) as total FROM products WHERE manufacturer_id=? AND company_id=? LIMIT 1')) {
        $stmt->bind_param('ii', $id, $company_id);
        if ($stmt->execute()) {
          $res = $stmt->get_result();
          $row = $res->fetch_assoc();
          $hasProducts = ($row['total'] ?? 0) > 0;
          $res->free();
        }
        $stmt->close();
      }
      
      if ($hasProducts) {
        $erro = 'Cannot delete manufacturer: there are products using this manufacturer. Please deactivate it instead or remove the products first.';
      } else {
        $sql = 'DELETE FROM manufacturers WHERE id=? AND company_id=?';
        if ($stmt = $conn->prepare($sql)) {
          $stmt->bind_param('ii', $id, $company_id);
          if ($stmt->execute()) { 
            $_SESSION['flash_ok'] = 'Manufacturer removed.'; 
            header('Location: manufacturers.php'); 
            exit; 
          } else { 
            $erro = 'Failed to remove: ' . $conn->error; 
          }
          $stmt->close();
        } else { 
          $erro = 'Failed to prepare deletion: ' . $conn->error; 
        }
      }
    } elseif ($action === 'delete_all') {
      // Verificar se há produtos usando fabricantes desta empresa
      $hasProducts = false;
      if ($stmt = $conn->prepare('SELECT COUNT(*) as total FROM products p INNER JOIN manufacturers m ON m.id=p.manufacturer_id WHERE m.company_id=? LIMIT 1')) {
        $stmt->bind_param('i', $company_id);
        if ($stmt->execute()) {
          $res = $stmt->get_result();
          $row = $res->fetch_assoc();
          $hasProducts = ($row['total'] ?? 0) > 0;
          $res->free();
        }
        $stmt->close();
      }
      
      if ($hasProducts) {
        $erro = 'Cannot delete all manufacturers: there are products using manufacturers. Please remove the products first or deactivate manufacturers instead.';
      } else {
        $sql = 'DELETE FROM manufacturers WHERE company_id=?';
        if ($stmt = $conn->prepare($sql)) {
          $stmt->bind_param('i', $company_id);
          if ($stmt->execute()) { 
            $_SESSION['flash_ok'] = 'All manufacturers removed.'; 
            header('Location: manufacturers.php'); 
            exit; 
          } else { 
            $erro = 'Failed to remove all manufacturers: ' . $conn->error; 
          }
          $stmt->close();
        } else { 
          $erro = 'Failed to prepare deletion: ' . $conn->error; 
        }
      }
    }
  }
}

// List manufacturers filtered by company
$rows = [];
// Filtros
$filter_name = isset($_GET['filter_name']) ? trim((string)$_GET['filter_name']) : '';
$filter_active = isset($_GET['filter_active']) ? trim((string)$_GET['filter_active']) : '';

// Sorting support
$sort = isset($_GET['sort']) ? strtolower((string)$_GET['sort']) : '';
$dir  = isset($_GET['dir'])  ? strtolower((string)$_GET['dir'])  : 'asc';
$dir  = ($dir === 'desc') ? 'DESC' : 'ASC';
$allowed = [ 'id' => 'id', 'description' => 'description', 'is_active' => 'is_active' ];
$orderSql = 'description ASC, id ASC';
if (isset($allowed[$sort])) {
  $col = $allowed[$sort];
  $orderSql = "$col $dir, id ASC";
}

// Construir query com filtros
$sqlList = 'SELECT id, description, is_active FROM manufacturers WHERE company_id=?';
$params = [$company_id];
$types = 'i';

if ($filter_name !== '') {
    $sqlList .= ' AND UPPER(description) LIKE ?';
    $params[] = '%' . strtoupper($filter_name) . '%';
    $types .= 's';
}

if ($filter_active !== '') {
    $filter_active_int = ($filter_active === '1') ? 1 : 0;
    $sqlList .= ' AND is_active = ?';
    $params[] = $filter_active_int;
    $types .= 'i';
}

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
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Catalog Seller - Manufacturers</title>
  <style>
    :root { --bg:#0f172a; --muted:#94a3b8; --text:#e2e8f0; }
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

    input[type=text]{padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,.22); background:#0b1220; color:var(--text)}
    .btn{appearance:none; display:inline-flex; align-items:center; justify-content:center; gap:6px; border:1px solid #3b4252; background:#0f172a; color:#e5e7eb; font-weight:600; font-size:13px; height:32px; padding:0 12px; border-radius:8px; cursor:pointer; box-shadow:none; text-decoration:none; line-height:1.2}
    .btn:hover{background:#0b1220}
    .btn-outline{background:transparent}
    .btn-danger{border-color:#5b616e; background:#141a23; color:#e5e7eb}
    .btn-danger:hover{background:#0f141c}

    table{width:100%; border-collapse:separate; border-spacing:0 8px; table-layout:fixed}
    col.col-id{width:56px}
    col.col-desc{width:calc(100% - 56px - 120px - 200px)}
    col.col-active{width:120px}
    col.col-actions{width:200px}
    .ellipsis{overflow:hidden; white-space:nowrap; text-overflow:ellipsis}
    th, td{padding:10px 12px; text-align:left}
    thead th{font-size:12px; color:var(--muted)}
    tbody tr{background: rgba(255,255,255,.02); box-shadow: 0 2px 8px rgba(0,0,0,.14); border:1px solid rgba(148,163,184,.14);}
    tbody tr:hover{background: rgba(255,255,255,.03)}
    tbody tr td:first-child{border-top-left-radius:12px; border-bottom-left-radius:12px}
    tbody tr td:last-child{border-top-right-radius:12px; border-bottom-right-radius:12px}

    .rowForm{display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap}
    .muted{color:var(--muted)}

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
    /* Add Manufacturer modal */
    .add-modal-overlay{position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(2,6,23,.75); backdrop-filter: blur(4px); z-index:10001}
    .add-modal-overlay.show{display:flex}
    .add-modal{min-width:420px; max-width:92vw; padding:0; border-radius:14px; border:1px solid rgba(148,163,184,.18); background: linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02)); color:var(--text); box-shadow: 0 10px 26px rgba(0,0,0,.5); overflow:hidden}
    .add-modal-header{padding:16px 18px; border-bottom:1px solid rgba(148,163,184,.18); font-weight:800}
    .add-modal-body{padding:18px}
    .add-modal-footer{padding:14px 18px; border-top:1px solid rgba(148,163,184,.18); display:flex; gap:8px; justify-content:flex-end}
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
  <!-- Add Manufacturer Modal (moved outside confirm overlay) -->
  <div id="addModal" class="add-modal-overlay" aria-hidden="true">
    <div class="add-modal">
      <div class="add-modal-header">Add Manufacturer</div>
      <form method="post" id="addForm">
        <div class="add-modal-body">
          <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="action" value="create">
          <div class="rowForm">
            <div style="flex:1 1 320px">
              <label class="muted" for="mf_desc_modal">Manufacturer</label>
              <input id="mf_desc_modal" class="uppercase" type="text" name="description" placeholder="E.g., ACME FOODS" required style="width:100%">
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
        <div class="add-modal-footer">
          <button type="button" class="btn btn-outline" id="btnCancelAdd">Cancel</button>
          <button type="submit" class="btn">Save</button>
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
        <a href="dashboard.php" class="btn">Back</a>
      </div>
    </header>

    <section class="card" style="position:relative">
      <h2 style="margin:0 0 10px 0">Manufacturers</h2>
      <div class="quick-actions">
        <a href="#" id="lnkAddManufacturer" class="linkBlue" style="color:#38bdf8">Add Manufacturer</a>
        <a class="linkAccent" href="import_manufacturers.php">Import Manufacturers</a>
        <form method="post" id="deleteAllForm" style="display:inline; margin:0">
          <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="action" value="delete_all">
          <button type="button" class="linkAccent" id="deleteAllBtn" style="background:none; border:none; padding:0; cursor:pointer; font-size:14px; color:#ef4444">Delete All</button>
        </form>
      </div>
      <?php if ($erro): ?><div class="msg error"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
      <?php if ($flash_ok): ?><div class="msg ok"><?php echo htmlspecialchars($flash_ok, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

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
            <a href="manufacturers.php<?php echo ($sort !== '' ? '?sort=' . htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') . '&dir=' . htmlspecialchars(strtolower($dir), ENT_QUOTES, 'UTF-8') : ''); ?>" class="btn btn-outline">Clear</a>
          <?php endif; ?>
        </div>
        <?php if ($sort !== ''): ?>
          <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="dir" value="<?php echo htmlspecialchars(strtolower($dir), ENT_QUOTES, 'UTF-8'); ?>">
        <?php endif; ?>
      </form>

      <!-- Removed New Manufacturer button; action moved to top link -->

      <!-- List -->
      <table>
        <colgroup>
          <col class="col-id"><col class="col-desc"><col class="col-active"><col class="col-actions">
        </colgroup>
        <thead>
          <?php 
            $q = function($s) use ($sort, $dir, $filter_name, $filter_active){ 
              $params = ['sort='.$s, 'dir='.(($sort===$s && $dir==='asc')?'desc':'asc')];
              if ($filter_name !== '') $params[] = 'filter_name=' . urlencode($filter_name);
              if ($filter_active !== '') $params[] = 'filter_active=' . urlencode($filter_active);
              return 'manufacturers.php?' . implode('&', $params);
            };
            $arrow = function($s) use ($sort, $dir){ if($sort!==$s) return ''; return $dir==='ASC'?' ▲':' ▼'; };
          ?>
          <tr>
            <th style="width:56px"><a href="<?php echo htmlspecialchars($q('id'), ENT_QUOTES, 'UTF-8'); ?>" class="muted" style="text-decoration:none">ID<?php echo $arrow('id'); ?></a></th>
            <th><a href="<?php echo htmlspecialchars($q('description'), ENT_QUOTES, 'UTF-8'); ?>" class="muted" style="text-decoration:none">Manufacturer<?php echo $arrow('description'); ?></a></th>
            <th style="width:120px"><a href="<?php echo htmlspecialchars($q('is_active'), ENT_QUOTES, 'UTF-8'); ?>" class="muted" style="text-decoration:none">Active<?php echo $arrow('is_active'); ?></a></th>
            <th style="width:200px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): $isEditing = ($editId === (int)$r['id']); $formId = 'fmf'.(int)$r['id']; ?>
          <?php if ($isEditing): ?><form id="<?php echo $formId; ?>" method="post"></form><?php endif; ?>
          <tr>
            <?php if ($isEditing): ?>
                <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>" form="<?php echo $formId; ?>">
                <input type="hidden" name="action" value="update" form="<?php echo $formId; ?>">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>" form="<?php echo $formId; ?>">
                <td><?php echo (int)$r['id']; ?></td>
                <td><input style="width:100%" class="uppercase" type="text" name="description" value="<?php echo htmlspecialchars($r['description'], ENT_QUOTES, 'UTF-8'); ?>" required form="<?php echo $formId; ?>"></td>
                <td>
                  <input type="hidden" name="is_active" value="0" form="<?php echo $formId; ?>">
                  <label class="toggle">
                    <input type="checkbox" name="is_active" value="1" <?php echo ($r['is_active'] ? 'checked' : ''); ?> aria-label="Toggle active" form="<?php echo $formId; ?>">
                    <span class="slider"></span>
                  </label>
                </td>
                <td class="actions">
                  <button class="btn" type="submit" form="<?php echo $formId; ?>">Save</button>
                  <a class="btn btn-outline" href="manufacturers.php">Cancel</a>
                </td>
            <?php else: ?>
              <td><?php echo (int)$r['id']; ?></td>
              <td class="ellipsis" title="<?php echo htmlspecialchars($r['description'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($r['description'], ENT_QUOTES, 'UTF-8'); ?></td>
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
                <a class="btn" href="manufacturers.php?edit=<?php echo (int)$r['id']; ?>">Edit</a>
                <form method="post" onsubmit="return confirm('Remove this manufacturer?');" style="display:inline">
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
            <td colspan="4" class="muted">No manufacturers found.</td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </section>
  </div>
<script>
  (function(){
    // Restaurar posição de scroll após reload (se foi salva)
    if (sessionStorage.getItem('manufacturers_scroll_pos')) {
      var savedPos = parseInt(sessionStorage.getItem('manufacturers_scroll_pos'), 10);
      window.scrollTo(0, savedPos);
      sessionStorage.removeItem('manufacturers_scroll_pos');
    }
    
    // Salvar posição de scroll e filtros antes de submeter o formulário
    document.querySelectorAll('.toggle-form').forEach(function(form){
      var cb = form.querySelector('input[type="checkbox"]');
      var to = form.querySelector('input[name="to"]');
      if (!cb || !to) return;
      cb.addEventListener('change', function(){
        // Salvar posição atual de scroll
        sessionStorage.setItem('manufacturers_scroll_pos', window.pageYOffset || document.documentElement.scrollTop);
        
        // Preservar filtros na URL
        var urlParams = new URLSearchParams(window.location.search);
        var filterName = urlParams.get('filter_name') || '';
        var filterActive = urlParams.get('filter_active') || '';
        var sort = urlParams.get('sort') || '';
        var dir = urlParams.get('dir') || '';
        
        // Adicionar campos hidden para preservar filtros
        if (filterName !== '') {
          var hiddenName = document.createElement('input');
          hiddenName.type = 'hidden';
          hiddenName.name = 'filter_name';
          hiddenName.value = filterName;
          form.appendChild(hiddenName);
        }
        if (filterActive !== '') {
          var hiddenActive = document.createElement('input');
          hiddenActive.type = 'hidden';
          hiddenActive.name = 'filter_active';
          hiddenActive.value = filterActive;
          form.appendChild(hiddenActive);
        }
        if (sort !== '') {
          var hiddenSort = document.createElement('input');
          hiddenSort.type = 'hidden';
          hiddenSort.name = 'sort';
          hiddenSort.value = sort;
          form.appendChild(hiddenSort);
        }
        if (dir !== '') {
          var hiddenDir = document.createElement('input');
          hiddenDir.type = 'hidden';
          hiddenDir.name = 'dir';
          hiddenDir.value = dir;
          form.appendChild(hiddenDir);
        }
        
        to.value = cb.checked ? '1' : '0';
        form.submit();
      });
    });
  })();
</script>
<script>
// Toast from existing .msg elements (run after full load to avoid timing issues)
window.addEventListener('load', function(){
  var msg = document.querySelector('.msg.ok, .msg.error');
  if(!msg) return;
  var text = (msg.textContent||'').trim();
  if(!text) return;
  msg.style.display='none';
  var overlay=document.createElement('div'); overlay.className='toast-overlay';
  var box=document.createElement('div'); box.className='toast ' + (msg.classList.contains('error')?'error':'success');
  var head=document.createElement('div'); head.className='toast-head'; head.innerHTML='<span>'+(msg.classList.contains('error')?'Error':'Success')+'</span>';
  var btn=document.createElement('button'); btn.className='toast-close'; btn.setAttribute('aria-label','Close'); btn.innerHTML='\u00D7';
  var body=document.createElement('div'); body.textContent=text;
  function close(){ if(!overlay) return; overlay.remove(); overlay=null; }
  btn.addEventListener('click', close);
  overlay.addEventListener('click', function(e){ if(e.target===overlay) close(); });
  document.addEventListener('keydown', function(ev){ if(ev.key==='Escape') close(); });
  head.appendChild(btn); box.appendChild(head); box.appendChild(body); overlay.appendChild(box); document.body.appendChild(overlay);
  setTimeout(close, 3000);
});
// Force uppercase while typing for manufacturer description inputs
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

// Double confirmation to delete all manufacturers
document.addEventListener('DOMContentLoaded', function(){
  var btn = document.getElementById('deleteAllBtn');
  var form = document.getElementById('deleteAllForm');
  if (!btn || !form) return;
  btn.addEventListener('click', async function(e){
    e.preventDefault();
    // First confirmation
    var first = await customConfirm('WARNING: Do you really want to delete ALL manufacturers? This action cannot be undone.', 'catalogseller.com says');
    if (!first) return;
    // Second confirmation
    var second = await customConfirm('FINAL CONFIRMATION: Are you absolutely sure? All manufacturers will be permanently deleted.', 'catalogseller.com says');
    if (second) {
      form.submit();
    }
  });
});
// Add Manufacturer modal behavior
document.addEventListener('DOMContentLoaded', function(){
  var openBtn = document.getElementById('btnOpenAdd') || document.getElementById('lnkAddManufacturer');
  var modal = document.getElementById('addModal');
  var cancel = document.getElementById('btnCancelAdd');
  var input = document.getElementById('mf_desc_modal');
  function open(){ if(modal){ modal.classList.add('show'); modal.setAttribute('aria-hidden','false'); setTimeout(function(){ try{ input && input.focus(); }catch(e){} }, 100); } }
  function close(){ if(modal){ modal.classList.remove('show'); modal.setAttribute('aria-hidden','true'); } }
  openBtn && openBtn.addEventListener('click', open);
  cancel && cancel.addEventListener('click', close);
  modal && modal.addEventListener('click', function(ev){ if(ev.target===modal) close(); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') close(); });
});
</script>
</body>
</html>
