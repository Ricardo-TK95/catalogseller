<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

header('Content-Type: application/json; charset=UTF-8');

set_exception_handler(function(Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'Server error']);
  exit;
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Headers: Content-Type');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  exit;
}
header('Access-Control-Allow-Origin: *');

// Load DB config from parent config.php to use the same DB as the app
$configPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';
if (file_exists($configPath)) {
  require_once $configPath;
}
$host   = isset($catalog_host) ? $catalog_host : (isset($host) ? $host : 'localhost');
$usuario= isset($catalog_usuario) ? $catalog_usuario : (isset($usuario) ? $usuario : 'root');
$senha  = isset($catalog_senha) ? $catalog_senha : (isset($senha) ? $senha : '');
$port   = isset($catalog_port) ? $catalog_port : (isset($port) ? $port : '3306');
$banco  = isset($catalog_banco) ? $catalog_banco : (isset($banco) ? $banco : 'bdteste');

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) { echo json_encode(['ok'=>false, 'error'=>'Invalid JSON']); exit; }

$vendedor_nome  = trim((string)($data['vendedor_nome'] ?? ''));
$vendedor_email = trim((string)($data['vendedor_email'] ?? ''));
$cliente_nome   = trim((string)($data['cliente_nome'] ?? ''));
$cliente_email  = trim((string)($data['cliente_email'] ?? ''));
$cliente_tel    = trim((string)($data['cliente_tel'] ?? ''));
$empresa        = isset($data['empresa']) ? trim((string)$data['empresa']) : '';
$payload_json   = (string)($data['payload_json'] ?? '');
// store_cod: quando usuário entrou via chave de 5 dígitos, vem da sessão
$store_cod      = isset($_SESSION['store_cod']) ? trim((string)$_SESSION['store_cod']) : null;

// Não bloquear: permitir criação do link curto mesmo com campos vazios

