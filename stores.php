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

$company_id = 1;

// Filter by store name
$q_store_name = isset($_GET['q_store_name']) ? trim((string)$_GET['q_store_name']) : '';
// Filter by city
$q_city = isset($_GET['q_city']) ? trim((string)$_GET['q_city']) : '';
// Filter by state
$q_state = isset($_GET['q_state']) ? trim((string)$_GET['q_state']) : '';
// Filter by status
$q_status = isset($_GET['q_status']) ? trim((string)$_GET['q_status']) : '';
// Sort order
$sort_by = isset($_GET['sort_by']) ? trim((string)$_GET['sort_by']) : 'store_name';
$sort_order = isset($_GET['sort']) && $_GET['sort'] === 'desc' ? 'DESC' : 'ASC';
// Validate sort_by to prevent SQL injection
$allowed_sort_columns = ['store_name', 'city', 'state'];
if (!in_array($sort_by, $allowed_sort_columns)) {
  $sort_by = 'store_name';
}

$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_POST[$csrf_name]) || !hash_equals($_SESSION[$csrf_name], (string)$_POST[$csrf_name])) {
    $erro = 'Session expired. Please reload the page.';
  } else {
    if ($action === 'toggle') {
      $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
      $to = isset($_POST['to']) && $_POST['to'] === '1' ? 1 : 0;
      $sql = 'UPDATE stores SET is_active=?, updated_at=NOW() WHERE id=? AND company_id=?';
      if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('iii', $to, $id, $company_id);
        if ($stmt->execute()) { 
          // Check if this is an AJAX request
          $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
          if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'is_active' => $to]);
            exit;
          }
          $_SESSION['flash_ok'] = 'Status updated.'; 
          $redirect = 'stores.php';
          $params = [];
          if ($q_store_name !== '') $params[] = 'q_store_name=' . urlencode($q_store_name);
          if ($q_city !== '') $params[] = 'q_city=' . urlencode($q_city);
          if ($q_state !== '') $params[] = 'q_state=' . urlencode($q_state);
          if ($q_status !== '') $params[] = 'q_status=' . urlencode($q_status);
          if ($sort_by !== 'store_name') $params[] = 'sort_by=' . urlencode($sort_by);
          if ($sort_order !== 'ASC') $params[] = 'sort=' . strtolower($sort_order);
          if (!empty($params)) $redirect .= '?' . implode('&', $params);
          header('Location: ' . $redirect); 
          exit; 
        } else { 
          $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
          if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Failed to update status.']);
            exit;
          }
          $erro = 'Failed to update status.'; 
        }
        $stmt->close();
      } else { 
        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($is_ajax) {
          header('Content-Type: application/json');
          echo json_encode(['success' => false, 'error' => 'Failed to prepare status update.']);
          exit;
        }
        $erro = 'Failed to prepare status update.'; 
      }
    } elseif ($action === 'delete') {
      $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
      $sql = 'DELETE FROM stores WHERE id=? AND company_id=?';
      if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('ii', $id, $company_id);
        if ($stmt->execute()) { 
          $_SESSION['flash_ok'] = 'Store removed.'; 
          $redirect = 'stores.php';
          $params = [];
          if ($q_store_name !== '') $params[] = 'q_store_name=' . urlencode($q_store_name);
          if ($q_city !== '') $params[] = 'q_city=' . urlencode($q_city);
          if ($q_state !== '') $params[] = 'q_state=' . urlencode($q_state);
          if ($q_status !== '') $params[] = 'q_status=' . urlencode($q_status);
          if ($sort_by !== 'store_name') $params[] = 'sort_by=' . urlencode($sort_by);
          if ($sort_order !== 'ASC') $params[] = 'sort=' . strtolower($sort_order);
          if (!empty($params)) $redirect .= '?' . implode('&', $params);
          header('Location: ' . $redirect); 
          exit; 
        } else { 
          $erro = 'Failed to remove store.'; 
        }
        $stmt->close();
      } else { 
        $erro = 'Failed to prepare deletion.'; 
      }
    } elseif ($action === 'update') {
      $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
      $store_name = isset($_POST['store_name']) ? trim((string)$_POST['store_name']) : '';
      $cod = isset($_POST['cod']) ? trim((string)$_POST['cod']) : '';
      $website = isset($_POST['website']) ? trim((string)$_POST['website']) : '';
      $email = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
      $contact_name = isset($_POST['contact_name']) ? trim((string)$_POST['contact_name']) : '';
      // Format phones: remove non-numeric, prepend "1" if not starting with it
      $phone = isset($_POST['phone']) ? trim((string)$_POST['phone']) : '';
      $phone = preg_replace('/[^0-9]/', '', $phone);
      if ($phone && !preg_match('/^1/', $phone)) { $phone = '1' . $phone; }
      $phone2 = isset($_POST['phone2']) ? trim((string)$_POST['phone2']) : '';
      $phone2 = preg_replace('/[^0-9]/', '', $phone2);
      if ($phone2 && !preg_match('/^1/', $phone2)) { $phone2 = '1' . $phone2; }
      $phone3 = isset($_POST['phone3']) ? trim((string)$_POST['phone3']) : '';
      $phone3 = preg_replace('/[^0-9]/', '', $phone3);
      if ($phone3 && !preg_match('/^1/', $phone3)) { $phone3 = '1' . $phone3; }
      $address_line1 = isset($_POST['address_line1']) ? trim((string)$_POST['address_line1']) : '';
      $city = isset($_POST['city']) ? trim((string)$_POST['city']) : '';
      $state = isset($_POST['state']) ? trim((string)$_POST['state']) : '';
      $zip_code = isset($_POST['zip_code']) ? trim((string)$_POST['zip_code']) : '';
      $sales_rep_id = isset($_POST['sales_rep_id']) ? (int)$_POST['sales_rep_id'] : 0;
      $is_active = isset($_POST['is_active']) && $_POST['is_active'] === '1' ? 1 : 0;
      
      if ($store_name === '') { $erro = 'Store Name is required.'; }
      if (!$erro && $id <= 0) { $erro = 'Invalid store ID.'; }
      
      if (!$erro) {
        $sql = 'UPDATE stores SET store_name=?, cod=?, website=?, email=?, contact_name=?, phone=?, phone2=?, phone3=?, address_line1=?, city=?, state=?, zip_code=?, sales_rep_id=?, is_active=?, updated_at=NOW() WHERE id=? AND company_id=?';
        if ($stmt = $conn->prepare($sql)) {
          $stmt->bind_param('ssssssssssssiiii', $store_name, $cod, $website, $email, $contact_name, $phone, $phone2, $phone3, $address_line1, $city, $state, $zip_code, $sales_rep_id, $is_active, $id, $company_id);
          if ($stmt->execute()) { 
            $redirect = 'stores.php';
            $params = [];
            if ($q_store_name !== '') $params[] = 'q_store_name=' . urlencode($q_store_name);
            if ($q_city !== '') $params[] = 'q_city=' . urlencode($q_city);
            if ($q_state !== '') $params[] = 'q_state=' . urlencode($q_state);
            if ($q_status !== '') $params[] = 'q_status=' . urlencode($q_status);
            if ($sort_by !== 'store_name') $params[] = 'sort_by=' . urlencode($sort_by);
            if ($sort_order !== 'ASC') $params[] = 'sort=' . strtolower($sort_order);
            if (!empty($params)) $redirect .= '?' . implode('&', $params);
            header('Location: ' . $redirect); 
            exit; 
          } else { 
            $erro = 'Failed to update store.'; 
          }
          $stmt->close();
        } else {
          $erro = 'Failed to prepare update statement.';
        }
      }
    } elseif ($action === 'delete_all') {
      $sql = 'DELETE FROM stores WHERE company_id=?';
      if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('i', $company_id);
        if ($stmt->execute()) { 
          $_SESSION['flash_ok'] = 'All stores removed.'; 
          $redirect = 'stores.php';
          $params = [];
          if ($q_store_name !== '') $params[] = 'q_store_name=' . urlencode($q_store_name);
          if ($q_city !== '') $params[] = 'q_city=' . urlencode($q_city);
          if ($q_state !== '') $params[] = 'q_state=' . urlencode($q_state);
          if ($q_status !== '') $params[] = 'q_status=' . urlencode($q_status);
          if ($sort_by !== 'store_name') $params[] = 'sort_by=' . urlencode($sort_by);
          if ($sort_order !== 'ASC') $params[] = 'sort=' . strtolower($sort_order);
          if (!empty($params)) $redirect .= '?' . implode('&', $params);
          header('Location: ' . $redirect); 
          exit; 
        } else { 
          $erro = 'Failed to remove all stores.'; 
        }
        $stmt->close();
      } else { 
        $erro = 'Failed to prepare deletion.'; 
      }
    }
  }
}

