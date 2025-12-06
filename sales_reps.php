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
$flash_ok = '';
if (!empty($_SESSION['flash_ok'])) { $flash_ok = (string)$_SESSION['flash_ok']; unset($_SESSION['flash_ok']); }

// Ensure new instance_id column exists (replacing legacy url_token usage in this UI)
try { @$conn->query("ALTER TABLE sales_reps ADD COLUMN instance_id VARCHAR(160) NULL"); } catch (Throwable $_) {}
// Ensure new API/token columns exist
try { @$conn->query("ALTER TABLE sales_reps ADD COLUMN instance_api VARCHAR(255) NULL"); } catch (Throwable $_) {}
try { @$conn->query("ALTER TABLE sales_reps ADD COLUMN instance_token VARCHAR(160) NULL"); } catch (Throwable $_) {}
try { @$conn->query("ALTER TABLE sales_reps ADD COLUMN security_token VARCHAR(160) NULL"); } catch (Throwable $_) {}
// Ensure is_admin_flag column exists and is NOT a GENERATED column
// If it's GENERATED, we need to convert it to a regular column so it can be set manually
try { 
  $check = $conn->query("SHOW COLUMNS FROM sales_reps LIKE 'is_admin_flag'");
  if ($check && $check->num_rows > 0) {
    $colInfo = $check->fetch_assoc();
    // Check if it's a GENERATED column by looking at Extra field
    if (isset($colInfo['Extra']) && stripos($colInfo['Extra'], 'GENERATED') !== false) {
      // Convert GENERATED column to regular column
      @$conn->query("ALTER TABLE sales_reps MODIFY COLUMN is_admin_flag TINYINT(1) DEFAULT 0");
    }
  } else {
    // Column doesn't exist, create it as a regular column
    @$conn->query("ALTER TABLE sales_reps ADD COLUMN is_admin_flag TINYINT(1) DEFAULT 0");
  }
} catch (Throwable $_) {}

