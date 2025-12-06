<?php
// dashboard.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userEmail = isset($_SESSION['user_email']) ? (string)$_SESSION['user_email'] : '';

// Buscar logo da company
$company_id = 1;
$logo_dir = __DIR__ . '/img_logo';
$logo_bg_url = 'logo.png'; // fallback padrão
$pattern = $logo_dir . '/logo_' . $company_id . '.*';
$existing_files = glob($pattern);
if (!empty($existing_files) && is_file($existing_files[0])) {
    $logo_file = basename($existing_files[0]);
    $logo_bg_url = 'img_logo/' . $logo_file;
}

// Load database connection
$arquivoConfig = __DIR__ . '/config.php';
if (!file_exists($arquivoConfig)) { die('config.php not found'); }
require_once $arquivoConfig;

// Get statistics
$stats = [
  'products' => 0,
  'products_active' => 0,
  'sales_reps' => 0,
  'sales_reps_active' => 0,
  'stores' => 0,
  'stores_active' => 0,
  'sections' => 0,
  'manufacturers' => 0
];

// Count products
if ($stmt = $conn->prepare('SELECT COUNT(*) as total, SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) as active FROM products WHERE company_id=?')) {
  $stmt->bind_param('i', $company_id);
  if ($stmt->execute()) {
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stats['products'] = (int)($row['total'] ?? 0);
    $stats['products_active'] = (int)($row['active'] ?? 0);
    $res->free();
  }
  $stmt->close();
}

// Count sales reps
if ($stmt = $conn->prepare('SELECT COUNT(*) as total, SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) as active FROM sales_reps WHERE company_id=?')) {
  $stmt->bind_param('i', $company_id);
  if ($stmt->execute()) {
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stats['sales_reps'] = (int)($row['total'] ?? 0);
    $stats['sales_reps_active'] = (int)($row['active'] ?? 0);
    $res->free();
  }
  $stmt->close();
}

// Count stores
if ($stmt = $conn->prepare('SELECT COUNT(*) as total, SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) as active FROM stores WHERE company_id=?')) {
  $stmt->bind_param('i', $company_id);
  if ($stmt->execute()) {
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stats['stores'] = (int)($row['total'] ?? 0);
    $stats['stores_active'] = (int)($row['active'] ?? 0);
    $res->free();
  }
  $stmt->close();
}

// Count sections
if ($stmt = $conn->prepare('SELECT COUNT(*) as total FROM sections WHERE company_id=?')) {
  $stmt->bind_param('i', $company_id);
  if ($stmt->execute()) {
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stats['sections'] = (int)($row['total'] ?? 0);
    $res->free();
  }
  $stmt->close();
}

