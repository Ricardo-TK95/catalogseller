<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  header('Access-Control-Allow-Headers: Content-Type');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  exit;
}

set_exception_handler(function(Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'Server error']);
  exit;
});

// PHPMailer autoload (opcional)
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
  require_once $autoload;
} else {
  $phpmailerBase = __DIR__ . '/PHPMailer/src';
  if (is_dir($phpmailerBase)) {
    @require_once $phpmailerBase . '/PHPMailer.php';
    @require_once $phpmailerBase . '/SMTP.php';
    @require_once $phpmailerBase . '/Exception.php';
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Headers: Content-Type');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  exit;
}
header('Access-Control-Allow-Origin: *');

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) { echo json_encode(['ok'=>false, 'error'=>'Invalid JSON']); exit; }

$log_id      = isset($data['log_id']) ? (int)$data['log_id'] : 0;
$to_email    = isset($data['to']) ? trim((string)$data['to']) : '';
$banner_text = isset($data['banner_text']) ? trim((string)$data['banner_text']) : 'Sr(a) Cliente, você está recebendo uma cópia do pedido enviado ao vendedor(a).';

if ($log_id <= 0){ echo json_encode(['ok'=>false, 'error'=>'Missing order id']); exit; }

// Config MySQL do app
$configPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';
if (file_exists($configPath)) { require_once $configPath; }
$host   = isset($catalog_host) ? $catalog_host : (isset($host) ? $host : 'localhost');
$usuario= isset($catalog_usuario) ? $catalog_usuario : (isset($usuario) ? $usuario : 'root');
$senha  = isset($catalog_senha) ? $catalog_senha : (isset($senha) ? $senha : '');
$port   = isset($catalog_port) ? $catalog_port : (isset($port) ? $port : '3306');
$banco  = isset($catalog_banco) ? $catalog_banco : (isset($banco) ? $banco : 'bdteste');

$dsn = "mysql:host={$host};port={$port};dbname={$banco};charset=utf8mb4";
try {
  $pdo = new PDO($dsn, $usuario, $senha, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e){
  echo json_encode(['ok'=>false, 'error'=>'DB connection error']);
  exit;
}

// Buscar pedido (orders) e itens (order_items)
try {
  $stmt = $pdo->prepare('SELECT id, seller_name, seller_email, client_name, client_email, client_phone, company_slug, order_total, created_at FROM `orders` WHERE id = ? LIMIT 1');
  $stmt->execute([$log_id]);
  $log = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$log) { echo json_encode(['ok'=>false,'error'=>'Pedido não encontrado']); exit; }

  $stmt2 = $pdo->prepare('SELECT qty AS qtd, product_code AS code, product_description AS nome, (qty * unit_value) AS total FROM `order_items` WHERE order_id = ? ORDER BY id ASC');
  $stmt2->execute([$log_id]);
  $items = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>'DB query error']);
  exit;
}

// Recalcular total geral
$total_geral = 0.0;
$countItems = 0;
foreach ($items as $it) {
  $q = (int)($it['qtd'] ?? 0);
  $v = (float)($it['total'] ?? 0.0);
  $total_geral += $v;
  $countItems += $q;
}

$subject = 'Cópia do Pedido #' . $log_id . ' - ' . h((string)($log['client_name'] ?? 'Cliente'));

// Corpo igual ao e-mail simples do vendedor, com tarja/badge no topo
$copyLines = [];
$copyLines[] = 'Informações do Cliente';
$copyLines[] = 'Nome/Loja: ' . (string)($log['client_name'] ?? '');
$copyLines[] = 'E-mail: ' . (string)($log['client_email'] ?? '');
$copyLines[] = 'Telefone: ' . (string)($log['client_phone'] ?? '');
$copyLines[] = '';
$copyLines[] = 'Cotação de Pedido ' . $log_id . ' - ' . $countItems . ' ' . ($countItems === 1 ? 'item' : 'itens');
$copyLines[] = '';
foreach ($items as $p) {
  $codeDisp = isset($p['code']) ? preg_replace('/^0+/', '', (string)$p['code']) : '';
  $q = (int)$p['qtd'];
  $lineTotal = number_format((float)$p['total'], 2, '.', ',');
  $copyLines[] = 'COD PRODUTO = ' . ($codeDisp !== '' ? $codeDisp : '0') . ' || QTDE = ' . $q;
  $copyLines[] = (string)$p['nome'] . ' | $ ' . $lineTotal;
  $copyLines[] = '';
}
$copyLines[] = 'Total: $ ' . number_format($total_geral, 2, '.', ',');
$copyText = implode("\n", $copyLines);