// Buscar logo da company
$company_id = 1; // fixed for now
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
      $full_name = isset($_POST['full_name']) ? trim((string)$_POST['full_name']) : '';
      $job_title = isset($_POST['job_title']) ? trim((string)$_POST['job_title']) : '';
      $email = isset($_POST['email']) ? strtolower(trim((string)$_POST['email'])) : '';
      $phone = isset($_POST['phone']) ? trim((string)$_POST['phone']) : '';
      $instance_api = isset($_POST['instance_api']) ? trim((string)$_POST['instance_api']) : '';
      $instance_id = isset($_POST['instance_id']) ? trim((string)$_POST['instance_id']) : '';
      $instance_token = isset($_POST['instance_token']) ? trim((string)$_POST['instance_token']) : '';
      $security_token = isset($_POST['security_token']) ? trim((string)$_POST['security_token']) : '';
      $is_active = isset($_POST['is_active']) && $_POST['is_active'] === '1' ? 1 : 0;
      $is_admin_flag = isset($_POST['is_admin_flag']) && $_POST['is_admin_flag'] === '1' ? 1 : 0;
      $message_text = isset($_POST['message_text']) ? trim((string)$_POST['message_text']) : '';

      if ($full_name === '') { $erro = 'Full name is required.'; }
      if ($erro === '' && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $erro = 'Invalid email address.'; }
      if ($erro === '' && $phone !== '' && !preg_match('/^\+1\s\d{3}\s\d{3}\s\d{4}$/', $phone)) { $erro = 'Invalid phone number. Use the format +1 407 680 8976.'; }

      if ($erro === '') {
        // For CREATE: Check if this is the first representative - if so, automatically set as Admin
        if ($action === 'create') {
          $checkFirstSql = 'SELECT COUNT(*) as total FROM sales_reps WHERE company_id = ?';
          if ($checkFirstStmt = $conn->prepare($checkFirstSql)) {
            $checkFirstStmt->bind_param('i', $company_id);
            $checkFirstStmt->execute();
            $checkFirstResult = $checkFirstStmt->get_result();
            if ($checkFirstResult) {
              $firstRow = $checkFirstResult->fetch_assoc();
              if ($firstRow && (int)$firstRow['total'] === 0) {
                // This is the first representative - automatically set as Admin
                $is_admin_flag = 1;
              }
            }
            $checkFirstStmt->close();
          }
        }
        
        // Check if trying to set as Admin and if another Admin already exists for this company
        if ($is_admin_flag === 1) {
          if ($action === 'create') {
            // Check if there's already an Admin for this company
            $checkSql = 'SELECT id FROM sales_reps WHERE company_id = ? AND is_admin_flag = 1 LIMIT 1';
            if ($checkStmt = $conn->prepare($checkSql)) {
              $checkStmt->bind_param('i', $company_id);
              $checkStmt->execute();
              $checkResult = $checkStmt->get_result();
              if ($checkResult && $checkResult->num_rows > 0) {
                $erro = 'Only one Admin profile is allowed per company. Please change the existing Admin to Seller first.';
              }
              $checkStmt->close();
            }
          } else {
            // For update, check if there's another Admin (different ID) for this company
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $checkSql = 'SELECT id FROM sales_reps WHERE company_id = ? AND is_admin_flag = 1 AND id != ? LIMIT 1';
            if ($checkStmt = $conn->prepare($checkSql)) {
              $checkStmt->bind_param('ii', $company_id, $id);
              $checkStmt->execute();
              $checkResult = $checkStmt->get_result();
              if ($checkResult && $checkResult->num_rows > 0) {
                $erro = 'Only one Admin profile is allowed per company. Please change the existing Admin to Seller first.';
              }
              $checkStmt->close();
            }
          }
        }
        
        if ($erro === '') {
          if ($action === 'create') {
            $sql = 'INSERT INTO sales_reps (company_id, full_name, job_title, email, phone, instance_api, instance_id, instance_token, security_token, is_active, is_admin_flag, message_text, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';
            if ($stmt = $conn->prepare($sql)) {
              $stmt->bind_param('issssssssiis', $company_id, $full_name, $job_title, $email, $phone, $instance_api, $instance_id, $instance_token, $security_token, $is_active, $is_admin_flag, $message_text);
              if ($stmt->execute()) { $_SESSION['flash_ok'] = 'Sales representative created.'; header('Location: sales_reps.php'); exit; } else { $erro = 'Failed to create.'; }
              $stmt->close();
            } else { $erro = 'Failed to prepare insert.'; }
          } else {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $sql = 'UPDATE sales_reps SET full_name=?, job_title=?, email=?, phone=?, instance_api=?, instance_id=?, instance_token=?, security_token=?, is_active=?, is_admin_flag=?, updated_at=NOW() WHERE id=?';
            if ($stmt = $conn->prepare($sql)) {
              $stmt->bind_param('ssssssssiii', $full_name, $job_title, $email, $phone, $instance_api, $instance_id, $instance_token, $security_token, $is_active, $is_admin_flag, $id);
              if ($stmt->execute()) { $_SESSION['flash_ok'] = 'Sales representative updated.'; header('Location: sales_reps.php'); exit; } else { $erro = 'Failed to update.'; }
              $stmt->close();
            } else { $erro = 'Failed to prepare update.'; }
          }
        }
      }
    } elseif ($action === 'toggle') {
      $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
      $to = isset($_POST['to']) && $_POST['to'] === '1' ? 1 : 0;
      $sql = 'UPDATE sales_reps SET is_active=?, updated_at=NOW() WHERE id=?';
      if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('ii', $to, $id);
        if ($stmt->execute()) { header('Location: sales_reps.php'); exit; } else { $erro = 'Failed to update status.'; }
        $stmt->close();
      } else { $erro = 'Failed to prepare status update.'; }
    } elseif ($action === 'delete') {
      $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
      $sql = 'DELETE FROM sales_reps WHERE id=?';
      if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) { $_SESSION['flash_ok'] = 'Sales representative removed.'; header('Location: sales_reps.php'); exit; } else { $erro = 'Failed to remove.'; }
        $stmt->close();
      } else { $erro = 'Failed to prepare deletion.'; }
    } elseif ($action === 'save_msg') {
      $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
      $msg = isset($_POST['message_text']) ? trim((string)$_POST['message_text']) : '';
      $sql = 'UPDATE sales_reps SET message_text=?, updated_at=NOW() WHERE id=?';
      if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('si', $msg, $id);
        if ($stmt->execute()) { $_SESSION['flash_ok'] = 'Message saved.'; header('Location: sales_reps.php'); exit; } else { $erro = 'Failed to save message.'; }
        $stmt->close();
      } else { $erro = 'Failed to prepare message save.'; }
    }
  }
}