// Count manufacturers
if ($stmt = $conn->prepare('SELECT COUNT(*) as total FROM manufacturers WHERE company_id=?')) {
  $stmt->bind_param('i', $company_id);
  if ($stmt->execute()) {
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stats['manufacturers'] = (int)($row['total'] ?? 0);
    $res->free();
  }
  $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Catalog Seller - Dashboard</title>
  <style>
    :root { --bg:#0f172a; --muted:#94a3b8; --text:#e2e8f0; --pri:#22c55e; --pri2:#16a34a; --logo-bg-url: url('<?php echo htmlspecialchars($logo_bg_url, ENT_QUOTES, 'UTF-8'); ?>'); }
    *{box-sizing:border-box}
    body{margin:0; font-family: system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,"Helvetica Neue",Arial; background: radial-gradient(1200px 800px at 80% -10%, #1e293b, transparent), var(--bg); color:var(--text); min-height:100vh;}
    .container{max-width:1200px; margin:0 auto; padding:24px;}
    .nav{display:flex; align-items:center; justify-content:space-between; gap:16px; padding:14px 18px; border:1px solid rgba(148,163,184,.18); border-radius:14px; background: linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02)); box-shadow: 0 10px 24px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.06); backdrop-filter: blur(6px);}
    .nav-left{display:flex; align-items:center; gap:10px; flex:0 0 auto}
    .nav-center{display:flex; align-items:center; justify-content:center; flex:1}
    .nav-right{display:flex; align-items:center; gap:10px; flex:0 0 auto}
    .brand{font-weight:800; letter-spacing:.3px}
    .brandRow{display:flex; align-items:center; gap:10px}
    .logo{display:block; height:24px; width:auto; background:#ffffff; padding:2px; border-radius:6px; border:1px solid rgba(148,163,184,.25); box-shadow: 0 2px 6px rgba(0,0,0,.15)}
    .logo-company{display:block; height:36px; width:auto; background:#ffffff; padding:3px; border-radius:6px; border:1px solid rgba(148,163,184,.25); box-shadow: 0 2px 6px rgba(0,0,0,.15)}
    .muted{color:var(--muted)}
    .layout{margin-top:22px; display:grid; grid-template-columns: 220px 1fr; gap:18px;}
    .sidebar{padding:14px; border:1px solid rgba(148,163,184,.18); border-radius:14px; background: linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02)); box-shadow: 0 10px 24px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.05)}
    .menu{list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:6px}
    .menu a{display:flex; align-items:center; gap:8px; padding:10px 12px; border-radius:10px; color:var(--text); text-decoration:none; border:1px solid transparent}
    .menu a:hover{border-color: rgba(148,163,184,.28); background: rgba(148,163,184,.06)}
    .menu a.active{background: linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.04)); border-color: rgba(148,163,184,.28); font-weight:700}
    .menu-divider{height:1px; background:rgba(251,191,36,.4); margin:8px 0; border:none}
    .workspace{position:relative; padding:0}
    .workspaceInner{position:relative; padding:18px; border:1px solid rgba(148,163,184,.18); border-radius:14px; background: linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.015)); box-shadow: 0 10px 24px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.05)}
    .workspace::before{content:""; position:absolute; inset:0; background: var(--logo-bg-url, url('logo.png')) center center / 800px auto no-repeat; opacity:.08; pointer-events:none; filter: saturate(0.9) brightness(1.2)}
    .gridCards{display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:18px}
    .card{padding:18px; border:1px solid rgba(148,163,184,.18); border-radius:14px; background: linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.015)); box-shadow: 0 10px 24px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.05);}
    a.btn{display:inline-block; text-decoration:none; color:#052e16; background: linear-gradient(180deg, var(--pri), var(--pri2)); padding:10px 14px; border-radius:10px; font-weight:700; box-shadow:0 8px 16px rgba(34,197,94,.25)}
    
    /* Stats Cards */
    .statsGrid{display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px}
    .statCard{position:relative; padding:20px; border:1px solid rgba(148,163,184,.18); border-radius:14px; background: linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02)); box-shadow: 0 8px 20px rgba(0,0,0,.25); overflow:hidden; transition:transform 0.2s, box-shadow 0.2s}
    .statCard:hover{transform:translateY(-2px); box-shadow: 0 12px 28px rgba(0,0,0,.35)}
    .statCard::before{content:""; position:absolute; top:0; left:0; right:0; height:4px; background:linear-gradient(90deg, var(--pri), var(--pri2))}
    .statHeader{display:flex; align-items:center; justify-content:space-between; margin-bottom:12px}
    .statIcon{width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:24px; background:linear-gradient(135deg, rgba(34,197,94,.2), rgba(22,163,74,.15)); color:var(--pri)}
    .statTitle{font-size:13px; color:var(--muted); text-transform:uppercase; letter-spacing:0.5px; font-weight:600}
    .statValue{font-size:32px; font-weight:800; color:var(--text); line-height:1; margin:8px 0 4px 0}
    .statSub{font-size:12px; color:var(--muted); display:flex; align-items:center; gap:4px}
    .statSub .active{color:var(--pri)}
    
    /* Chart */
    .chartContainer{position:relative; width:100%; height:200px; margin-top:16px}
    .chartBar{display:flex; align-items:flex-end; justify-content:space-around; height:100%; gap:8px}
    .bar{flex:1; background:linear-gradient(180deg, var(--pri), var(--pri2)); border-radius:6px 6px 0 0; min-width:40px; position:relative; transition:all 0.3s ease}
    .bar:hover{opacity:0.8; transform:scaleY(1.05)}
    .barLabel{position:absolute; bottom:-20px; left:0; right:0; text-align:center; font-size:11px; color:var(--muted)}
    .barValue{position:absolute; top:-20px; left:0; right:0; text-align:center; font-size:12px; font-weight:700; color:var(--text)}
    
    /* Pie Chart */
    .pieChart{width:120px; height:120px; border-radius:50%; background:conic-gradient(var(--pri) 0% var(--pct), rgba(148,163,184,.2) var(--pct) 100%); position:relative; margin:0 auto}
    .pieChart::after{content:""; position:absolute; inset:20px; border-radius:50%; background:var(--bg)}
    .pieValue{position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); font-size:20px; font-weight:800; color:var(--text); z-index:1}
  </style>