$badgeHtml = '<div style="background:#0ea5e9;color:#fff;padding:10px 14px;border-radius:8px;margin:0 0 12px 0;font-weight:700;text-align:center;">' . h($banner_text) . '</div>';

$body = '<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cópia de Cotação</title>
  <style>
    body { background:#f5f7fb; margin:0; padding:0; font-family: Arial, Helvetica, sans-serif; }
    .card { background:#ffffff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 18px; }
    pre { white-space: pre-wrap; margin:0; font-family: Consolas, Monaco, Menlo, "Courier New", monospace; color:#0f172a; line-height:1.5; font-size:14px; }
  </style>
</head>
<body>
  <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background:#f5f7fb;">
    <tr>
      <td align="center" style="padding:22px 14px;">
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="680" style="max-width:680px;">
          <tr>
            <td>' . $badgeHtml . '</td>
          </tr>
          <tr>
            <td class="card">
              <pre>' . h($copyText) . '</pre>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>';

// Determinar email do vendedor (pegar da tabela sales_reps, campo email, caso não informado)
$vendedor_email = isset($log['seller_email']) ? trim((string)$log['seller_email']) : '';
if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
  // se o destinatário não veio válido, tenta usar vendedor_email de sales_reps
  try {
    $stmtVendTo = $pdo->query("SELECT email FROM sales_reps WHERE is_active = 1 AND email IS NOT NULL AND email <> '' ORDER BY id LIMIT 1");
    $to_email = ($stmtVendTo && ($row=$stmtVendTo->fetch(PDO::FETCH_ASSOC))) ? trim((string)$row['email']) : '';
  } catch (Throwable $_) { $to_email = ''; }
}
if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['ok'=>false,'error'=>'Vendor e-mail inválido']); exit; }

// Removido uso de sales_reps.gmail_user / gmail_app_password. Usar somente variáveis de ambiente (ou defaults de desenvolvimento).
$gmailUser = getenv('GMAIL_USER') ?: 'orderpnbzap@gmail.com';
$gmailPass = getenv('GMAIL_APP_PASSWORD') ?: 'wbzuqgjmzfojnngw';
$gmailPass = str_replace([" ", "\t", "\r", "\n"], '', $gmailPass);
// Sempre usar a conta Gmail autenticada como remetente real
$fromEmail = $gmailUser;
$fromName  = 'Panebras / Zap Foods - Cotações';
$replyTo   = filter_var($vendedor_email, FILTER_VALIDATE_EMAIL) ? $vendedor_email : $fromEmail;

function isGmailAppPasswordError($errorMsg): bool {
  return strpos((string)$errorMsg, '534') !== false && 
         (strpos((string)$errorMsg, '5.7.9') !== false || 
          strpos((string)$errorMsg, 'Application-specific password required') !== false ||
          strpos((string)$errorMsg, 'InvalidSecondFactor') !== false);
}

