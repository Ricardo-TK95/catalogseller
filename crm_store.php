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

// WhatsApp batch progress for progress panel
if (isset($_GET['action']) && $_GET['action'] === 'whatsapp_batch_status') {
  header('Content-Type: application/json; charset=UTF-8');
  $srid = isset($_GET['sales_rep_id']) ? (int)$_GET['sales_rep_id'] : 0;
  if ($srid <= 0) { echo json_encode(['success'=>false,'error'=>'Missing sales_rep_id']); exit; }
  $batch = null;
  $st = $conn->prepare("SELECT id, status, total_recipients, sent_count, error_count FROM access_key_sending_batches WHERE sales_rep_id=? AND status IN ('pending','processing') ORDER BY id DESC LIMIT 1");
  if ($st) {
    $st->bind_param('i', $srid);
    if ($st->execute()) {
      $rs = $st->get_result();
      $batch = $rs->fetch_assoc();
      $rs->free();
    }
    $st->close();
  }
  if (!$batch) { echo json_encode(['success'=>true,'active'=>false]); exit; }
  $pending=0;$sending=0;$sent=0;$error=0;$canceled=0;
  $sti = $conn->prepare("SELECT status, COUNT(*) c FROM access_key_sending_items WHERE batch_id=? GROUP BY status");
  if ($sti) {
    $sti->bind_param('i', $batch['id']);
    if ($sti->execute()) {
      $rsi = $sti->get_result();
      while ($row = $rsi->fetch_assoc()) {
        $k = (string)($row['status'] ?? '');
        $v = (int)($row['c'] ?? 0);
        if ($k==='pending') $pending=$v;
        elseif ($k==='sending') $sending=$v;
        elseif ($k==='sent') $sent=$v;
        elseif ($k==='error') $error=$v;
        elseif ($k==='canceled') $canceled=$v;
      }
      $rsi->free();
    }
    $sti->close();
  }
  $total = (int)($batch['total_recipients'] ?? 0);
  if ($total === 0) { $total = $pending + $sending + $sent + $error + $canceled; }
  echo json_encode(['success'=>true,'active'=>true,'batch'=>[
    'id'=>(int)$batch['id'],'status'=>$batch['status'],'total'=>$total,
    'pending'=>$pending,'sending'=>$sending,'sent'=>$sent,'error'=>$error
  ]]);
  exit;
}

$company_id = 1;
 $mailer_api = getenv('MAILERSEND_API_KEY');
 if (!$mailer_api) { $mailer_api = 'mlsn.39d4777d8c34806b6b5ba0eeba1db256daf350266b0a47c6b9f783c25016e2ac'; }

$action = isset($_POST['action']) ? $_POST['action'] : '';

