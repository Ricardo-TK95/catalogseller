<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  header('Access-Control-Allow-Headers: Content-Type');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  exit;
}

// Tenta carregar PHPMailer via Composer ou fontes locais
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

function clean_text($s){ return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

try {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) { throw new Exception('JSON inválido'); }

  $to = isset($data['to']) ? trim((string)$data['to']) : '';
  $subject = isset($data['subject']) ? (string)$data['subject'] : '';
  $html = isset($data['message_html']) ? (string)$data['message_html'] : '';
  $text = isset($data['message_text']) ? (string)$data['message_text'] : '';
  $fromName = isset($data['from_name']) ? (string)$data['from_name'] : '';
  $fromEmail = isset($data['from_email']) ? trim((string)$data['from_email']) : '';
  $cc = isset($data['cc']) ? trim((string)$data['cc']) : '';

  if ($to === '' || $subject === '' || ($html === '' && $text === '')) {
    throw new Exception('Parâmetros ausentes');
  }
  $to = filter_var($to, FILTER_VALIDATE_EMAIL) ? $to : '';
  if ($to === '') { throw new Exception('E-mail de destino inválido'); }

  // Config SMTP Gmail (usar variáveis de ambiente em produção)
  $gmailUser = getenv('GMAIL_USER') ?: 'orderpnbzap@gmail.com';
  $gmailPass = getenv('GMAIL_APP_PASSWORD') ?: 'wbzuqgjmzfojnngw';
  $gmailPass = str_replace([" ", "\t", "\r", "\n"], '', $gmailPass);
  $fromEmail = filter_var($fromEmail, FILTER_VALIDATE_EMAIL) ? $fromEmail : '';
  $fromName = $fromName !== '' ? $fromName : 'Panebras / Zap Foods - Cotações';
  // Sempre usar a conta Gmail autenticada como remetente real
  $senderEmail = $gmailUser;
  $replyToEmail = $fromEmail ?: $gmailUser;

  $bodyHtml = $html !== '' ? $html : nl2br(clean_text($text));

  // 1) Tenta via PHPMailer SMTP (porta 465 SSL)
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
      // From precisa ser o usuário autenticado do Gmail
      $mailer->setFrom($senderEmail, $fromName);
      $mailer->addAddress($to);
      if ($cc && filter_var($cc, FILTER_VALIDATE_EMAIL)) { $mailer->addCC($cc); }
      if ($replyToEmail) { $mailer->addReplyTo($replyToEmail, $fromName); }

      $mailer->isHTML(true);
      $mailer->Subject = $subject;
      $mailer->Body = $bodyHtml;
      $mailer->AltBody = strip_tags($bodyHtml);
      $mailer->send();
      echo json_encode(['ok'=>true]);
      exit;
    } catch (Throwable $e) {
      // continua para fallback
    }
  }

  // 2) Fallback SMTP manual (STARTTLS 587)
  function smtp_send_gmail_plain($to, $subject, $body, $fromEmail, $fromName, $replyTo, $user, $pass, &$errorMsg): bool {
    $errorMsg = '';
    $hostname = 'smtp.gmail.com';
    $port = 587;
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
    $banner = $read(); if (strncmp($banner, '220', 3) !== 0) { $errorMsg = 'SMTP no banner: ' . trim($banner); return false; }
    $write("EHLO localhost\r\n"); $ehlo = $expect('250'); if (strncmp($ehlo, '250', 3) !== 0) { $errorMsg = 'SMTP EHLO failed: ' . trim($ehlo); return false; }
    $write("STARTTLS\r\n"); $tls = $expect('220'); if (strncmp($tls, '220', 3) !== 0) { $errorMsg = 'SMTP STARTTLS failed: ' . trim($tls); return false; }
    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) { $errorMsg = 'TLS negotiation failed'; return false; }
    $write("EHLO localhost\r\n"); $ehlo2 = $expect('250'); if (strncmp($ehlo2, '250', 3) !== 0) { $errorMsg = 'SMTP EHLO (2) failed: ' . trim($ehlo2); return false; }
    $write("AUTH LOGIN\r\n"); $r = $expect('334'); if (strncmp($r, '334', 3) !== 0) { $errorMsg = 'SMTP AUTH not accepted: ' . trim($r); return false; }
    $write(base64_encode($user) . "\r\n"); $r = $expect('334'); if (strncmp($r, '334', 3) !== 0) { $errorMsg = 'SMTP USER not accepted: ' . trim($r); return false; }
    $write(base64_encode($pass) . "\r\n"); $r = $expect('235'); if (strncmp($r, '235', 3) !== 0) { $errorMsg = 'SMTP AUTH failed: ' . trim($r); return false; }
    $write("MAIL FROM:<{$fromEmail}>\r\n"); $r = $expect('250'); if (strncmp($r, '250', 3) !== 0) { $errorMsg = 'MAIL FROM failed: ' . trim($r); return false; }
    $write("RCPT TO:<{$to}>\r\n"); $r = $expect('250'); if (strncmp($r, '250', 3) !== 0) { $errorMsg = 'RCPT TO failed: ' . trim($r); return false; }
    $write("DATA\r\n"); $r = $expect('354'); if (strncmp($r, '354', 3) !== 0) { $errorMsg = 'DATA not accepted: ' . trim($r); return false; }
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    // Sempre usar a conta Gmail autenticada como From
    $headers[] = 'From: ' . ($fromName ?: $user) . ' <' . $user . '>';
    if ($replyTo) $headers[] = 'Reply-To: ' . $replyTo;
    $headers[] = 'To: <' . $to . '>';
    $headers[] = 'Subject: ' . $subject;
    $msg = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n";
    $write($msg); $r = $expect('250'); if (strncmp($r, '250', 3) !== 0) { $errorMsg = 'Message not accepted: ' . trim($r); return false; }
    $write("QUIT\r\n"); fclose($fp); return true;
  }

  $smtpErr = '';
  if (smtp_send_gmail_plain($to, $subject, $bodyHtml, $senderEmail, $fromName, $replyToEmail, $gmailUser, $gmailPass, $smtpErr)) {
    echo json_encode(['ok'=>true]);
    exit;
  }

  // 3) Fallback final: mail()
  $headers = [];
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-type: text/html; charset=UTF-8';
  $headers[] = 'From: ' . ($fromName !== '' ? (sprintf('"%s" <%s>', str_replace(["\r","\n"], '', $fromName), $senderEmail)) : $senderEmail);
  if ($replyToEmail) { $headers[] = 'Reply-To: ' . $replyToEmail; }
  if ($cc && filter_var($cc, FILTER_VALIDATE_EMAIL)) { $headers[] = 'Cc: ' . $cc; }
  $ok = @mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $bodyHtml, implode("\r\n", $headers));
  if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Falha ao enviar e-mail (mail())']);
    exit;
  }
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}