// AJAX endpoint to get store data
$get_store_id = isset($_GET['get_store']) ? (int)$_GET['get_store'] : 0;
if ($get_store_id > 0) {
  header('Content-Type: application/json');
  $store = null;
  if ($stmt = $conn->prepare('SELECT id, cod, store_name, website, email, contact_name, phone, phone2, phone3, address_line1, city, state, zip_code, sales_rep_id, is_active FROM stores WHERE id=? AND company_id=?')) {
    $stmt->bind_param('ii', $get_store_id, $company_id);
    if ($stmt->execute()) {
      $res = $stmt->get_result();
      $store = $res->fetch_assoc() ?: null;
      $res->free();
    }
    $stmt->close();
  }
  echo json_encode($store);
  exit;
}

// Helper function to format phone number
function formatPhone($phone) {
  if (empty($phone)) return '';
  $phoneClean = preg_replace('/[^0-9]/', '', $phone);
  // Phone format: 1DDDNUMERO or DDDNUMERO (DDD sempre 3 dígitos)
  if (preg_match('/^1?(\d{3})(\d{4,10})$/', $phoneClean, $matches)) {
    $ddd = $matches[1];
    $num = $matches[2];
    $numFormatted = strlen($num) > 4 ? substr($num, 0, -4) . '-' . substr($num, -4) : $num;
    return '(' . $ddd . ') ' . $numFormatted;
  }
  return $phone;
}