// Process POST actions
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
          $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
          if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'is_active' => $to]);
            exit;
          }
          header('Location: crm_store.php'); 
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
    } elseif ($action === 'delete_all') {
      $sql = 'DELETE FROM stores WHERE company_id=?';
      if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('i', $company_id);
        if ($stmt->execute()) { 
          $_SESSION['flash_ok'] = 'All stores removed.'; 
          header('Location: crm_store.php'); 
          exit; 
        } else { 
          $erro = 'Failed to remove all stores.'; 
        }
        $stmt->close();
      } else { 
        $erro = 'Failed to prepare deletion.'; 
      }
    } elseif ($action === 'update_phone') {
      $store_id = isset($_POST['store_id']) ? (int)$_POST['store_id'] : 0;
      $phone_idx = isset($_POST['phone_idx']) ? (int)$_POST['phone_idx'] : 1;
      $phone = isset($_POST['phone']) ? trim((string)$_POST['phone']) : '';
      $phone = preg_replace('/[^0-9]/', '', $phone);
      
      if ($store_id > 0 && $phone_idx >= 1 && $phone_idx <= 3) {
        $phone_field = $phone_idx === 1 ? 'phone' : ($phone_idx === 2 ? 'phone2' : 'phone3');
        $sql = 'UPDATE stores SET ' . $phone_field . '=?, updated_at=NOW() WHERE id=? AND company_id=?';
        if ($stmt = $conn->prepare($sql)) {
          $stmt->bind_param('sii', $phone, $store_id, $company_id);
          if ($stmt->execute()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
          } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Failed to update phone.']);
            exit;
          }
          $stmt->close();
        } else {
          header('Content-Type: application/json');
          echo json_encode(['success' => false, 'error' => 'Failed to prepare phone update.']);
          exit;
        }
      } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid store ID or phone index.']);
        exit;
      }
    } elseif ($action === 'gen_store_key') {
      $store_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
      if ($store_id > 0) {
        // Fetch sales_rep_id for the store to compose the key
        $sr_id = 0;
        if ($st = $conn->prepare('SELECT sales_rep_id FROM stores WHERE id=? AND company_id=? LIMIT 1')) {
          $st->bind_param('ii', $store_id, $company_id);
          if ($st->execute()) {
            $rs = $st->get_result();
            if ($row = $rs->fetch_assoc()) { $sr_id = (int)($row['sales_rep_id'] ?? 0); }
            $rs->free();
          }
          $st->close();
        }

        // Generate a NEW random 5-digit key (always regenerate even if current is valid)
        $code = random_int(0, 99999);
        $key_code = str_pad((string)$code, 5, '0', STR_PAD_LEFT);
        $valid_until = date('Y-m-d', strtotime('+7 days'));

        if ($up = $conn->prepare('UPDATE stores SET `key_access`=?, `key_validate`=? WHERE id=? AND company_id=?')) {
          $up->bind_param('ssii', $key_code, $valid_until, $store_id, $company_id);
          $up->execute();
          $up->close();
        }

        // AJAX support: return JSON instead of redirect
        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($is_ajax) {
          header('Content-Type: application/json; charset=UTF-8');
          echo json_encode(['success'=>true, 'key'=>$key_code, 'valid'=>$valid_until, 'store_id'=>$store_id]);
          exit;
        }
      }
      // Redirect back preserving current filters (non-AJAX)
      $qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? ('?' . $_SERVER['QUERY_STRING']) : '';
      header('Location: crm_store.php' . $qs);
      exit;
    } elseif ($action === 'send_keys_email') {
      header('Content-Type: application/json; charset=UTF-8');
      $ids_raw = isset($_POST['ids']) ? (string)$_POST['ids'] : '';
      $ids = json_decode($ids_raw, true);
      if (!is_array($ids) || empty($ids)) { echo json_encode(['success'=>false,'error'=>'No selected stores']); exit; }
      $out = [];
      foreach ($ids as $sid) {
        $store_id = (int)$sid;
        if ($store_id <= 0) { continue; }
        $email = '';
        $store_name = '';
        $sales_rep_id = 0;
        $key_code = '';
        $valid_until = '';
        if ($st = $conn->prepare('SELECT email, store_name, sales_rep_id, `key_access`, `key_validate` FROM stores WHERE id=? AND company_id=? LIMIT 1')) {
          $st->bind_param('ii', $store_id, $company_id);
          if ($st->execute()) {
            $rs = $st->get_result();
            if ($row = $rs->fetch_assoc()) {
              $email = trim((string)($row['email'] ?? ''));
              $store_name = (string)($row['store_name'] ?? '');
              $sales_rep_id = (int)($row['sales_rep_id'] ?? 0);
              $key_code = (string)($row['key_access'] ?? '');
              $valid_until = (string)($row['key_validate'] ?? '');
            }
            $rs->free();
          }
          $st->close();
        }
        if ($email === '') { $out[] = ['id'=>$store_id,'ok'=>false,'msg'=>'Missing email']; continue; }

        $msg_tpl = '';
        if ($sales_rep_id > 0 && ($st2 = $conn->prepare('SELECT message_text FROM sales_reps WHERE id=? AND company_id=? LIMIT 1'))) {
          $st2->bind_param('ii', $sales_rep_id, $company_id);
          if ($st2->execute()) {
            $rs2 = $st2->get_result();
            if ($rw2 = $rs2->fetch_assoc()) { $msg_tpl = (string)($rw2['message_text'] ?? ''); }
            $rs2->free();
          }
          $st2->close();
        }

        // Do not generate or update keys here; Send Email uses existing key/validity only

        $text_msg = $msg_tpl;
        $html_msg = nl2br(htmlspecialchars($msg_tpl, ENT_QUOTES, 'UTF-8'));
        if ($msg_tpl !== '') {
          $repl = [
            '{{key}}' => $key_code,
            '{{valid}}' => $valid_until,
            '{{store}}' => $store_name,
          ];
          $text_msg = strtr($text_msg, $repl);
          $html_msg = strtr($html_msg, [
            '{{key}}' => htmlspecialchars($key_code, ENT_QUOTES, 'UTF-8'),
            '{{valid}}' => htmlspecialchars($valid_until, ENT_QUOTES, 'UTF-8'),
            '{{store}}' => htmlspecialchars($store_name, ENT_QUOTES, 'UTF-8'),
          ]);
        }
        $append_text = '';
        $append_html = '';
        if ($key_code !== '') {
          $append_text = "\n\nAccess key: $key_code" . ($valid_until !== '' ? "\nValid: $valid_until" : '');
          $append_html = '<p><strong>Access key:</strong> ' . htmlspecialchars($key_code, ENT_QUOTES, 'UTF-8') . '</p>';
          if ($valid_until !== '') { $append_html = $append_html . '<p><strong>Valid:</strong> ' . htmlspecialchars($valid_until, ENT_QUOTES, 'UTF-8') . '</p>'; }
        }
        $payload = [
          'from' => ['email' => 'noreply@catalogseller.com', 'name' => 'CatalogSeller'],
          'to' => [ ['email' => $email, 'name' => $store_name] ],
          'subject' => 'Access key',
          'text' => ($text_msg !== '' ? $text_msg : ' ') . $append_text,
          'html' => ($html_msg !== '' ? $html_msg : '<p></p>') . $append_html,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
          CURLOPT_URL => 'https://api.mailersend.com/v1/email',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_POST => true,
          CURLOPT_HTTPHEADER => [ 'Content-Type: application/json', 'Authorization: Bearer ' . $mailer_api ],
          CURLOPT_POSTFIELDS => json_encode($payload),
          CURLOPT_TIMEOUT => 20,
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        $ok = $err === '';
        $out[] = ['id'=>$store_id,'ok'=>$ok,'msg'=>$ok ? 'sent' : $err, 'key'=>$key_code, 'valid'=>$valid_until];
      }
      echo json_encode(['success'=>true,'results'=>$out]);
      exit;
    } elseif ($action === 'get_whatsapp_messages') {
      header('Content-Type: application/json; charset=UTF-8');
      $ids_raw = isset($_POST['ids']) ? (string)$_POST['ids'] : '';
      $ids = json_decode($ids_raw, true);
      if (!is_array($ids) || empty($ids)) { echo json_encode(['success'=>false,'error'=>'No selected stores']); exit; }
      $out = [];
      foreach ($ids as $sid) {
        $store_id = (int)$sid; if ($store_id <= 0) { continue; }
        $store_name = '';
        $sales_rep_id = 0;
        $key_code = '';
        $valid_until = '';
        $phone_raw = '';
        if ($st = $conn->prepare('SELECT store_name, sales_rep_id, `key_access`, `key_validate`, phone FROM stores WHERE id=? AND company_id=? LIMIT 1')) {
          $st->bind_param('ii', $store_id, $company_id);
          if ($st->execute()) {
            $rs = $st->get_result();
            if ($row = $rs->fetch_assoc()) {
              $store_name = (string)($row['store_name'] ?? '');
              $sales_rep_id = (int)($row['sales_rep_id'] ?? 0);
              $key_code = (string)($row['key_access'] ?? '');
              $valid_until = (string)($row['key_validate'] ?? '');
              $phone_raw = (string)($row['phone'] ?? '');
            }
            $rs->free();
          }
          $st->close();
        }
        if ($phone_raw === '') { $out[] = ['id'=>$store_id,'ok'=>false,'msg'=>'Missing phone']; continue; }
        // Normalize phone to digits only for wa.me
        $phone_digits = preg_replace('/[^0-9]/','', $phone_raw);
        // If it's a 10-digit US number, prefix with country code 1
        if (strlen($phone_digits) === 10) {
          $phone_digits = '1' . $phone_digits;
        }
        // Compose message using sales rep template
        $msg_tpl = '';
        if ($sales_rep_id > 0 && ($st2 = $conn->prepare('SELECT message_text FROM sales_reps WHERE id=? AND company_id=? LIMIT 1'))) {
          $st2->bind_param('ii', $sales_rep_id, $company_id);
          if ($st2->execute()) {
            $rs2 = $st2->get_result();
            if ($rw2 = $rs2->fetch_assoc()) { $msg_tpl = (string)($rw2['message_text'] ?? ''); }
            $rs2->free();
          }
          $st2->close();
        }
        $text_msg = $msg_tpl;
        if ($msg_tpl !== '') {
          $text_msg = strtr($text_msg, [
            '{{key}}' => $key_code,
            '{{valid}}' => $valid_until,
            '{{store}}' => $store_name,
          ]);
        } else {
          $parts = [];
          if ($store_name !== '') { $parts[] = $store_name; }
          if ($key_code !== '') { $parts[] = 'Access key: ' . $key_code; }
          if ($valid_until !== '') { $parts[] = 'Valid: ' . $valid_until; }
          $text_msg = implode("\n", $parts);
        }
        $out[] = ['id'=>$store_id,'ok'=>true,'phone'=>$phone_digits,'text'=>$text_msg,'key'=>$key_code,'valid'=>$valid_until];
      }
      echo json_encode(['success'=>true,'results'=>$out]);
      exit;
    } elseif ($action === 'send_keys_whatsapp') {
      // Send ONE WhatsApp message server-side (placeholder provider)
      header('Content-Type: application/json; charset=UTF-8');
      $store_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
      if ($store_id <= 0) { echo json_encode(['success'=>false,'error'=>'Invalid store']); exit; }
      $store_name = '';
      $sales_rep_id = 0;
      $key_code = '';
      $valid_until = '';
      $phone_raw = '';
      if ($st = $conn->prepare('SELECT store_name, sales_rep_id, `key_access`, `key_validate`, phone FROM stores WHERE id=? AND company_id=? LIMIT 1')) {
        $st->bind_param('ii', $store_id, $company_id);
        if ($st->execute()) {
          $rs = $st->get_result();
          if ($row = $rs->fetch_assoc()) {
            $store_name = (string)($row['store_name'] ?? '');
            $sales_rep_id = (int)($row['sales_rep_id'] ?? 0);
            $key_code = (string)($row['key_access'] ?? '');
            $valid_until = (string)($row['key_validate'] ?? '');
            $phone_raw = (string)($row['phone'] ?? '');
          }
          $rs->free();
        }
        $st->close();
      }
      if ($phone_raw === '') { echo json_encode(['success'=>false,'error'=>'Missing phone']); exit; }
      $phone_digits = preg_replace('/[^0-9]/','', $phone_raw);
      if (strlen($phone_digits) === 10) { $phone_digits = '1' . $phone_digits; }
      $msg_tpl = '';
      if ($sales_rep_id > 0 && ($st2 = $conn->prepare('SELECT message_text FROM sales_reps WHERE id=? AND company_id=? LIMIT 1'))) {
        $st2->bind_param('ii', $sales_rep_id, $company_id);
        if ($st2->execute()) {
          $rs2 = $st2->get_result();
          if ($rw2 = $rs2->fetch_assoc()) { $msg_tpl = (string)($rw2['message_text'] ?? ''); }
          $rs2->free();
        }
        $st2->close();
      }
      $text_msg = $msg_tpl !== '' ? strtr($msg_tpl, [
        '{{key}}' => $key_code,
        '{{valid}}' => $valid_until,
        '{{store}}' => $store_name,
      ]) : trim($store_name . "\n" . ($key_code!==''?('Access key: ' . $key_code):'') . ($valid_until!==''?("\nValid: " . $valid_until):''));
      // TODO: integrate with WhatsApp provider using seller credentials
      $sent_ok = true; // placeholder success
      echo json_encode(['success'=>true,'sent'=>$sent_ok,'id'=>$store_id,'phone'=>$phone_digits,'text'=>$text_msg]);
      exit;
    } elseif ($action === 'enqueue_access_key_whatsapp') {
      header('Content-Type: application/json; charset=UTF-8');
      $sales_rep_id = isset($_POST['sales_rep_id']) ? (int)$_POST['sales_rep_id'] : 0;
      $ids_raw = isset($_POST['ids']) ? (string)$_POST['ids'] : '';
      $ids = $ids_raw !== '' ? json_decode($ids_raw, true) : [];
      $force_cancel = isset($_POST['force_cancel']) && $_POST['force_cancel'] === '1';
      if ($sales_rep_id <= 0) { echo json_encode(['success'=>false,'error'=>'Missing sales_rep_id']); exit; }

      // Check existing active batch
      $hasActive = false; $activeBatchId = 0;
      if ($stx = $conn->prepare("SELECT id FROM access_key_sending_batches WHERE sales_rep_id=? AND status IN ('pending','processing') ORDER BY id DESC LIMIT 1")) {
        $stx->bind_param('i', $sales_rep_id);
        if ($stx->execute()) { $rx = $stx->get_result(); if ($rw = $rx->fetch_assoc()) { $hasActive = true; $activeBatchId = (int)$rw['id']; } $rx->free(); }
        $stx->close();
      }
      if ($hasActive && !$force_cancel) {
        echo json_encode(['success'=>false,'active'=>true,'batch_id'=>$activeBatchId,'error'=>'Active batch exists']);
        exit;
      }
      if ($hasActive && $force_cancel) {
        if ($stc = $conn->prepare("UPDATE access_key_sending_batches SET status='canceled' WHERE sales_rep_id=? AND status IN ('pending','processing')")) {
          $stc->bind_param('i', $sales_rep_id); $stc->execute(); $stc->close();
        } else { echo json_encode(['success'=>false,'error'=>'Prepare cancel batches failed: '.$conn->error]); exit; }
        // Remove unprocessed items (keep history of sent)
        if ($std = $conn->prepare("DELETE i FROM access_key_sending_items i INNER JOIN access_key_sending_batches b ON b.id=i.batch_id WHERE b.sales_rep_id=? AND b.status='canceled' AND i.status IN ('pending','sending')")) {
          $std->bind_param('i', $sales_rep_id); $std->execute(); $std->close();
        }
      }
      // Also cancel pending items from those batches
      // This assumes FK or application-level consistency; optional depending on schema
      // Fetch current open batch ids (before cancel) if needed - skipping for simplicity

      // key_value por vendedor pode não existir no schema atual; manter vazio por ora
      $key_value = '';
      // Carregar template de mensagem do vendedor (igual ao Send Email)
      $msg_tpl = '';
      if ($st2 = $conn->prepare('SELECT message_text FROM sales_reps WHERE id=? AND company_id=? LIMIT 1')) {
        $st2->bind_param('ii', $sales_rep_id, $company_id);
        if ($st2->execute()) {
          $rs2 = $st2->get_result();
          if ($rw2 = $rs2->fetch_assoc()) { $msg_tpl = (string)($rw2['message_text'] ?? ''); }
          $rs2->free();
        }
        $st2->close();
      }

      // Create new batch
      $batch_id = 0;
      if ($stb = $conn->prepare("INSERT INTO access_key_sending_batches (sales_rep_id, key_value, batch_type, status, total_recipients) VALUES (?,?,?,?,0)")) {
        $type = 'access_key'; $statusB = 'pending';
        $stb->bind_param('isss', $sales_rep_id, $key_value, $type, $statusB);
        if ($stb->execute()) { $batch_id = $conn->insert_id; }
        $stb->close();
      } else { echo json_encode(['success'=>false,'error'=>'Prepare insert batch failed: '.$conn->error]); exit; }
      if ($batch_id <= 0) { echo json_encode(['success'=>false,'error'=>'Failed to create batch']); exit; }

      // Fetch target clients: if a list of ids was provided, use it; otherwise, all stores of the sales rep
      $total = 0;
      if (is_array($ids) && !empty($ids)) {
        // Build dynamic IN clause safely
        $ids = array_values(array_filter(array_map('intval', $ids), function($v){ return $v>0; }));
        if (empty($ids)) { echo json_encode(['success'=>false,'error'=>'No valid store ids']); exit; }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids)+2);
        $sql = 'SELECT id, store_name, phone, `key_access`, `key_validate` FROM stores WHERE company_id=? AND sales_rep_id=? AND id IN (' . $placeholders . ')';
        $st = $conn->prepare($sql);
        if (!$st) { echo json_encode(['success'=>false,'error'=>'Prepare select stores by ids failed: '.$conn->error]); exit; }
        $bind = [];
        $bind[] = & $types;
        $cid = $company_id; $srid = $sales_rep_id;
        $bind[] = & $cid; $bind[] = & $srid;
        foreach ($ids as $k=>$_id) { $bind[] = & $ids[$k]; }
        call_user_func_array([$st,'bind_param'], $bind);
      } else {
        $st = $conn->prepare('SELECT id, store_name, phone, `key_access`, `key_validate` FROM stores WHERE company_id=? AND sales_rep_id=?');
        if (!$st) { echo json_encode(['success'=>false,'error'=>'Prepare select stores failed: '.$conn->error]); exit; }
        $st->bind_param('ii', $company_id, $sales_rep_id);
      }
        if ($st->execute()) {
          $rs = $st->get_result();
          // Prepare insert for items
          if ($ins = $conn->prepare("INSERT INTO access_key_sending_items (batch_id, store_id, client_name, phone, message_text, status) VALUES (?,?,?,?,?, 'pending')")) {
            while ($row = $rs->fetch_assoc()) {
              $store_id = (int)($row['id'] ?? 0);
              $client_name = (string)($row['store_name'] ?? '');
              $phone_raw = (string)($row['phone'] ?? '');
              $phone_digits = preg_replace('/[^0-9]/','', $phone_raw);
              if ($phone_digits !== '' && strlen($phone_digits) === 10) { $phone_digits = '1' . $phone_digits; }
              // Compose message text using the sales rep template (same as Send Email)
              $store_key = (string)($row['key_access'] ?? '');
              $store_valid = (string)($row['key_validate'] ?? '');
              if ($msg_tpl !== '') {
                $msg = strtr($msg_tpl, [
                  '{{key}}' => $store_key,
                  '{{valid}}' => $store_valid,
                  '{{store}}' => $client_name,
                ]);
              } else {
                $msgParts = [];
                if ($client_name !== '') { $msgParts[] = $client_name; }
                if ($store_key !== '') { $msgParts[] = 'Access key: ' . $store_key; }
                if ($store_valid !== '') { $msgParts[] = 'Valid: ' . $store_valid; }
                $msg = implode("\n", $msgParts);
              }
              $ins->bind_param('iisss', $batch_id, $store_id, $client_name, $phone_digits, $msg);
              $ins->execute();
              $total++;
            }
            $ins->close();
          } else { echo json_encode(['success'=>false,'error'=>'Prepare insert items failed: '.$conn->error]); exit; }
          $rs->free();
        }
        $st->close();

      // Update total_recipients
      if ($stu = $conn->prepare('UPDATE access_key_sending_batches SET total_recipients=? WHERE id=?')) {
        $stu->bind_param('ii', $total, $batch_id);
        $stu->execute();
        $stu->close();
      }

      echo json_encode(['success'=>true,'batch_id'=>$batch_id,'total'=>$total]);
      exit;
    } elseif ($action === 'bulk_toggle_schedule') {
      // AJAX: alternar vínculos de VÁRIOS clientes/dias em lote
      header('Content-Type: application/json; charset=UTF-8');
      $raw = $_POST['ops'] ?? '';
      if (!is_string($raw) || $raw === '') { 
        echo json_encode(['ok'=>true,'msg'=>'Nothing to save']); 
        exit; 
      }
      $ops = json_decode($raw, true);
      if (!is_array($ops) || empty($ops)) { 
        echo json_encode(['ok'=>true,'msg'=>'Nothing to save']); 
        exit; 
      }

      $conn->begin_transaction();
      $inTransaction = true;
      $cntSIns = 0; $cntSUpd = 0; $cntSDel = 0; $cntSAt = 0;
      $cntPIns = 0; $cntPUpd = 0; $cntPAt = 0;

      try {
        // Statement para buscar código do cliente
        $stGetCod = $conn->prepare("SELECT cod FROM stores WHERE id=? AND company_id=? LIMIT 1");
        
        // Preparar statements para semanal (S)
        $stUpdTouch = $conn->prepare("UPDATE customer_weekday_schedule SET cod=?, updated_at=NOW() WHERE sales_rep_id=? AND customer_id=? AND weekday_id=? AND send_type='S'");
        $stChk = $conn->prepare("SELECT MAX(is_active) AS mA FROM customer_weekday_schedule WHERE sales_rep_id=? AND customer_id=? AND send_type='S'");
        $stIns = $conn->prepare("INSERT INTO customer_weekday_schedule (sales_rep_id, customer_id, cod, weekday_id, is_active, send_type, created_at, updated_at) VALUES (?,?,?,?,?,'S',NOW(),NOW())");
        $stDel = $conn->prepare("DELETE FROM customer_weekday_schedule WHERE sales_rep_id=? AND customer_id=? AND weekday_id=? AND send_type='S'");
        
        // Statements para período (P) - weekday_id IS NULL
        $stPUpd = $conn->prepare("UPDATE customer_weekday_schedule SET cod=?, start_date=?, end_date=?, updated_at=NOW() WHERE sales_rep_id=? AND customer_id=? AND weekday_id IS NULL AND send_type='P'");
        $stPIns = $conn->prepare("INSERT INTO customer_weekday_schedule (sales_rep_id, customer_id, cod, weekday_id, is_active, send_type, start_date, end_date, created_at, updated_at) VALUES (?,?,?,NULL,1,'P',?,?,NOW(),NOW())");
        $stPUpdAt = $conn->prepare("UPDATE customer_weekday_schedule SET is_active=?, updated_at=NOW() WHERE sales_rep_id=? AND customer_id=? AND weekday_id IS NULL AND send_type='P'");
        $stPDel = $conn->prepare("DELETE FROM customer_weekday_schedule WHERE sales_rep_id=? AND customer_id=? AND weekday_id IS NULL AND send_type='P'");

        foreach ($ops as $op) {
          $customer_id = isset($op['customer_id']) ? (int)$op['customer_id'] : 0;
          $sales_rep_id = isset($op['sales_rep_id']) ? (int)$op['sales_rep_id'] : 0;
          if ($customer_id <= 0 || $sales_rep_id <= 0) { continue; }
          
          // Buscar código do cliente
          $customer_cod = null;
          $stGetCod->bind_param('ii', $customer_id, $company_id);
          $stGetCod->execute();
          $resCod = $stGetCod->get_result();
          if ($rowCod = $resCod->fetch_assoc()) {
            $cod_val = $rowCod['cod'] ?? null;
            // Converter para string se não for null/vazio, senão manter null
            if ($cod_val !== null && $cod_val !== '') {
              $customer_cod = (string)$cod_val;
            } else {
              $customer_cod = null;
            }
          }
          $resCod->free();
          
          $on  = isset($op['on'])  && is_array($op['on'])  ? $op['on']  : [];
          $off = isset($op['off']) && is_array($op['off']) ? $op['off'] : [];
          $ativo = array_key_exists('ativo',$op) ? (int)$op['ativo'] : null;
          $pinfo = (isset($op['p']) && is_array($op['p'])) ? $op['p'] : null;

          // Ligar dias semanais
          foreach ($on as $dia) {
            $dia = (int)$dia;
            if ($dia < 1 || $dia > 7) { continue; }
            // Atualizar código mesmo se o registro já existir
            // Usar variáveis por referência para garantir que NULL seja tratado corretamente
            $cod_ref = $customer_cod;
            $stUpdTouch->bind_param('siii', $cod_ref, $sales_rep_id, $customer_id, $dia);
            $stUpdTouch->execute();
            if ($stUpdTouch->affected_rows === 0) {
              // Registro não existe, criar novo
              $temAtivo = 0;
              try {
                $stChk->bind_param('ii', $sales_rep_id, $customer_id);
                $stChk->execute();
                $resChk = $stChk->get_result();
                $row = $resChk->fetch_assoc() ?: [];
                $resChk->free();
                $temAtivo = (int)($row['mA'] ?? 0) ? 1 : 0;
              } catch (Exception $e) { /* mantém defaults */ }
              $cod_ins = $customer_cod;
              $stIns->bind_param('iisii', $sales_rep_id, $customer_id, $cod_ins, $dia, $temAtivo);
              $stIns->execute();
              $cntSIns++;
            } else { 
              // Registro existe e foi atualizado
              $cntSUpd++; 
            }
          }

          // Desligar dias
          foreach ($off as $dia) {
            $dia = (int)$dia;
            if ($dia < 1 || $dia > 7) { continue; }
            $stDel->bind_param('iii', $sales_rep_id, $customer_id, $dia);
            $stDel->execute();
            if ($stDel->affected_rows > 0) { $cntSDel++; }
          }

          // Verificar quantidade de dias semanais
          $stCount = $conn->prepare("SELECT COUNT(*) FROM customer_weekday_schedule WHERE sales_rep_id=? AND customer_id=? AND send_type='S'");
          $stCount->bind_param('ii', $sales_rep_id, $customer_id);
          $stCount->execute();
          $resCount = $stCount->get_result();
          $qtdDias = (int)$resCount->fetch_row()[0];
          $resCount->free();

          // Atualizar flags para semanal
          if ($ativo !== null) {
            $updAt = $conn->prepare("UPDATE customer_weekday_schedule SET cod=?, is_active=?, updated_at=NOW() WHERE sales_rep_id=? AND customer_id=? AND send_type='S'");
            $updAt->bind_param('siii', $customer_cod, $ativo, $sales_rep_id, $customer_id);
            $updAt->execute();
            $cntSAt += $updAt->affected_rows;
          }

          // Salvar período (P)
          if ($qtdDias > 0) {
            $stPDel->bind_param('ii', $sales_rep_id, $customer_id);
            $stPDel->execute();
          } elseif ($pinfo !== null) {
            $d1 = isset($pinfo['start_date']) ? trim((string)$pinfo['start_date']) : '';
            $d2 = isset($pinfo['end_date']) ? trim((string)$pinfo['end_date']) : '';
            $d1n = $d1 !== '' ? $d1 : null;
            $d2n = $d2 !== '' ? $d2 : null;
            
            $stPUpd->bind_param('sssii', $customer_cod, $d1n, $d2n, $sales_rep_id, $customer_id);
            $stPUpd->execute();
            if ($stPUpd->affected_rows === 0) {
              $stPIns->bind_param('iisss', $sales_rep_id, $customer_id, $customer_cod, $d1n, $d2n);
              $stPIns->execute();
              $cntPIns++;
            } else { $cntPUpd += $stPUpd->affected_rows; }
            
            if (array_key_exists('ativo',$pinfo) && $pinfo['ativo'] !== null) {
              $stPUpdAt->bind_param('iii', $pinfo['ativo'], $sales_rep_id, $customer_id);
              $stPUpdAt->execute();
              $cntPAt += $stPUpdAt->affected_rows;
            }
          }
        }

        $conn->commit();
        $inTransaction = false;
        echo json_encode(['ok'=>true,'msg'=>'Saved','diag'=>[
          'S_ins'=>$cntSIns,'S_upd'=>$cntSUpd,'S_del'=>$cntSDel,'S_ativo'=>$cntSAt,
          'P_ins'=>$cntPIns,'P_upd'=>$cntPUpd,'P_ativo'=>$cntPAt
        ]]);
      } catch (Exception $e) {
        if ($inTransaction) { $conn->rollback(); }
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
      }
      exit;
    }
  }
}

