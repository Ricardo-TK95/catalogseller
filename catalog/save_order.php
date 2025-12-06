<?php
declare(strict_types=1);

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
if (file_exists($configPath)) { require_once $configPath; }
$host   = isset($catalog_host) ? $catalog_host : (isset($host) ? $host : 'localhost');
$usuario= isset($catalog_usuario) ? $catalog_usuario : (isset($usuario) ? $usuario : 'root');
$senha  = isset($catalog_senha) ? $catalog_senha : (isset($senha) ? $senha : '');
$port   = isset($catalog_port) ? $catalog_port : (isset($port) ? $port : '3306');
$banco  = isset($catalog_banco) ? $catalog_banco : (isset($banco) ? $banco : 'bdteste');

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) { echo json_encode(['ok'=>false, 'error'=>'Invalid JSON']); exit; }

$orderId       = trim((string)($data['order_id'] ?? ''));
$vendedor_nome = trim((string)($data['vendedor_nome'] ?? ''));
$empresa       = isset($data['empresa']) ? trim((string)$data['empresa']) : '';
$payload_json  = (string)($data['payload_json'] ?? '');

if ($orderId === '') { echo json_encode(['ok'=>false, 'error'=>'Missing order_id']); exit; }

$dsn = "mysql:host={$host};port={$port};dbname={$banco};charset=utf8mb4";
try {
  $pdo = new PDO($dsn, $usuario, $senha, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e){
  echo json_encode(['ok'=>false, 'error'=>'DB connection error']);
  exit;
}

try {
  // Ensure new order_items exists
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
    KEY `idx_product_code` (`product_code`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

  // Ensure orders has order_total column (compat with older MySQL without IF NOT EXISTS)
  try {
    $pdo->exec("ALTER TABLE `orders` ADD COLUMN `order_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00");
  } catch (Throwable $__ignore) { /* column may already exist */ }
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'DB migrate error: '.$e->getMessage()]);
  exit;
}

$items = [];
if ($payload_json) {
  $payload_data = json_decode($payload_json, true);
  if (is_array($payload_data) && isset($payload_data['items']) && is_array($payload_data['items'])) {
    $items = $payload_data['items'];
  }
}

$qtde_distintos = is_array($items) ? count($items) : 0;
$tot_geral = 0.0;
foreach ($items as $it){
  $q = isset($it['q']) ? (int)$it['q'] : 0;
  $p = isset($it['p']) ? (float)$it['p'] : 0.0;
  $tot_geral += ($q * $p);
}
$tot_geral = round($tot_geral, 2);

try {
  $pdo->beginTransaction();
  // Delete existing items for this order
  $del = $pdo->prepare('DELETE FROM `order_items` WHERE `order_id` = ?');
  $del->execute([(int)$orderId]);

  if ($qtde_distintos > 0) {
    $ins = $pdo->prepare('INSERT INTO `order_items` (`order_id`,`qty`,`product_code`,`product_description`,`unit_value`,`pack_qty`) VALUES (?,?,?,?,?,?)');
    foreach ($items as $it) {
      $code = isset($it['c']) ? (string)$it['c'] : '';
      $qty  = isset($it['q']) ? (int)$it['q'] : 0;
      $price= isset($it['p']) ? (float)$it['p'] : 0.0; // unit price
      $name = isset($it['n']) ? (string)$it['n'] : '';
      $ins->execute([(int)$orderId, $qty, $code, $name, round($price,2), $qtde_distintos]);
    }
  }
  // Update order_total on header from order_items sum (robusto mesmo sem payload)
  try {
    $upd2 = $pdo->prepare('UPDATE `orders` o
      LEFT JOIN (
        SELECT oi.order_id, SUM(oi.qty * oi.unit_value) AS total
        FROM `order_items` oi
        WHERE oi.order_id = ?
        GROUP BY oi.order_id
      ) t ON t.order_id = o.id
      SET o.order_total = COALESCE(t.total, 0.00)
      WHERE o.id = ?');
    $oid = (int)$orderId;
    $upd2->execute([$oid, $oid]);
  } catch (Throwable $__u) { /* ignore */ }
  $pdo->commit();
  echo json_encode(['ok'=>true, 'saved'=> $qtde_distintos]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'DB save error']);
}