// Buscar logo da company
$logo_dir = __DIR__ . '/img_logo';
$logo_url = 'logo.png'; // fallback padrão
$pattern = $logo_dir . '/logo_' . $company_id . '.*';
$existing_files = glob($pattern);
if (!empty($existing_files) && is_file($existing_files[0])) {
    $logo_file = basename($existing_files[0]);
    $logo_url = 'img_logo/' . $logo_file;
}

// List stores
$rows = [];
$where = ' WHERE s.company_id=?';
$types = 'i';
$bindVals = [$company_id];

if ($q_store_name !== '') {
  $where .= ' AND s.store_name LIKE ?';
  $types .= 's';
  $bindVals[] = '%' . $q_store_name . '%';
}

if ($q_city !== '') {
  $where .= ' AND s.city LIKE ?';
  $types .= 's';
  $bindVals[] = '%' . $q_city . '%';
}

if ($q_state !== '') {
  $where .= ' AND s.state LIKE ?';
  $types .= 's';
  $bindVals[] = '%' . $q_state . '%';
}

if ($q_status !== '') {
  if ($q_status === 'active') {
    $where .= ' AND s.is_active=1';
  } elseif ($q_status === 'inactive') {
    $where .= ' AND s.is_active=0';
  }
}

$sqlList = 'SELECT s.id, s.cod, s.store_name, s.contact_name, s.phone, s.phone2, s.phone3, s.email, 
                   s.city, s.state, s.is_active, s.sales_rep_id, s.website,
                   s.address_line1, s.zip_code,
                   sr.full_name AS sales_rep_name
            FROM stores s
            LEFT JOIN sales_reps sr ON sr.id = s.sales_rep_id AND sr.company_id = s.company_id
            ' . $where . ' 
            ORDER BY s.' . $sort_by . ' ' . $sort_order;