// WhatsApp queue count for badge
if (isset($_GET['action']) && $_GET['action'] === 'whatsapp_queue_count') {
  header('Content-Type: application/json; charset=UTF-8');
  $srid = isset($_GET['sales_rep_id']) ? (int)$_GET['sales_rep_id'] : 0;
  if ($srid <= 0) { echo json_encode(['success'=>false,'error'=>'Missing sales_rep_id']); exit; }
  $pending = 0; $sending = 0;
  $sql = "SELECT i.status, COUNT(*) AS c
          FROM access_key_sending_items i
          INNER JOIN access_key_sending_batches b ON b.id = i.batch_id
          WHERE b.sales_rep_id = ? AND i.status IN ('pending','sending')
          GROUP BY i.status";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param('i', $srid);
    if ($st->execute()) {
      $rs = $st->get_result();
      while ($row = $rs->fetch_assoc()) {
        $stt = (string)($row['status'] ?? '');
        $cnt = (int)($row['c'] ?? 0);
        if ($stt === 'pending') { $pending = $cnt; }
        if ($stt === 'sending') { $sending = $cnt; }
      }
      $rs->free();
    }
    $st->close();
  }
  echo json_encode(['success'=>true,'pending'=>$pending,'sending'=>$sending]);
  exit;
}

