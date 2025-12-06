<?php
// ============================================
// SISTEMA DE DEBUG - ATIVAR/DESATIVAR AQUI
// ============================================
$DEBUG_MODE = true; // Mude para false em produção

if ($DEBUG_MODE) {
  error_reporting(E_ALL);
  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
  ini_set('log_errors', 1);
  
  // Criar arquivo de log
  $DEBUG_LOG_FILE = __DIR__ . '/products_debug.log';
  
  // Função simples de log
  function debug_log($msg, $level = 'INFO') {
    global $DEBUG_LOG_FILE;
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] [$level] $msg\n";
    @file_put_contents($DEBUG_LOG_FILE, $entry, FILE_APPEND);
    
    // Também mostrar na tela se for erro crítico
    if (in_array($level, ['ERROR', 'FATAL', 'EXCEPTION'])) {
      echo "<!-- DEBUG ERROR: " . htmlspecialchars($msg, ENT_QUOTES) . " -->\n";
    }
  }
  
  // Registrar erro fatal
  register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
      debug_log("FATAL ERROR: {$error['message']} in {$error['file']}:{$error['line']}", 'FATAL');
    }
  });
  
  debug_log('=== SCRIPT INICIADO ===', 'START');
} else {
  error_reporting(0);
  ini_set('display_errors', 0);
}