if ($stmt = $conn->prepare($sqlList)) {
  $params = [];
  $params[] = &$types;
  foreach ($bindVals as $k => $v) { $params[] = &$bindVals[$k]; }
  call_user_func_array([$stmt, 'bind_param'], $params);
  if ($stmt->execute()) {
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    $res->free();
  }
  $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Catalog Seller - Stores</title>
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

    input[type=text], input[type=number], select{padding:12px 14px; border-radius:10px; border:1px solid rgba(148,163,184,.22); background:#0b1220; color:var(--text); width:100%; height:44px; font-size:14px}
    .btn{appearance:none; display:inline-flex; align-items:center; justify-content:center; gap:6px; border:1px solid #3b4252; background:#0f172a; color:#e5e7eb; font-weight:600; font-size:13px; height:32px; padding:0 12px; border-radius:8px; cursor:pointer; box-shadow:none; text-decoration:none; line-height:1.2}
    .btn:hover{background:#0b1220}
    .btn-primary{background:#22c55e; border-color:#16a34a; color:#fff}
    .btn-primary:hover{background:#16a34a}
    .btn-outline{background:transparent}

    .muted{color:var(--muted)}
    .grid{display:grid; grid-template-columns: repeat(12, 1fr); gap:4px}
    .filters .btn{height:44px}
    .col-12{grid-column: span 12}
    .col-6{grid-column: span 6}
    .col-4{grid-column: span 4}
    .col-3{grid-column: span 3}
    .col-2{grid-column: span 2}
    .col-1{grid-column: span 1}

    table{width:100%; border-collapse:separate; border-spacing:0 4px; table-layout:fixed}
    th, td{padding:8px 4px; text-align:left; vertical-align:middle}
    thead th{font-size:11px; color:var(--muted); font-weight:600; text-transform:uppercase; letter-spacing:0.5px; padding:10px 4px}
    tbody td{font-size:12px; color:var(--text)}
    tbody tr{
      box-shadow: 0 1px 4px rgba(0,0,0,.12); 
      border:1px solid rgba(148,163,184,.10);
      background: rgba(255,255,255,.03);
      border-radius:8px;
    }
    tbody tr:hover{
      background: rgba(255,255,255,.06);
    }
    .badge{display:inline-block; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:500; line-height:1.4}
    .badge-active{background:#22c55e; color:#052e16}
    .badge-inactive{background:#64748b; color:#fff}
    .icon-btn{background:none; border:none; color:var(--text); cursor:pointer; font-size:16px; padding:4px 8px; border-radius:4px; text-decoration:none; display:inline-block}
    .icon-btn:hover{background:rgba(148,163,184,.1)}
    .icon-btn-danger:hover{background:rgba(239,68,68,.2); color:#fca5a5}
    .linkAccent{color:#facc15; text-decoration:none; font-size:14px}
    .linkAccent:hover{text-decoration:underline}
    .badge-toggle:disabled{opacity:0.6; cursor:not-allowed}
    thead th a:hover{opacity:0.8}
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
    .store-modal-overlay{position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(2,6,23,.85); backdrop-filter:blur(4px); z-index:10002; overflow-y:auto; padding:20px}
    .store-modal-overlay.show{display:flex}
    .store-modal-content{position:relative; width:90%; max-width:800px; max-height:90vh; padding:24px; background:rgba(15,23,42,.98); border-radius:14px; border:1px solid rgba(148,163,184,.2); box-shadow:0 10px 40px rgba(0,0,0,.5); overflow-y:auto}
    .store-modal-close{position:absolute; top:15px; right:20px; font-size:32px; font-weight:bold; color:var(--text); cursor:pointer; line-height:1; opacity:.7; transition:opacity 0.2s; z-index:1}
    .store-modal-close:hover{opacity:1}
    .toggle{position:relative; width:44px; height:24px; display:inline-block}
    .toggle input{opacity:0; width:0; height:0}
    .slider{position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background:#0f172a; border:1px solid rgba(148,163,184,.25); border-radius:999px; transition:.15s}
    .slider:before{content:""; position:absolute; height:20px; width:20px; left:1px; top:1px; background:#475569; border-radius:999px; transition:.15s}
    .toggle input:checked + .slider{background:linear-gradient(180deg, #22c55e, #16a34a); border-color:rgba(34,197,94,.6)}
    .toggle input:checked + .slider:before{transform:translateX(20px); background:#052e16}
    /* Toast notification styles */
    .toast-overlay{position:fixed; inset:0; display:flex; align-items:center; justify-content:center; background:rgba(2,6,23,.55); backdrop-filter: blur(4px); z-index:9999}
    .toast{min-width:300px; max-width:92vw; padding:16px 18px; border-radius:14px; border:1px solid rgba(148,163,184,.18); background: linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02)); color:var(--text); box-shadow: 0 10px 26px rgba(0,0,0,.35)}
    .toast.success{border-color:rgba(34,197,94,.55)}
    .toast.error{border-color:rgba(239,68,68,.55)}
    .toast-head{display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:6px; font-weight:700}
    .toast-close{appearance:none; border:0; background:transparent; color:var(--text); font-size:18px; line-height:1; cursor:pointer; padding:2px 6px; border-radius:8px}
    .toast-close:hover{background:rgba(255,255,255,.06)}
    .msg{display:none}
  </style>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      document.querySelectorAll('.badge-toggle').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
          e.preventDefault();
          var btn = this;
          var id = btn.getAttribute('data-id');
          var current = btn.getAttribute('data-current');
          var to = current === '1' ? '0' : '1';
          var csrf = btn.getAttribute('data-csrf');
          
          btn.disabled = true;
          
          var formData = new FormData();
          formData.append('action', 'toggle');
          formData.append('id', id);
          formData.append('to', to);
          formData.append('_csrf', csrf);
          
          fetch('stores.php', {
            method: 'POST',
            headers: {
              'X-Requested-With': 'XMLHttpRequest'
            },
            body: formData
          })
          .then(function(response) {
            return response.json();
          })
          .then(function(data) {
            if (data.success) {
              var isActive = data.is_active === 1;
              btn.setAttribute('data-current', isActive ? '1' : '0');
              btn.textContent = isActive ? 'Active' : 'Inactive';
              btn.style.background = isActive ? '#22c55e' : '#64748b';
              btn.style.color = isActive ? '#052e16' : '#fff';
              if (isActive) {
                btn.classList.remove('badge-inactive');
                btn.classList.add('badge-active');
              } else {
                btn.classList.remove('badge-active');
                btn.classList.add('badge-inactive');
              }
            } else {
              alert('Error: ' + (data.error || 'Failed to update status'));
            }
            btn.disabled = false;
          })
          .catch(function(error) {
            console.error('Error:', error);
            alert('Error updating status. Please try again.');
            btn.disabled = false;
          });
        });
      });
    });

function customConfirm(message, title) {
  return new Promise(function(resolve) {
    var modal = document.getElementById('confirmModal');
    var titleEl = document.getElementById('confirmModalTitle');
    var messageEl = document.getElementById('confirmModalMessage');
    var cancelBtn = document.getElementById('confirmModalCancel');
    var okBtn = document.getElementById('confirmModalOk');
    
    if (!modal || !titleEl || !messageEl || !cancelBtn || !okBtn) {
      // Fallback to native confirm if modal elements are missing
      resolve(confirm(message));
      return;
    }
    
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
        resolve(false);
        document.removeEventListener('keydown', escapeHandler);
      }
    };
    document.addEventListener('keydown', escapeHandler);
  });
}

document.addEventListener('DOMContentLoaded', function() {
  var btn = document.getElementById('deleteAllBtn');
  var form = document.getElementById('deleteAllForm');
  if (!btn || !form) return;
  btn.addEventListener('click', async function(e){
    e.preventDefault();
    // First confirmation
    var first = await customConfirm('WARNING: Do you really want to delete ALL stores? This action cannot be undone.', 'catalogseller.com says');
    if (!first) return;
    // Second confirmation
    var second = await customConfirm('FINAL CONFIRMATION: Are you absolutely sure? All stores will be permanently deleted.', 'catalogseller.com says');
    if (second) {
      form.submit();
    }
  });
});

function openStoreModal(storeId) {
  var modal = document.getElementById('storeModal');
  var form = document.getElementById('storeForm');
  var titleEl = document.getElementById('storeModalTitle');
  var submitBtn = document.getElementById('storeSubmitBtn');
  var actionInput = document.getElementById('storeAction');
  var idInput = document.getElementById('storeId');
  
  if (!modal || !form) return;
  
  // Reset form
  form.reset();
  document.getElementById('active_store_modal').checked = true;
  
  if (storeId) {
    // Load store data via AJAX
    fetch('stores.php?get_store=' + storeId)
      .then(function(response) { return response.json(); })
      .then(function(data) {
        if (data && data.id) {
          titleEl.textContent = 'Edit Store';
          submitBtn.textContent = 'Save';
          actionInput.value = 'update';
          idInput.value = data.id;
          
          // Helper function to format phone for input (remove leading 1 and format)
          function formatPhoneForInput(phone) {
            if (!phone) return '';
            var phoneClean = phone.replace(/[^0-9]/g, '');
            // Remove leading "1" if present
            if (phoneClean.startsWith('1') && phoneClean.length > 10) {
              phoneClean = phoneClean.substring(1);
            }
            return phoneClean;
          }
          
          // Fill form fields
          document.getElementById('store_name_modal').value = data.store_name || '';
          document.getElementById('cod_modal').value = data.cod || '';
          document.getElementById('sales_rep_modal').value = data.sales_rep_id || '0';
          document.getElementById('contact_name_modal').value = data.contact_name || '';
          document.getElementById('email_modal').value = data.email || '';
          document.getElementById('phone_modal').value = formatPhoneForInput(data.phone || '');
          document.getElementById('phone2_modal').value = formatPhoneForInput(data.phone2 || '');
          document.getElementById('phone3_modal').value = formatPhoneForInput(data.phone3 || '');
          document.getElementById('website_modal').value = data.website || '';
          document.getElementById('address_line1_modal').value = data.address_line1 || '';
          document.getElementById('city_modal').value = data.city || '';
          document.getElementById('state_modal').value = data.state || '';
          document.getElementById('zip_code_modal').value = data.zip_code || '';
          document.getElementById('active_store_modal').checked = data.is_active == 1;
        }
        modal.classList.add('show');
      })
      .catch(function(error) {
        console.error('Error loading store:', error);
        alert('Error loading store data.');
        modal.classList.add('show');
      });
  } else {
    modal.classList.add('show');
  }
  
  // Close on Escape key
  var escapeHandler = function(e) {
    if (e.key === 'Escape') {
      closeStoreModal();
      document.removeEventListener('keydown', escapeHandler);
    }
  };
  document.addEventListener('keydown', escapeHandler);
}

function closeStoreModal() {
  var modal = document.getElementById('storeModal');
  if (modal) {
    modal.classList.remove('show');
  }
}
  </script>
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

  <div id="storeModal" class="store-modal-overlay" onclick="closeStoreModal()">
    <div class="store-modal-content" onclick="event.stopPropagation()">
      <span class="store-modal-close" onclick="closeStoreModal()">&times;</span>
      <h2 style="margin:0 0 20px 0; color:var(--text)" id="storeModalTitle">Edit Store</h2>
      <form method="post" id="storeForm" class="grid">
        <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" id="storeAction" value="update">
        <input type="hidden" name="id" id="storeId" value="">

        <div class="col-6">
          <label class="muted" for="store_name_modal">Store Name <span style="color:#ef4444">*</span></label>
          <input id="store_name_modal" type="text" name="store_name" placeholder="Store name" required>
        </div>
        <div class="col-3">
          <label class="muted" for="cod_modal">Cod</label>
          <input id="cod_modal" type="text" name="cod" placeholder="Code">
        </div>
        <div class="col-3">
          <label class="muted" for="sales_rep_modal">Sales Representative</label>
          <select id="sales_rep_modal" name="sales_rep_id">
            <option value="0">None</option>
            <?php foreach ($sales_reps as $sr): ?>
            <option value="<?php echo (int)$sr['id']; ?>"><?php echo htmlspecialchars($sr['full_name'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-3">
          <label class="muted" for="contact_name_modal">Contact Name</label>
          <input id="contact_name_modal" type="text" name="contact_name" placeholder="Contact name">
        </div>
        <div class="col-3">
          <label class="muted" for="phone_modal">Phone 1</label>
          <input id="phone_modal" type="text" name="phone" placeholder="Phone 1" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
        </div>
        <div class="col-3">
          <label class="muted" for="phone2_modal">Phone 2</label>
          <input id="phone2_modal" type="text" name="phone2" placeholder="Phone 2" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
        </div>
        <div class="col-3">
          <label class="muted" for="phone3_modal">Phone 3</label>
          <input id="phone3_modal" type="text" name="phone3" placeholder="Phone 3" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
        </div>
        <div class="col-6">
          <label class="muted" for="email_modal">Email</label>
          <input id="email_modal" type="text" name="email" placeholder="email@example.com">
        </div>
        <div class="col-6">
          <label class="muted" for="website_modal">Website</label>
          <input id="website_modal" type="text" name="website" placeholder="https://example.com">
        </div>
        <div class="col-12">
          <label class="muted" for="address_line1_modal">Address</label>
          <input id="address_line1_modal" type="text" name="address_line1" placeholder="Address line 1">
        </div>
        <div class="col-4">
          <label class="muted" for="city_modal">City</label>
          <input id="city_modal" type="text" name="city" placeholder="City">
        </div>
        <div class="col-4">
          <label class="muted" for="state_modal">State</label>
          <input id="state_modal" type="text" name="state" placeholder="State" maxlength="2" style="text-transform:uppercase" oninput="this.value = this.value.toUpperCase()">
        </div>
        <div class="col-4">
          <label class="muted" for="zip_code_modal">Zip Code</label>
          <input id="zip_code_modal" type="text" name="zip_code" placeholder="Zip code">
        </div>
        <div class="col-12" style="display:flex; flex-direction:column; gap:6px">
          <label class="muted">Active</label>
          <label class="toggle">
            <input type="checkbox" name="is_active" value="1" id="active_store_modal" checked>
            <span class="slider"></span>
          </label>
        </div>
        <div class="col-12" style="display:flex; align-items:flex-end; justify-content:flex-end; gap:8px; margin-top:10px">
          <button type="button" class="btn btn-outline" onclick="closeStoreModal()">Cancel</button>
          <button type="submit" class="btn" id="storeSubmitBtn">Save</button>
        </div>
      </form>
    </div>
  </div>

    <section class="card">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px">
        <h1 style="margin:0; font-size:24px; font-weight:700">Stores</h1>
        <div style="display:flex; align-items:center; gap:12px">
          <a href="import_stores.php" class="linkAccent" style="font-size:14px">Import Stores</a>
          <form method="post" id="deleteAllForm" style="display:inline; margin:0">
            <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="delete_all">
            <button type="button" class="linkAccent" id="deleteAllBtn" style="background:none; border:none; padding:0; cursor:pointer; font-size:14px; color:#ef4444">Delete All</button>
          </form>
        </div>
      </div>

      <?php if ($erro): ?>
        <div class="msg error"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>
      <?php if ($flash_ok): ?>
        <div class="msg ok"><?php echo htmlspecialchars($flash_ok, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <form method="get" class="grid filters" style="margin-bottom:20px">
        <?php if ($sort_by !== 'store_name'): ?>
        <input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sort_by, ENT_QUOTES, 'UTF-8'); ?>">
        <?php endif; ?>
        <?php if ($sort_order !== 'ASC'): ?>
        <input type="hidden" name="sort" value="<?php echo strtolower($sort_order); ?>">
        <?php endif; ?>
        <div class="col-3">
          <label class="muted" for="q_store_name">Store Name</label>
          <input type="text" id="q_store_name" name="q_store_name" value="<?php echo htmlspecialchars($q_store_name, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search by store name...">
        </div>
        <div class="col-3">
          <label class="muted" for="q_city">City</label>
          <input type="text" id="q_city" name="q_city" value="<?php echo htmlspecialchars($q_city, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search by city...">
        </div>
        <div class="col-2">
          <label class="muted" for="q_state">State</label>
          <input type="text" id="q_state" name="q_state" value="<?php echo htmlspecialchars($q_state, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search by state..." maxlength="2" style="text-transform:uppercase" oninput="this.value = this.value.toUpperCase()">
        </div>
        <div class="col-2">
          <label class="muted" for="q_status">Status</label>
          <select id="q_status" name="q_status" onchange="this.form.submit()">
            <option value="">All</option>
            <option value="active" <?php echo ($q_status==='active'?'selected':''); ?>>Active</option>
            <option value="inactive" <?php echo ($q_status==='inactive'?'selected':''); ?>>Inactive</option>
          </select>
        </div>
        <div class="col-2" style="display:flex; align-items:flex-end; gap:6px">
          <button class="btn" type="submit" style="flex:1">Filter</button>
          <a class="btn btn-outline" href="stores.php" style="flex:1; text-align:center">Clear</a>
        </div>
      </form>

      <table>
        <thead>
          <tr>
            <th style="width:200px">
              <a href="?<?php 
                $params = [];
                if ($q_store_name !== '') $params[] = 'q_store_name=' . urlencode($q_store_name);
                if ($q_city !== '') $params[] = 'q_city=' . urlencode($q_city);
                if ($q_state !== '') $params[] = 'q_state=' . urlencode($q_state);
                if ($q_status !== '') $params[] = 'q_status=' . urlencode($q_status);
                $new_sort = ($sort_by === 'store_name' && $sort_order === 'ASC') ? 'desc' : 'asc';
                $params[] = 'sort_by=store_name';
                $params[] = 'sort=' . $new_sort;
                echo implode('&', $params);
              ?>" style="color:inherit; text-decoration:none; display:flex; align-items:center; gap:6px; cursor:pointer">
                Store Name
                <?php if ($sort_by === 'store_name'): ?>
                <span style="font-size:10px; opacity:0.7"><?php echo $sort_order === 'ASC' ? '↑' : '↓'; ?></span>
                <?php endif; ?>
              </a>
            </th>
            <th style="width:100px">Contact</th>
            <th style="width:120px">Phone</th>
            <th style="width:150px">Email</th>
            <th style="width:100px">
              <a href="?<?php 
                $params = [];
                if ($q_store_name !== '') $params[] = 'q_store_name=' . urlencode($q_store_name);
                if ($q_city !== '') $params[] = 'q_city=' . urlencode($q_city);
                if ($q_state !== '') $params[] = 'q_state=' . urlencode($q_state);
                if ($q_status !== '') $params[] = 'q_status=' . urlencode($q_status);
                $new_sort = ($sort_by === 'city' && $sort_order === 'ASC') ? 'desc' : 'asc';
                $params[] = 'sort_by=city';
                $params[] = 'sort=' . $new_sort;
                echo implode('&', $params);
              ?>" style="color:inherit; text-decoration:none; display:flex; align-items:center; gap:6px; cursor:pointer">
                City
                <?php if ($sort_by === 'city'): ?>
                <span style="font-size:10px; opacity:0.7"><?php echo $sort_order === 'ASC' ? '↑' : '↓'; ?></span>
                <?php endif; ?>
              </a>
            </th>
            <th style="width:80px">
              <a href="?<?php 
                $params = [];
                if ($q_store_name !== '') $params[] = 'q_store_name=' . urlencode($q_store_name);
                if ($q_city !== '') $params[] = 'q_city=' . urlencode($q_city);
                if ($q_state !== '') $params[] = 'q_state=' . urlencode($q_state);
                if ($q_status !== '') $params[] = 'q_status=' . urlencode($q_status);
                $new_sort = ($sort_by === 'state' && $sort_order === 'ASC') ? 'desc' : 'asc';
                $params[] = 'sort_by=state';
                $params[] = 'sort=' . $new_sort;
                echo implode('&', $params);
              ?>" style="color:inherit; text-decoration:none; display:flex; align-items:center; gap:6px; cursor:pointer">
                State
                <?php if ($sort_by === 'state'): ?>
                <span style="font-size:10px; opacity:0.7"><?php echo $sort_order === 'ASC' ? '↑' : '↓'; ?></span>
                <?php endif; ?>
              </a>
            </th>
            <th style="width:80px">Status</th>
            <th style="width:80px; text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td><?php echo htmlspecialchars($r['store_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r['contact_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars(formatPhone($r['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r['city'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($r['state'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td>
              <button type="button" 
                      class="badge-toggle badge <?php echo $r['is_active'] ? 'badge-active' : 'badge-inactive'; ?>" 
                      data-id="<?php echo (int)$r['id']; ?>"
                      data-current="<?php echo $r['is_active'] ? '1' : '0'; ?>"
                      data-csrf="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>"
                      style="border:none; cursor:pointer; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:500; line-height:1.4; background:<?php echo $r['is_active'] ? '#22c55e' : '#64748b'; ?>; color:<?php echo $r['is_active'] ? '#052e16' : '#fff'; ?>">
                <?php echo $r['is_active'] ? 'Active' : 'Inactive'; ?>
              </button>
            </td>
            <td style="white-space:nowrap; text-align:right">
              <a href="javascript:void(0)" 
                 onclick="openStoreModal(<?php echo (int)$r['id']; ?>)" 
                 title="Edit"
                 class="icon-btn">✏️</a>
              <form method="post" onsubmit="return confirm('Remove this store?');" style="display:inline">
                <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <button type="submit" 
                        title="Delete"
                        class="icon-btn icon-btn-danger"
                        style="margin-right:0">🗑️</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
          <tr><td colspan="8" class="muted" style="text-align:center; padding:40px">No stores found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </section>
  </div>
<script>
// Toast from existing .msg elements (run immediately when DOM is ready)
(function(){
  function showToast() {
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
    var escapeHandler = function(ev){ if(ev.key==='Escape') { close(); document.removeEventListener('keydown', escapeHandler); } };
    document.addEventListener('keydown', escapeHandler);
    head.appendChild(btn); box.appendChild(head); box.appendChild(body); overlay.appendChild(box); document.body.appendChild(overlay);
    // Auto-close after 1 second
    setTimeout(close, 1000);
  }
  
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', showToast);
  } else {
    showToast();
  }
})();
</script>
</body>
</html>