// AJAX: lista vínculos de dias da semana
if (isset($_GET['ajax']) && $_GET['ajax'] === 'list_schedule') {
  header('Content-Type: application/json; charset=UTF-8');
  try {
    $sales_rep_id = isset($_GET['sales_rep_id']) ? (int)$_GET['sales_rep_id'] : 0;
    if ($sales_rep_id <= 0) {
      echo json_encode(['ok'=>false,'msg'=>'Sales rep ID required']);
      exit;
    }
    
    // Retorna todos os dias semanais (send_type = 'S') para cada cliente
    $st = $conn->prepare("SELECT customer_id, weekday_id, is_active FROM customer_weekday_schedule WHERE sales_rep_id = ? AND send_type='S'");
    $st->bind_param('i', $sales_rep_id);
    $st->execute();
    $res = $st->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    $res->free();
    $st->close();
    
    $map = [];
    foreach ($rows as $r) {
      $customer_id = (int)($r['customer_id'] ?? 0);
      if ($customer_id === 0) continue;
      $day  = (int)($r['weekday_id'] ?? 0);
      if (!isset($map[$customer_id])) { $map[$customer_id] = []; }
      $map[$customer_id][$day] = [
        'is_active' => (int)($r['is_active'] ?? 0),
      ];
    }

    // Mapa indicando se o cliente possui registro personalizado (send_type = 'P', weekday_id IS NULL)
    $stP = $conn->prepare("SELECT customer_id, start_date, end_date, is_active FROM customer_weekday_schedule WHERE sales_rep_id = ? AND send_type='P' AND weekday_id IS NULL");
    $stP->bind_param('i', $sales_rep_id);
    $stP->execute();
    $resP = $stP->get_result();
    $rowsP = [];
    while ($rP = $resP->fetch_assoc()) { $rowsP[] = $rP; }
    $resP->free();
    $stP->close();
    
    $mapP = [];
    foreach ($rowsP as $rP) {
      $cP = (int)($rP['customer_id'] ?? 0);
      if ($cP === 0) continue;
      $mapP[$cP] = [
        'start_date' => $rP['start_date'] ?? null,
        'end_date' => $rP['end_date'] ?? null,
        'is_active' => isset($rP['is_active']) ? (int)$rP['is_active'] : 0,
      ];
    }

    echo json_encode(['ok'=>true,'vinculos'=>$map,'personalizado'=>$mapP]);
  } catch (Exception $e) {
    echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
  }
  exit;
}

// Filter by store name
$q_store_name = isset($_GET['q_store_name']) ? trim((string)$_GET['q_store_name']) : '';
// Filter by city
$q_city = isset($_GET['q_city']) ? trim((string)$_GET['q_city']) : '';
// Filter by weekday
$q_weekday = isset($_GET['q_weekday']) ? (int)$_GET['q_weekday'] : 0;
// Allow direct filtering by weekday_id (1..7) via GET parameter 'weekday_id'
$weekday_id_param = isset($_GET['weekday_id']) ? (int)$_GET['weekday_id'] : 0;
if ($weekday_id_param > 0 && $weekday_id_param <= 7) { $q_weekday = $weekday_id_param; }

// Buscar logo da company
$logo_dir = __DIR__ . '/img_logo';
$logo_url = 'logo.png';
$pattern = $logo_dir . '/logo_' . $company_id . '.*';
$existing_files = glob($pattern);
if (!empty($existing_files) && is_file($existing_files[0])) {
    $logo_file = basename($existing_files[0]);
    $logo_url = 'img_logo/' . $logo_file;
}

// Load sales reps for filter
$sales_reps = [];
if ($stmt = $conn->prepare('SELECT id, full_name FROM sales_reps WHERE company_id=? AND is_active=1 ORDER BY full_name')) {
  $stmt->bind_param('i', $company_id);
  if ($stmt->execute()) { 
    $res = $stmt->get_result(); 
    while($r = $res->fetch_assoc()){ $sales_reps[] = $r; } 
    $res->free(); 
  }
  $stmt->close();
}

// Helper function to translate weekday name to English
function translateWeekday($dayName) {
  $translations = [
    'Domingo' => 'Sunday',
    'Segunda' => 'Monday',
    'Segunda-feira' => 'Monday',
    'Terça' => 'Tuesday',
    'Terça-feira' => 'Tuesday',
    'Quarta' => 'Wednesday',
    'Quarta-feira' => 'Wednesday',
    'Quinta' => 'Thursday',
    'Quinta-feira' => 'Thursday',
    'Sexta' => 'Friday',
    'Sexta-feira' => 'Friday',
    'Sábado' => 'Saturday',
  ];
  $dayName = trim($dayName);
  return isset($translations[$dayName]) ? $translations[$dayName] : $dayName;
}

// Load week days
$week_days = [];
if ($stmt = $conn->prepare('SELECT id, name FROM week_days ORDER BY id')) {
  if ($stmt->execute()) {
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) { $week_days[$r['id']] = $r['name']; }
    $res->free();
  }
  $stmt->close();
}

// Create mapping: filter value (1-7) => database weekday_id
// Sunday=1, Monday=2, Tuesday=3, Wednesday=4, Thursday=5, Friday=6, Saturday=7
$weekday_filter_to_db = [];
$order = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
foreach ($order as $index => $englishName) {
  $filterValue = $index + 1; // Sunday=1, Monday=2, etc.
  $found = false;
  foreach ($week_days as $db_id => $db_name) {
    $translated = translateWeekday($db_name);
    if ($translated === $englishName) {
      $weekday_filter_to_db[$filterValue] = $db_id;
      $found = true;
      break;
    }
  }
  // If no mapping found, assume direct correspondence (Sunday=1, Monday=2, etc.)
  if (!$found) {
    $weekday_filter_to_db[$filterValue] = $filterValue;
  }
}

// List stores
$rows = [];
$where = ' WHERE s.company_id=?';
$types = '';
$bindVals = [];
$joinWeekday = '';

if ($q_weekday > 0 && $q_weekday <= 7) {
  // Use filter value directly as weekday_id (Sunday=1 .. Saturday=7)
  $db_weekday_id = (int)$q_weekday;
  // Filter by weekday_id only - search in customer_weekday_schedule.weekday_id field
  $joinWeekday = ' INNER JOIN customer_weekday_schedule cws ON cws.customer_id = s.id AND cws.weekday_id = ? AND cws.send_type = \'S\'';
  $types .= 'i';
  $bindVals[] = $db_weekday_id;
}

// Bind company_id after join params to match placeholder order in SQL
$types .= 'i';
$bindVals[] = $company_id;

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

$sqlList = 'SELECT DISTINCT s.id, s.cod, s.store_name, s.contact_name, s.phone, s.phone2, s.phone3, s.email, 
                   s.city, s.state, s.is_active, s.sales_rep_id,
                   s.address_line1, s.zip_code, s.website, s.`key_access`, s.`key_validate`,
                   sr.full_name AS sales_rep_name
            FROM stores s
            LEFT JOIN sales_reps sr ON sr.id = s.sales_rep_id AND sr.company_id = s.company_id
            ' . $joinWeekday . '
            ' . $where . ' 
            ORDER BY s.store_name ASC';

if ($stmt = $conn->prepare($sqlList)) {
  if ($stmt === false) {
    error_log("Prepare failed: " . $conn->error);
  } else {
    $params = [];
    $params[] = &$types;
    foreach ($bindVals as $k => $v) { $params[] = &$bindVals[$k]; }
    call_user_func_array([$stmt, 'bind_param'], $params);
    if ($stmt->execute()) {
      $res = $stmt->get_result();
      $rowCount = 0;
      while ($r = $res->fetch_assoc()) { 
        $rows[] = $r; 
        $rowCount++;
      }
      if ($q_weekday > 0 && $q_weekday <= 7) {
        error_log("Rows found: " . $rowCount);
      }
      $res->free();
    } else {
      error_log("Execute failed: " . $stmt->error);
    }
    $stmt->close();
  }
}

// Helper function to get weekday ID based on English name
// Maps: Sunday=1, Monday=2, Tuesday=3, Wednesday=4, Thursday=5, Friday=6, Saturday=7
function getWeekdayIdByEnglishName($week_days, $englishName) {
  $dayMapping = [
    'Sunday' => 1,
    'Monday' => 2,
    'Tuesday' => 3,
    'Wednesday' => 4,
    'Thursday' => 5,
    'Friday' => 6,
    'Saturday' => 7,
  ];
  
  if (!isset($dayMapping[$englishName])) {
    return null;
  }
  
  $targetId = $dayMapping[$englishName];
  
  // Find the database ID that corresponds to this English day name
  foreach ($week_days as $db_id => $db_name) {
    $translated = translateWeekday($db_name);
    if ($translated === $englishName) {
      return $db_id;
    }
  }
  
  return null;
}

// Helper function to get ordered weekdays for filter (Sunday=1, Monday=2, etc.)
function getOrderedWeekdaysForFilter($week_days) {
  $order = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  $ordered = [];
  
  foreach ($order as $englishName) {
    foreach ($week_days as $db_id => $db_name) {
      $translated = translateWeekday($db_name);
      if ($translated === $englishName) {
        $ordered[] = [
          'id' => $db_id,
          'name' => $db_name,
          'name_en' => $englishName,
          'filter_id' => array_search($englishName, $order) + 1 // Sunday=1, Monday=2, etc.
        ];
        break;
      }
    }
  }
  
  return $ordered;
}

