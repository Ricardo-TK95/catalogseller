<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$arquivoConfig = __DIR__ . '/config.php';
if (!file_exists($arquivoConfig)) { die('config.php não encontrado'); }
require_once $arquivoConfig;

$csrf_name = '_csrf';
if (empty($_SESSION[$csrf_name])) { $_SESSION[$csrf_name] = bin2hex(random_bytes(16)); }
$csrf_token = $_SESSION[$csrf_name];

$erro = '';
$ok = '';
$flash_ok = '';
if (!empty($_SESSION['flash_ok'])) { $flash_ok = (string)$_SESSION['flash_ok']; unset($_SESSION['flash_ok']); }

function normalize_order($v){ $v = trim((string)$v); return ($v === '' ? null : max(0, (int)$v)); }

// Função para extrair hora de formato HH:MM ou HH
function extractHour($time) {
    if (empty($time)) return null;
    $parts = explode(':', $time);
    $hour = (int)($parts[0] ?? 0);
    if ($hour < 0 || $hour > 23) return null;
    return $hour;
}

// Handle POST actions: create/update/delete/toggle
$company_id = 1; // fixed for now

// Buscar logo da company
$logo_dir = __DIR__ . '/img_logo';
$logo_url = 'logo.png'; // fallback padrão
$pattern = $logo_dir . '/logo_' . $company_id . '.*';
$existing_files = glob($pattern);
if (!empty($existing_files) && is_file($existing_files[0])) {
    $logo_file = basename($existing_files[0]);
    $logo_url = 'img_logo/' . $logo_file;
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

// Buscar sales_rep_id do usuário logado através do email
$logged_sales_rep_id = 0;
$user_email = isset($_SESSION['user_email']) ? (string)$_SESSION['user_email'] : '';
if ($user_email !== '') {
    if ($stmt = $conn->prepare('SELECT id FROM sales_reps WHERE email=? AND company_id=? AND is_active=1 LIMIT 1')) {
        $stmt->bind_param('si', $user_email, $company_id);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            $row = $res->fetch_assoc();
            if ($row) {
                $logged_sales_rep_id = (int)$row['id'];
            }
            $res->free();
        }
        $stmt->close();
    }
}

// Load sales reps - apenas o que está logado
$sales_reps = [];
if ($logged_sales_rep_id > 0) {
    if ($stmt = $conn->prepare('SELECT id, full_name FROM sales_reps WHERE id=? AND company_id=? AND is_active=1')) {
        $stmt->bind_param('ii', $logged_sales_rep_id, $company_id);
        if ($stmt->execute()) { 
            $res = $stmt->get_result(); 
            while($r = $res->fetch_assoc()){ $sales_reps[] = $r; } 
            $res->free(); 
        }
        $stmt->close();
    }
}