</head>
<body>
  <div class="container">
    <header class="nav">
      <div class="nav-left">
        <div class="brandRow"><img src="logo.png" alt="Catalog Seller logo" class="logo"><div class="brand">Catalog Seller</div></div>
        <div class="muted" style="font-size:12px;">Welcome, <?php echo htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
      <div class="nav-center">
        <img src="<?php echo htmlspecialchars($logo_bg_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Company logo" class="logo-company">
      </div>
      <div class="nav-right">
        <a class="btn" href="logout.php">Sign out</a>
      </div>
    </header>

    <section class="layout">
      <aside class="sidebar" aria-label="Main menu">
        <ul class="menu">
          <li><a href="dashboard.php" class="active" aria-current="page">Dashboard</a></li>
          <li><a href="company_edit.php">Company</a></li>
          <li><a href="sales_reps.php">Sales Representatives</a></li>
          <li><hr class="menu-divider"></li>
          <li><a href="manufacturers.php">Manufacturers</a></li>
          <li><a href="sections.php">Sections</a></li>
          <li><a href="products.php">Products</a></li>
          <li><hr class="menu-divider"></li>
          <li><a href="crm_store.php">CRM Stores</a></li>
          <li><a href="messages.php">Messages Orders</a></li>          
          <li><a href="orders.php">Orders</a></li>
        </ul>
      </aside>
      <div class="workspace">
        <div class="workspaceInner">
          <h2 style="margin-top:0; margin-bottom:20px; font-size:24px; font-weight:700">Dashboard Overview</h2>
          
          <div class="statsGrid">
            <div class="statCard">
              <div class="statHeader">
                <div>
                  <div class="statTitle">Products</div>
                  <div class="statValue"><?php echo number_format($stats['products']); ?></div>
                </div>
                <div class="statIcon">📦</div>
              </div>
              <div class="statSub">
                <span class="active"><?php echo number_format($stats['products_active']); ?> active</span>
                <?php if ($stats['products'] > 0): ?>
                <span>• <?php echo number_format(($stats['products_active'] / $stats['products']) * 100, 1); ?>%</span>
                <?php endif; ?>
              </div>
              <?php if ($stats['products'] > 0): ?>
              <div class="pieChart" style="--pct: <?php echo ($stats['products_active'] / $stats['products']) * 100; ?>%">
                <div class="pieValue"><?php echo number_format(($stats['products_active'] / $stats['products']) * 100, 0); ?>%</div>
              </div>
              <?php endif; ?>
            </div>
            
            <div class="statCard">
              <div class="statHeader">
                <div>
                  <div class="statTitle">Sales Representatives</div>
                  <div class="statValue"><?php echo number_format($stats['sales_reps']); ?></div>
                </div>
                <div class="statIcon">👥</div>
              </div>
              <div class="statSub">
                <span class="active"><?php echo number_format($stats['sales_reps_active']); ?> active</span>
                <?php if ($stats['sales_reps'] > 0): ?>
                <span>• <?php echo number_format(($stats['sales_reps_active'] / $stats['sales_reps']) * 100, 1); ?>%</span>
                <?php endif; ?>
              </div>
              <?php if ($stats['sales_reps'] > 0): ?>
              <div class="pieChart" style="--pct: <?php echo ($stats['sales_reps_active'] / $stats['sales_reps']) * 100; ?>%">
                <div class="pieValue"><?php echo number_format(($stats['sales_reps_active'] / $stats['sales_reps']) * 100, 0); ?>%</div>
              </div>
              <?php endif; ?>
            </div>
            
            <div class="statCard">
              <div class="statHeader">
                <div>
                  <div class="statTitle">Stores</div>
                  <div class="statValue"><?php echo number_format($stats['stores']); ?></div>
                </div>
                <div class="statIcon">🏪</div>
              </div>
              <div class="statSub">
                <span class="active"><?php echo number_format($stats['stores_active']); ?> active</span>
                <?php if ($stats['stores'] > 0): ?>
                <span>• <?php echo number_format(($stats['stores_active'] / $stats['stores']) * 100, 1); ?>%</span>
                <?php endif; ?>
              </div>
              <?php if ($stats['stores'] > 0): ?>
              <div class="pieChart" style="--pct: <?php echo ($stats['stores_active'] / $stats['stores']) * 100; ?>%">
                <div class="pieValue"><?php echo number_format(($stats['stores_active'] / $stats['stores']) * 100, 0); ?>%</div>
              </div>
              <?php endif; ?>
            </div>
            
            <div class="statCard">
              <div class="statHeader">
                <div>
                  <div class="statTitle">Sections</div>
                  <div class="statValue"><?php echo number_format($stats['sections']); ?></div>
                </div>
                <div class="statIcon">📁</div>
              </div>
              <div class="statSub">Categories</div>
            </div>
            
            <div class="statCard">
              <div class="statHeader">
                <div>
                  <div class="statTitle">Manufacturers</div>
                  <div class="statValue"><?php echo number_format($stats['manufacturers']); ?></div>
                </div>
                <div class="statIcon">🏭</div>
              </div>
              <div class="statSub">Brands</div>
            </div>
          </div>
          
          <div class="card" style="margin-top:24px">
            <h3 style="margin-top:0; margin-bottom:16px">Distribution Overview</h3>
            <div class="chartContainer">
              <?php 
                $maxValue = max($stats['products'], $stats['sales_reps'], $stats['stores'], $stats['sections'], $stats['manufacturers'], 1);
                $getBarHeight = function($value) use ($maxValue) {
                  return $maxValue > 0 ? min(100, ($value / $maxValue) * 100) : 0;
                };
              ?>
              <div class="chartBar">
                <div class="bar" style="height:<?php echo $getBarHeight($stats['products']); ?>%">
                  <div class="barValue"><?php echo number_format($stats['products']); ?></div>
                  <div class="barLabel">Products</div>
                </div>
                <div class="bar" style="height:<?php echo $getBarHeight($stats['sales_reps']); ?>%">
                  <div class="barValue"><?php echo number_format($stats['sales_reps']); ?></div>
                  <div class="barLabel">Sales Reps</div>
                </div>
                <div class="bar" style="height:<?php echo $getBarHeight($stats['stores']); ?>%">
                  <div class="barValue"><?php echo number_format($stats['stores']); ?></div>
                  <div class="barLabel">Stores</div>
                </div>
                <div class="bar" style="height:<?php echo $getBarHeight($stats['sections']); ?>%">
                  <div class="barValue"><?php echo number_format($stats['sections']); ?></div>
                  <div class="barLabel">Sections</div>
                </div>
                <div class="bar" style="height:<?php echo $getBarHeight($stats['manufacturers']); ?>%">
                  <div class="barValue"><?php echo number_format($stats['manufacturers']); ?></div>
                  <div class="barLabel">Manufacturers</div>
                </div>
              </div>
            </div>
          </div>
          
          <div class="gridCards" style="margin-top:24px">
            <div class="card">
              <h3 style="margin-top:0">Quick Actions</h3>
              <ul class="muted" style="list-style:none; padding:0; margin:0">
                <li style="margin-bottom:8px"><a href="products.php" style="color:var(--pri); text-decoration:none">→ Manage Products</a></li>
                <li style="margin-bottom:8px"><a href="stores.php" style="color:var(--pri); text-decoration:none">→ Manage Stores</a></li>
                <li style="margin-bottom:8px"><a href="sales_reps.php" style="color:var(--pri); text-decoration:none">→ Manage Sales Reps</a></li>
                <li style="margin-bottom:8px"><a href="import_products.php" style="color:var(--pri); text-decoration:none">→ Import Products</a></li>
              </ul>
            </div>
            <div class="card">
              <h3 style="margin-top:0">System Status</h3>
              <div style="display:flex; flex-direction:column; gap:8px">
                <div style="display:flex; align-items:center; gap:8px">
                  <span style="width:8px; height:8px; border-radius:50%; background:var(--pri)"></span>
                  <span class="muted" style="font-size:13px">All systems operational</span>
                </div>
                <div style="display:flex; align-items:center; gap:8px">
                  <span style="width:8px; height:8px; border-radius:50%; background:var(--pri)"></span>
                  <span class="muted" style="font-size:13px">Database connected</span>
                </div>
                <div style="display:flex; align-items:center; gap:8px">
                  <span style="width:8px; height:8px; border-radius:50%; background:var(--pri)"></span>
                  <span class="muted" style="font-size:13px">Ready for operations</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</body>
</html>