$dsn = "mysql:host={$host};port={$port};dbname={$banco};charset=utf8mb4";
try {
  $pdo = new PDO($dsn, $usuario, $senha, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e){
  echo json_encode(['ok'=>false, 'error'=>'DB connection error']);
  exit;
}

// Garantir store_cod: se vier vazio mas houver store_id na sessão, buscar na tabela stores
try {
  if ((!isset($store_cod) || $store_cod === '' || $store_cod === null) && isset($_SESSION['store_id']) && (int)$_SESSION['store_id'] > 0) {
    $sid = (int)$_SESSION['store_id'];
    $stmtCod = $pdo->prepare('SELECT cod FROM stores WHERE id = ? LIMIT 1');
    $stmtCod->execute([$sid]);
    $sc = $stmtCod->fetchColumn();
    if ($sc !== false && $sc !== null && $sc !== '') { $store_cod = (string)$sc; }
  }
} catch (Throwable $__) { /* ignore; mantém nulo */ }

try {
  // New tables: orders (header) and order_items (items)
  $pdo->exec("CREATE TABLE IF NOT EXISTS `orders` (
    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `seller_name` VARCHAR(120) NOT NULL,
    `seller_email` VARCHAR(160) NOT NULL,
    `client_name` VARCHAR(160) NOT NULL,
    `client_phone` VARCHAR(40) NOT NULL,
    `client_email` VARCHAR(100) DEFAULT NULL,
    `store_cod` VARCHAR(64) DEFAULT NULL,
    `company_slug` VARCHAR(16) DEFAULT NULL,
    `order_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `ip` VARCHAR(64) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `send_status` ENUM('pending','sent','error') NOT NULL DEFAULT 'pending',
    `send_error` TEXT DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_seller_email` (`seller_email`),
    KEY `idx_store_cod` (`store_cod`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ");
  $pdo->exec("CREATE TABLE IF NOT EXISTS `order_items` (
    `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `order_id` BIGINT(20) UNSIGNED NOT NULL,
    `qty` INT(11) NOT NULL,
    `product_code` VARCHAR(40) NOT NULL,
    `product_description` VARCHAR(255) NOT NULL,
    `unit_value` DECIMAL(12,2) NOT NULL,
    `pack_qty` INT(11) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_order_id` (`order_id`),
    KEY `idx_product_code` (`product_code`),
    CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'DB migrate error']);
  exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

// Compute order total from payload items if provided
$itemsFromPayload = [];
if (isset($data['items']) && is_array($data['items'])) {
  $itemsFromPayload = $data['items'];
}
$orderTotal = 0.0;
if ($itemsFromPayload) {
  foreach ($itemsFromPayload as $it) {
    $qty = isset($it['q']) ? (int)$it['q'] : 0;
    $price = isset($it['p']) ? (float)$it['p'] : 0.0;
    if ($qty > 0 && $price >= 0) { $orderTotal += ($qty * $price); }
  }
}
$orderTotal = round($orderTotal, 2);

// Resolve company_slug when missing
try {
  if ($empresa === '' && isset($_GET['empresa'])) {
    $empresa = trim((string)$_GET['empresa']);
  }
  if ($empresa === '' && isset($_SESSION) && isset($_SESSION['company_id'])) {
    $cid = (int)$_SESSION['company_id'];
    if ($cid > 0) {
      try {
        $stmtSlug = $pdo->prepare('SELECT slug FROM companies WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmtSlug->execute([$cid]);
        $empresa = (string)($stmtSlug->fetchColumn() ?: '');
      } catch (Throwable $__) { /* ignore */ }
    }
  }
  if ($empresa === '') { $empresa = 'flpnb'; }
} catch (Throwable $__) { if ($empresa === '') { $empresa = 'flpnb'; } }

// Inserir header em orders e obter id
try {
  $ins = $pdo->prepare("INSERT INTO `orders`
    (`seller_name`,`seller_email`,`client_name`,`client_email`,`client_phone`,`store_cod`,`company_slug`,`order_total`,`ip`,`user_agent`,`send_status`)
    VALUES (?,?,?,?,?,?,?,?,?,?,'pending')");
  $ins->execute([$vendedor_nome,$vendedor_email,$cliente_nome,$cliente_email,$cliente_tel,$store_cod,$empresa,$orderTotal,$ip,$ua]);
  $logId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'DB insert error']);
  exit;
}
 
// Montar URL curta baseada no id
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = $scheme . '://' . $host;
$urlPedido = $base . "/catalog/index_vend.php?id=" . urlencode((string)$logId) . ($empresa ? ("&empresa=" . urlencode($empresa)) : '');

// Salvar itens do pedido (opcional, quando payload_json vier)
$items = [];
if ($payload_json) {
  $payload_data = json_decode($payload_json, true);
  if (is_array($payload_data) && isset($payload_data['items']) && is_array($payload_data['items'])) {
    $items = $payload_data['items'];
  }
}
try {
  if (!empty($items)) {
    $pdo->beginTransaction();
    $qtde_distintos = count($items);
    $tot_geral = 0.0;
    foreach ($items as $it) { $tot_geral += ((int)($it['q'] ?? 0)) * ((float)($it['p'] ?? 0)); }
    $tot_geral = round($tot_geral, 2);
    $insItem = $pdo->prepare('INSERT INTO `order_items` (`order_id`,`qty`,`product_code`,`product_description`,`unit_value`,`pack_qty`) VALUES (?,?,?,?,?,?)');
    foreach ($items as $it) {
      $code = isset($it['c']) ? (string)$it['c'] : '';
      $qty  = isset($it['q']) ? (int)$it['q'] : 0;
      $price= isset($it['p']) ? (float)$it['p'] : 0.0; // unit price
      $name = isset($it['n']) ? (string)$it['n'] : '';
      $insItem->execute([$logId, $qty, $code, $name, round($price,2), $qtde_distintos]);
    }
    $pdo->commit();
  }
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

echo json_encode(['ok'=>true, 'id'=>$logId, 'url'=>$urlPedido]);