try {
  debug_log('Iniciando session_start()', 'SESSION');
  session_start();
  
  if (!isset($_SESSION['user_id'])) { 
    debug_log('Usuário não autenticado', 'AUTH');
    header('Location: login.php'); 
    exit; 
  }
  debug_log('Usuário autenticado: ' . $_SESSION['user_id'], 'AUTH');
  
  debug_log('Carregando config.php', 'CONFIG');
  $arquivoConfig = __DIR__ . '/config.php';
  if (!file_exists($arquivoConfig)) { 
    debug_log('ERRO: config.php não encontrado', 'ERROR');
    die('config.php not found'); 
  }
  
  require_once $arquivoConfig;
  debug_log('config.php carregado', 'CONFIG');
  
  // Verificar conexão
  if (!isset($conn)) {
    debug_log('ERRO: $conn não definido', 'ERROR');
    die('Database connection not initialized');
  }
  
  if (is_object($conn) && isset($conn->connect_error) && $conn->connect_error) {
    debug_log('ERRO de conexão: ' . $conn->connect_error, 'ERROR');
    die('Database connection error: ' . $conn->connect_error);
  }
  
  debug_log('Conexão OK', 'DB');
  
} catch (Throwable $e) {
  if ($DEBUG_MODE) {
    debug_log('EXCEÇÃO: ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine(), 'EXCEPTION');
    die('<h1 style="color:red">Erro Fatal</h1><pre>' . htmlspecialchars($e->getMessage() . "\n\n" . $e->getTraceAsString()) . '</pre>');
  } else {
    die('Erro interno');
  }
}

$csrf_name = '_csrf';
if (empty($_SESSION[$csrf_name])) { $_SESSION[$csrf_name] = bin2hex(random_bytes(16)); }
$csrf_token = $_SESSION[$csrf_name];

$erro = '';
$flash_ok = '';
if (!empty($_SESSION['flash_ok'])) { $flash_ok = (string)$_SESSION['flash_ok']; unset($_SESSION['flash_ok']); }

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

function post_num($key) {
  if (!isset($_POST[$key]) || $_POST[$key] === '') return null;
  return (float)str_replace(',', '.', (string)$_POST[$key]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  if (!isset($_POST[$csrf_name]) || !hash_equals($_SESSION[$csrf_name], (string)$_POST[$csrf_name])) {
    $erro = 'Session expired. Please reload the page.';
  } else {

    if ($action === 'create' || $action === 'update') {

      $manufacturer_id = isset($_POST['manufacturer_id']) ? (int)$_POST['manufacturer_id'] : 0;
      $section_id      = isset($_POST['section_id']) ? (int)$_POST['section_id'] : 0;
      $description     = isset($_POST['description']) ? trim((string)$_POST['description']) : '';
      $unit            = isset($_POST['unit']) ? trim((string)$_POST['unit']) : '';
      $box_qty         = isset($_POST['box_qty']) && $_POST['box_qty'] !== '' ? (int)$_POST['box_qty'] : null;

      $real_value      = post_num('real_value');
      $discounted_value = post_num('discounted_value');
      $unit_value      = post_num('unit_value');

      if ($unit_value === null) {
        $unit_value = 0.00;
      }

      $is_great_deal = isset($_POST['is_great_deal']) && $_POST['is_great_deal'] === '1' ? 1 : 0;
      $is_active     = isset($_POST['is_active']) && $_POST['is_active'] === '1' ? 1 : 0;
      $is_featured   = isset($_POST['is_featured']) && $_POST['is_featured'] === '1' ? 1 : 0;

      if ($manufacturer_id <= 0) { $erro = 'Manufacturer is required.'; }
      if (!$erro && $section_id <= 0) { $erro = 'Section is required.'; }
      if (!$erro && $description === '') { $erro = 'Description is required.'; }

      if (!$erro) {

        if ($action === 'create') {

          $cod = isset($_POST['cod']) ? trim((string)$_POST['cod']) : '';

          $sql = 'INSERT INTO products (company_id, manufacturer_id, section_id, description, unit, box_qty, real_value, unit_value, is_great_deal, is_active, is_featured, created_at, updated_at) 
                  VALUES (?,?,?,?,?,?,?,?,?,?,?, NOW(), NOW())';

          if ($stmt = $conn->prepare($sql)) {

            $stmt->bind_param('iiissiddiiii', 
              $company_id, $manufacturer_id, $section_id, 
              $description, $unit, $box_qty, 
              $real_value, $unit_value, 
              $is_great_deal, $is_active, $is_featured
            );

            if ($stmt->execute()) {

              $new_product_id = $stmt->insert_id;
              $stmt->close();

              if ($cod === '') {
                $cod = (string)$new_product_id;
              }

              $update_sql = 'UPDATE products SET cod=? WHERE id=? AND company_id=?';
              if ($update_stmt = $conn->prepare($update_sql)) {
                $update_stmt->bind_param('sii', $cod, $new_product_id, $company_id);
                $update_stmt->execute();
                $update_stmt->close();
              }

              $_SESSION['flash_ok'] = 'Product created successfully.';
              header('Location: products.php');
              exit;

            } else {

              $erro = 'Failed to save product.';
              $stmt->close();
            }

          } else {
            $erro = 'Failed to prepare statement.';
          }

        } else {  // UPDATE

          $id  = isset($_POST['id']) ? (int)$_POST['id'] : 0;
          $cod = isset($_POST['cod']) ? trim((string)$_POST['cod']) : '';

          $sql = 'UPDATE products SET manufacturer_id=?, section_id=?, description=?, unit=?, box_qty=?, real_value=?, unit_value=?, is_great_deal=?, is_active=?, is_featured=?, updated_at=NOW() 
                  WHERE id=? AND company_id=?';

          if ($stmt = $conn->prepare($sql)) {

            $stmt->bind_param('iissiddiiiii',
              $manufacturer_id, $section_id, $description, 
              $unit, $box_qty, $real_value, $unit_value,
              $is_great_deal, $is_active, $is_featured,
              $id, $company_id
            );

            if ($stmt->execute()) {

              if ($cod !== '') {
                $update_sql = 'UPDATE products SET cod=? WHERE id=? AND company_id=?';
                if ($update_stmt = $conn->prepare($update_sql)) {
                  $update_stmt->bind_param('sii', $cod, $id, $company_id);
                  $update_stmt->execute();
                  $update_stmt->close();
                }
              }

              $stmt->close();
              $_SESSION['flash_ok'] = 'Product updated.';
              header('Location: products.php');
              exit;

            } else {
              $erro = 'Failed to save product.';
              $stmt->close();
            }

          } else {
            $erro = 'Failed to prepare statement.';
          }
        }
      }

    } elseif ($action === 'toggle') {

      $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
      $to = isset($_POST['to']) && $_POST['to'] === '1' ? 1 : 0;

      $sql = 'UPDATE products SET is_active=?, updated_at=NOW() WHERE id=? AND company_id=?';

      if ($stmt = $conn->prepare($sql)) {

        $stmt->bind_param('iii', $to, $id, $company_id);

        if ($stmt->execute()) {
          $_SESSION['flash_ok'] = 'Status updated.';
          header('Location: products.php');
          exit;
        } else {
          $erro = 'Failed to update status.';
        }

        $stmt->close();

      } else {
        $erro = 'Failed to prepare status update.';
      }

    } elseif ($action === 'toggle_featured') {

      $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
      $to = isset($_POST['to']) && $_POST['to'] === '1' ? 1 : 0;

      header('Content-Type: application/json');
      $result = ['success' => false];

      $sql = 'UPDATE products SET is_featured=?, updated_at=NOW() WHERE id=? AND company_id=?';

      if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('iii', $to, $id, $company_id);
        if ($stmt->execute()) {
          $result['success'] = true;
        }
        $stmt->close();
      }

      echo json_encode($result);
      exit;

    } elseif ($action === 'delete') {

      $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
      $sql = 'DELETE FROM products WHERE id=? AND company_id=?';

      if ($stmt = $conn->prepare($sql)) {

        $stmt->bind_param('ii', $id, $company_id);

        if ($stmt->execute()) {
          $_SESSION['flash_ok'] = 'Product removed.';
          header('Location: products.php'); exit;
        } else {
          $erro = 'Failed to remove.';
        }

        $stmt->close();

      } else {
        $erro = 'Failed to prepare deletion.';
      }

    } elseif ($action === 'delete_all') {

      $sql = 'DELETE FROM products WHERE company_id=?';

      if ($stmt = $conn->prepare($sql)) {

        $stmt->bind_param('i', $company_id);

        if ($stmt->execute()) {
          $_SESSION['flash_ok'] = 'All products removed.';
          header('Location: products.php'); exit;
        } else {
          $erro = 'Failed to remove all products.';
        }

        $stmt->close();
      } else {
        $erro = 'Failed to prepare deletion.';
      }

    } elseif ($action === 'upload_photo') {

      // --- (todo o seu bloco upload permanece igual) ---

    } elseif ($action === 'delete_photo') {

      // --- (todo o seu bloco delete photo permanece igual) ---

    }

  } // fecha o else interno
} // fecha o if ($_SERVER['REQUEST_METHOD'] === 'POST')

// load manufacturers and sections (active only) by company
if ($DEBUG_MODE) debug_log('Carregando manufacturers e sections', 'DB');
$manufacturers = [];
if ($stmt = $conn->prepare('SELECT id, description FROM manufacturers WHERE company_id=? AND is_active=1 ORDER BY description')) {
  $stmt->bind_param('i', $company_id);
  if ($stmt->execute()) { 
    $res=$stmt->get_result(); 
    while($r=$res->fetch_assoc()){ $manufacturers[]=$r; } 
    $res->free(); 
    if ($DEBUG_MODE) debug_log('Manufacturers carregados: ' . count($manufacturers), 'DB');
  } else {
    if ($DEBUG_MODE) debug_log('ERRO ao executar query manufacturers: ' . $stmt->error, 'ERROR');
  }
  $stmt->close();
} else {
  if ($DEBUG_MODE) debug_log('ERRO ao preparar query manufacturers: ' . $conn->error, 'ERROR');
}

$sections = [];
if ($stmt = $conn->prepare('SELECT id, description FROM sections WHERE company_id=? AND is_active=1 ORDER BY description')) {
  $stmt->bind_param('i', $company_id);
  if ($stmt->execute()) { 
    $res=$stmt->get_result(); 
    while($r=$res->fetch_assoc()){ $sections[]=$r; } 
    $res->free(); 
    if ($DEBUG_MODE) debug_log('Sections carregados: ' . count($sections), 'DB');
  } else {
    if ($DEBUG_MODE) debug_log('ERRO ao executar query sections: ' . $stmt->error, 'ERROR');
  }
  $stmt->close();
} else {
  if ($DEBUG_MODE) debug_log('ERRO ao preparar query sections: ' . $conn->error, 'ERROR');
}

// list products
$rows = [];
// Search filters (GET)
$q_cod = isset($_GET['q_cod']) ? trim((string)$_GET['q_cod']) : '';
$q_desc = isset($_GET['q_desc']) ? trim((string)$_GET['q_desc']) : '';
$q_manu = isset($_GET['q_manu']) ? (int)$_GET['q_manu'] : 0;
$q_sec  = isset($_GET['q_sec'])  ? (int)$_GET['q_sec']  : 0;
$q_active = isset($_GET['q_active']) ? (string)$_GET['q_active'] : '';
$q_great_deal = isset($_GET['q_great_deal']) ? (string)$_GET['q_great_deal'] : '';
$q_no_photo = isset($_GET['q_no_photo']) && $_GET['q_no_photo'] === '1' ? true : false;
// Sorting
$order_by = isset($_GET['order_by']) ? (string)$_GET['order_by'] : '';
$order_dir = isset($_GET['order_dir']) && $_GET['order_dir'] === 'ASC' ? 'ASC' : 'DESC';

$baseSql = 'SELECT p.id, p.cod, p.manufacturer_id, p.section_id, p.description, p.unit, p.box_qty, p.real_value,
                   COALESCE(p.unit_value, CASE WHEN p.box_qty IS NOT NULL AND p.box_qty>0 THEN p.real_value / p.box_qty ELSE NULL END) AS unit_value,
                   p.is_great_deal, p.is_active, p.is_featured,
                   m.description AS manufacturer, s.description AS section
            FROM products p
            LEFT JOIN manufacturers m ON m.id=p.manufacturer_id AND m.company_id=p.company_id
            LEFT JOIN sections s ON s.id=p.section_id AND s.company_id=p.company_id';

$where = ' WHERE p.company_id=?';
$types = 'i';
$bindVals = [ $company_id ];
if ($q_cod !== '') { $where .= ' AND p.cod = ?'; $types .= 's'; $bindVals[] = $q_cod; }
if ($q_manu > 0) { $where .= ' AND p.manufacturer_id=?'; $types .= 'i'; $bindVals[] = $q_manu; }
if ($q_sec  > 0) { $where .= ' AND p.section_id=?';      $types .= 'i'; $bindVals[] = $q_sec; }
if ($q_desc !== '') { $where .= ' AND p.description LIKE ?'; $types .= 's'; $bindVals[] = ('%'.$q_desc.'%'); }
if ($q_active !== '') { $where .= ' AND p.is_active=?'; $types .= 'i'; $bindVals[] = ($q_active === '1' ? 1 : 0); }
if ($q_great_deal !== '') { $where .= ' AND p.is_great_deal=?'; $types .= 'i'; $bindVals[] = ($q_great_deal === '1' ? 1 : 0); }

// Build ORDER BY clause
$orderClause = ' ORDER BY ';
if ($order_by === 'manufacturer') {
  $orderClause .= 'm.description ' . $order_dir . ', p.updated_at DESC, p.id DESC';
} elseif ($order_by === 'section') {
  $orderClause .= 's.description ' . $order_dir . ', p.updated_at DESC, p.id DESC';
} else {
  $orderClause .= 'p.updated_at DESC, p.id DESC';
}

$sqlList = $baseSql . $where . $orderClause;
if ($DEBUG_MODE) debug_log('Preparando query produtos. SQL length: ' . strlen($sqlList), 'DB');
if ($stmt = $conn->prepare($sqlList)) {
  // bind dynamically (all by reference)
  $params = [];
  $params[] = &$types;
  foreach ($bindVals as $k => $v) { $params[] = &$bindVals[$k]; }
  call_user_func_array([$stmt, 'bind_param'], $params);
  if ($stmt->execute()) {
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    $res->free();
    if ($DEBUG_MODE) debug_log('Produtos carregados: ' . count($rows), 'DB');
  } else {
    if ($DEBUG_MODE) debug_log('ERRO ao executar query produtos: ' . $stmt->error, 'ERROR');
  }
  $stmt->close();
} else {
  if ($DEBUG_MODE) debug_log('ERRO ao preparar query produtos: ' . $conn->error, 'ERROR');
}

// Filter products without photos if requested
if ($q_no_photo) {
  $filtered_rows = [];
  // Buscar slug da empresa uma vez antes do loop
  $company_slug = '';
  $slug_sql = 'SELECT slug FROM companies WHERE id = ? AND is_active = 1 LIMIT 1';
  if ($slug_stmt = $conn->prepare($slug_sql)) {
    $slug_stmt->bind_param('i', $company_id);
    $slug_stmt->execute();
    $slug_result = $slug_stmt->get_result();
    $slug_row = $slug_result->fetch_assoc();
    if ($slug_row && isset($slug_row['slug']) && $slug_row['slug'] !== '') {
      $company_slug = trim((string)$slug_row['slug']);
    }
    $slug_result->free();
    $slug_stmt->close();
  }
  $folder_name = $company_slug !== '' ? $company_slug : 'company_' . $company_id;
  
  foreach ($rows as $r) {
    $product_cod = (string)$r['cod'];
    // Buscar foto no novo padrão: {cod}.png na pasta do slug (raiz)
    $photo_file = __DIR__ . '/' . $folder_name . '/' . $product_cod . '.png';
    $existing_photo = file_exists($photo_file) ? [$photo_file] : [];
    // Se não encontrar, tentar padrão antigo para compatibilidade
    if (empty($existing_photo)) {
      $photo_pattern = __DIR__ . '/img_product/img_' . $company_id . '_' . $product_cod . '.*';
      $existing_photo = glob($photo_pattern);
    }
    if (empty($existing_photo)) {
      $filtered_rows[] = $r;
    }
  }
  $rows = $filtered_rows;
}

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$getProductId = isset($_GET['get_product']) ? (int)$_GET['get_product'] : 0;

// If requesting product data via AJAX
if ($getProductId > 0) {
  header('Content-Type: application/json');
  $product = null;
  if ($stmt = $conn->prepare('SELECT id, cod, manufacturer_id, section_id, description, unit, box_qty, real_value, unit_value, is_great_deal, is_active, is_featured FROM products WHERE id=? AND company_id=?')) {
    $stmt->bind_param('ii', $getProductId, $company_id);
    if ($stmt->execute()) {
      $res = $stmt->get_result();
      $product = $res->fetch_assoc() ?: null;
      $res->free();
    }
    $stmt->close();
  }
  echo json_encode($product);
  exit;
}

// If editing, load the product to prefill the top form (legacy support)
$editing = null;
if ($editId > 0) {
  if ($stmt = $conn->prepare('SELECT id, cod, manufacturer_id, section_id, description, unit, box_qty, real_value, unit_value, is_great_deal, is_active FROM products WHERE id=? AND company_id=?')) {
    $stmt->bind_param('ii', $editId, $company_id);
    if ($stmt->execute()) {
      $res = $stmt->get_result();
      $editing = $res->fetch_assoc() ?: null;
      $res->free();
    }
    $stmt->close();
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Catalog Seller - Products</title>
  <style>
    :root { --bg:#0f172a; --muted:#94a3b8; --text:#e2e8f0; }
    *{box-sizing:border-box}
    body{margin:0; font-family: system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,"Helvetica Neue",Arial; background: radial-gradient(1200px 800px at 80% -10%, #1e293b, transparent), var(--bg); color:var(--text); min-height:100vh;}
    .container{max-width:1400px; margin:24px auto; padding:0 24px}
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
    .btn-outline{background:transparent}
    .btn-danger{border-color:#5b616e; background:#141a23; color:#e5e7eb}
    .btn-danger:hover{background:#0f141c}

    .muted{color:var(--muted)}
    .grid{display:grid; grid-template-columns: repeat(12, 1fr); gap:4px}
    /* Make filter buttons match input height */
    .filters .btn{height:44px}
    .col-12{grid-column: span 12}
    .col-6{grid-column: span 6}
    .col-4{grid-column: span 4}
    .col-3{grid-column: span 3}
    .col-2{grid-column: span 2}
    .separator{grid-column: 1 / -1; height:2px; background: rgba(148,163,184,.28); border-radius:2px; margin:8px 0 10px}

    .toggle{position:relative; width:44px; height:24px; display:inline-block}
    .toggle input{opacity:0; width:0; height:0}
    .slider{position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background:#0f172a; border:1px solid rgba(148,163,184,.25); border-radius:999px; transition:.15s}
    .slider:before{content:""; position:absolute; height:20px; width:20px; left:1px; top:1px; background:#475569; border-radius:999px; transition:.15s}
    .toggle input:checked + .slider{background:linear-gradient(180deg, #22c55e, #16a34a); border-color:rgba(34,197,94,.6)}
    .toggle input:checked + .slider:before{transform:translateX(20px); background:#052e16}

    table{width:100%; border-collapse:separate; border-spacing:0 4px; table-layout:fixed}
    th, td{padding:8px 4px; text-align:left; vertical-align:middle}
    thead th{font-size:11px; color:var(--muted); font-weight:600; text-transform:uppercase; letter-spacing:0.5px; padding:10px 4px}
    thead tr:first-child th{border-bottom:none}
    thead tr:last-child th{border-top:none; padding-top:4px}
    tbody td{font-size:12px; color:var(--text)}
    th.sortable{cursor:pointer; user-select:none; position:relative; padding-right:20px}
    th.sortable:hover{color:var(--text)}
    th.sortable .sort-indicator, .sortable .sort-indicator{position:absolute; right:0; top:50%; transform:translateY(-50%); font-size:10px; opacity:0.6}
    th.sortable.active .sort-indicator, .sortable.active .sort-indicator{opacity:1; color:#facc15}
    th.sortable:hover, .sortable:hover{color:var(--text)}
    th .sortable{cursor:pointer; user-select:none; position:relative; padding-right:16px}
    tbody tr{
      box-shadow: 0 1px 4px rgba(0,0,0,.12); 
      border:1px solid rgba(148,163,184,.10);
      background: rgba(255,255,255,.03);
      border-radius:8px;
    }
    tbody tr:hover{
      background: rgba(255,255,255,.06);
      border-color: rgba(148,163,184,.16);
    }
    tbody tr td{background: inherit !important;}
    td.num, th.num{text-align:right}
    .ellipsis{display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; text-overflow:ellipsis; line-height:1.4; word-wrap:break-word; color:var(--text) !important; min-height:2.8em; background:inherit !important; font-size:11px}
    /* Photo thumbnail */
    .photo-thumb{width:32px; height:32px; object-fit:cover; border-radius:4px; border:1px solid rgba(148,163,184,.2); cursor:pointer; display:block}
    .photo-placeholder{width:32px; height:32px; border-radius:4px; border:1px solid rgba(148,163,184,.2); background:rgba(148,163,184,.1); display:flex; align-items:center; justify-content:center; color:rgba(148,163,184,.5); font-size:16px; cursor:pointer}
    .photo-upload-btn{height:24px; padding:0 8px; font-size:11px; margin-left:6px}
    /* Badges */
    .badge{display:inline-block; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:500; line-height:1.4}
    .badge-active{background:rgba(34,197,94,.15); color:#22c55e; border:1px solid rgba(34,197,94,.3)}
    .badge-inactive{background:rgba(148,163,184,.15); color:var(--muted); border:1px solid rgba(148,163,184,.3)}
    .badge-deal{background:rgba(251,191,36,.15); color:#fbbf24; border:1px solid rgba(251,191,36,.3)}
    /* Compact action buttons */
    .btn-compact{height:28px; padding:0 10px; font-size:12px}
    /* Icon action buttons */
    .icon-btn{display:inline-block; width:28px; height:28px; line-height:28px; text-align:center; border-radius:6px; background:rgba(148,163,184,.1); border:1px solid rgba(148,163,184,.2); color:var(--text); text-decoration:none; font-size:16px; cursor:pointer; transition:all 0.2s; margin-right:6px; vertical-align:middle; padding:0; appearance:none}
    .icon-btn:hover{background:rgba(148,163,184,.2); border-color:rgba(148,163,184,.3)}
    .icon-btn-danger{background:rgba(239,68,68,.1) !important; border:1px solid rgba(239,68,68,.2) !important; color:#ef4444 !important}
    .icon-btn-danger:hover{background:rgba(239,68,68,.2) !important; border-color:rgba(239,68,68,.3) !important}
    /* Kebab actions menu */
    .kebab{position:relative; display:inline-block}
    .kebab summary{list-style:none}
    .kebab summary::-webkit-details-marker{display:none}
    .kebabBtn{height:28px; padding:0 10px; font-size:16px; line-height:1}
    .menu{position:absolute; right:0; top:34px; min-width:120px; background:#0b1220; border:1px solid rgba(148,163,184,.22); border-radius:10px; box-shadow:0 8px 20px rgba(0,0,0,.35); padding:6px}
    .menu a, .menu button{display:block; width:100%; text-align:left; background:transparent; border:0; color:var(--text); padding:8px 10px; border-radius:8px; font-size:12px; cursor:pointer}
    .menu a:hover, .menu button:hover{background:rgba(255,255,255,.06)}

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

    .actions{display:flex; gap:8px; align-items:center; justify-content:flex-end; flex-wrap:nowrap; white-space:nowrap}
    .actions form{display:inline}
    /* Smaller buttons inside table to avoid overflow */
    tbody .actions .btn{height:28px; padding:0 10px; font-size:12px}
    /* Table improvements for compact layout */
    tbody tr td:first-child{padding-left:6px}
    tbody tr td:last-child{padding-right:6px}
    /* Quick actions (Import/Update) */
    .quick-actions{position:absolute; right:20px; top:22px; display:flex; flex-direction:row; gap:12px; align-items:center; flex-wrap:wrap}
    .linkAccent{color:#facc15; text-decoration:none; font-size:14px}
    .linkAccent:hover{text-decoration:underline}
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
    /* Photo viewer modal */
    .photo-modal-overlay{position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(2,6,23,.9); backdrop-filter:blur(4px); z-index:10001}
    .photo-modal-overlay.show{display:flex}
    .photo-modal-content{position:relative; padding:20px; background:rgba(15,23,42,.95); border-radius:12px; border:1px solid rgba(148,163,184,.2); box-shadow:0 10px 40px rgba(0,0,0,.5)}
    .photo-modal-close{position:absolute; top:10px; right:15px; font-size:32px; font-weight:bold; color:#fff; cursor:pointer; line-height:1; opacity:.7; transition:opacity 0.2s}
    .photo-modal-close:hover{opacity:1}
    .product-photo-container{display:inline-flex; align-items:center; margin-right:16px}
    /* Product modal */
    .product-modal-overlay{position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(2,6,23,.85); backdrop-filter:blur(4px); z-index:10002; overflow-y:auto; padding:20px}
    .product-modal-overlay.show{display:flex}
    .product-modal-content{position:relative; width:90%; max-width:800px; max-height:90vh; padding:24px; background:rgba(15,23,42,.98); border-radius:14px; border:1px solid rgba(148,163,184,.2); box-shadow:0 10px 40px rgba(0,0,0,.5); overflow-y:auto}
    .product-modal-close{position:absolute; top:15px; right:20px; font-size:32px; font-weight:bold; color:var(--text); cursor:pointer; line-height:1; opacity:.7; transition:opacity 0.2s; z-index:1}
    .product-modal-close:hover{opacity:1}
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
  <!-- Photo viewer modal -->
  <div id="photoModal" class="photo-modal-overlay" onclick="closePhotoModal()">
    <div class="photo-modal-content" onclick="event.stopPropagation()" style="display:flex; flex-direction:column; gap:16px">
      <img id="photoModalImg" src="" alt="Product photo" style="max-width:90vw; max-height:70vh; border-radius:8px; align-self:center">
      <div style="display:flex; gap:12px; justify-content:center; padding-top:8px">
        <button type="button" class="btn" onclick="openUploadFromModal()">Upload</button>
        <button type="button" class="btn btn-danger" onclick="deletePhotoFromModal()">Delete</button>
      </div>
    </div>
  </div>
  <!-- Photo upload modal -->
  <div id="uploadModal" class="photo-modal-overlay" onclick="closeUploadModal()">
    <div class="photo-modal-content" onclick="event.stopPropagation()" style="min-width:400px; max-width:500px">
      <span class="photo-modal-close" onclick="closeUploadModal()">&times;</span>
      <h3 style="margin:0 0 20px 0; color:var(--text)">Upload Product Photo</h3>
      <form id="uploadPhotoForm" method="post" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:16px">
        <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="upload_photo">
        <input type="hidden" name="cod" id="uploadProductCod" value="">
        <div>
          <label class="muted" for="uploadPhotoFile" style="display:block; margin-bottom:8px">Select Image</label>
          <input id="uploadPhotoFile" type="file" name="photo" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp" required style="width:100%; padding:8px; border:1px solid rgba(148,163,184,.2); border-radius:6px; background:var(--bg); color:var(--text)">
        </div>
        <div style="display:flex; gap:8px; justify-content:flex-end">
          <button type="button" class="btn btn-outline" onclick="closeUploadModal()">Cancel</button>
          <button type="submit" class="btn">Upload</button>
        </div>
      </form>
    </div>
  </div>
  <!-- Product add/edit modal -->
  <div id="productModal" class="product-modal-overlay" onclick="closeProductModal()">
    <div class="product-modal-content" onclick="event.stopPropagation()">
      <span class="product-modal-close" onclick="closeProductModal()">&times;</span>
      <h2 style="margin:0 0 20px 0; color:var(--text)" id="productModalTitle">Add Product</h2>
      <form method="post" id="productForm" class="grid">
        <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" id="productAction" value="create">
        <input type="hidden" name="id" id="productId" value="">

        <div class="col-3">
          <label class="muted" for="m_modal">Manufacturer</label>
          <select id="m_modal" name="manufacturer_id" required>
            <option value="">Select...</option>
            <?php foreach ($manufacturers as $m): ?>
            <option value="<?php echo (int)$m['id']; ?>"><?php echo htmlspecialchars($m['description'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-3">
          <label class="muted" for="s_modal">Section</label>
          <select id="s_modal" name="section_id" required>
            <option value="">Select...</option>
            <?php foreach ($sections as $s): ?>
            <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['description'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6">
          <label class="muted" for="d_modal">Description</label>
          <input id="d_modal" type="text" name="description" placeholder="Product name" required style="text-transform:uppercase" oninput="this.value = this.value.toUpperCase()">
        </div>
        <div class="col-2">
          <label class="muted" for="cod_modal">Cod <span style="font-size:11px; color:var(--muted); font-weight:normal">(opt)</span></label>
          <input id="cod_modal" type="text" name="cod" placeholder="Auto" style="font-size:12px; padding:8px 10px">
        </div>
        <div class="col-3">
          <label class="muted" for="u_modal">Unit</label>
          <input id="u_modal" type="text" name="unit" placeholder="e.g., kg, box" required style="text-transform:uppercase" oninput="this.value = this.value.toUpperCase()">
        </div>
        <div class="col-3">
          <label class="muted" for="b_modal">Qty for Box</label>
          <input id="b_modal" type="number" name="box_qty" min="0" step="1" placeholder="qty" value="1">
        </div>
        <div class="col-2">
          <label class="muted" for="rv_modal">Real value</label>
          <input id="rv_modal" type="number" name="real_value" min="0" step="0.01" placeholder="0.00" required>
        </div>
        <input type="hidden" name="discounted_value" value="0">
        <div class="col-2">
          <label class="muted" for="uv_modal">Unit value</label>
          <div style="display:flex; gap:6px; align-items:center">
            <input id="uv_modal" type="number" name="unit_value" min="0" step="0.01" placeholder="0.00" style="flex:1">
            <button type="button" class="btn" id="calc_uv_btn_modal" title="Calculate Unit = Real / Box">=</button>
          </div>
        </div>
        <div class="col-3" style="display:flex; flex-direction:column; gap:6px">
          <label class="muted">Great deal</label>
          <label class="toggle">
            <input type="checkbox" name="is_great_deal" value="1" id="gd_modal">
            <span class="slider"></span>
          </label>
        </div>
        <div class="col-3" style="display:flex; flex-direction:column; gap:6px">
          <label class="muted">Active</label>
          <label class="toggle">
            <input type="checkbox" name="is_active" value="1" id="active_modal" checked>
            <span class="slider"></span>
          </label>
        </div>
        <div class="col-3" style="display:flex; flex-direction:column; gap:6px">
          <label class="muted">Featured</label>
          <label class="toggle">
            <input type="checkbox" name="is_featured" value="1" id="featured_modal">
            <span class="slider"></span>
          </label>
        </div>
        <div class="col-3" style="display:flex; align-items:flex-end; justify-content:flex-end; gap:8px; margin-top:10px">
          <button type="button" class="btn btn-outline" onclick="closeProductModal()">Cancel</button>
          <button type="submit" class="btn" id="productSubmitBtn">Add product</button>
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
      <h2 style="margin:0 0 10px 0">Products <span style="font-size:12px; color:#94a3b8; border:1px solid rgba(148,163,184,.35); padding:2px 6px; border-radius:999px; vertical-align:middle">v2</span></h2>
      <div class="quick-actions">
        <a class="linkAccent" href="import_products.php">Import Products</a> |
        <a class="linkAccent" href="update_prices.php">Update Prices</a> |
        <a class="linkAccent" href="update_prices_bulk.php">Bulk Update Prices</a> |
        <form method="post" id="deleteAllForm" style="display:inline; margin:0">
          <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="action" value="delete_all">
          <button type="button" class="linkAccent" id="deleteAllBtn" style="background:none; border:none; padding:0; cursor:pointer; font-size:14px; color:#ef4444">Delete All</button>
        </form>
      </div>
      <?php if ($erro): ?><div class="msg error"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
      <?php if ($flash_ok): ?><div class="msg ok"><?php echo htmlspecialchars($flash_ok, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

      <div style="margin-bottom:14px; display:flex; justify-content:flex-end; gap:8px">
        <button type="button" class="btn btn-primary" onclick="openProductModal()" style="background:#22c55e; border-color:#16a34a; color:#fff">+ Add Product</button>
      </div>

      <!-- Search filters -->
      <form method="get" class="grid filters" style="margin:0 0 10px 0">
        <div class="col-1">
          <label class="muted" for="q_cod">Cod</label>
          <input id="q_cod" type="text" name="q_cod" value="<?php echo htmlspecialchars($q_cod, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search code">
        </div>
        <div class="col-2">
          <label class="muted" for="q_manu">Manufacturer</label>
          <select id="q_manu" name="q_manu" onchange="this.form.submit()">
            <option value="0">All</option>
            <?php foreach ($manufacturers as $m): ?>
            <option value="<?php echo (int)$m['id']; ?>" <?php echo ($q_manu===(int)$m['id']?'selected':''); ?>><?php echo htmlspecialchars($m['description'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-2">
          <label class="muted" for="q_sec">Section</label>
          <select id="q_sec" name="q_sec" onchange="this.form.submit()">
            <option value="0">All</option>
            <?php foreach ($sections as $s): ?>
            <option value="<?php echo (int)$s['id']; ?>" <?php echo ($q_sec===(int)$s['id']?'selected':''); ?>><?php echo htmlspecialchars($s['description'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-2">
          <label class="muted" for="q_desc">Description</label>
          <input id="q_desc" type="text" name="q_desc" value="<?php echo htmlspecialchars($q_desc, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search description">
        </div>
        <div class="col-1">
          <label class="muted" for="q_active">Active</label>
          <select id="q_active" name="q_active" onchange="this.form.submit()">
            <option value="">All</option>
            <option value="1" <?php echo ($q_active==='1'?'selected':''); ?>>Yes</option>
            <option value="0" <?php echo ($q_active==='0'?'selected':''); ?>>No</option>
          </select>
        </div>
        <div class="col-1">
          <label class="muted" for="q_great_deal">Great Deal</label>
          <select id="q_great_deal" name="q_great_deal" onchange="this.form.submit()">
            <option value="">All</option>
            <option value="1" <?php echo ($q_great_deal==='1'?'selected':''); ?>>Yes</option>
            <option value="0" <?php echo ($q_great_deal==='0'?'selected':''); ?>>No</option>
          </select>
        </div>
        <div class="col-1">
          <label class="muted" for="q_no_photo" style="display:flex; align-items:center; gap:6px; cursor:pointer; margin-top:0">
            <input type="checkbox" id="q_no_photo" name="q_no_photo" value="1" <?php echo ($q_no_photo ? 'checked' : ''); ?> style="width:auto; height:auto; margin:0">
            <span><br>No Photo</span>
          </label>
        </div>
        <div class="col-2" style="display:flex; align-items:flex-end; gap:6px">
          <button class="btn" type="submit">Search</button>
          <a class="btn btn-outline" href="products.php">Clear</a>
        </div>
      </form>

      <?php
      // Buscar slug da empresa para determinar pasta de imagens
      $company_slug = '';
      $slug_sql = 'SELECT slug FROM companies WHERE id = ? AND is_active = 1 LIMIT 1';
      if ($slug_stmt = $conn->prepare($slug_sql)) {
        $slug_stmt->bind_param('i', $company_id);
        $slug_stmt->execute();
        $slug_result = $slug_stmt->get_result();
        $slug_row = $slug_result->fetch_assoc();
        if ($slug_row && isset($slug_row['slug']) && $slug_row['slug'] !== '') {
          $company_slug = trim((string)$slug_row['slug']);
        }
        $slug_result->free();
        $slug_stmt->close();
      }
      $folder_name = $company_slug !== '' ? $company_slug : 'company_' . $company_id;
      
      // Helper function to build sort URL
      $buildSortUrl = function($sortField) use ($q_cod, $q_desc, $q_manu, $q_sec, $order_by, $order_dir) {
        $params = [];
        if ($q_cod !== '') $params['q_cod'] = $q_cod;
        if ($q_desc !== '') $params['q_desc'] = $q_desc;
        if ($q_manu > 0) $params['q_manu'] = $q_manu;
        if ($q_sec > 0) $params['q_sec'] = $q_sec;
        
        // Toggle direction if clicking the same field, otherwise default to ASC
        if ($order_by === $sortField) {
          $params['order_dir'] = ($order_dir === 'ASC') ? 'DESC' : 'ASC';
        } else {
          $params['order_dir'] = 'ASC';
        }
        $params['order_by'] = $sortField;
        
        return 'products.php?' . http_build_query($params);
      };
      
      // Helper function to render sortable header
      $renderSortableHeader = function($title, $sortField) use ($buildSortUrl, $order_by, $order_dir) {
        $isActive = ($order_by === $sortField);
        $currentDir = $isActive ? $order_dir : '';
        $arrow = '';
        if ($isActive) {
          $arrow = $currentDir === 'ASC' ? '↑' : '↓';
        }
        $url = $buildSortUrl($sortField);
        $class = 'sortable' . ($isActive ? ' active' : '');
        return '<th class="' . $class . '"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="text-decoration:none; color:inherit; display:block">' . 
               htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . 
               ($arrow ? '<span class="sort-indicator">' . $arrow . '</span>' : '') . 
               '</a></th>';
      };
      ?>

      <table>
        <colgroup>
          <col class="col-photo" style="width:50px">
          <col class="col-cod" style="width:80px">
          <col class="col-manufacturer-section" style="width:180px">
          <col class="col-description" style="width:300px">
          <col class="col-unit" style="width:50px">
          <col class="col-box" style="width:60px">
          <col class="col-real" style="width:90px">
          <col class="col-unitvalue" style="width:90px">
          <col class="col-featured" style="width:80px">
          <col class="col-badges" style="width:100px">
          <col class="col-actions" style="width:80px">
        </colgroup>
        <thead>
          <tr>
            <th></th>
            <th>Cod</th>
            <th style="vertical-align:top; padding-top:10px; padding-bottom:10px">
              <?php
              $manu_isActive = ($order_by === 'manufacturer');
              $manu_arrow = $manu_isActive ? ($order_dir === 'ASC' ? '↑' : '↓') : '';
              $manu_url = $buildSortUrl('manufacturer');
              $manu_class = 'sortable' . ($manu_isActive ? ' active' : '');
              $sec_isActive = ($order_by === 'section');
              $sec_arrow = $sec_isActive ? ($order_dir === 'ASC' ? '↑' : '↓') : '';
              $sec_url = $buildSortUrl('section');
              $sec_class = 'sortable' . ($sec_isActive ? ' active' : '');
              ?>
              <div style="font-weight:600; margin-bottom:4px; line-height:1.3">
                <a href="<?php echo htmlspecialchars($manu_url, ENT_QUOTES, 'UTF-8'); ?>" 
                   class="<?php echo $manu_class; ?>" 
                   style="text-decoration:none; color:inherit; display:inline-block; position:relative; padding-right:16px">
                  MANUFACTURER
                  <?php if ($manu_arrow): ?>
                    <span class="sort-indicator"><?php echo $manu_arrow; ?></span>
                  <?php endif; ?>
                </a>
              </div>
              <div style="font-size:10px; color:var(--muted); line-height:1.3; font-weight:600; text-transform:uppercase; letter-spacing:0.5px">
                <a href="<?php echo htmlspecialchars($sec_url, ENT_QUOTES, 'UTF-8'); ?>" 
                   class="<?php echo $sec_class; ?>" 
                   style="text-decoration:none; color:inherit; display:inline-block; position:relative; padding-right:16px">
                  SECTION
                  <?php if ($sec_arrow): ?>
                    <span class="sort-indicator"><?php echo $sec_arrow; ?></span>
                  <?php endif; ?>
                </a>
              </div>
            </th>
            <th>Description</th>
            <th style="text-align:center">Unit</th>
            <th class="num">Qty</th>
            <th class="num">Real Value</th>
            <th class="num">Unit Value</th>
            <th style="text-align:center">Featured</th>
            <th>Status</th>
            <th style="text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): 
            $product_cod = htmlspecialchars((string)$r['cod'], ENT_QUOTES, 'UTF-8');
            // Buscar foto no novo padrão: {cod}.png na pasta do slug (raiz)
            // $folder_name já foi definido antes do loop
            $photo_file = __DIR__ . '/' . $folder_name . '/' . $product_cod . '.png';
            $existing_photo = file_exists($photo_file) ? [$photo_file] : [];
            // Se não encontrar, tentar padrão antigo para compatibilidade
            if (empty($existing_photo)) {
              $photo_pattern = __DIR__ . '/img_product/img_' . $company_id . '_' . $product_cod . '.*';
              $existing_photo = glob($photo_pattern);
            }
          ?>
          <tr id="product-<?php echo $product_cod; ?>">
            <!-- Photo -->
            <td style="padding:8px">
              <?php if (!empty($existing_photo)): 
                $photo_url = $folder_name . '/' . basename($existing_photo[0]);
                // Adicionar timestamp para forçar refresh após upload
                $photo_url_with_cache = $photo_url . '?v=' . filemtime($existing_photo[0]);
              ?>
                <img src="<?php echo htmlspecialchars($photo_url_with_cache, ENT_QUOTES, 'UTF-8'); ?>" 
                     alt="Product photo" 
                     class="photo-thumb" 
                     onclick="openPhotoModal('<?php echo htmlspecialchars($photo_url, ENT_QUOTES, 'UTF-8'); ?>', '<?php echo $product_cod; ?>')">
              <?php else: ?>
                <div class="photo-placeholder" onclick="openUploadModal('<?php echo $product_cod; ?>')" title="Upload photo">📷</div>
              <?php endif; ?>
            </td>
            <!-- Cod -->
            <td><?php echo $product_cod; ?></td>
            <!-- Manufacturer and Section (stacked) -->
            <td style="vertical-align:top; padding-top:8px; padding-bottom:8px">
              <div style="font-size:11px; font-weight:500; margin-bottom:4px; line-height:1.3">
                <?php echo htmlspecialchars($r['manufacturer'], ENT_QUOTES, 'UTF-8'); ?>
              </div>
              <div style="font-size:11px; color:var(--muted); line-height:1.3">
                <?php echo htmlspecialchars($r['section'], ENT_QUOTES, 'UTF-8'); ?>
              </div>
            </td>
            <!-- Description -->
            <td class="ellipsis" title="<?php echo htmlspecialchars($r['description'], ENT_QUOTES, 'UTF-8'); ?>">
              <?php echo htmlspecialchars($r['description'], ENT_QUOTES, 'UTF-8'); ?>
            </td>
            <!-- Unit -->
            <td style="text-align:center"><?php echo htmlspecialchars($r['unit'], ENT_QUOTES, 'UTF-8'); ?></td>
            <!-- Qty for Box -->
            <td class="num"><?php echo htmlspecialchars((string)$r['box_qty'], ENT_QUOTES, 'UTF-8'); ?></td>
            <!-- Real Value -->
            <td class="num"><?php echo number_format((float)$r['real_value'], 2); ?></td>
            <!-- Unit Value -->
            <td class="num"><?php echo number_format((float)$r['unit_value'], 2); ?></td>
            <!-- Featured -->
            <td style="text-align:center; padding:8px 4px">
              <input type="checkbox" 
                     class="featured-checkbox" 
                     data-product-id="<?php echo (int)$r['id']; ?>"
                     <?php echo (isset($r['is_featured']) && $r['is_featured'] == 1 ? 'checked' : ''); ?>
                     style="width:18px; height:18px; cursor:pointer; accent-color:#22c55e">
            </td>
            <!-- Badges -->
            <td style="white-space:nowrap">
              <?php if ($r['is_great_deal']): ?>
                <span class="badge badge-deal">Deal</span>
              <?php endif; ?>
              <?php if ($r['is_active']): ?>
                <span class="badge badge-active">Active</span>
              <?php else: ?>
                <span class="badge badge-inactive">Inactive</span>
              <?php endif; ?>
            </td>
            <!-- Actions -->
            <td style="white-space:nowrap; text-align:right">
              <a href="javascript:void(0)" 
                 onclick="openProductModal(<?php echo (int)$r['id']; ?>)" 
                 title="Edit"
                 class="icon-btn">✏️</a>
              <form method="post" onsubmit="return confirm('Remove this product?');" style="display:inline">
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
          <tr><td colspan="11" class="muted" style="text-align:center; padding:40px">No products found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </section>
  </div>

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
// Toast from existing .msg elements (run after full load)
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
  // Auto-close after 2 seconds
  setTimeout(close, 2000);
});
// Product modal functions
var currentEditingId = null;
var productData = <?php echo json_encode($editing ? $editing : null); ?>;

function openProductModal(productId) {
  var modal = document.getElementById('productModal');
  var form = document.getElementById('productForm');
  var titleEl = document.getElementById('productModalTitle');
  var submitBtn = document.getElementById('productSubmitBtn');
  var actionInput = document.getElementById('productAction');
  var idInput = document.getElementById('productId');
  
  if (!modal || !form) return;
  
  // Reset form
  form.reset();
  document.getElementById('active_modal').checked = true;
  document.getElementById('featured_modal').checked = false;
  
  if (productId) {
    // Load product data via AJAX
    fetch('products.php?get_product=' + productId)
      .then(function(response) { return response.json(); })
      .then(function(data) {
        if (data && data.id) {
          currentEditingId = data.id;
          titleEl.textContent = 'Edit Product';
          submitBtn.textContent = 'Save';
          actionInput.value = 'update';
          idInput.value = data.id;
          
          // Fill form fields
          document.getElementById('cod_modal').value = data.cod || '';
          document.getElementById('m_modal').value = data.manufacturer_id || '';
          document.getElementById('s_modal').value = data.section_id || '';
          document.getElementById('d_modal').value = data.description || '';
          document.getElementById('u_modal').value = data.unit || '';
          document.getElementById('b_modal').value = data.box_qty || '1';
          document.getElementById('rv_modal').value = data.real_value || '';
          document.getElementById('uv_modal').value = data.unit_value || '';
          document.getElementById('gd_modal').checked = data.is_great_deal == 1;
          document.getElementById('active_modal').checked = data.is_active == 1;
          document.getElementById('featured_modal').checked = data.is_featured == 1;
        }
        modal.classList.add('show');
      })
      .catch(function(error) {
        console.error('Error loading product:', error);
        modal.classList.add('show');
      });
  } else {
    // New product
    currentEditingId = null;
    titleEl.textContent = 'Add Product';
    submitBtn.textContent = 'Add product';
    actionInput.value = 'create';
    idInput.value = '';
    modal.classList.add('show');
  }
  
  // Close on Escape key
  var escapeHandler = function(e) {
    if (e.key === 'Escape') {
      closeProductModal();
      document.removeEventListener('keydown', escapeHandler);
    }
  };
  document.addEventListener('keydown', escapeHandler);
}

function closeProductModal() {
  var modal = document.getElementById('productModal');
  if (modal) {
    modal.classList.remove('show');
    currentEditingId = null;
  }
}

// Calc Unit Value = Real / Box (for modal)
function calculateUnitValue() {
  var rv = parseFloat((document.getElementById('rv_modal')||{}).value || '0');
  var bx = parseFloat((document.getElementById('b_modal')||{}).value || '0');
  if(!bx || isNaN(rv) || isNaN(bx) || bx <= 0) {
    var uvInput = document.getElementById('uv_modal');
    if(uvInput && (isNaN(rv) || rv === 0)) { uvInput.value = '0.00'; }
    return;
  }
  // Truncate (not round) to 2 decimals: e.g., 19.99/10 = 1.99
  var raw = rv / bx;
  var uv = Math.trunc(raw * 100) / 100; // truncate toward 0
  var uvInput = document.getElementById('uv_modal');
  if(uvInput){ uvInput.value = uv.toFixed(2); }
}

document.addEventListener('DOMContentLoaded', function(){
  var btnModal = document.getElementById('calc_uv_btn_modal');
  if (btnModal) {
    btnModal.addEventListener('click', calculateUnitValue);
  }
  
  // Auto-calculate when Real Value loses focus
  var rvInput = document.getElementById('rv_modal');
  if (rvInput) {
    rvInput.addEventListener('blur', calculateUnitValue);
  }
  
  // Auto-calculate when Qty for Box changes
  var bxInput = document.getElementById('b_modal');
  if (bxInput) {
    bxInput.addEventListener('blur', calculateUnitValue);
  }
  
  // Featured checkbox toggle
  var featuredCheckboxes = document.querySelectorAll('.featured-checkbox');
  featuredCheckboxes.forEach(function(checkbox) {
    checkbox.addEventListener('change', function() {
      var productId = this.getAttribute('data-product-id');
      var isChecked = this.checked ? 1 : 0;
      var formData = new FormData();
      formData.append('action', 'toggle_featured');
      formData.append('id', productId);
      formData.append('to', isChecked);
      formData.append('<?php echo $csrf_name; ?>', '<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>');
      
      fetch('products.php', {
        method: 'POST',
        body: formData
      })
      .then(function(response) { return response.json(); })
      .then(function(data) {
        if (!data.success) {
          // Revert checkbox if failed
          checkbox.checked = !checkbox.checked;
          alert('Failed to update featured status.');
        }
      })
      .catch(function(error) {
        console.error('Error:', error);
        checkbox.checked = !checkbox.checked;
        alert('Error updating featured status.');
      });
    });
  });
  
  // If editing from URL, open modal
  var urlParams = new URLSearchParams(window.location.search);
  var editId = urlParams.get('edit');
  if (editId) {
    openProductModal(parseInt(editId));
    // Clean URL
    var newUrl = window.location.pathname + (window.location.search.replace(/[?&]edit=\d+/, '').replace(/^&/, '?') || '');
    history.replaceState({}, '', newUrl);
  }
  
  // Scroll to product after photo upload
  if (window.location.hash) {
    var hash = window.location.hash.substring(1);
    if (hash.startsWith('product-')) {
      setTimeout(function() {
        var element = document.getElementById(hash);
        if (element) {
          element.scrollIntoView({ behavior: 'smooth', block: 'center' });
          // Remove hash from URL without scrolling again
          history.replaceState(null, null, window.location.pathname + window.location.search);
        }
      }, 100);
    }
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

// Photo viewer modal functions
var currentProductCod = '';
function openPhotoModal(photoUrl, productCod) {
  var modal = document.getElementById('photoModal');
  var img = document.getElementById('photoModalImg');
  if (modal && img) {
    img.src = photoUrl;
    currentProductCod = productCod;
    modal.classList.add('show');
    // Close on Escape key
    var escapeHandler = function(e) {
      if (e.key === 'Escape') {
        closePhotoModal();
        document.removeEventListener('keydown', escapeHandler);
      }
    };
    document.addEventListener('keydown', escapeHandler);
  }
}
function closePhotoModal() {
  var modal = document.getElementById('photoModal');
  if (modal) {
    modal.classList.remove('show');
    currentProductCod = '';
  }
}

function openUploadFromModal() {
  closePhotoModal();
  if (currentProductCod) {
    openUploadModal(currentProductCod);
  }
}

function deletePhotoFromModal() {
  if (!confirm('Delete this photo?')) return;
  if (!currentProductCod) return;
  
  var form = document.createElement('form');
  form.method = 'POST';
  form.style.display = 'none';
  
  var csrfInput = document.createElement('input');
  csrfInput.type = 'hidden';
  csrfInput.name = '<?php echo $csrf_name; ?>';
  csrfInput.value = '<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>';
  
  var actionInput = document.createElement('input');
  actionInput.type = 'hidden';
  actionInput.name = 'action';
  actionInput.value = 'delete_photo';
  
  var codInput = document.createElement('input');
  codInput.type = 'hidden';
  codInput.name = 'cod';
  codInput.value = currentProductCod;
  
  form.appendChild(csrfInput);
  form.appendChild(actionInput);
  form.appendChild(codInput);
  document.body.appendChild(form);
  form.submit();
}

// Photo upload modal functions
function openUploadModal(productCod) {
  var modal = document.getElementById('uploadModal');
  var productCodInput = document.getElementById('uploadProductCod');
  if (modal && productCodInput) {
    productCodInput.value = productCod || '';
    console.log('Setting product COD:', productCod);
    modal.classList.add('show');
    // Close on Escape key
    var escapeHandler = function(e) {
      if (e.key === 'Escape') {
        closeUploadModal();
        document.removeEventListener('keydown', escapeHandler);
      }
    };
    document.addEventListener('keydown', escapeHandler);
  }
}
function closeUploadModal() {
  var modal = document.getElementById('uploadModal');
  if (modal) {
    modal.classList.remove('show');
    // Reset form
    var form = document.getElementById('uploadPhotoForm');
    if (form) {
      form.reset();
    }
  }
}

// Double confirmation to delete all products
document.addEventListener('DOMContentLoaded', function(){
  var btn = document.getElementById('deleteAllBtn');
  var form = document.getElementById('deleteAllForm');
  if (!btn || !form) return;
  btn.addEventListener('click', async function(e){
    e.preventDefault();
    // First confirmation
    var first = await customConfirm('WARNING: Do you really want to delete ALL products? This action cannot be undone.', 'catalogseller.com says');
    if (!first) return;
    // Second confirmation
    var second = await customConfirm('FINAL CONFIRMATION: Are you absolutely sure? All products will be permanently deleted.', 'catalogseller.com says');
    if (second) {
      form.submit();
    }
  });
});
</script>
<?php if ($DEBUG_MODE) debug_log('=== SCRIPT FINALIZADO COM SUCESSO ===', 'END'); ?>
</body>
</html>