// Helper function to parse phone number
function parsePhone($phone) {
  $phoneClean = preg_replace('/[^0-9]/', '', $phone);
  $ddd = '';
  $num = '';
  // Phone format: 1DDDNUMERO or DDDNUMERO (DDD sempre 3 dígitos)
  if (preg_match('/^1?(\d{3})(\d{4,10})$/', $phoneClean, $matches)) {
    $ddd = $matches[1];
    $num = $matches[2];
  } elseif (preg_match('/^(\d{3})(\d{4,10})$/', $phoneClean, $matches)) {
    $ddd = $matches[1];
    $num = $matches[2];
  }
  return ['ddd' => $ddd, 'num' => $num];
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CRM Stores - Catalog Seller</title>
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
    .btn-primary{background:#22c55e; border-color:#16a34a; color:#fff}
    .btn-primary:hover{background:#16a34a}

    .muted{color:var(--muted)}
    .grid{display:grid; grid-template-columns: repeat(12, 1fr); gap:4px}
    .filters .btn{height:44px}
    .col-12{grid-column: span 12}
    .col-6{grid-column: span 6}
    .col-4{grid-column: span 4}
    .col-3{grid-column: span 3}
    .col-2{grid-column: span 2}
    .col-1{grid-column: span 1}

    .list { max-height: 70vh; overflow:auto; border:1px solid rgba(148,163,184,.16); border-radius:8px; padding:8px; }
    .row { display:flex; align-items:center; gap:12px; padding:12px 16px; margin:8px 0; border:1px solid rgba(148,163,184,.10); border-radius:12px; background: rgba(255,255,255,.03); }
    .row:hover { background: rgba(255,255,255,.06); }
    .row-info { flex:1; min-width:0; }
    .row-name { font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; font-size:14px; margin-bottom:4px; }
    .row-meta { color:var(--muted); font-size:12px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .row-actions { display:flex; align-items:center; gap:8px; flex-shrink:0; }
    
    .badge{display:inline-block; padding:4px 10px; border-radius:6px; font-size:11px; font-weight:500; line-height:1.4; cursor:pointer; user-select:none; transition:all 0.2s}
    .badge-active{background:#22c55e; color:#052e16}
    .badge-inactive{background:#64748b; color:#fff}
    .badge:hover{opacity:0.8}
    
    .linkAccent{color:#facc15; text-decoration:none; font-size:14px}
    .linkAccent:hover{text-decoration:underline}
    .key-value{color:#fef08a}
    [data-role="keyline"]{font-size:12px}
    .key-valid{font-size:11px}
    
    .icon-btn{background:none; border:none; color:var(--text); cursor:pointer; font-size:16px; padding:4px 8px; border-radius:4px; text-decoration:none; display:inline-block}
    .icon-btn:hover{background:rgba(148,163,184,.1)}
    .icon-btn-danger:hover{background:rgba(239,68,68,.2); color:#fca5a5}
    
    .phones { margin-top:6px; display:flex; flex-direction:row; gap:6px; flex-wrap:wrap; color:var(--text); }
    .phone-item { font-size:12px; flex:0 0 auto; }
    .phone-item .tel-view { cursor:pointer; padding:4px 8px; border-radius:6px; background:rgba(148,163,184,.1); border:1px solid rgba(148,163,184,.2); display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }
    .phone-item .tel-view .ph-icn { font-size:12px; opacity:.7; }
    .phone-item.primary .tel-view { background:rgba(34,197,94,.15); border-color:rgba(34,197,94,.3); color:#86efac; }
    .phone-item.empty .tel-view { background:rgba(255,255,255,.02); border-style:dashed; color:var(--muted); }
    .phone-item .tel-editors { display:none; gap:4px; align-items:center; flex-wrap:wrap; }
    .phone-item input { width:60px; padding:4px 6px; border-radius:4px; border:1px solid rgba(148,163,184,.2); background:#0b1220; color:var(--text); font-size:12px; }
    .phone-item input.num { width:100px; }
    .phone-item .btn { padding:4px 8px; font-size:11px; height:28px; }
    
    .toggle{position:relative; width:44px; height:24px; display:inline-block}
    .toggle input{opacity:0; width:0; height:0}
    .slider{position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background:#0f172a; border:1px solid rgba(148,163,184,.25); border-radius:999px; transition:.15s}
    .slider:before{content:""; position:absolute; height:20px; width:20px; left:1px; top:1px; background:#475569; border-radius:999px; transition:.15s}
    .toggle input:checked + .slider{background:linear-gradient(180deg, #22c55e, #16a34a); border-color:rgba(34,197,94,.6)}
    .toggle input:checked + .slider:before{transform:translateX(20px); background:#052e16}
    
    .store-modal-overlay{position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(2,6,23,.85); backdrop-filter:blur(4px); z-index:10002; overflow-y:auto; padding:20px}
    .store-modal-overlay.show{display:flex}
    .store-modal-content{position:relative; width:90%; max-width:800px; max-height:90vh; padding:24px; background:rgba(15,23,42,.98); border-radius:14px; border:1px solid rgba(148,163,184,.2); box-shadow:0 10px 40px rgba(0,0,0,.5); overflow-y:auto}
    .store-modal-close{position:absolute; top:15px; right:20px; font-size:32px; font-weight:bold; color:var(--text); cursor:pointer; line-height:1; opacity:.7; transition:opacity 0.2s; z-index:1}
    .store-modal-close:hover{opacity:1}
    
    .badge.day, .badge.dayP, .badge.b-ativo, .badge.b-save { cursor:pointer; user-select:none; }
    .badge.off { opacity:0.35; }
    .badge.b-save.pending { border-color:#16a34a; color:#16a34a; background:#ecfdf5; }
    .badges-att { display:flex; align-items:center; gap:6px; }
    .att-summary { color:var(--muted); font-size:11px; }
    .day-sep { width:1px; align-self:stretch; background:rgba(148,163,184,.2); margin:0 4px; }
    .p-editor { display:none; }
    .row.expanded { border-bottom:0; border-radius:12px 12px 0 0; }
    .row.expanded + .p-editor { display:block; border-top:0; border-radius:0 0 12px 12px; }
    /* WhatsApp progress overlay */
    .wa-overlay{position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(2,6,23,.7); z-index:10010}
    .wa-box{min-width:340px; max-width:560px; padding:18px; border-radius:12px; background:rgba(15,23,42,.98); border:1px solid rgba(148,163,184,.25); box-shadow:0 10px 30px rgba(0,0,0,.5)}
    .wa-title{margin:0 0 8px 0; font-weight:700; font-size:16px}
    .wa-note{color:var(--muted); font-size:12px; margin-bottom:10px}
    .wa-bar{height:10px; background:#0b1220; border:1px solid rgba(148,163,184,.25); border-radius:999px; overflow:hidden}
    .wa-bar > span{display:block; height:100%; width:0; background:linear-gradient(90deg,#22c55e,#16a34a)}
    .wa-count{margin-top:8px; font-size:12px; color:var(--muted)}
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
  </style>
  <script>
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
      // CSRF constants for AJAX posts
      var CSRF_NAME = <?php echo json_encode($csrf_name); ?>;
      var CSRF_TOKEN = <?php echo json_encode($csrf_token); ?>;

      // Delete All button with double confirmation
      var btn = document.getElementById('deleteAllBtn');
      var form = document.getElementById('deleteAllForm');
      if (btn && form) {
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
      }

      // Generate all keys for current filtered rows
      var btnGenAll = document.getElementById('btnGenAll');
      if (btnGenAll) {
        btnGenAll.addEventListener('click', function(){
          if (!confirm('Generate access keys for all stores in the current list?')) { return; }
          var rows = Array.prototype.slice.call(document.querySelectorAll('.list .row'));
          if (!rows.length) { alert('No rows to process.'); return; }
          btnGenAll.disabled = true; btnGenAll.textContent = 'Generating...';
          var idx = 0;
          var next = function(){
            if (idx >= rows.length) { btnGenAll.disabled = false; btnGenAll.textContent = 'Generate all'; return; }
            var row = rows[idx++];
            var storeId = row.getAttribute('data-store-id');
            var fd = new FormData();
            fd.append(CSRF_NAME, CSRF_TOKEN);
            fd.append('action', 'gen_store_key');
            fd.append('id', storeId);
            fetch('crm_store.php', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd })
              .then(function(r){ return r.json(); })
              .then(function(json){
                if (json && json.success) {
                  var nameDiv = row.querySelector('.row-name');
                  if (nameDiv) {
                    var keySpan = nameDiv.querySelector('[data-role="keyline"]');
                    if (!keySpan) {
                      keySpan = document.createElement('span');
                      keySpan.className = 'muted';
                      keySpan.setAttribute('data-role','keyline');
                      keySpan.style.marginLeft = '12px';
                      nameDiv.appendChild(keySpan);
                    }
                    keySpan.style.display = '';
                    keySpan.innerHTML = '<span class="muted" style="margin-right:8px">Access key:</span>' +
                      '<span class="key-value">' + String(json.key || '') + '</span>' +
                      '&nbsp;&nbsp;<span class="muted">Valid: </span>' + String(json.valid || '');
                  }
                }
              })
              .catch(function(){ /* ignore individual errors */ })
              .finally(function(){ next(); });
          };
          next();
        });
      }
      // Helpers: WhatsApp progress overlay
      function waShowProgress(total){
        var ov = document.getElementById('waProgress'); if (!ov) return;
        ov.style.display = 'flex';
        ov.querySelector('[data-role="count"]').textContent = '0 / ' + total;
        ov.querySelector('[data-role="bar"]').style.width = '0%';
      }
      function waUpdateProgress(done,total){
        var ov = document.getElementById('waProgress'); if (!ov) return;
        ov.querySelector('[data-role="count"]').textContent = done + ' / ' + total;
        var pct = total>0 ? Math.round((done/total)*100) : 0;
        ov.querySelector('[data-role="bar"]').style.width = pct + '%';
      }
      function waHideProgress(){ var ov = document.getElementById('waProgress'); if (ov) ov.style.display='none'; }

      // Helper: derive sales rep id from current list
      function getCurrentSalesRepId(){
        var rowEls = Array.prototype.slice.call(document.querySelectorAll('.list .row'));
        var srId = 0;
        for (var i=0;i<rowEls.length;i++){
          var rep = parseInt(rowEls[i].getAttribute('data-sales-rep-id')||'0',10);
          if (rep>0){ if (srId===0) srId=rep; else if (srId!==rep) { return 0; } }
        }
        return srId;
      }
      // Queue badge updater
      var waBadge = document.getElementById('waQueueInfo');
      function refreshQueueBadge(){
        if (!waBadge) return;
        var srId = getCurrentSalesRepId();
        if (!srId) { waBadge.textContent = 'Queue: 0'; return; }
        fetch('crm_store.php?action=whatsapp_queue_count&sales_rep_id='+srId, { credentials:'same-origin' })
          .then(function(r){ return r.json(); })
          .then(function(j){ if (!j || j.success!==true) return; var n = (j.pending||0)+(j.sending||0); waBadge.textContent = 'Queue: ' + n; waBadge.classList.toggle('badge-active', n>0); waBadge.classList.toggle('badge-inactive', n===0); })
          .catch(function(){});
      }
      setInterval(refreshQueueBadge, 5000);
      setTimeout(refreshQueueBadge, 500);

      // Enqueue WhatsApp batch (populate DB tables only) using currently visible rows
      var btnSendWhats = document.getElementById('btnSendWhats');
      if (btnSendWhats) {
        btnSendWhats.addEventListener('click', async function(){
          var rowEls = Array.prototype.slice.call(document.querySelectorAll('.list .row'));
          if (!rowEls.length) { alert('No stores in the current list.'); return; }
          // Derive sales_rep_id from rows and validate single rep
          var srId = 0; var ids = [];
          for (var i=0;i<rowEls.length;i++){
            var r = rowEls[i];
            var rid = parseInt(r.getAttribute('data-store-id')||'0',10);
            var rep = parseInt(r.getAttribute('data-sales-rep-id')||'0',10);
            if (rid>0) ids.push(rid);
            if (rep>0){ if (srId===0) srId = rep; else if (srId!==rep){ alert('The current list has stores from multiple sales reps. Please filter to a single representative.'); return; } }
          }
          if (srId===0) { alert('Could not determine Sales Representative from the current list.'); return; }
          var confirmed = await customConfirm('Create a WhatsApp batch for this list (' + ids.length + ' recipients)?', 'catalogseller.com says');
          if (!confirmed) { return; }

          function enqueue(forceCancel){
            var fd = new FormData();
            fd.append(CSRF_NAME, CSRF_TOKEN);
            fd.append('action','enqueue_access_key_whatsapp');
            fd.append('sales_rep_id', String(srId));
            fd.append('ids', JSON.stringify(ids));
            if (forceCancel) fd.append('force_cancel','1');
            btnSendWhats.disabled = true; var oldTxt = btnSendWhats.textContent; btnSendWhats.textContent = 'Enqueuing...';
            fetch('crm_store.php', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd })
              .then(function(r){
                if (!r.ok) { return r.text().then(function(t){ throw new Error('HTTP '+r.status+': '+t); }); }
                return r.text();
              })
              .then(function(txt){
                var j = null; try { j = JSON.parse(txt); } catch(e){ alert('Server reply is not JSON: '+ txt.substring(0,200)); return; }
                if (!j) { alert('Failed to enqueue'); return; }
                if (j.active === true) {
                  customConfirm('There is an active batch (ID: '+ j.batch_id +'). Cancel it and create a new one?', 'catalogseller.com says').then(function(cancelConfirmed){
                    if (cancelConfirmed) {
                      enqueue(true);
                    }
                  });
                  return;
                }
                if (j.success !== true) { alert((j && j.error) || 'Failed to enqueue'); return; }
                alert('Batch created. Batch ID: ' + j.batch_id + '\nRecipients queued: ' + j.total);
                refreshQueueBadge();
              })
              .catch(function(err){ console.error(err); alert('Network error: '+ (err && err.message ? err.message : '')); })
              .finally(function(){ btnSendWhats.disabled = false; btnSendWhats.textContent = oldTxt; });
          }
          enqueue(false);
        });
      }
      var btnSendSel = document.getElementById('btnSendSelected');
      if (btnSendSel) {
        btnSendSel.addEventListener('click', async function(){
          var rows = Array.prototype.slice.call(document.querySelectorAll('.list .row'));
          if (!rows.length) { alert('No stores in the current list.'); return; }
          var confirmed = await customConfirm('Send email to ALL stores in the current list?', 'catalogseller.com says');
          if (!confirmed) { return; }
          btnSendSel.disabled = true; btnSendSel.textContent = 'Sending...';
          var ids = rows.map(function(r){ return r.getAttribute('data-store-id'); }).filter(Boolean);
          var fd = new FormData();
          fd.append(CSRF_NAME, CSRF_TOKEN);
          fd.append('action','send_keys_email');
          fd.append('ids', JSON.stringify(ids));
          fetch('crm_store.php', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd })
            .then(function(r){ return r.json(); })
            .then(function(json){
              if (!json || !json.success) { alert('Failed to send'); return; }
              (json.results || []).forEach(function(it){
                // Only update key display if a key exists (Send Email does not generate keys)
                if (!it || !it.key) return;
                var row = document.querySelector('.row[data-store-id="'+ it.id +'"]');
                if (!row) return;
                var nameDiv = row.querySelector('.row-name');
                if (!nameDiv) return;
                var keySpan = nameDiv.querySelector('[data-role="keyline"]');
                if (!keySpan) {
                  keySpan = document.createElement('span');
                  keySpan.className = 'muted';
                  keySpan.setAttribute('data-role','keyline');
                  keySpan.style.marginLeft = '12px';
                  nameDiv.appendChild(keySpan);
                }
                keySpan.style.display = '';
                keySpan.innerHTML = '<span class="muted" style="margin-right:8px">Access key:</span>' +
                  '<span class="key-value">' + String(it.key) + '</span>' +
                  (it.valid ? '&nbsp;&nbsp;<span class="muted">Valid: </span>' + String(it.valid) : '');
              });
              alert('Emails sent.');
            })
            .catch(function(){ alert('Network error'); })
            .finally(function(){ btnSendSel.disabled = false; btnSendSel.textContent = 'Send Email'; });
        });
      }
      // Phone editing
      document.querySelectorAll('.tel-view').forEach(function(telView) {
        telView.addEventListener('click', function() {
          var item = this.closest('.phone-item');
          if (!item) return;
          var editors = item.querySelector('.tel-editors');
          if (editors) {
            this.style.display = 'none';
            editors.style.display = 'flex';
            
            // Se for telefone 2 ou 3, preencher DDD com o DDD do telefone 1
            var idx = parseInt(item.getAttribute('data-idx') || '1', 10);
            var dddInput = item.querySelector('input.ddd');
            var numInput = item.querySelector('input.num');
            
            if (idx === 2 || idx === 3) {
              var row = item.closest('.row');
              if (row) {
                var phone1Item = row.querySelector('.phone-item[data-idx="1"]');
                if (phone1Item) {
                  var phone1DddInput = phone1Item.querySelector('input.ddd');
                  var phone1View = phone1Item.querySelector('.tel-view');
                  
                  if (dddInput) {
                    // Se o campo DDD atual estiver vazio, preencher com o DDD do telefone 1
                    if (!dddInput.value || dddInput.value.trim() === '') {
                      var dddToCopy = '';
                      
                      // Tentar pegar do input se estiver visível
                      if (phone1DddInput && phone1DddInput.value) {
                        dddToCopy = phone1DddInput.value;
                      } else if (phone1View) {
                        // Tentar extrair do texto exibido (formato: (DDD) XXXXX-XXXX)
                        var viewText = phone1View.textContent || phone1View.innerText || '';
                        var match = viewText.match(/\((\d{3})\)/);
                        if (match && match[1]) {
                          dddToCopy = match[1];
                        }
                      }
                      
                      if (dddToCopy && dddToCopy.length === 3) {
                        dddInput.value = dddToCopy;
                      }
                    }
                  }
                }
              }
            }
            
            // Focar sempre no campo DDD
            setTimeout(function() {
              if (dddInput) {
                dddInput.focus();
              }
            }, 50);
          }
        });
      });
      
      document.querySelectorAll('.cancel-tel').forEach(function(btn) {
        btn.addEventListener('click', function() {
          var item = this.closest('.phone-item');
          if (!item) return;
          var view = item.querySelector('.tel-view');
          var editors = item.querySelector('.tel-editors');
          if (view && editors) {
            editors.style.display = 'none';
            view.style.display = 'inline-flex';
          }
        });
      });
      
      document.querySelectorAll('.save-tel').forEach(function(btn) {
        btn.addEventListener('click', function() {
          var item = this.closest('.phone-item');
          if (!item) return;
          var row = item.closest('.row');
          var storeId = row ? row.getAttribute('data-store-id') : '';
          var idx = parseInt(item.getAttribute('data-idx') || '1', 10);
          var dddInput = item.querySelector('input.ddd');
          var numInput = item.querySelector('input.num');
          var ddd = dddInput ? dddInput.value.replace(/[^0-9]/g, '') : '';
          var num = numInput ? numInput.value.replace(/[^0-9]/g, '') : '';
          
          // Validar DDD: deve ter exatamente 3 dígitos
          if (ddd && ddd.length !== 3) {
            alert('DDD deve ter exatamente 3 dígitos');
            if (dddInput) dddInput.focus();
            return;
          }
          
          // Concatenate DDD + Phone and prepend "1"
          var fullPhone = '';
          if (ddd && ddd.length === 3 && num) {
            fullPhone = '1' + ddd + num;
          } else if (num && !ddd) {
            // Se não tiver DDD, salva apenas o número com "1" na frente
            fullPhone = '1' + num;
          } else if (ddd && ddd.length === 3 && !num) {
            // Se tiver apenas DDD, não salva
            alert('Por favor, informe o número do telefone');
            if (numInput) numInput.focus();
            return;
          }
          
          // Update phone in database via AJAX
          var formData = new FormData();
          formData.append('action', 'update_phone');
          formData.append('store_id', storeId);
          formData.append('phone_idx', idx);
          formData.append('phone', fullPhone);
          formData.append('_csrf', '<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>');
          
          fetch('crm_store.php', {
            method: 'POST',
            body: formData
          })
          .then(function(response) { return response.json(); })
          .then(function(data) {
            if (data.success) {
              var view = item.querySelector('.tel-view');
              var editors = item.querySelector('.tel-editors');
              var label = 'Adicionar telefone ' + idx;
              if (ddd && num) {
                // Format number: (DDD) XXXXX-XXXX or (DDD) XXXX-XXXX
                var numFormatted = num.length > 4 ? num.substring(0, num.length - 4) + '-' + num.substring(num.length - 4) : num;
                label = '(' + ddd + ') ' + numFormatted;
              }
              if (view) {
                view.innerHTML = '<span class="ph-icn">☎</span><strong>' + label + '</strong>';
              }
              if (editors && view) {
                editors.style.display = 'none';
                view.style.display = 'inline-flex';
              }
              // Update item classes
              var hasPhone = ddd && num;
              item.classList.toggle('empty', !hasPhone);
              item.classList.toggle('primary', (hasPhone && idx === 1));
            } else {
              alert('Erro ao salvar telefone: ' + (data.error || 'Erro desconhecido'));
            }
          })
          .catch(function(error) {
            console.error('Error:', error);
            alert('Erro ao salvar telefone');
          });
        });
      });
      
      // Status toggle
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
          
          fetch('crm_store.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
          })
          .then(function(response) { return response.json(); })
          .then(function(data) {
            if (data.success) {
              var isActive = data.is_active === 1;
              btn.setAttribute('data-current', isActive ? '1' : '0');
              btn.textContent = isActive ? 'Active Store' : 'Inactive Store';
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
      
      // Schedule management
      var vincMap = {}; // { customer_id: { day: {is_active} } }
      var pMap = {}; // { customer_id: {start_date, end_date, is_active} }
      var lista = document.getElementById('lista') || document.querySelector('.list');
      var btnSaveAll = document.getElementById('btnSaveAll');
      
      function carregarMarcados() {
        if (!lista) return;
        var salesRepIds = new Set();
        lista.querySelectorAll('.row').forEach(function(row) {
          var srId = parseInt(row.getAttribute('data-sales-rep-id')||'0', 10);
          if (srId > 0) salesRepIds.add(srId);
        });
        if (salesRepIds.size === 0) return;
        
        // Load for all sales reps found
        Promise.all(Array.from(salesRepIds).map(function(srId) {
          return fetch('crm_store.php?ajax=list_schedule&sales_rep_id=' + srId, { credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(j){
              if (!j || j.ok !== true) { return {}; }
              return {vinculos: j.vinculos||{}, personalizado: j.personalizado||{}};
            })
            .catch(function(e){ console.error('Error loading schedule:', e); return {}; });
        })).then(function(results) {
          // Merge all results
          results.forEach(function(result) {
            if (result.vinculos) {
              for (var cid in result.vinculos) {
                if (!vincMap[cid]) vincMap[cid] = {};
                Object.assign(vincMap[cid], result.vinculos[cid]);
              }
            }
            if (result.personalizado) {
              Object.assign(pMap, result.personalizado);
            }
          });
          
          // Update UI
          if (lista) {
            lista.querySelectorAll('.row').forEach(function(r){
              var customerId = parseInt(r.getAttribute('data-store-id')||'0', 10);
              if (customerId <= 0) return;
              setDayPills(r, vincMap[customerId] || {});
              var hasP = !!(pMap && pMap[customerId]);
              var hasS = false; 
              var byDay = vincMap[customerId]||{}; 
              for (var k in byDay){ if (byDay[k]) { hasS = true; break; } }
              if (hasS) { hasP = false; }
              setPersonalizadoBadge(r, hasP);
              var ed = getOrCreatePeriodoEditor(r);
              if (hasP){
                ed.style.display = 'block';
                r.classList.add('expanded');
                var info = pMap[customerId]||{};
                var d1 = ed.querySelector('[data-role="d1"]');
                var d2 = ed.querySelector('[data-role="d2"]');
                if (d1) d1.value = info.start_date||'';
                if (d2) d2.value = info.end_date||'';
                setBadges(r, {ativo: info.is_active?1:0});
                r.setAttribute('data-p-selected','1');
              } else {
                ed.style.display = 'none';
                r.classList.remove('expanded');
              }
            });
          }
        });
      }
      
      function setBadges(row, info){
        var bAt = row.querySelector('.b-ativo');
        var ativo = info && info.ativo ? 1 : 0;
        if (bAt) bAt.classList.toggle('off', !ativo);
      }
      
      function setDayPills(row, infoByDay){
        var pills = row.querySelectorAll('.day');
        pills.forEach(function(p){
          var d = parseInt(p.getAttribute('data-day'),10)||0;
          var exists = !!(infoByDay && infoByDay[d]);
          p.classList.toggle('off', !exists);
        });
        var anyActive = false, anyDay = false;
        if (infoByDay){
          for (var k in infoByDay){
            var it = infoByDay[k]; if (!it) continue;
            anyDay = true;
            if (it.is_active===1) anyActive = true;
          }
        }
        setBadges(row, {ativo:anyActive?1:0});
        var bSem = row.querySelector('.dayP[data-role="semanal"]');
        if (bSem) bSem.classList.toggle('off', !anyDay);
        var bPer = row.querySelector('.dayP[data-role="periodo"]');
        if (bPer) bPer.classList.toggle('off', anyDay ? true : bPer.classList.contains('off'));
      }
      
      function setPersonalizadoBadge(row, hasP){
        var bP = row.querySelector('.dayP[data-role="periodo"]');
        if (!bP) return;
        bP.classList.toggle('off', !hasP);
      }
      
      function getOrCreatePeriodoEditor(row){
        var ed = row.nextElementSibling;
        var customerId = row.getAttribute('data-store-id')||'';
        if (!ed || !ed.classList || !ed.classList.contains('p-editor')){
          ed = document.createElement('div');
          ed.className = 'p-editor';
          ed.style.cssText = 'display:none; padding:8px 10px; background:rgba(15,23,42,.5); border-top:1px dashed rgba(148,163,184,.2); margin-top:-8px; border-radius:0 0 12px 12px;';
          ed.innerHTML = '<div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">'+
            '  <label style="color:var(--muted); font-size:12px;">Data Início: <input type="date" class="input" data-role="d1" style="font-size:12px; height:32px;"></label>'+
            '  <label style="color:var(--muted); font-size:12px;">Data Fim: <input type="date" class="input" data-role="d2" style="font-size:12px; height:32px;"></label>'+
            '</div>';
          row.parentNode.insertBefore(ed, row.nextSibling);
          var d1i = ed.querySelector('[data-role="d1"]');
          var d2i = ed.querySelector('[data-role="d2"]');
          [d1i,d2i].forEach(function(inp){ if (!inp) return; inp.addEventListener('change', function(){ markRowPending(row, true); }); });
        }
        return ed;
      }
      
      function markRowPending(row, pending){
        var bSave = row.querySelector('.b-save');
        if (!bSave) return;
        if (pending){
          bSave.classList.remove('off');
          bSave.classList.add('pending');
        } else {
          bSave.classList.remove('pending');
          bSave.classList.add('off');
        }
      }
      
      function collectDayChanges(row, customerId){
        var original = vincMap[customerId] || {};
        var pills = row.querySelectorAll('.day');
        var toOn = [];
        var toOff = [];
        pills.forEach(function(p){
          var d = parseInt(p.getAttribute('data-day'),10)||0; if (!d) return;
          var domOn = !p.classList.contains('off');
          var wasOn = !!original[d];
          if (domOn && !wasOn) toOn.push(d);
          if (!domOn && wasOn) toOff.push(d);
        });
        return {on: toOn, off: toOff};
      }
      
      // Event listeners for badges
      if (lista) {
        lista.addEventListener('click', function(ev){
          var row = ev.target.closest('.row'); if (!row) return;
          var customerId = parseInt(row.getAttribute('data-store-id')||'0', 10);
          if (customerId <= 0) return;
          
          // Click on day badge
          if (ev.target.classList.contains('day')){
            var pill = ev.target; 
            var day = parseInt(pill.getAttribute('data-day'),10)||0; 
            if (!day) return;
            var pOn = row.querySelector('.dayP[data-role="periodo"]') && !row.querySelector('.dayP[data-role="periodo"]').classList.contains('off');
            if (pOn) return;
            pill.classList.toggle('off');
            var anyDayOn = false;
            row.querySelectorAll('.day').forEach(function(p){ if (!p.classList.contains('off')) anyDayOn = true; });
            var sBadgeTipo = row.querySelector('.dayP[data-role="semanal"]');
            if (sBadgeTipo) sBadgeTipo.classList.toggle('off', !anyDayOn);
            if (anyDayOn) {
              var bPbadge = row.querySelector('.dayP[data-role="periodo"]');
              if (bPbadge) bPbadge.classList.add('off');
              var edNow = row.nextElementSibling;
              if (edNow && edNow.classList && edNow.classList.contains('p-editor')) {
                edNow.style.display = 'none';
                var d1c = edNow.querySelector('[data-role="d1"]'); if (d1c) d1c.value = '';
                var d2c = edNow.querySelector('[data-role="d2"]'); if (d2c) d2c.value = '';
              }
              row.removeAttribute('data-p-selected');
            }
            markRowPending(row, true);
            return;
          }
          
          // Click on P badge
          if (ev.target.classList.contains('dayP')){
            var hasWeekly = false; 
            row.querySelectorAll('.day').forEach(function(p){ if (!p.classList.contains('off')) hasWeekly = true; });
            if (hasWeekly) { alert('Customer already has weekly days selected'); return; }
            var ed = getOrCreatePeriodoEditor(row);
            var visible = ed.style.display !== 'none' && ed.style.display !== '';
            var bP = row.querySelector('.dayP[data-role="periodo"]');
            if (!visible) {
              ed.style.display = 'block';
              row.classList.add('expanded');
              var info = pMap[customerId]||{};
              var d1 = ed.querySelector('[data-role="d1"]');
              var d2 = ed.querySelector('[data-role="d2"]');
              if (d1) d1.value = info.start_date||'';
              if (d2) d2.value = info.end_date||'';
              row.setAttribute('data-p-selected','1');
              if (bP) bP.classList.remove('off');
              row.querySelectorAll('.day').forEach(function(p){ p.classList.add('off'); });
              markRowPending(row, true);
            } else {
              ed.style.display = 'none';
              row.classList.remove('expanded');
              var d1off = ed.querySelector('[data-role="d1"]'); if (d1off) d1off.value = '';
              var d2off = ed.querySelector('[data-role="d2"]'); if (d2off) d2off.value = '';
              row.removeAttribute('data-p-selected');
              if (bP) bP.classList.add('off');
            }
            return;
          }
          
          // Click on Ativo badge
          if (ev.target.classList.contains('b-ativo')){
            ev.target.classList.toggle('off');
            markRowPending(row, true);
            return;
          }
        });
      }
      
      // Save all button
      if (btnSaveAll) {
        btnSaveAll.addEventListener('click', function(){
          var rows = lista ? lista.querySelectorAll('.row') : [];
          var ops = [];
          rows.forEach(function(row){
            var bSave = row.querySelector('.b-save');
            if (!bSave || !bSave.classList.contains('pending')) return;
            var customerId = parseInt(row.getAttribute('data-store-id')||'0', 10);
            var salesRepId = parseInt(row.getAttribute('data-sales-rep-id')||'0', 10);
            if (customerId <= 0 || salesRepId <= 0) return;
            var ch = collectDayChanges(row, customerId);
            var bAt = row.querySelector('.b-ativo');
            var ativoNow = bAt && !bAt.classList.contains('off') ? 1 : 0;
            var ed = row.nextElementSibling;
            var isP = row.hasAttribute('data-p-selected');
            var pData = null;
            if (isP && ed && ed.classList && ed.classList.contains('p-editor')){
              var d1 = ed.querySelector('[data-role="d1"]');
              var d2 = ed.querySelector('[data-role="d2"]');
              pData = { start_date: d1 && d1.value ? d1.value : '', end_date: d2 && d2.value ? d2.value : '', ativo: ativoNow };
            }
            var payload = { customer_id: customerId, sales_rep_id: salesRepId, on: ch.on, off: ch.off, ativo: ativoNow };
            if (isP) { payload.p = pData; payload.ativo = null; }
            ops.push(payload);
          });
          if (ops.length===0){
            alert('No pending changes');
            return;
          }
          btnSaveAll.disabled = true;
          btnSaveAll.textContent = 'Saving...';
          var fd = new FormData();
          fd.append('action','bulk_toggle_schedule');
          fd.append('ops', JSON.stringify(ops));
          fd.append('_csrf', '<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>');
          fetch('crm_store.php', { method:'POST', body:fd, credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(j){
              if (!j || j.ok !== true){
                alert((j&&j.msg)||'Error saving');
                return;
              }
              carregarMarcados();
              rows.forEach(function(row){ markRowPending(row,false); });
            })
            .catch(function(e){ alert('Network failure'); console.error(e); })
            .finally(function(){
              btnSaveAll.disabled = false;
              btnSaveAll.textContent = 'Save changes';
            });
        });
      }
      
      // Load schedule on page load
      carregarMarcados();

      // WhatsApp progress panel
      var waPanel = document.getElementById('waProgressPanel');
      var waProgText = document.getElementById('waProgText');
      var waProgBar = document.getElementById('waProgBar');
      var waPanelClose = document.getElementById('waPanelClose');
      function getCurrentSalesRepId(){
        var lista = document.getElementById('lista');
        var rows = lista ? lista.querySelectorAll('.row') : [];
        if (rows.length > 0) {
          var rid = parseInt(rows[0].getAttribute('data-sales-rep-id')||'0',10);
          if (!isNaN(rid) && rid>0) return rid;
        }
        var sel = document.getElementById('sales_rep');
        if (sel) { var v = parseInt(sel.value||'0',10); if (!isNaN(v)&&v>0) return v; }
        return 0;
      }
      function updateWaPanel(data){
        if (!data || data.success!==true) return;
        if (!data.active){
          if (waPanel && waPanel.style.display!== 'none') {
            // completed or no active: allow user to close
            waProgText.textContent = 'No active batch';
            waProgBar.style.width = '0%';
            waPanelClose.style.display = 'inline-block';
          }
          return;
        }
        var b = data.batch || {};
        var total = b.total||0; var sent = b.sent||0; var pend = b.pending||0; var sending = b.sending||0; var err = b.error||0;
        var done = sent + err;
        var pct = total>0 ? Math.max(0, Math.min(100, Math.round((done/total)*100))) : 0;
        waProgText.textContent = 'Sent: '+sent+' / '+total+' | In queue: '+(pend+sending)+' | Errors: '+err;
        waProgBar.style.width = pct+'%';
        waPanel.style.display = 'block';
        // Only allow close when finished
        if (done>=total && total>0){
          waProgText.textContent = 'Completed: '+sent+' sent, '+err+' errors.';
          waPanelClose.style.display = 'inline-block';
        } else {
          waPanelClose.style.display = 'none';
        }
      }
      function pollWaBatch(){
        var srid = getCurrentSalesRepId();
        if (!srid) return;
        fetch('crm_store.php?action=whatsapp_batch_status&sales_rep_id='+srid, {credentials:'same-origin'})
          .then(function(r){ return r.json(); })
          .then(function(j){ updateWaPanel(j); })
          .catch(function(e){ /* silent */ });
      }
      if (waPanelClose){ waPanelClose.addEventListener('click', function(){ if (waPanel) waPanel.style.display='none'; }); }
      // Start polling
      setInterval(pollWaBatch, 5000);
      // Initial
      pollWaBatch();
    });
    
    function openStoreModal(storeId) {
      var modal = document.getElementById('storeModal');
      if (!modal) return;
      
      if (storeId) {
        fetch('stores.php?get_store=' + storeId)
          .then(function(response) { return response.json(); })
          .then(function(data) {
            if (data && data.id) {
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
              
              document.getElementById('storeModalTitle').textContent = 'Edit Store';
              document.getElementById('storeAction').value = 'update';
              document.getElementById('storeId').value = data.id;
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

    <div id="storeModal" class="store-modal-overlay" onclick="closeStoreModal()">
      <div class="store-modal-content" onclick="event.stopPropagation()">
        <span class="store-modal-close" onclick="closeStoreModal()">&times;</span>
        <h2 style="margin:0 0 20px 0; color:var(--text)" id="storeModalTitle">Edit Store</h2>
        <form method="post" action="stores.php" id="storeForm" class="grid">
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
        <h1 style="margin:0; font-size:24px; font-weight:700">CRM Stores</h1>
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
        <div style="padding:12px; background:rgba(239,68,68,.15); border:1px solid rgba(239,68,68,.3); border-radius:8px; color:#fca5a5; margin-bottom:16px"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div>
      <?php endif; ?>

      <form method="get" class="grid filters" style="margin-bottom:20px">
        <div class="col-4">
          <label class="muted" for="q_store_name">Store Name</label>
          <input type="text" id="q_store_name" name="q_store_name" value="<?php echo htmlspecialchars($q_store_name, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search by store name...">
        </div>
        <div class="col-3">
          <label class="muted" for="q_city">City</label>
          <input type="text" id="q_city" name="q_city" value="<?php echo htmlspecialchars($q_city, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search by city...">
        </div>
        <div class="col-2">
          <label class="muted" for="q_weekday">Day of Week</label>
          <select id="q_weekday" name="q_weekday" onchange="this.form.submit()">
            <option value="0">All</option>
            <?php 
            // Display days in order: Sunday=1, Monday=2, Tuesday=3, etc.
            $order = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            foreach ($order as $index => $englishName):
              $filterValue = $index + 1; // Sunday=1, Monday=2, etc.
              // Find the corresponding database day
              $found = false;
              foreach ($week_days as $db_id => $db_name) {
                $translated = translateWeekday($db_name);
                if ($translated === $englishName) {
                  $found = true;
                  break;
                }
              }
              if ($found):
            ?>
            <option value="<?php echo $filterValue; ?>" <?php echo ($q_weekday===$filterValue?'selected':''); ?>><?php echo htmlspecialchars($englishName, ENT_QUOTES, 'UTF-8'); ?></option>
            <?php 
              endif;
            endforeach; 
            ?>
          </select>
        </div>
        <div class="col-3" style="display:flex; align-items:flex-end; gap:6px">
          <button class="btn" type="submit" style="flex:1">Filter</button>
          <a class="btn btn-outline" href="crm_store.php" style="flex:1; text-align:center">Clear</a>
        </div>
      </form>
      
      <div style="margin-bottom:12px; display:flex; align-items:center; gap:8px">
        <button id="btnSendSelected" class="btn btn-primary" type="button">Send Email</button>
        <button id="btnSendWhats" class="btn btn-primary" type="button" style="margin-right:auto">Send Whatsapp</button>
        <span id="waQueueInfo" class="badge" title="Messages in queue for this sales rep">Queue: 0</span>
        <div id="waProgressPanel" style="display:none; max-height:90px; overflow-y:auto; padding:8px 10px; background:#0b2535; color:#e5e7eb; border:1px solid #1f3b4a; border-radius:6px; font-size:12px; min-width:220px">
          <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:6px">
            <strong style="font-size:12px">WhatsApp Sending</strong>
            <button id="waPanelClose" class="btn" type="button" style="padding:2px 6px; font-size:11px; display:none">Close</button>
          </div>
          <div id="waProgText" style="margin-bottom:6px">Pending...</div>
          <div style="height:6px; background:#102938; border-radius:4px; overflow:hidden">
            <div id="waProgBar" style="height:6px; width:0%; background:#22c55e"></div>
          </div>
        </div>
        <button id="btnGenAll" class="btn" type="button">Generate all</button>
        <button id="btnSaveAll" class="btn btn-primary" type="button">Save changes</button>
      </div>

      <!-- WhatsApp progress overlay -->
      <div id="waProgress" class="wa-overlay" role="dialog" aria-live="polite" aria-label="WhatsApp sending progress">
        <div class="wa-box">
          <h3 class="wa-title">Sending WhatsApp messages</h3>
          <div class="wa-note">Each message is sent every 30 seconds. Keep this tab open.</div>
          <div class="wa-bar"><span data-role="bar"></span></div>
          <div class="wa-count" data-role="count">0 / 0</div>
        </div>
      </div>

      <div class="list">
        <?php foreach ($rows as $r): 
          $phones = [
            1 => parsePhone($r['phone'] ?? ''),
            2 => parsePhone($r['phone2'] ?? ''),
            3 => parsePhone($r['phone3'] ?? '')
          ];
        ?>
          <div class="row" data-store-id="<?php echo (int)$r['id']; ?>" data-sales-rep-id="<?php echo (int)($r['sales_rep_id'] ?? 0); ?>">
            <div class="row-info">
              <div class="row-name">
                <?php echo htmlspecialchars($r['store_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                <?php if ($r['cod']): ?>
                  <span class="muted" style="margin-left:8px">— <?php echo htmlspecialchars($r['cod'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
                <?php if (!empty($r['key_access']) || !empty($r['key_validate'])): ?>
                   <span class="muted" data-role="keyline" style="margin-left:12px">
                    <span class="muted" style="margin-right:8px">Access key:</span><span class="key-value"><?php echo htmlspecialchars((string)($r['key_access'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                    &nbsp;&nbsp;<span class="key-valid"><span class="muted">Valid: </span><?php echo htmlspecialchars((string)($r['key_validate'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                  </span>
                <?php else: ?>
                  <span class="muted" data-role="keyline" style="margin-left:12px; display:none"></span>
                <?php endif; ?>
              </div>
              <div class="row-meta">
                <?php 
                  $address = trim(($r['address_line1'] ?? '') . ' ' . ($r['city'] ?? '') . ' ' . ($r['state'] ?? ''));
                  $email   = trim((string)($r['email'] ?? ''));
                  if ($address): 
                ?>
                  <?php echo htmlspecialchars($address, ENT_QUOTES, 'UTF-8'); ?>
                  <?php if (!empty($email)): ?>
                    <span class="muted"> — </span>
                    <a href="mailto:<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" class="muted" style="text-decoration:none;">
                      <?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
              <div class="phones">
                <?php for ($i = 1; $i <= 3; $i++): 
                  $phoneData = $phones[$i];
                  $hasPhone = !empty($phoneData['ddd']) || !empty($phoneData['num']);
                  $isPrimary = $i === 1 && $hasPhone;
                ?>
                <div class="phone-item <?php echo $isPrimary ? 'primary' : ($hasPhone ? '' : 'empty'); ?>" data-idx="<?php echo $i; ?>">
                  <span class="tel-view" title="Clique para editar">
                    <span class="ph-icn">☎</span>
                    <strong><?php 
                      if ($hasPhone && $phoneData['ddd'] && $phoneData['num']) {
                        // Format number: (DDD) XXXXX-XXXX or (DDD) XXXX-XXXX
                        $numFormatted = strlen($phoneData['num']) > 4 ? substr($phoneData['num'], 0, -4) . '-' . substr($phoneData['num'], -4) : $phoneData['num'];
                        echo '(' . htmlspecialchars($phoneData['ddd'], ENT_QUOTES, 'UTF-8') . ') ' . htmlspecialchars($numFormatted, ENT_QUOTES, 'UTF-8');
                      } else {
                        echo 'Adicionar telefone ' . $i;
                      }
                    ?></strong>
                  </span>
                  <span class="tel-editors" role="group" aria-label="Editar telefone">
                    <input type="text" class="input ddd" maxlength="3" minlength="3" placeholder="DDD (3 dígitos)" pattern="[0-9]{3}" value="<?php echo htmlspecialchars($phoneData['ddd'], ENT_QUOTES, 'UTF-8'); ?>" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 3)">
                    <input type="text" class="input num" maxlength="10" placeholder="Número" value="<?php echo htmlspecialchars($phoneData['num'], ENT_QUOTES, 'UTF-8'); ?>" oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10)">
                    <button type="button" class="btn sm save-tel">Salvar</button>
                    <button type="button" class="btn sm cancel-tel">Cancelar</button>
                  </span>
                </div>
                <?php endfor; ?>
              </div>
            </div>
            <div class="row-actions">
              <div class="badges-att" style="display:flex; align-items:center; gap:6px; margin-right:8px;">
                <span class="att-summary" style="color:var(--muted); font-size:11px;">&nbsp;</span>
                <div class="days" aria-label="Days of week" style="display:flex; gap:4px; margin-right:8px;">
                  <span class="badge dayP off" data-role="semanal" title="Weekly">S</span>
                  <span class="badge dayP off" data-role="periodo" title="Period">P</span>
                  <span class="day-sep" style="width:1px; align-self:stretch; background:rgba(148,163,184,.2); margin:0 4px;"></span>
                  <span class="badge day off" data-day="1" title="Sunday">S</span>
                  <span class="badge day off" data-day="2" title="Monday">M</span>
                  <span class="badge day off" data-day="3" title="Tuesday">T</span>
                  <span class="badge day off" data-day="4" title="Wednesday">W</span>
                  <span class="badge day off" data-day="5" title="Thursday">T</span>
                  <span class="badge day off" data-day="6" title="Friday">F</span>
                  <span class="badge day off" data-day="7" title="Saturday">S</span>
                </div>
                <span class="badge b-ativo off" title="Activate/deactivate">Active</span>
                <span class="badge b-save off" title="Save changes for this row" style="font-weight:700; padding:2px 8px;">✔</span>
              </div>
              <button type="button" 
                      class="badge-toggle badge <?php echo $r['is_active'] ? 'badge-active' : 'badge-inactive'; ?>" 
                      data-id="<?php echo (int)$r['id']; ?>"
                      data-current="<?php echo $r['is_active'] ? '1' : '0'; ?>"
                      data-csrf="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>"
                      style="border:none; cursor:pointer; padding:4px 10px; border-radius:6px; font-size:11px; font-weight:500; line-height:1.4; background:<?php echo $r['is_active'] ? '#22c55e' : '#64748b'; ?>; color:<?php echo $r['is_active'] ? '#052e16' : '#fff'; ?>">
                <?php echo $r['is_active'] ? 'Active Store' : 'Inactive Store'; ?>
              </button>
              <a href="javascript:void(0)" 
                 onclick="openStoreModal(<?php echo (int)$r['id']; ?>)" 
                 title="Edit"
                 class="icon-btn">✏️</a>
              <form method="post" action="stores.php" onsubmit="return confirm('Remove this store?');" style="display:inline">
                <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <button type="submit" 
                        title="Delete"
                        class="icon-btn icon-btn-danger"
                        style="margin-right:0">🗑️</button>
              </form>
            </div>
            <div class="p-editor" style="display:none; padding:8px 10px; background:rgba(15,23,42,.5); border-top:1px dashed rgba(148,163,184,.2); margin-top:-8px; border-radius:0 0 12px 12px;">
              <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <label style="color:var(--muted); font-size:12px;">Data Início: <input type="date" class="input" data-role="d1" style="font-size:12px; height:32px;"></label>
                <label style="color:var(--muted); font-size:12px;">Data Fim: <input type="date" class="input" data-role="d2" style="font-size:12px; height:32px;"></label>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
          <div class="row"><em class="muted" style="text-align:center; padding:40px">Nenhum cliente encontrado.</em></div>
        <?php endif; ?>
      </div>
    </section>
  </div>
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
</body>
</html>