// List
$rows = [];
$sqlList = 'SELECT id, full_name, job_title, email, phone, instance_api, instance_id, instance_token, security_token, is_active, is_admin_flag, message_text FROM sales_reps ORDER BY full_name ASC';
if ($res = $conn->query($sqlList)) {
  while ($r = $res->fetch_assoc()) { $rows[] = $r; }
  $res->free();
}

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Catalog Seller - Sales Representatives</title>
  <style>
    :root { --bg:#0f172a; --muted:#94a3b8; --text:#e2e8f0; }
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

    input[type=text], input[type=email], input[type=password], input[type=date]{padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,.22); background:#0b1220; color:var(--text)}
    .btn{appearance:none; display:inline-flex; align-items:center; justify-content:center; gap:6px; border:1px solid #3b4252; background:#0f172a; color:#e5e7eb; font-weight:600; font-size:13px; height:32px; padding:0 12px; border-radius:8px; cursor:pointer; box-shadow:none; text-decoration:none; line-height:1.2}
    .btn:hover{background:#0b1220}
    .btn-outline{background:transparent}
    .btn-danger{border-color:#5b616e; background:#141a23; color:#e5e7eb}
    .btn-danger:hover{background:#0f141c}

    table{width:100%; border-collapse:separate; border-spacing:0 8px; table-layout: fixed}
    col.col-name{width:22%}
    col.col-email{width:20%}
    col.col-phone{width:18%}
    col.col-active{width:60px}
    col.col-profile{width:16%}
    col.col-send{width:120px}
    col.col-actions{width:340px}
    .profile-badge{display:inline-block; padding:4px 10px; border-radius:6px; font-size:12px; font-weight:600; text-transform:uppercase}
    .profile-badge.admin{background:rgba(139,92,246,.2); color:#a78bfa; border:1px solid rgba(139,92,246,.3)}
    .profile-badge.seller{background:rgba(59,130,246,.2); color:#60a5fa; border:1px solid rgba(59,130,246,.3)}
    .subline{font-size:12px; color:var(--muted)}
    .label{font-size:12px; color:var(--muted); margin-right:6px}
    .ellipsis{overflow:hidden; white-space:nowrap; text-overflow:ellipsis}
    .nowrap{white-space:nowrap}
    th, td{padding:10px 12px; text-align:left}
    td.actions{ text-align:right; white-space:nowrap; vertical-align:middle }
    td.actions.edit-mode{ vertical-align:top; padding-top:6px }
    td.actions.edit-mode .act-wrap{ width:100%; display:flex; justify-content:flex-end; gap:8px }
    thead th.actions{ text-align:right }
    thead th{font-size:12px; color:var(--muted)}
    thead th:nth-child(4){ text-align:center } /* Active */
    tbody td:nth-child(4){ text-align:center; padding-left:6px; padding-right:6px }
    .note-sm{font-size:12px; opacity:.85}
    thead th:nth-child(5){ text-align:left } /* Profile */
    tbody tr{background: rgba(255,255,255,.02); box-shadow: 0 2px 8px rgba(0,0,0,.14); border:1px solid rgba(148,163,184,.14);}
    tbody tr:hover{background: rgba(255,255,255,.03)}
    tbody tr td:first-child{border-top-left-radius:12px; border-bottom-left-radius:12px}
    tbody tr td:last-child{border-top-right-radius:12px; border-bottom-right-radius:12px}

    .rowForm{display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap}
    .rowForm > div{display:flex; flex-direction:column}
    .muted{color:var(--muted)}
    .inline-controls{display:flex; align-items:center; gap:10px; flex-wrap:nowrap; flex-direction:row; white-space:nowrap}
    .inline-controls.compact{align-self:flex-end; height:32px}
    .inline-controls label{display:inline-flex; align-items:center; gap:6px}
    .inline-controls input[type="radio"]{margin:0}
    .break{flex-basis:100%; height:0}
    .hidden{display:none !important}

    .toggle{position:relative; width:44px; height:24px; display:inline-block}
    .toggle input{opacity:0; width:0; height:0}
    .slider{position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background:#0f172a; border:1px solid rgba(148,163,184,.25); border-radius:999px; transition:.15s}
    .slider:before{content:""; position:absolute; height:20px; width:20px; left:1px; top:1px; background:#475569; border-radius:999px; transition:.15s}
    .toggle input:checked + .slider{background:linear-gradient(180deg, #22c55e, #16a34a); border-color:rgba(34,197,94,.6)}
    .toggle input:checked + .slider:before{transform:translateX(20px); background:#052e16}

    .actions{display:flex; gap:4px; align-items:center; justify-content:center; flex-wrap:nowrap; margin-top:4px}
    .actions .btn{height:28px; padding:0 8px; font-size:12px}
    tbody td:nth-child(5){ text-align:left } /* Profile */

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
    /* Add representative modal */
    .modal-overlay{position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(2,6,23,.75); backdrop-filter: blur(4px); z-index:10000}
    .modal-overlay.show{display:flex}
    .modal{min-width:520px; max-width:92vw; padding:0; border-radius:14px; border:1px solid rgba(148,163,184,.18); background: linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02)); color:var(--text); box-shadow: 0 10px 26px rgba(0,0,0,.5); overflow:hidden}
    .modal-header{padding:16px 18px; border-bottom:1px solid rgba(255,255,255,.12); font-weight:700; font-size:16px}
    .modal-body{padding:16px 18px}
    .modal-footer{padding:12px 18px; border-top:1px solid rgba(255,255,255,.12); display:flex; gap:10px; justify-content:flex-end}
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

    <!-- Add Representative Modal -->
    <div id="repAddModal" class="modal-overlay" aria-hidden="true">
      <div class="modal">
        <div class="modal-header">Add representative</div>
        <form method="post" id="repAddForm">
          <div class="modal-body">
            <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="create">
            <div class="rowForm" style="align-items:flex-end">
              <div style="flex:1 1 320px">
                <label class="muted" for="rep_full_name">Full name</label>
                <input id="rep_full_name" type="text" name="full_name" required>
              </div>
              <div style="flex:0 0 220px">
                <label class="muted" for="rep_job_title">Job title</label>
                <input id="rep_job_title" type="text" name="job_title">
              </div>
            </div>
            <div class="rowForm" style="align-items:flex-end">
              <div style="flex:1 1 320px">
                <label class="muted" for="rep_email">Email</label>
                <input id="rep_email" type="email" name="email" placeholder="name@domain.com">
              </div>
              <div style="flex:0 0 220px">
                <label class="muted" for="rep_phone">Phone</label>
                <input id="rep_phone" type="text" name="phone" placeholder="+1 407 680 8976">
              </div>
            </div>
            <div class="rowForm" style="align-items:flex-end">
              <div style="flex:1 1 100%">
                <label class="muted" for="rep_instance_api">Instance API</label>
                <input id="rep_instance_api" type="text" name="instance_api">
              </div>
            </div>
            <div class="rowForm" style="align-items:flex-end; display:grid; grid-template-columns:1fr 1fr; gap:10px">
              <div style="flex:1 1 50%">
                <label class="muted" for="rep_instance_id">Instance ID</label>
                <input id="rep_instance_id" type="text" name="instance_id">
              </div>
              <div style="flex:1 1 50%">
                <label class="muted" for="rep_instance_token">Instance Token</label>
                <input id="rep_instance_token" type="text" name="instance_token">
              </div>
            </div>
            <div class="rowForm" style="align-items:flex-end">
              <div style="flex:1 1 100%">
                <label class="muted" for="rep_security_token">Security Token</label>
                <input id="rep_security_token" type="text" name="security_token">
              </div>
            </div>
            <div class="rowForm" style="align-items:flex-end">
              <div style="flex:0 0 120px">
                <label class="muted">Profile</label>
                <select style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,.22); background:#0b1220; color:var(--text)" name="is_admin_flag">
                  <option value="1">Admin</option>
                  <option value="0">Seller</option>
                </select>
              </div>
              <div style="flex:0 0 120px">
                <label class="muted">Active</label>
                <label class="toggle">
                  <input type="checkbox" name="is_active" value="1" checked>
                  <span class="slider"></span>
                </label>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline" id="repAddCancel">Cancel</button>
            <button type="submit" class="btn">Save</button>
          </div>
        </form>
      </div>
    </div>

    <section class="card">
      <h2 style="margin:0 0 8px 0">Sales Representatives</h2>
      <?php if ($erro): ?><div class="msg error" style="color:#ef4444; margin-bottom:8px"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
      <?php if ($flash_ok): ?><div class="msg ok" style="color:#22c55e; margin-bottom:8px"><?php echo htmlspecialchars($flash_ok, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

      <div style="display:flex; justify-content:flex-end; margin:0 0 6px 0">
        <button type="button" class="btn" id="btnOpenRepAdd">Add representative</button>
      </div>

      <!-- List -->
      <table>
        <colgroup>
          <col class="col-name"><col class="col-email"><col class="col-phone"><col class="col-active"><col class="col-profile"><col class="col-actions">
        </colgroup>
        <thead>
          <tr>
            <th>Full name</th>
            <th>Email</th>
            <th>Phone</th>
            <th style="width:60px; text-align:center">Active</th>
            <th>Profile</th>
            <th class="actions" style="width:340px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): $isEditing = ($editId === (int)$r['id']); $formId = 'fsr'.(int)$r['id']; ?>
          <?php if ($isEditing): ?><form id="<?php echo $formId; ?>" method="post"></form><?php endif; ?>
          <tr>
            <?php if ($isEditing): ?>
              <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>" form="<?php echo $formId; ?>">
              <input type="hidden" name="action" value="update" form="<?php echo $formId; ?>">
              <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>" form="<?php echo $formId; ?>">
              <td><input style="width:100%" type="text" name="full_name" value="<?php echo htmlspecialchars($r['full_name'], ENT_QUOTES, 'UTF-8'); ?>" required form="<?php echo $formId; ?>"></td>
              <td data-mode-group="E"><input style="width:100%" type="email" name="email" value="<?php echo htmlspecialchars($r['email'], ENT_QUOTES, 'UTF-8'); ?>" form="<?php echo $formId; ?>"></td>
              <td><input style="width:100%" id="phone_edit_<?php echo (int)$r['id']; ?>" type="text" name="phone" value="<?php echo htmlspecialchars($r['phone'], ENT_QUOTES, 'UTF-8'); ?>" pattern="^\+1 \d{3} \d{3} \d{4}$" form="<?php echo $formId; ?>"></td>
              <td style="display:flex; flex-direction:column; align-items:center; gap:4px">
                <input type="hidden" name="is_active" value="0" form="<?php echo $formId; ?>">
                <label class="toggle">
                  <input type="checkbox" name="is_active" value="1" <?php echo ($r['is_active'] ? 'checked' : ''); ?> aria-label="Toggle active" form="<?php echo $formId; ?>">
                  <span class="slider"></span>
                </label>
              </td>
              <td>
                <select style="width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,.22); background:#0b1220; color:var(--text)" name="is_admin_flag" form="<?php echo $formId; ?>">
                  <option value="1" <?php echo (isset($r['is_admin_flag']) && (int)$r['is_admin_flag'] === 1 ? 'selected' : ''); ?>>Admin</option>
                  <option value="0" <?php echo (!isset($r['is_admin_flag']) || (int)$r['is_admin_flag'] === 0 ? 'selected' : ''); ?>>Seller</option>
                </select>
              </td>
              <td>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                <button class="btn" type="submit" form="<?php echo $formId; ?>">Save</button>
                <a class="btn btn-outline" href="sales_reps.php">Cancel</a>
              </td>
            <?php else: ?>
              <td class="ellipsis" title="<?php echo htmlspecialchars($r['full_name'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($r['full_name'], ENT_QUOTES, 'UTF-8'); ?>
              </td>
              <td class="ellipsis" title="<?php echo htmlspecialchars($r['email'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($r['email'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td class="nowrap" title="<?php echo htmlspecialchars($r['phone'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($r['phone'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td style="text-align:center">
                <form method="post" style="margin:0; display:flex; flex-direction:column; align-items:center; gap:4px" class="toggle-form">
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
              <td>
                <?php $isAdmin = isset($r['is_admin_flag']) && (int)$r['is_admin_flag'] === 1; ?>
                <span class="profile-badge <?php echo $isAdmin ? 'admin' : 'seller'; ?>"><?php echo $isAdmin ? 'Admin' : 'Seller'; ?></span>
              </td>
              <td class="actions">
                <button type="button" class="btn js-open-msg" data-id="<?php echo (int)$r['id']; ?>">Message</button>
                <a class="btn" href="sales_reps.php?edit=<?php echo (int)$r['id']; ?>">Edit</a>
                <form method="post" onsubmit="return confirm('Remove this sales representative?');" style="display:inline">
                  <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" class="btn btn-danger">Delete</button>
                </form>
              </td>
            <?php endif; ?>
          </tr>
          <?php if (!$isEditing): ?>
          <tr class="msgRow hidden" data-id="<?php echo (int)$r['id']; ?>">
            <td colspan="6">
              <div style="padding:10px 12px; display:flex; flex-direction:column; gap:8px">
                <div id="msg-<?php echo (int)$r['id']; ?>" class="tab-pane" style="display:block">
                  <form method="post" class="msg-form" style="display:flex; gap:10px; align-items:flex-start">
                    <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="save_msg">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <textarea name="message_text" rows="10" style="flex:1; width:100%; min-height:220px; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,.22); background:#0b1220; color:var(--text)" placeholder="Message to send to customers when a new key is available..."><?php echo htmlspecialchars((string)($r['message_text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    <button type="submit" class="btn">Save message</button>
                  </form>
                </div>
              </div>
            </td>
          </tr>
          <?php endif; ?>
          <?php if ($isEditing): ?>
          <tr>
            <td colspan="6">
              <div class="rowForm" style="margin:8px 0 2px; align-items:flex-start">
                <div class="break"></div>
                <div style="flex:1 1 100%">
                  <label class="muted">Instance API <span class="note-sm">(API da instância)</span></label>
                  <input type="text" name="instance_api" value="<?php echo htmlspecialchars((string)($r['instance_api'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" form="<?php echo $formId; ?>">
                </div>
              </div>
              <div class="rowForm" style="margin:8px 0 2px; align-items:flex-start; display:grid; grid-template-columns:1fr 1fr; gap:10px">
                <div style="flex:1 1 50%">
                  <label class="muted">Instance ID <span class="note-sm">(ID da instância)</span></label>
                  <input type="text" name="instance_id" value="<?php echo htmlspecialchars((string)($r['instance_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" form="<?php echo $formId; ?>">
                </div>
                <div style="flex:1 1 50%">
                  <label class="muted">Instance Token <span class="note-sm">(Token da instância)</span></label>
                  <input type="text" name="instance_token" value="<?php echo htmlspecialchars((string)($r['instance_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" form="<?php echo $formId; ?>">
                </div>
              </div>
              <div class="rowForm" style="margin:8px 0 2px; align-items:flex-start">
                <div style="flex:1 1 100%">
                  <label class="muted">Security Token <span class="note-sm">(Token de segurança)</span></label>
                  <input type="text" name="security_token" value="<?php echo htmlspecialchars((string)($r['security_token'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" form="<?php echo $formId; ?>">
                </div>
              </div>
            </td>
          </tr>
          <?php endif; ?>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
          <tr>
            <td colspan="6" class="muted">No sales representatives found.</td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </section>
  </div>
<script>
  (function(){
    // autosubmit switches
    document.querySelectorAll('.toggle-form').forEach(function(form){
      var cb = form.querySelector('input[type="checkbox"]');
      var to = form.querySelector('input[name="to"]');
      if (!cb || !to) return;
      cb.addEventListener('change', function(){
        to.value = cb.checked ? '1' : '0';
        form.submit();
      });
    });
    // autosubmit Type (Email/Zap)
    document.querySelectorAll('.mode-form').forEach(function(form){
      form.querySelectorAll('input[type="radio"]').forEach(function(r){
        r.addEventListener('change', function(){ form.submit(); });
      });
    });
    // phone mask quick add and edits
    function applyMask(el){
      if(!el) return;
      function format(v){
        v = (v||'').replace(/[^0-9]/g,'');
        if (v.charAt(0) !== '1') v = '1' + v;
        v = v.slice(0,11);
        var d = v.slice(1);
        var a = d.slice(0,3), b = d.slice(3,6), c = d.slice(6,10);
        var out = '+1'; if(a) out+=' '+a; if(b) out+=' '+b; if(c) out+=' '+c; return out;
      }
      el.addEventListener('focus', function(){ if(!el.value) el.value = '+1 '; });
      el.addEventListener('input', function(){ el.value = format(el.value); });
      el.addEventListener('blur', function(){ if (el.value && !/^\+1 \d{3} \d{3} \d{4}$/.test(el.value)) { el.setCustomValidity('Use the format +1 407 680 8976'); } else { el.setCustomValidity(''); } });
    }
    applyMask(document.getElementById('phone_new'));
    document.querySelectorAll('[id^="phone_edit_"]').forEach(applyMask);

    // key generator: fills key (3 digits) and key_validate (+7 days)
    function next7(){
      var d=new Date(); d.setDate(d.getDate()+7);
      var m=(d.getMonth()+1).toString().padStart(2,'0');
      var day=d.getDate().toString().padStart(2,'0');
      return d.getFullYear()+ '-' + m + '-' + day;
    }
    function genKey(){ return Math.floor(Math.random()*1000).toString().padStart(3,'0'); }
    var btnNew = document.getElementById('gen_key_new');
    if(btnNew){
      btnNew.addEventListener('click', function(){
        var keyEl = document.getElementById('key_new');
        var valEl = document.getElementById('key_validate_new');
        if(keyEl) keyEl.value = genKey();
        if(valEl) valEl.value = next7();
      });
    }
    // edit buttons
    document.querySelectorAll('[id^="gen_key_edit_"]').forEach(function(btn){
      btn.addEventListener('click', function(){
        var id = btn.id.replace('gen_key_edit_','');
        var keyEl = document.getElementById('key_edit_'+id);
        var valEl = document.getElementById('key_validate_edit_'+id);
        if(keyEl) keyEl.value = genKey();
        if(valEl) valEl.value = next7();
      });
    });
    // toggle Message tab rows
    document.querySelectorAll('.js-open-msg').forEach(function(btn){
      btn.addEventListener('click', function(){
        var id = btn.getAttribute('data-id');
        var row = document.querySelector('.msgRow[data-id="'+id+'"]');
        if (!row) return;
        var isHidden = row.classList.contains('hidden');
        // close others
        document.querySelectorAll('.msgRow').forEach(function(r){ r.classList.add('hidden'); });
        if (isHidden) row.classList.remove('hidden');
      });
    });
  })();
  // Add representative modal behavior
  (function(){
    var openBtn = document.getElementById('btnOpenRepAdd');
    var modal = document.getElementById('repAddModal');
    var cancel = document.getElementById('repAddCancel');
    var input = document.getElementById('rep_full_name');
    function open(){ if(modal){ modal.classList.add('show'); modal.setAttribute('aria-hidden','false'); setTimeout(function(){ try{ input && input.focus(); }catch(e){} }, 100); } }
    function close(){ if(modal){ modal.classList.remove('show'); modal.setAttribute('aria-hidden','true'); } }
    if (openBtn) openBtn.addEventListener('click', open);
    if (cancel) cancel.addEventListener('click', close);
    if (modal) modal.addEventListener('click', function(ev){ if(ev.target===modal) close(); });
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') close(); });
    // phone mask for modal
    (function(){
      var el = document.getElementById('rep_phone');
      if(!el) return;
      function format(v){ v=(v||'').replace(/[^0-9]/g,''); if(v.charAt(0)!=='1') v='1'+v; v=v.slice(0,11); var d=v.slice(1); var a=d.slice(0,3),b=d.slice(3,6),c=d.slice(6,10); var out='+1'; if(a) out+=' '+a; if(b) out+=' '+b; if(c) out+=' '+c; return out; }
      el.addEventListener('focus', function(){ if(!el.value) el.value = '+1 '; });
      el.addEventListener('input', function(){ el.value = format(el.value); });
    })();
  })();
</script>
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
  box.className='toast ' + (existing.classList.contains('error') ? 'error' : 'success');
  var head = document.createElement('div'); head.className='toast-head'; head.innerHTML='<span>'+(existing.classList.contains('error')?'Erro':'Sucesso')+'</span>';
  var btn = document.createElement('button'); btn.className='toast-close'; btn.setAttribute('aria-label','Fechar'); btn.innerHTML='\u00D7';
  var body = document.createElement('div'); body.textContent = text;
  function close(){ if(!overlay) return; overlay.remove(); overlay=null; }
  btn.addEventListener('click', close);
  overlay.addEventListener('click', function(e){ if(e.target===overlay) close(); });
  document.addEventListener('keydown', function(ev){ if(ev.key==='Escape') close(); });
  head.appendChild(btn); box.appendChild(head); box.appendChild(body); overlay.appendChild(box); document.body.appendChild(overlay);
  setTimeout(close, 3000);
})();
</script>
</body>
</html>
