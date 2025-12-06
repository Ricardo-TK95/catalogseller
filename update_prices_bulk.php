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

$erro = '';
$ok = '';
if (!empty($_SESSION['flash_ok'])) { $ok = (string)$_SESSION['flash_ok']; unset($_SESSION['flash_ok']); }

// Load manufacturers and sections
$manufacturers = [];
if ($stmt = $conn->prepare('SELECT id, description FROM manufacturers WHERE company_id=? AND is_active=1 ORDER BY description')) {
  $stmt->bind_param('i', $company_id);
  if ($stmt->execute()) { $res=$stmt->get_result(); while($r=$res->fetch_assoc()){ $manufacturers[]=$r; } $res->free(); }
  $stmt->close();
}
$sections = [];
if ($stmt = $conn->prepare('SELECT id, description FROM sections WHERE company_id=? AND is_active=1 ORDER BY description')) {
  $stmt->bind_param('i', $company_id);
  if ($stmt->execute()) { $res=$stmt->get_result(); while($r=$res->fetch_assoc()){ $sections[]=$r; } $res->free(); }
  $stmt->close();
}

// Handle price updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_POST[$csrf_name]) || !hash_equals($_SESSION[$csrf_name], (string)$_POST[$csrf_name])) {
    $erro = 'Session expired. Please reload the page.';
  } else {
    if (isset($_POST['action']) && $_POST['action'] === 'update_prices') {
      $updates = isset($_POST['prices']) && is_array($_POST['prices']) ? $_POST['prices'] : [];
      $updated = 0;
      
      if (!empty($updates)) {
        $conn->begin_transaction();
        try {
          $sql = 'UPDATE products SET real_value=?, unit_value=?, updated_at=NOW() WHERE id=? AND company_id=?';
          $stmt = $conn->prepare($sql);
          if (!$stmt) throw new Exception('Failed to prepare statement.');
          
          foreach ($updates as $product_id => $data) {
            $product_id = (int)$product_id;
            $real_value = isset($data['real_value']) ? (float)str_replace(',', '.', (string)$data['real_value']) : null;
            $unit_value = isset($data['unit_value']) ? (float)str_replace(',', '.', (string)$data['unit_value']) : null;
            
            if ($real_value !== null && $product_id > 0 && $real_value >= 0) {
              // If unit_value is not provided, calculate it from real_value and box_qty
              if ($unit_value === null) {
                // Get box_qty for this product
                $boxStmt = $conn->prepare('SELECT box_qty FROM products WHERE id=? AND company_id=?');
                $boxStmt->bind_param('ii', $product_id, $company_id);
                if ($boxStmt->execute()) {
                  $boxRes = $boxStmt->get_result();
                  $boxRow = $boxRes->fetch_assoc();
                  $box_qty = $boxRow && isset($boxRow['box_qty']) && $boxRow['box_qty'] > 0 ? (int)$boxRow['box_qty'] : 1;
                  $boxRes->free();
                  
                  // Calculate unit_value = real_value / box_qty
                  if ($box_qty > 0) {
                    $unit_value = $real_value / $box_qty;
                    // Truncate to 2 decimals
                    $unit_value = floor($unit_value * 100) / 100;
                  } else {
                    $unit_value = 0.00;
                  }
                }
                $boxStmt->close();
              }
              
              $stmt->bind_param('ddii', $real_value, $unit_value, $product_id, $company_id);
              if ($stmt->execute()) {
                $updated++;
              }
            }
          }
          
          $stmt->close();
          $conn->commit();
          
          if ($updated > 0) {
            // Redirect to preserve filter and show updated values
            $filter_type_param = isset($_POST['filter_type']) ? urlencode($_POST['filter_type']) : '';
            $filter_id_param = isset($_POST['filter_id']) ? (int)$_POST['filter_id'] : 0;
            $_SESSION['flash_ok'] = "Updated prices for {$updated} product(s).";
            header('Location: update_prices_bulk.php?filter_type=' . $filter_type_param . '&filter_id=' . $filter_id_param);
            exit;
          } else {
            $erro = 'No prices were updated.';
          }
        } catch (Throwable $e) {
          $conn->rollback();
          $erro = 'Failed to update prices. No changes were saved.';
        }
      } else {
        $erro = 'No prices to update.';
      }
    }
  }
}