$action = isset($_POST['action']) ? $_POST['action'] : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST[$csrf_name]) || !hash_equals($_SESSION[$csrf_name], (string)$_POST[$csrf_name])) {
        $erro = 'Sessão expirada. Recarregue a página.';
    } else {
        if ($action === 'create' || $action === 'update') {
            $sales_rep_id = isset($_POST['sales_rep_id']) ? (int)$_POST['sales_rep_id'] : 0;
            $week_day_ids = isset($_POST['week_day_ids']) && is_array($_POST['week_day_ids']) ? $_POST['week_day_ids'] : [];
            $week_day_ids = array_filter(array_map('intval', $week_day_ids), function($v) { return $v > 0 && $v <= 7; });
            $message_text = isset($_POST['message_text']) ? trim((string)$_POST['message_text']) : '';
            $is_active = isset($_POST['status']) && $_POST['status'] === '1' ? (int)1 : (int)0;
            $send_order = normalize_order(isset($_POST['send_order']) ? $_POST['send_order'] : '');
            
            // Obter hora (0-23)
            $send_time = null;
            if (isset($_POST['send_hour']) && $_POST['send_hour'] !== '') {
                $hour = (int)$_POST['send_hour'];
                if ($hour >= 0 && $hour <= 23) {
                    $send_time = str_pad((string)$hour, 2, '0', STR_PAD_LEFT) . ':00:00';
                }
            }
            
            if ($message_text === '') { $erro = 'Mensagem é obrigatória.'; }
            if ($sales_rep_id <= 0) { $erro = 'Sales Representative é obrigatório.'; }
            if (empty($week_day_ids)) { $erro = 'Selecione pelo menos um dia da semana.'; }
            
            if ($erro === '') {
                try {
                    $conn->begin_transaction();
                    if ($action === 'create') {
                        $sql = 'INSERT INTO sales_rep_messages (sales_rep_id, week_day_id, message_text, is_active, send_time, send_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())';
                        if ($stmt = $conn->prepare($sql)) {
                            $created = 0;
                            foreach ($week_day_ids as $week_day_id) {
                                $stmt->bind_param('iisisi', $sales_rep_id, $week_day_id, $message_text, $is_active, $send_time, $send_order);
                                if ($stmt->execute()) {
                                    $created++;
                                }
                            }
                            $stmt->close();
                            if ($created > 0) {
                                $conn->commit();
                                header('Location: messages.php');
                                exit;
                            } else {
                                $conn->rollback();
                                $erro = 'Falha ao criar mensagens.';
                            }
                        } else {
                            $conn->rollback();
                            $erro = 'Falha ao preparar criação: ' . $conn->error;
                        }
                    } else {
                        // Para update, vamos deletar os registros antigos e criar novos
                        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
                        // Buscar dados antigos do registro
                        $sql_find = 'SELECT sales_rep_id, message_text, send_time, send_order FROM sales_rep_messages WHERE id=?';
                        if ($stmt_find = $conn->prepare($sql_find)) {
                            $stmt_find->bind_param('i', $id);
                            $stmt_find->execute();
                            $res_find = $stmt_find->get_result();
                            $old_row = $res_find->fetch_assoc();
                            $stmt_find->close();
                            
                            if ($old_row) {
                                // Buscar todos os registros relacionados usando dados antigos
                                $old_sales_rep_id = $old_row['sales_rep_id'];
                                $old_message_text = $old_row['message_text'];
                                $old_send_time = $old_row['send_time'];
                                $old_send_order = $old_row['send_order'];
                                
                                // Construir query baseada em valores NULL
                                if ($old_send_time === null && $old_send_order === null) {
                                    $sql_group = 'SELECT id FROM sales_rep_messages WHERE sales_rep_id=? AND message_text=? AND send_time IS NULL AND send_order IS NULL';
                                    $stmt_group = $conn->prepare($sql_group);
                                    $stmt_group->bind_param('is', $old_sales_rep_id, $old_message_text);
                                } elseif ($old_send_time === null) {
                                    $sql_group = 'SELECT id FROM sales_rep_messages WHERE sales_rep_id=? AND message_text=? AND send_time IS NULL AND send_order=?';
                                    $stmt_group = $conn->prepare($sql_group);
                                    $stmt_group->bind_param('isi', $old_sales_rep_id, $old_message_text, $old_send_order);
                                } elseif ($old_send_order === null) {
                                    $sql_group = 'SELECT id FROM sales_rep_messages WHERE sales_rep_id=? AND message_text=? AND send_time=? AND send_order IS NULL';
                                    $stmt_group = $conn->prepare($sql_group);
                                    $stmt_group->bind_param('iss', $old_sales_rep_id, $old_message_text, $old_send_time);
                                } else {
                                    $sql_group = 'SELECT id FROM sales_rep_messages WHERE sales_rep_id=? AND message_text=? AND send_time=? AND send_order=?';
                                    $stmt_group = $conn->prepare($sql_group);
                                    $stmt_group->bind_param('issi', $old_sales_rep_id, $old_message_text, $old_send_time, $old_send_order);
                                }
                                
                                if ($stmt_group) {
                                    $stmt_group->execute();
                                    $res_group = $stmt_group->get_result();
                                    $related_ids = [];
                                    while ($row = $res_group->fetch_assoc()) {
                                        $related_ids[] = (int)$row['id'];
                                    }
                                    $res_group->free();
                                    $stmt_group->close();
                                    
                                    // Deletar registros relacionados
                                    if (!empty($related_ids)) {
                                        $placeholders = str_repeat('?,', count($related_ids) - 1) . '?';
                                        $sql_del = "DELETE FROM sales_rep_messages WHERE id IN ($placeholders)";
                                        $stmt_del = $conn->prepare($sql_del);
                                        $types = str_repeat('i', count($related_ids));
                                        $stmt_del->bind_param($types, ...$related_ids);
                                        $stmt_del->execute();
                                        $stmt_del->close();
                                    }
                                }
                                
                                // Criar novos registros
                                $sql = 'INSERT INTO sales_rep_messages (sales_rep_id, week_day_id, message_text, is_active, send_time, send_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())';
                                if ($stmt = $conn->prepare($sql)) {
                                    $created = 0;
                                    foreach ($week_day_ids as $week_day_id) {
                                        $stmt->bind_param('iisisi', $sales_rep_id, $week_day_id, $message_text, $is_active, $send_time, $send_order);
                                        if ($stmt->execute()) {
                                            $created++;
                                        }
                                    }
                                    $stmt->close();
                                    if ($created > 0) {
                                        $conn->commit();
                                        header('Location: messages.php');
                                        exit;
                                    } else {
                                        $conn->rollback();
                                        $erro = 'Falha ao atualizar mensagens.';
                                    }
                                } else {
                                    $conn->rollback();
                                    $erro = 'Falha ao preparar atualização: ' . $conn->error;
                                }
                            } else {
                                $conn->rollback();
                                $erro = 'Registro não encontrado.';
                            }
                        } else {
                            $conn->rollback();
                            $erro = 'Falha ao buscar registro.';
                        }
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    $erro = 'Erro: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'toggle') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $to_raw = isset($_POST['to']) ? trim((string)$_POST['to']) : '0';
            $to = ($to_raw === '1' || $to_raw === 1) ? 1 : 0;
            $to = (int)$to; // Garantir que é inteiro
            if ($id > 0) {
                try {
                    // Buscar dados do registro para encontrar todos os relacionados
                    $sql_find = 'SELECT sales_rep_id, message_text, send_time, send_order FROM sales_rep_messages WHERE id=?';
                    if ($stmt_find = $conn->prepare($sql_find)) {
                        $stmt_find->bind_param('i', $id);
                        $stmt_find->execute();
                        $res_find = $stmt_find->get_result();
                        $row_data = $res_find->fetch_assoc();
                        $stmt_find->close();
                        
                        if ($row_data) {
                            $sales_rep_id = $row_data['sales_rep_id'];
                            $message_text = $row_data['message_text'];
                            $send_time = $row_data['send_time'];
                            $send_order = $row_data['send_order'];
                            
                            // Buscar todos os registros relacionados (mesma mensagem, mesmo sales_rep, mesmo tempo e ordem)
                            if ($send_time === null && $send_order === null) {
                                $sql_group = 'SELECT id FROM sales_rep_messages WHERE sales_rep_id=? AND message_text=? AND send_time IS NULL AND send_order IS NULL';
                                $stmt_group = $conn->prepare($sql_group);
                                $stmt_group->bind_param('is', $sales_rep_id, $message_text);
                            } elseif ($send_time === null) {
                                $sql_group = 'SELECT id FROM sales_rep_messages WHERE sales_rep_id=? AND message_text=? AND send_time IS NULL AND send_order=?';
                                $stmt_group = $conn->prepare($sql_group);
                                $stmt_group->bind_param('isi', $sales_rep_id, $message_text, $send_order);
                            } elseif ($send_order === null) {
                                $sql_group = 'SELECT id FROM sales_rep_messages WHERE sales_rep_id=? AND message_text=? AND send_time=? AND send_order IS NULL';
                                $stmt_group = $conn->prepare($sql_group);
                                $stmt_group->bind_param('iss', $sales_rep_id, $message_text, $send_time);
                            } else {
                                $sql_group = 'SELECT id FROM sales_rep_messages WHERE sales_rep_id=? AND message_text=? AND send_time=? AND send_order=?';
                                $stmt_group = $conn->prepare($sql_group);
                                $stmt_group->bind_param('issi', $sales_rep_id, $message_text, $send_time, $send_order);
                            }
                            
                            if ($stmt_group) {
                                $stmt_group->execute();
                                $res_group = $stmt_group->get_result();
                                $related_ids = [];
                                while ($row = $res_group->fetch_assoc()) {
                                    $related_ids[] = (int)$row['id'];
                                }
                                $res_group->free();
                                $stmt_group->close();
                                
                                // Atualizar todos os registros relacionados
                                if (!empty($related_ids)) {
                                    $placeholders = str_repeat('?,', count($related_ids) - 1) . '?';
                                    $sql_update = "UPDATE sales_rep_messages SET is_active=?, updated_at=NOW() WHERE id IN ($placeholders)";
                                    $stmt_update = $conn->prepare($sql_update);
                                    $types = 'i' . str_repeat('i', count($related_ids));
                                    $params = array_merge([$to], $related_ids);
                                    $stmt_update->bind_param($types, ...$params);
                                    if ($stmt_update->execute()) {
                                        header('Location: messages.php');
                                        exit;
                                    } else {
                                        $erro = 'Falha ao atualizar status: ' . $stmt_update->error;
                                    }
                                    $stmt_update->close();
                                } else {
                                    $erro = 'Nenhum registro relacionado encontrado.';
                                }
                            } else {
                                $erro = 'Falha ao buscar registros relacionados.';
                            }
                        } else {
                            $erro = 'Registro não encontrado.';
                        }
                    } else {
                        $erro = 'Falha ao buscar registro.';
                    }
                } catch (Exception $e) {
                    $erro = 'Erro: ' . $e->getMessage();
                }
            } else {
                $erro = 'ID inválido.';
            }
        } elseif ($action === 'delete') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id > 0) {
                try {
                    // Buscar dados do registro para encontrar todos os relacionados
                    $sql_find = 'SELECT sales_rep_id, message_text, send_time, send_order FROM sales_rep_messages WHERE id=?';
                    if ($stmt_find = $conn->prepare($sql_find)) {
                        $stmt_find->bind_param('i', $id);
                        $stmt_find->execute();
                        $res_find = $stmt_find->get_result();
                        $row_data = $res_find->fetch_assoc();
                        $stmt_find->close();
                        
                        if ($row_data) {
                            $sales_rep_id = $row_data['sales_rep_id'];
                            $message_text = $row_data['message_text'];
                            $send_time = $row_data['send_time'];
                            $send_order = $row_data['send_order'];
                            
                            // Buscar todos os registros relacionados (mesma mensagem, mesmo sales_rep, mesmo tempo e ordem)
                            if ($send_time === null && $send_order === null) {
                                $sql_group = 'SELECT id FROM sales_rep_messages WHERE sales_rep_id=? AND message_text=? AND send_time IS NULL AND send_order IS NULL';
                                $stmt_group = $conn->prepare($sql_group);
                                $stmt_group->bind_param('is', $sales_rep_id, $message_text);
                            } elseif ($send_time === null) {
                                $sql_group = 'SELECT id FROM sales_rep_messages WHERE sales_rep_id=? AND message_text=? AND send_time IS NULL AND send_order=?';
                                $stmt_group = $conn->prepare($sql_group);
                                $stmt_group->bind_param('isi', $sales_rep_id, $message_text, $send_order);
                            } elseif ($send_order === null) {
                                $sql_group = 'SELECT id FROM sales_rep_messages WHERE sales_rep_id=? AND message_text=? AND send_time=? AND send_order IS NULL';
                                $stmt_group = $conn->prepare($sql_group);
                                $stmt_group->bind_param('iss', $sales_rep_id, $message_text, $send_time);
                            } else {
                                $sql_group = 'SELECT id FROM sales_rep_messages WHERE sales_rep_id=? AND message_text=? AND send_time=? AND send_order=?';
                                $stmt_group = $conn->prepare($sql_group);
                                $stmt_group->bind_param('issi', $sales_rep_id, $message_text, $send_time, $send_order);
                            }
                            
                            if ($stmt_group) {
                                $stmt_group->execute();
                                $res_group = $stmt_group->get_result();
                                $related_ids = [];
                                while ($row = $res_group->fetch_assoc()) {
                                    $related_ids[] = (int)$row['id'];
                                }
                                $res_group->free();
                                $stmt_group->close();
                                
                                // Deletar todos os registros relacionados
                                if (!empty($related_ids)) {
                                    $placeholders = str_repeat('?,', count($related_ids) - 1) . '?';
                                    $sql_del = "DELETE FROM sales_rep_messages WHERE id IN ($placeholders)";
                                    $stmt_del = $conn->prepare($sql_del);
                                    $types = str_repeat('i', count($related_ids));
                                    $stmt_del->bind_param($types, ...$related_ids);
                                    if ($stmt_del->execute()) {
                                        header('Location: messages.php');
                                        exit;
                                    } else {
                                        $erro = 'Falha ao remover.';
                                    }
                                    $stmt_del->close();
                                } else {
                                    $erro = 'Registro não encontrado.';
                                }
                            } else {
                                $erro = 'Falha ao buscar registros relacionados.';
                            }
                        } else {
                            $erro = 'Registro não encontrado.';
                        }
                    } else {
                        $erro = 'Falha ao buscar registro.';
                    }
                } catch (Exception $e) {
                    $erro = 'Erro: ' . $e->getMessage();
                }
            } else {
                $erro = 'ID inválido.';
            }
        }
    }
}