// Fallback SMTP simples (copiado de send_quote.php)
function smtp_send_gmail_plain($to, $subject, $body, $fromEmail, $fromName, $replyTo, $user, $pass, &$errorMsg): bool {
  $errorMsg = '';
  $hostname = 'smtp.gmail.com';
  $port = 587; // STARTTLS
  $timeout = 20;
  $ctx = stream_context_create([
    'ssl' => [
      'verify_peer' => true,
      'verify_peer_name' => true,
      'allow_self_signed' => false,
      'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
    ]
  ]);
  $fp = @stream_socket_client("tcp://{$hostname}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
  if (!$fp) { $errorMsg = "SMTP connect error: $errstr ($errno)"; return false; }
  stream_set_timeout($fp, $timeout);
  $read = function() use ($fp) { return fgets($fp, 5120) ?: ''; };
  $write = function($data) use ($fp) { return fwrite($fp, $data); };
  $expect = function($prefix) use ($read) {
    $resp = '';
    do { $line = $read(); $resp .= $line; } while ($line && isset($line[3]) && $line[3] === '-');
    return strncmp($resp, $prefix, 3) === 0 ? $resp : $resp;
  };
  $banner = $read();
  if (strncmp($banner, '220', 3) !== 0) { $errorMsg = 'SMTP no banner: ' . trim($banner); return false; }
  $write("EHLO localhost\r\n");
  $ehlo = $expect('250');
  if (strncmp($ehlo, '250', 3) !== 0) { $errorMsg = 'SMTP EHLO failed: ' . trim($ehlo); return false; }
  $write("STARTTLS\r\n");
  $tls = $expect('220');
  if (strncmp($tls, '220', 3) !== 0) { $errorMsg = 'SMTP STARTTLS failed: ' . trim($tls); return false; }
  if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) { $errorMsg = 'TLS negotiation failed'; return false; }
  $write("EHLO localhost\r\n");
  $ehlo2 = $expect('250');
  if (strncmp($ehlo2, '250', 3) !== 0) { $errorMsg = 'SMTP EHLO (2) failed: ' . trim($ehlo2); return false; }
  $write("AUTH LOGIN\r\n");
  $r = $expect('334'); if (strncmp($r, '334', 3) !== 0) { $errorMsg = 'SMTP AUTH not accepted: ' . trim($r); return false; }
  $write(base64_encode($user) . "\r\n");
  $r = $expect('334'); if (strncmp($r, '334', 3) !== 0) { $errorMsg = 'SMTP USER not accepted: ' . trim($r); return false; }
  $write(base64_encode($pass) . "\r\n");
  $r = $expect('235'); if (strncmp($r, '235', 3) !== 0) { $errorMsg = 'SMTP AUTH failed: ' . trim($r); return false; }
  $write("MAIL FROM:<{$fromEmail}>\r\n");
  $r = $expect('250'); if (strncmp($r, '250', 3) !== 0) { $errorMsg = 'MAIL FROM failed: ' . trim($r); return false; }
  $write("RCPT TO:<{$to}>\r\n");
  $r = $expect('250'); if (strncmp($r, '250', 3) !== 0) { $errorMsg = 'RCPT TO failed: ' . trim($r); return false; }
  $write("DATA\r\n");
  $r = $expect('354'); if (strncmp($r, '354', 3) !== 0) { $errorMsg = 'DATA not accepted: ' . trim($r); return false; }
  $headers = [];
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-Type: text/html; charset=UTF-8';
  $headers[] = 'From: ' . ($fromName ?: $fromEmail) . ' <' . $fromEmail . '>';
  if ($replyTo) $headers[] = 'Reply-To: ' . $replyTo;
  $headers[] = 'To: <' . $to . '>';
  $headers[] = 'Subject: ' . $subject;
  $msg = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n";
  $write($msg);
  $r = $expect('250'); if (strncmp($r, '250', 3) !== 0) { $errorMsg = 'Message not accepted: ' . trim($r); return false; }
  $write("QUIT\r\n");
  fclose($fp);
  return true;
}

$sent = false; $smtpErr = null;
if (@class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
  try {
    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->SMTPDebug = 0;
    $mailer->isSMTP();
    $mailer->Host = 'smtp.gmail.com';
    $mailer->SMTPAuth = true;
    $mailer->Username = $gmailUser;
    $mailer->Password = $gmailPass;
    $mailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    $mailer->Port = 465;

    $mailer->setFrom($fromEmail, $fromName);
    // enviar para o e-mail do vendedor; nome opcional com seller_name
    $mailer->addAddress($to_email, ($log['seller_name'] ?? '') ?: 'Vendedor');
    if ($replyTo) $mailer->addReplyTo($replyTo, $fromName);

    $mailer->isHTML(true);
    $mailer->Subject = $subject;
    $mailer->Body = $body;
    $mailer->AltBody = strip_tags($banner_text . "\n\n" . $copyText);

    $mailer->send();
    $sent = true;
  } catch (Throwable $e) {
    $smtpErr = 'PHPMailer: ' . $e->getMessage();
    $sent = false;
  }
}

if (!$sent) {
  $ok = smtp_send_gmail_plain($to_email, $subject, $body, $fromEmail, $fromName, $replyTo, $gmailUser, $gmailPass, $smtpErr);
  $sent = $ok;
}

if (!$sent) {
  $msg = $smtpErr ?: 'send error';
  if (isGmailAppPasswordError($msg)) {
    $msg = 'Erro de autenticação Gmail: configure uma Senha de App.';
  }
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$msg]);
  exit;
}

echo json_encode(['ok'=>true]);