// Load products based on filter
$rows = [];
$filter_type = isset($_GET['filter_type']) ? (string)$_GET['filter_type'] : (isset($_POST['filter_type']) ? (string)$_POST['filter_type'] : '');
$filter_id = isset($_GET['filter_id']) ? (int)$_GET['filter_id'] : (isset($_POST['filter_id']) ? (int)$_POST['filter_id'] : 0);
$filter_name = '';

if ($filter_type && $filter_id > 0) {
  $baseSql = 'SELECT p.id, p.cod, p.manufacturer_id, p.section_id, p.description, p.unit, p.box_qty, p.real_value,
                     COALESCE(p.unit_value, CASE WHEN p.box_qty IS NOT NULL AND p.box_qty>0 THEN p.real_value / p.box_qty ELSE NULL END) AS unit_value,
                     p.is_great_deal, p.is_active,
                     m.description AS manufacturer, s.description AS section
              FROM products p
              LEFT JOIN manufacturers m ON m.id=p.manufacturer_id AND m.company_id=p.company_id
              LEFT JOIN sections s ON s.id=p.section_id AND s.company_id=p.company_id
              WHERE p.company_id=?';
  
  if ($filter_type === 'manufacturer') {
    $baseSql .= ' AND p.manufacturer_id=?';
    foreach ($manufacturers as $m) {
      if ((int)$m['id'] === $filter_id) {
        $filter_name = $m['description'];
        break;
      }
    }
  } else {
    $baseSql .= ' AND p.section_id=?';
    foreach ($sections as $s) {
      if ((int)$s['id'] === $filter_id) {
        $filter_name = $s['description'];
        break;
      }
    }
  }
  
  $baseSql .= ' ORDER BY p.cod, p.description';
  
  if ($stmt = $conn->prepare($baseSql)) {
    $stmt->bind_param('ii', $company_id, $filter_id);
    if ($stmt->execute()) {
      $res = $stmt->get_result();
      while ($r = $res->fetch_assoc()) { $rows[] = $r; }
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
  <title>Bulk Update Prices - Catalog Seller</title>
  <style>
    :root { --bg:#0f172a; --muted:#94a3b8; --text:#e2e8f0; }
    *{box-sizing:border-box}
    body{margin:0; font-family: system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,"Helvetica Neue",Arial; background: radial-gradient(1200px 800px at 80% -10%, #1e293b, transparent), var(--bg); color:var(--text); min-height:100vh;}
    .container{max-width:1200px; margin:24px auto; padding:0 24px}
    .nav{display:flex; align-items:center; justify-content:space-between; gap:16px; padding:12px 16px; border:1px solid rgba(148,163,184,.14); border-radius:12px; background: rgba(255,255,255,.02); box-shadow: 0 4px 12px rgba(0,0,0,.18)}
    .nav-left{display:flex; align-items:center; gap:10px; flex:0 0 auto}
    .nav-center{display:flex; align-items:center; justify-content:center; flex:1}
    .nav-right{display:flex; align-items:center; gap:10px; flex:0 0 auto}
    .brandRow{display:flex; align-items:center; gap:10px}
    .logo{display:block; height:20px; width:auto; background:#fff; padding:2px; border-radius:6px; border:1px solid rgba(148,163,184,.25)}
    .logo-company{display:block; height:32px; width:auto; background:#fff; padding:3px; border-radius:6px; border:1px solid rgba(148,163,184,.25)}
    .card{margin-top:22px; padding:18px; border:1px solid rgba(148,163,184,.16); border-radius:14px; background: rgba(255,255,255,.02); box-shadow: 0 6px 16px rgba(0,0,0,.20)}

    input[type=text], input[type=number], select{padding:12px 14px; border-radius:10px; border:1px solid rgba(148,163,184,.22); background:#0b1220; color:var(--text); width:100%; height:44px; font-size:14px}
    .btn{appearance:none; display:inline-flex; align-items:center; justify-content:center; gap:6px; border:1px solid #3b4252; background:#0f172a; color:#e5e7eb; font-weight:600; font-size:13px; height:34px; padding:0 12px; border-radius:8px; cursor:pointer; box-shadow:none; text-decoration:none; line-height:1.2}
    .btn:hover{background:#0b1220}
    .btn-primary{background:#22c55e; border-color:#16a34a; color:#fff}
    .btn-primary:hover{background:#16a34a}

    .muted{color:var(--muted)}
    .grid{display:grid; grid-template-columns: repeat(12, 1fr); gap:4px}
    .col-12{grid-column: span 12}
    .col-6{grid-column: span 6}
    .col-4{grid-column: span 4}
    .col-3{grid-column: span 3}
    .col-2{grid-column: span 2}
    .msg{margin:8px 0 0; font-size:14px}
    .error{color:#ef4444}
    .ok{color:#22c55e}
    
    table{width:100%; border-collapse:separate; border-spacing:0 8px; table-layout:fixed}
    th, td{padding:10px 12px; text-align:left}
    thead th{font-size:12px; color:var(--muted); font-weight:600}
    thead th:nth-child(3), thead th:nth-child(4), thead th:nth-child(5){text-align:center}
    tbody tr{background: rgba(255,255,255,.02); box-shadow: 0 2px 8px rgba(0,0,0,.14); border:1px solid rgba(148,163,184,.14);}
    tbody tr:hover{background: rgba(255,255,255,.03)}
    tbody tr td:first-child{border-top-left-radius:12px; border-bottom-left-radius:12px}
    tbody tr td:last-child{border-top-right-radius:12px; border-bottom-right-radius:12px}
    td.num{text-align:center}
    tbody td:nth-child(3), tbody td:nth-child(4), tbody td:nth-child(5){text-align:center}
    .price-input{width:100px; text-align:center; padding:6px 8px; font-size:14px; margin:0 auto; display:block}
    .unit-value-display{color:var(--muted); font-size:13px; padding:6px 8px; text-align:center; min-width:80px; display:inline-block}
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
        <a class="btn" href="products.php">Back</a>
      </div>
    </header>

    <section class="card">
      <h2 style="margin:0 0 10px 0">Atualizar Preços em Massa</h2>
      <?php if ($erro): ?><div class="msg error"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="msg ok"><?php echo htmlspecialchars($ok, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

      <!-- Filter form -->
      <form method="get" class="grid" style="margin-bottom:20px" id="filterForm">
        <div class="col-4">
          <label class="muted" for="filter_type">Filtrar por</label>
          <select id="filter_type" name="filter_type" onchange="toggleFilter()">
            <option value="">Select...</option>
            <option value="manufacturer" <?php echo ($filter_type==='manufacturer'?'selected':''); ?>>Manufacturer</option>
            <option value="section" <?php echo ($filter_type==='section'?'selected':''); ?>>Section</option>
          </select>
        </div>

        <div class="col-4" id="manufacturer_field" style="display:<?php echo ($filter_type==='manufacturer'?'block':'none'); ?>">
          <label class="muted" for="filter_manufacturer">Manufacturer</label>
          <select id="filter_manufacturer" <?php echo ($filter_type==='manufacturer'?'name="filter_id"':''); ?> onchange="submitFilter()">
            <option value="0">Select...</option>
            <?php foreach ($manufacturers as $m): ?>
            <option value="<?php echo (int)$m['id']; ?>" <?php echo ($filter_type==='manufacturer' && $filter_id===(int)$m['id']?'selected':''); ?>><?php echo htmlspecialchars($m['description'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-4" id="section_field" style="display:<?php echo ($filter_type==='section'?'block':'none'); ?>">
          <label class="muted" for="filter_section">Section</label>
          <select id="filter_section" <?php echo ($filter_type==='section'?'name="filter_id"':''); ?> onchange="submitFilter()">
            <option value="0">Select...</option>
            <?php foreach ($sections as $s): ?>
            <option value="<?php echo (int)$s['id']; ?>" <?php echo ($filter_type==='section' && $filter_id===(int)$s['id']?'selected':''); ?>><?php echo htmlspecialchars($s['description'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>

      <?php if (!empty($rows)): ?>
      <form method="post" id="pricesForm">
        <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="update_prices">
        <input type="hidden" name="filter_type" value="<?php echo htmlspecialchars($filter_type, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="filter_id" value="<?php echo (int)$filter_id; ?>">
        
        <h3 style="margin:0 0 15px 0"><?php echo htmlspecialchars($filter_name, ENT_QUOTES, 'UTF-8'); ?> (<?php echo count($rows); ?> produtos)</h3>
        
        <table>
          <colgroup>
            <col style="width:8%">
            <col style="width:auto">
            <col style="width:8%">
            <col style="width:12%">
            <col style="width:12%">
          </colgroup>
          <thead>
            <tr>
              <th>Cod</th>
              <th>Description</th>
              <th>Box</th>
              <th>Real Value</th>
              <th>Unit Value</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php echo htmlspecialchars((string)$r['cod'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($r['description'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td class="num"><?php echo htmlspecialchars((string)$r['box_qty'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td class="num">
                <input type="number" 
                       name="prices[<?php echo (int)$r['id']; ?>][real_value]" 
                       class="price-input real-value-input" 
                       data-product-id="<?php echo (int)$r['id']; ?>"
                       data-box-qty="<?php echo (int)$r['box_qty']; ?>"
                       min="0" 
                       step="0.01" 
                       value="<?php echo htmlspecialchars((string)$r['real_value'], ENT_QUOTES, 'UTF-8'); ?>"
                       placeholder="0.00">
              </td>
              <td class="num">
                <span class="unit-value-display" id="unit_value_<?php echo (int)$r['id']; ?>">
                  <?php echo number_format((float)$r['unit_value'], 2); ?>
                </span>
                <input type="hidden" 
                       name="prices[<?php echo (int)$r['id']; ?>][unit_value]" 
                       id="unit_value_input_<?php echo (int)$r['id']; ?>"
                       value="<?php echo htmlspecialchars((string)$r['unit_value'], ENT_QUOTES, 'UTF-8'); ?>">
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        
        <div style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px">
          <button type="submit" class="btn btn-primary">Save All Prices</button>
        </div>
      </form>
      <?php elseif ($filter_type && $filter_id > 0): ?>
      <p class="muted">No products found for the selected filter.</p>
      <?php endif; ?>
    </section>
  </div>

<script>
function toggleFilter() {
  var filterType = document.getElementById('filter_type').value;
  var manufacturerField = document.getElementById('manufacturer_field');
  var sectionField = document.getElementById('section_field');
  var manufacturerSelect = document.getElementById('filter_manufacturer');
  var sectionSelect = document.getElementById('filter_section');
  
  if (filterType === 'manufacturer') {
    manufacturerField.style.display = 'block';
    sectionField.style.display = 'none';
    if (manufacturerSelect) {
      manufacturerSelect.setAttribute('name', 'filter_id');
      manufacturerSelect.value = '0';
    }
    if (sectionSelect) {
      sectionSelect.removeAttribute('name');
      sectionSelect.value = '0';
    }
  } else if (filterType === 'section') {
    manufacturerField.style.display = 'none';
    sectionField.style.display = 'block';
    if (manufacturerSelect) {
      manufacturerSelect.removeAttribute('name');
      manufacturerSelect.value = '0';
    }
    if (sectionSelect) {
      sectionSelect.setAttribute('name', 'filter_id');
      sectionSelect.value = '0';
    }
  } else {
    manufacturerField.style.display = 'none';
    sectionField.style.display = 'none';
    if (manufacturerSelect) {
      manufacturerSelect.removeAttribute('name');
      manufacturerSelect.value = '0';
    }
    if (sectionSelect) {
      sectionSelect.removeAttribute('name');
      sectionSelect.value = '0';
    }
  }
}

function submitFilter() {
  var filterType = document.getElementById('filter_type').value;
  var filterForm = document.getElementById('filterForm');
  
  if (filterType && filterForm) {
    filterForm.submit();
  }
}

// Calculate Unit Value = Real Value / Box when Real Value changes
document.addEventListener('DOMContentLoaded', function(){
  // Initialize filter display on page load
  toggleFilter();
  
  var realValueInputs = document.querySelectorAll('.real-value-input');
  
  realValueInputs.forEach(function(input) {
    input.addEventListener('input', function() {
      var productId = this.getAttribute('data-product-id');
      var boxQty = parseFloat(this.getAttribute('data-box-qty')) || 1;
      var realValue = parseFloat(this.value) || 0;
      
      // Calculate unit value
      var unitValue = 0.00;
      if (boxQty > 0 && realValue > 0) {
        unitValue = realValue / boxQty;
        // Truncate to 2 decimals
        unitValue = Math.floor(unitValue * 100) / 100;
      }
      
      // Update display and hidden input
      var displayEl = document.getElementById('unit_value_' + productId);
      var inputEl = document.getElementById('unit_value_input_' + productId);
      
      if (displayEl) {
        displayEl.textContent = unitValue.toFixed(2);
      }
      if (inputEl) {
        inputEl.value = unitValue.toFixed(2);
      }
    });
  });
});
</script>
</body>
</html>