// Read for list - agrupar por mensagem, sales_rep, send_time e send_order
$rows_raw = [];
$sqlList = 'SELECT m.id, m.sales_rep_id, m.week_day_id, m.message_text, m.is_active, m.send_time, m.send_order,
                   sr.full_name AS sales_rep_name,
                   wd.name AS week_day_name, wd.id AS week_day_id_num
            FROM sales_rep_messages m
            LEFT JOIN sales_reps sr ON sr.id = m.sales_rep_id
            LEFT JOIN week_days wd ON wd.id = m.week_day_id
            ORDER BY COALESCE(m.send_order, 999999) ASC, m.id ASC';
if ($stmt = $conn->prepare($sqlList)) {
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) { $rows_raw[] = $r; }
        $res->free();
    }
    $stmt->close();
}

// Agrupar mensagens relacionadas
$rows = [];
$grouped = [];
foreach ($rows_raw as $r) {
    $key = $r['sales_rep_id'] . '|' . $r['message_text'] . '|' . ($r['send_time'] ?? '') . '|' . ($r['send_order'] ?? '');
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'id' => $r['id'],
            'sales_rep_id' => $r['sales_rep_id'],
            'sales_rep_name' => $r['sales_rep_name'],
            'message_text' => $r['message_text'],
            'status' => $r['is_active'],
            'send_time' => $r['send_time'],
            'send_order' => $r['send_order'],
            'week_days' => []
        ];
    }
    $grouped[$key]['week_days'][] = [
        'id' => $r['week_day_id_num'],
        'name' => $r['week_day_name']
    ];
}
$rows = array_values($grouped);

// Definir variáveis de ordenação
$sort = isset($_GET['sort']) ? strtolower((string)$_GET['sort']) : '';
$dir  = isset($_GET['dir'])  ? strtolower((string)$_GET['dir'])  : 'asc';
$dir  = ($dir === 'desc') ? 'DESC' : 'ASC';

// Buscar ordens já cadastradas para o sales rep logado
$used_orders = [];
if ($logged_sales_rep_id > 0) {
    $sql_used_orders = 'SELECT DISTINCT send_order FROM sales_rep_messages WHERE sales_rep_id=? AND send_order IS NOT NULL';
    if ($stmt_used = $conn->prepare($sql_used_orders)) {
        $stmt_used->bind_param('i', $logged_sales_rep_id);
        if ($stmt_used->execute()) {
            $res_used = $stmt_used->get_result();
            while ($row_used = $res_used->fetch_assoc()) {
                $used_orders[] = (int)$row_used['send_order'];
            }
            $res_used->free();
        }
        $stmt_used->close();
    }
}

// Para edição, buscar todos os dias relacionados e dados do registro
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_data = null;
$edit_week_days = [];
$edit_hour = null;
if ($editId > 0) {
    $sql_edit = 'SELECT sales_rep_id, message_text, send_time, send_order, is_active FROM sales_rep_messages WHERE id=?';
    if ($stmt_edit = $conn->prepare($sql_edit)) {
        $stmt_edit->bind_param('i', $editId);
        if ($stmt_edit->execute()) {
            $res_edit = $stmt_edit->get_result();
            $edit_data = $res_edit->fetch_assoc();
            if ($edit_data) {
                // Extrair hora (0-23)
                $edit_hour = extractHour($edit_data['send_time'] ?? '');
                // Buscar todos os registros relacionados (mesmos dados exceto week_day_id)
                $send_time = $edit_data['send_time'];
                $send_order = $edit_data['send_order'];
                if ($send_time === null && $send_order === null) {
                    $sql_related = 'SELECT week_day_id FROM sales_rep_messages WHERE sales_rep_id=? AND message_text=? AND send_time IS NULL AND send_order IS NULL';
                    $stmt_related = $conn->prepare($sql_related);
                    $stmt_related->bind_param('is', $edit_data['sales_rep_id'], $edit_data['message_text']);
                } elseif ($send_time === null) {
                    $sql_related = 'SELECT week_day_id FROM sales_rep_messages WHERE sales_rep_id=? AND message_text=? AND send_time IS NULL AND send_order=?';
                    $stmt_related = $conn->prepare($sql_related);
                    $stmt_related->bind_param('isi', $edit_data['sales_rep_id'], $edit_data['message_text'], $send_order);
                } elseif ($send_order === null) {
                    $sql_related = 'SELECT week_day_id FROM sales_rep_messages WHERE sales_rep_id=? AND message_text=? AND send_time=? AND send_order IS NULL';
                    $stmt_related = $conn->prepare($sql_related);
                    $stmt_related->bind_param('iss', $edit_data['sales_rep_id'], $edit_data['message_text'], $send_time);
                } else {
                    $sql_related = 'SELECT week_day_id FROM sales_rep_messages WHERE sales_rep_id=? AND message_text=? AND send_time=? AND send_order=?';
                    $stmt_related = $conn->prepare($sql_related);
                    $stmt_related->bind_param('issi', $edit_data['sales_rep_id'], $edit_data['message_text'], $send_time, $send_order);
                }
                if ($stmt_related) {
                    $stmt_related->execute();
                    $res_related = $stmt_related->get_result();
                    while ($row_related = $res_related->fetch_assoc()) {
                        $edit_week_days[] = (int)$row_related['week_day_id'];
                    }
                    $res_related->free();
                    $stmt_related->close();
                }
            }
            $res_edit->free();
        }
        $stmt_edit->close();
    }
}

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Catalog Seller - Messages</title>
  <style>
    :root { --bg:#0f172a; --muted:#94a3b8; --text:#e2e8f0; --pri:#22c55e; --pri2:#16a34a; }
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
    .grid2{display:grid; grid-template-columns: 1fr; gap:12px}
    .rowForm{display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:16px; padding:24px; background:linear-gradient(135deg, rgba(15,23,42,.5), rgba(11,18,32,.4)); border:1px solid rgba(148,163,184,.15); border-radius:14px; box-shadow:inset 0 1px 0 rgba(255,255,255,.03), 0 4px 12px rgba(0,0,0,.2)}
    .form-group{display:flex; flex-direction:column; gap:8px}
    .form-group.full-width{grid-column: 1 / -1}
    input[type=text], input[type=number], input[type=time], textarea, select{padding:12px 14px; border-radius:10px; border:1px solid rgba(148,163,184,.25); background:#0b1220; color:var(--text); font-family:inherit; transition:border-color 0.2s, box-shadow 0.2s; text-decoration:none}
    input[type=text]:focus, input[type=number]:focus, textarea:focus, select:focus{outline:none; border-color:#60a5fa; box-shadow:0 0 0 3px rgba(96,165,250,.15)}
    textarea{min-height:150px; resize:vertical; width:100%; line-height:1.5}
    select{cursor:pointer}
    .btn{appearance:none; display:inline-flex; align-items:center; justify-content:center; gap:6px; border:1px solid #3b4252; background:#0f172a; color:#e5e7eb; font-weight:600; font-size:13px; height:32px; padding:0 12px; border-radius:8px; cursor:pointer; box-shadow:none; text-decoration:none; line-height:1.2}
    .btn:hover{background:#0b1220}
    .btn-outline{background:transparent}
    .btn-danger{border-color:#5b616e; background:#141a23; color:#e5e7eb}
    .btn-danger:hover{background:#0f141c}
    .muted{color:var(--muted)}
    .tableWrap{width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch}
    table{width:100%; min-width: 1100px; border-collapse:separate; border-spacing:0 8px}
    th, td{padding:10px 12px; text-align:left; vertical-align:middle}
    thead th{font-size:12px; color:var(--muted)}
    tbody tr{background: rgba(255,255,255,.02); box-shadow: 0 2px 8px rgba(0,0,0,.14); border:1px solid rgba(148,163,184,.14);}
    tbody tr:hover{background: rgba(255,255,255,.03)}
    tbody tr td:first-child{border-top-left-radius:12px; border-bottom-left-radius:12px}
    tbody tr td:last-child{border-top-right-radius:12px; border-bottom-right-radius:12px}
    .toggle{position:relative; width:44px; height:24px; display:inline-block}
    .toggle input{opacity:0; width:0; height:0}
    .slider{position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background:#0f172a; border:1px solid rgba(148,163,184,.25); border-radius:999px; transition:.15s}
    .slider:before{content:""; position:absolute; height:20px; width:20px; left:1px; top:1px; background:#475569; border-radius:999px; transition:.15s}
    .toggle input:checked + .slider{background:linear-gradient(180deg, #22c55e, #16a34a); border-color:rgba(34,197,94,.6)}
    .toggle input:checked + .slider:before{transform:translateX(20px); background:#052e16}
    .actions{display:flex; gap:8px; align-items:center; justify-content:flex-start}
    tbody tr td.actions{vertical-align:middle}
    .actions-inline{display:inline-flex; align-items:center; gap:8px; vertical-align:middle}
    .weekdays{display:flex; gap:6px; flex-wrap:wrap; align-items:center}
    .weekday-badge{display:inline-block; padding:4px 8px; border-radius:6px; font-size:11px; background:rgba(34,197,94,.15); color:#86efac; border:1px solid rgba(34,197,94,.3); white-space:nowrap}
    .weekday-checkbox{display:flex; gap:8px; flex-wrap:wrap; align-items:center; padding:2px; background:rgba(15,23,42,.3); border-radius:8px; border:1px solid rgba(148,163,184,.1)}
    .weekday-checkbox label{display:flex; align-items:center; gap:6px; cursor:pointer; font-size:13px; padding:6px 10px; border-radius:6px; transition:background 0.2s}
    .weekday-checkbox label:hover{background:rgba(148,163,184,.1)}
    .weekday-checkbox input[type="checkbox"]{width:18px; height:18px; cursor:pointer; accent-color:#22c55e}
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
        <a href="dashboard.php" class="btn" style="padding:8px 12px">Back</a>
      </div>
    </header>

    <section class="card">
      <h2 style="margin:0 0 10px 0">Sales Rep Messages<?php if (!empty($sales_reps) && isset($sales_reps[0]['full_name'])): ?> - <?php echo htmlspecialchars($sales_reps[0]['full_name'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></h2>
      <?php if ($erro): ?><div class="msg error" style="color:#ef4444; margin-bottom:8px"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="msg ok" style="color:#22c55e; margin-bottom:8px"><?php echo htmlspecialchars($ok, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

      <!-- Quick add / Edit -->
      <form method="post" class="rowForm" style="margin-bottom:24px" id="messageForm">
        <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="<?php echo $editId > 0 ? 'update' : 'create'; ?>" id="formAction">
        <?php if ($editId > 0): ?>
        <input type="hidden" name="id" value="<?php echo $editId; ?>">
        <?php endif; ?>
        <div class="form-group full-width">
          <label class="muted" for="message_text_new" style="font-weight:600; font-size:13px">Message Text</label>
          <textarea id="message_text_new" name="message_text" placeholder="Enter message text..." required><?php echo $edit_data ? htmlspecialchars($edit_data['message_text'], ENT_QUOTES, 'UTF-8') : ''; ?></textarea>
        </div>
        <?php if (count($sales_reps) === 1): ?>
          <input type="hidden" name="sales_rep_id" value="<?php echo (int)$sales_reps[0]['id']; ?>">
        <?php else: ?>
        <div class="form-group">
          <label class="muted" for="sales_rep_id_new" style="font-weight:600; font-size:13px">Sales Representative</label>
          <select id="sales_rep_id_new" name="sales_rep_id" required>
            <option value="0">Select...</option>
            <?php foreach ($sales_reps as $sr): ?>
            <option value="<?php echo (int)$sr['id']; ?>" <?php echo (($edit_data && $edit_data['sales_rep_id'] == $sr['id']) || (!$edit_data && $logged_sales_rep_id > 0 && $sr['id'] == $logged_sales_rep_id)) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sr['full_name'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="form-group full-width">
          <div style="display:flex; align-items:center; gap:24px; flex-wrap:wrap">
            <div style="flex:0 1 auto; min-width:200px; display:flex; flex-direction:column; justify-content:center">
              <label class="muted" style="font-weight:600; font-size:13px; margin-bottom:4px; display:block">Days of Week</label>
              <div class="weekday-checkbox" style="background:transparent; border:none; padding:0">
                <?php foreach ($week_days as $wd_id => $wd_name): ?>
                <label>
                  <input type="checkbox" name="week_day_ids[]" value="<?php echo $wd_id; ?>" <?php echo ($editId > 0 && in_array($wd_id, $edit_week_days)) ? 'checked' : ''; ?>>
                  <span><?php echo htmlspecialchars($wd_name, ENT_QUOTES, 'UTF-8'); ?></span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>
            <div style="display:flex; align-items:center; gap:24px; flex-wrap:wrap; flex:0 0 auto">
              <div style="max-width:100px; flex:0 0 auto">
                <label class="muted" for="send_hour_new" style="font-weight:600; font-size:13px; display:block; margin-bottom:8px">Send Time</label>
                <select id="send_hour_new" name="send_hour" style="width:100%">
                  <option value="">-- Select --</option>
                  <?php for ($h = 0; $h <= 23; $h++): ?>
                  <option value="<?php echo $h; ?>" <?php echo ($edit_hour !== null && $edit_hour == $h) ? 'selected' : ''; ?>><?php echo str_pad($h, 2, '0', STR_PAD_LEFT); ?>:00</option>
                  <?php endfor; ?>
                </select>
              </div>
              <div style="max-width:100px; flex:0 0 auto">
                <label class="muted" for="send_order_new" style="font-weight:600; font-size:13px; display:block; margin-bottom:8px">Send Order</label>
                <select id="send_order_new" name="send_order" required>
                  <?php
                  $all_orders = [1, 2, 3];
                  $current_order = $edit_data ? (int)($edit_data['send_order'] ?? 1) : 0;
                  foreach ($all_orders as $order) {
                      // Mostrar se não está usado OU se é a ordem atual (em modo edição)
                      if (!in_array($order, $used_orders) || ($editId > 0 && $order == $current_order)) {
                          $selected = (!$edit_data && $order == 1) || ($edit_data && $order == $current_order) ? 'selected' : '';
                          echo '<option value="' . $order . '" ' . $selected . '>' . $order . '</option>';
                      }
                  }
                  ?>
                </select>
              </div>
              <div style="flex:0 0 auto">
                <label class="muted" style="font-weight:600; font-size:13px; display:block; margin-bottom:8px">Status</label>
                <label class="toggle">
                  <input type="checkbox" name="status" value="1" <?php echo (!$edit_data || ($edit_data && $edit_data['is_active'])) ? 'checked' : ''; ?>>
                  <span class="slider"></span>
                </label>
              </div>
              <div style="flex:0 0 auto; display:flex; align-items:center; gap:8px; margin-left:24px; margin-top:20px">
                <?php if ($editId > 0): ?>
                <a href="messages.php" class="btn btn-outline" style="padding:12px 24px">Cancel</a>
                <?php endif; ?>
                <button class="btn" type="submit" style="background:linear-gradient(180deg, #22c55e, #16a34a); color:#052e16; border:none; padding:12px 24px; font-weight:700; box-shadow:0 4px 12px rgba(34,197,94,.3)"><?php echo $editId > 0 ? 'Save' : 'Add Message'; ?></button>
              </div>
            </div>
          </div>
        </div>
      </form>

      <!-- List -->
      <div class="grid2">
        <div class="tableWrap">
        <table>
          <thead>
            <?php 
              $q = function($s) use ($sort, $dir){ $next = ($sort===$s && $dir==='asc')?'desc':'asc'; return 'messages.php?sort='.$s.'&dir='.$next; };
              $arrow = function($s) use ($sort, $dir){ if($sort!==$s) return ''; return $dir==='ASC'?' ▲':' ▼'; };
            ?>
            <tr>
              <th style="width:200px">Day</th>
              <th style="min-width:300px">Message</th>
              <th style="width:100px">Send Time</th>
              <th style="width:100px"><a href="<?php echo htmlspecialchars($q('send_order'), ENT_QUOTES, 'UTF-8'); ?>" class="muted" style="text-decoration:none">Order<?php echo $arrow('send_order'); ?></a></th>
              <th style="width:100px"><a href="<?php echo htmlspecialchars($q('status'), ENT_QUOTES, 'UTF-8'); ?>" class="muted" style="text-decoration:none">Status<?php echo $arrow('status'); ?></a></th>
              <th style="width:180px">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td>
                  <div class="weekdays">
                    <?php 
                      if (!empty($r['week_days'])) {
                        foreach ($r['week_days'] as $wd) {
                          echo '<span class="weekday-badge">' . htmlspecialchars($wd['name'], ENT_QUOTES, 'UTF-8') . '</span>';
                        }
                      } else {
                        echo '<span class="muted" style="font-size:12px">None</span>';
                      }
                    ?>
                  </div>
                </td>
                <td><?php echo nl2br(htmlspecialchars($r['message_text'], ENT_QUOTES, 'UTF-8')); ?></td>
                <td>
                  <?php 
                    if (!empty($r['send_time'])) {
                      $hour = extractHour($r['send_time']);
                      if ($hour !== null) {
                        echo htmlspecialchars(str_pad($hour, 2, '0', STR_PAD_LEFT) . ':00', ENT_QUOTES, 'UTF-8');
                      } else {
                        echo '-';
                      }
                    } else {
                      echo '-';
                    }
                  ?>
                </td>
                <td><?php echo htmlspecialchars($r['send_order'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                  <form method="post" style="margin:0; display:inline-flex; align-items:center; gap:8px" class="toggle-form">
                    <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="to" value="<?php echo ($r['status'] ? '1' : '0'); ?>">
                    <label class="toggle">
                      <input type="checkbox" <?php echo ($r['status'] ? 'checked' : ''); ?> aria-label="Toggle is_active">
                      <span class="slider"></span>
                    </label>
                  </form>
                </td>
                <td class="actions">
                  <span class="actions-inline">
                  <a class="btn" href="messages.php?edit=<?php echo (int)$r['id']; ?>">Edit</a>
                    <form method="post" onsubmit="return confirm('Remove this message?');" style="display:inline-block; margin:0">
                    <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <button type="submit" class="btn btn-danger">Delete</button>
                  </form>
                  </span>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
            <tr>
              <td colspan="6" class="muted">No messages found.</td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>
        </div>
      </div>
    </section>
  </div>
<script>
</script>
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
    
    // Scroll para o formulário quando estiver editando
    var form = document.getElementById('messageForm');
    if (form && <?php echo $editId > 0 ? 'true' : 'false'; ?>) {
      setTimeout(function() {
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        var textarea = document.getElementById('message_text_new');
        if (textarea) textarea.focus();
      }, 100);
    }
  })();
</script>
</body>
</html>
