<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$arquivoConfig = __DIR__ . '/config.php';
if (!file_exists($arquivoConfig)) { die('config.php not found'); }
require_once $arquivoConfig;
$erro = '';
$ok = '';
$flash_ok = '';
if (!empty($_SESSION['flash_ok'])) {
    $flash_ok = (string)$_SESSION['flash_ok'];
    unset($_SESSION['flash_ok']);
}
$csrf_name = '_csrf';
if (empty($_SESSION[$csrf_name])) { $_SESSION[$csrf_name] = bin2hex(random_bytes(16)); }
$csrf_token = $_SESSION[$csrf_name];
$fields = ['company_name','ein','email','phone','catalog_req_password','address_line1','city','state','zip_code','country','website','contact_person','slug','is_active'];
$data = array_fill_keys($fields, '');
$data['logo_path'] = '';
$id = 1;

// Diretório para logos (criar se não existir)
$logo_dir = __DIR__ . '/img_logo';
if (!is_dir($logo_dir)) {
    if (!@mkdir($logo_dir, 0755, true)) {
        $erro = 'Failed to create logo directory: ' . htmlspecialchars($logo_dir, ENT_QUOTES, 'UTF-8');
    }
}
// Verificar se o diretório é gravável
if (is_dir($logo_dir) && !is_writable($logo_dir)) {
    @chmod($logo_dir, 0755);
    if (!is_writable($logo_dir)) {
        $erro = 'Logo directory is not writable: ' . htmlspecialchars($logo_dir, ENT_QUOTES, 'UTF-8');
    }
}

// Diretório base para sites dos clientes
$sites_base_dir = __DIR__;

// Endpoint AJAX para validar slug
if (isset($_GET['action']) && $_GET['action'] === 'validate_slug' && isset($_GET['slug'])) {
    header('Content-Type: application/json');
    $slug_to_check = strtolower(trim((string)$_GET['slug']));
    $response = ['valid' => false, 'message' => ''];
    
    if ($slug_to_check === '') {
        $response['message'] = 'Slug cannot be empty.';
        echo json_encode($response);
        exit;
    }
    
    // Normalizar slug
    $slug_normalized = preg_replace('/[^a-z0-9_-]/', '', $slug_to_check);
    
    // Validar formato
    if (empty($slug_normalized) || !preg_match('/^[a-z0-9][a-z0-9_-]*[a-z0-9]$|^[a-z0-9]$/', $slug_normalized)) {
        $response['message'] = 'Invalid format. Use only lowercase letters, numbers, hyphens (-) and underscores (_). Must start and end with a letter or number.';
        echo json_encode($response);
        exit;
    }
    
    // Verificar se já existe
    $check_sql = "SELECT id FROM companies WHERE slug = ? AND id != ? LIMIT 1";
    if ($check_stmt = $conn->prepare($check_sql)) {
        $check_stmt->bind_param('si', $slug_normalized, $id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows > 0) {
            $response['message'] = 'This slug is already in use by another company. Please choose a different one.';
        } else {
            $response['valid'] = true;
            $response['message'] = 'Slug is available and valid!';
        }
        $check_stmt->close();
    } else {
        $response['message'] = 'Database error. Please try again.';
    }
    
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST[$csrf_name]) || !hash_equals($csrf_token, (string)$_POST[$csrf_name])) { $erro = 'Session expired. Please try again.'; }
    if ($erro === '') {
        foreach ($fields as $f) { $data[$f] = isset($_POST[$f]) ? trim((string)$_POST[$f]) : ''; }
        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) { $erro = 'Invalid email address.'; }
        if ($data['website'] !== '') {
            $data['website'] = preg_replace('#^(https?://)#i', '', $data['website']);
            if (!preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}(\/.*)?$/i', $data['website'])) {
                $erro = 'Invalid website. Example: www.tk95it.com or www.tk95it.com/cd';
            }
        }
        if ($data['phone'] !== '' && !preg_match('/^\+1\s\d{3}\s\d{3}\s\d{4}$/', $data['phone'])) { $erro = 'Invalid phone number. Use the format +1 407 680 8976.'; }
        
        // Validação do slug
        $slug_original = $data['slug'];
        if ($data['slug'] !== '') {
            // Normalizar slug: converter para minúsculas e remover espaços
            $data['slug'] = strtolower(trim($data['slug']));
            // Remover caracteres especiais, permitir apenas letras, números, hífen e underscore
            $data['slug'] = preg_replace('/[^a-z0-9_-]/', '', $data['slug']);
            
            // Validar formato: deve ter pelo menos 1 caractere alfanumérico
            if (empty($data['slug']) || !preg_match('/^[a-z0-9][a-z0-9_-]*[a-z0-9]$|^[a-z0-9]$/', $data['slug'])) {
                $erro = 'Invalid slug. Use only lowercase letters, numbers, hyphens (-) and underscores (_). Must start and end with a letter or number.';
            } else {
                // Verificar se o slug já existe para outra empresa
                $check_sql = "SELECT id FROM companies WHERE slug = ? AND id != ? LIMIT 1";
                if ($check_stmt = $conn->prepare($check_sql)) {
                    $check_stmt->bind_param('si', $data['slug'], $id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    if ($check_result->num_rows > 0) {
                        $erro = 'This slug is already in use by another company. Please choose a different one.';
                    }
                    $check_stmt->close();
                }
            }
        }
        
        $is_active = ($data['is_active'] === '1') ? 1 : 0;
        $catalog_req_password = ($data['catalog_req_password'] === '1') ? 1 : 0;
        
        // Processar upload de logo - verifica apenas o diretório
        $save_logo_only = isset($_POST['save_logo']) && $_POST['save_logo'] === '1';
        $logo_path = '';
        $remove_logo = isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1';
        $logo_uploaded = false;
        
        if ($remove_logo) {
            // Remover logo existente (qualquer extensão)
            $pattern = $logo_dir . '/logo_' . $id . '.*';
            $files = glob($pattern);
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            if ($save_logo_only) {
                header('Location: company_edit.php?tab=logo');
                exit;
            }
        } elseif (isset($_FILES['logo']) && !empty($_FILES['logo']['tmp_name'])) {
            // Verificar erro de upload primeiro
            if ($_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
                $upload_errors = [
                    UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
                    UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
                    UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                    UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                    UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
                ];
                $erro = 'Upload error: ' . (isset($upload_errors[$_FILES['logo']['error']]) ? $upload_errors[$_FILES['logo']['error']] : 'Unknown error');
            } elseif (!is_uploaded_file($_FILES['logo']['tmp_name'])) {
                $erro = 'Invalid file upload. Security check failed.';
            } else {
                // Validar arquivo
                $file = $_FILES['logo'];
            $max_size = 2 * 1024 * 1024; // 2MB
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            // Verificar tamanho
            if ($file['size'] > $max_size) {
                $erro = 'Logo file is too large. Maximum size is 2MB.';
                if ($save_logo_only) {
                    $_SESSION['flash_erro'] = $erro;
                    header('Location: company_edit.php?tab=logo');
                    exit;
                }
            } else {
                // Verificar tipo MIME real
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                
                // Verificar extensão
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if (!in_array($mime_type, $allowed_types) || !in_array($ext, $allowed_ext)) {
                    $erro = 'Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed.';
                    if ($save_logo_only) {
                        $_SESSION['flash_erro'] = $erro;
                        header('Location: company_edit.php');
                        exit;
                    }
                } else {
                    // Nome do arquivo: logo_X.ext (onde X é o ID da empresa)
                    $new_filename = 'logo_' . $id . '.' . $ext;
                    $target_path = $logo_dir . '/' . $new_filename;
                    
                    // Remover logo antigo se existir (qualquer extensão)
                    $pattern = $logo_dir . '/logo_' . $id . '.*';
                    $old_files = glob($pattern);
                    foreach ($old_files as $old_file) {
                        if (is_file($old_file)) {
                            @unlink($old_file);
                        }
                    }
                    
                    // Verificar se o diretório é gravável antes de mover
                    if (!is_writable($logo_dir)) {
                        $erro = 'Logo directory is not writable. Please check permissions.';
                        if ($save_logo_only) {
                            $_SESSION['flash_erro'] = $erro;
                            header('Location: company_edit.php');
                            exit;
                        }
                    } else {
                        // Mover arquivo
                        if (move_uploaded_file($file['tmp_name'], $target_path)) {
                            // Verificar se o arquivo foi realmente criado
                            if (file_exists($target_path)) {
                                // Garantir permissões corretas
                                @chmod($target_path, 0644);
                                $logo_path = '/img_logo/' . $new_filename;
                                $logo_uploaded = true;
                                if ($save_logo_only) {
                                    header('Location: company_edit.php?tab=logo');
                                    exit;
                                }
                            } else {
                                $erro = 'File was moved but not found in destination. Path: ' . htmlspecialchars($target_path, ENT_QUOTES, 'UTF-8');
                                if ($save_logo_only) {
                                    $_SESSION['flash_erro'] = $erro;
                                    header('Location: company_edit.php');
                                    exit;
                                }
                            }
                        } else {
                            $upload_error = error_get_last();
                            $erro = 'Failed to upload logo file. Error: ' . ($upload_error ? htmlspecialchars($upload_error['message'], ENT_QUOTES, 'UTF-8') : 'Unknown error') . '. Target: ' . htmlspecialchars($target_path, ENT_QUOTES, 'UTF-8') . '. Directory writable: ' . (is_writable($logo_dir) ? 'Yes' : 'No');
                            if ($save_logo_only) {
                                $_SESSION['flash_erro'] = $erro;
                                header('Location: company_edit.php');
                                exit;
                            }
                        }
                    }
                }
            }
            }
        }
        
        // Se for apenas salvar logo e não houver erro, já redirecionou acima
        // Se houver erro no logo e for save_logo_only, mostrar erro e não processar outros campos
        if ($save_logo_only && $erro !== '') {
            // Erro já está definido, não processar outros campos
        } elseif ($erro === '') {
            $emailLower = strtolower($data['email']);
            
            // Criar pasta do site se slug for válido
            if ($data['slug'] !== '') {
                $site_dir = $sites_base_dir . '/' . $data['slug'];
                if (!is_dir($site_dir)) {
                    if (!@mkdir($site_dir, 0755, true)) {
                        $erro = 'Failed to create site directory: ' . htmlspecialchars($site_dir, ENT_QUOTES, 'UTF-8');
                    }
                }
                
                // Criar arquivo index.html com redirect se não existir
                if ($erro === '' && is_dir($site_dir)) {
                    $index_file = $site_dir . '/index.html';
                    if (!file_exists($index_file)) {
                        // Usar slug ao invés de company_id para maior segurança
                        $slug_normalized = strtolower(trim($data['slug']));
                        $slug_normalized = preg_replace('/[^a-z0-9_-]/', '', $slug_normalized);
                        $redirect_url = 'https://catalogseller.com/catalog/index.php?slug=' . urlencode($slug_normalized);
                        $html_content = '<!DOCTYPE html>' . "\n" .
                                      '<html lang="en">' . "\n" .
                                      '<head>' . "\n" .
                                      '  <meta charset="UTF-8">' . "\n" .
                                      '  <meta http-equiv="refresh" content="0; url=' . htmlspecialchars($redirect_url, ENT_QUOTES, 'UTF-8') . '">' . "\n" .
                                      '  <title>Redirecting...</title>' . "\n" .
                                      '</head>' . "\n" .
                                      '<body>' . "\n" .
                                      '  <p>Redirecting to <a href="' . htmlspecialchars($redirect_url, ENT_QUOTES, 'UTF-8') . '">catalog</a>...</p>' . "\n" .
                                      '  <script>window.location.href = "' . htmlspecialchars($redirect_url, ENT_QUOTES, 'UTF-8') . '";</script>' . "\n" .
                                      '</body>' . "\n" .
                                      '</html>';
                        
                        if (@file_put_contents($index_file, $html_content) === false) {
                            $erro = 'Failed to create index.html file in site directory.';
                        } else {
                            @chmod($index_file, 0644);
                        }
                    }
                }
            }
            
            if ($erro === '') {
                // Update without exposing or modifying gmail credentials
                $sql = "UPDATE companies SET company_name=?, ein=?, email=?, phone=?, catalog_req_password=?, address_line1=?, city=?, state=?, zip_code=?, country=?, website=?, contact_person=?, slug=?, is_active=?, updated_at=NOW() WHERE id=?";
                if ($stmt = $conn->prepare($sql)) {
                    $stmt->bind_param(
                        'ssssissssssssii',
                        $data['company_name'],
                        $data['ein'],
                        $emailLower,
                        $data['phone'],
                        $catalog_req_password,
                        $data['address_line1'],
                        $data['city'],
                        $data['state'],
                        $data['zip_code'],
                        $data['country'],
                        $data['website'],
                        $data['contact_person'],
                        $data['slug'],
                        $is_active,
                        $id
                    );
                    if ($stmt->execute()) { 
                        $ok = 'Company updated successfully.'; 
                    } else { $erro = 'Save failed.'; }
                    $stmt->close();
                } else { $erro = 'Failed to prepare update statement.'; }
            }
        }
    }
}
if ($erro === '' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $sql = "SELECT id, company_name, ein, email, phone, catalog_req_password, address_line1, city, state, zip_code, country, website, contact_person, slug, is_active FROM companies WHERE id=? LIMIT 1";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            $stmt->bind_result($rid, $company_name, $ein, $email, $phone, $catalog_req_password, $address_line1, $city, $state, $zip_code, $country, $website, $contact_person, $slug, $is_active);
            if ($stmt->fetch()) {
                // Verificar se existe logo no diretório
                $logo_path = '';
                $pattern = $logo_dir . '/logo_' . $id . '.*';
                $existing_files = glob($pattern);
                if (!empty($existing_files) && is_file($existing_files[0])) {
                    $logo_path = '/img_logo/' . basename($existing_files[0]);
                }
                
                $data = [
                    'company_name'=>(string)$company_name,
                    'ein'=>(string)$ein,
                    'email'=>(string)$email,
                    'phone'=>(string)$phone,
                    'catalog_req_password'=>(string)(int)$catalog_req_password,
                    'address_line1'=>(string)$address_line1,
                    'city'=>(string)$city,
                    'state'=>(string)$state,
                    'zip_code'=>(string)$zip_code,
                    'country'=>(string)$country,
                    'website'=>(string)$website,
                    'contact_person'=>(string)$contact_person,
                    'slug'=>(string)$slug,
                    'logo_path'=>$logo_path,
                    'is_active'=> (string)(int)$is_active
                ];
                // Logo URL para cabeçalho
                $logo_url = !empty($logo_path) ? ltrim($logo_path, '/') : 'logo.png';
            }
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Catalog Seller - Company</title>
  <style>
    :root { --bg:#0f172a; --muted:#94a3b8; --text:#e2e8f0; --pri:#22c55e; --pri2:#16a34a; }
    *{box-sizing:border-box}
    body{margin:0; font-family: system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,"Helvetica Neue",Arial; background: radial-gradient(1200px 800px at 80% -10%, #1e293b, transparent), var(--bg); color:var(--text); min-height:100vh;}
    .container{max-width:900px; margin:24px auto; padding:0 24px}
    .nav{display:flex; align-items:center; justify-content:space-between; gap:16px; padding:14px 18px; border:1px solid rgba(148,163,184,.18); border-radius:14px; background: linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02)); box-shadow: 0 10px 24px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.06); backdrop-filter: blur(6px);}
    .nav-left{display:flex; align-items:center; gap:10px; flex:0 0 auto}
    .nav-center{display:flex; align-items:center; justify-content:center; flex:1}
    .nav-right{display:flex; align-items:center; gap:10px; flex:0 0 auto}
    .brandRow{display:flex; align-items:center; gap:10px}
    .logo{display:block; height:20px; width:auto; background:#fff; padding:2px; border-radius:6px; border:1px solid rgba(148,163,184,.25)}
    .logo-company{display:block; height:32px; width:auto; background:#fff; padding:3px; border-radius:6px; border:1px solid rgba(148,163,184,.25)}
    .card{margin-top:22px; padding:18px; border:1px solid rgba(148,163,184,.18); border-radius:14px; background: linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.015)); box-shadow: 0 10px 24px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.05)}
    form{display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:14px}
    label{font-size:12px; color:var(--muted)}
    input, select{width:100%; padding:10px 12px; border-radius:10px; border:1px solid rgba(148,163,184,.25); background:#0b1220; color:var(--text)}
    .row{display:flex; gap:14px}
    .row > div{flex:1}
    .actions{margin-top:8px; display:flex; justify-content:flex-end; gap:10px}
    .btn{appearance:none; display:inline-flex; align-items:center; justify-content:center; gap:6px; border:1px solid #3b4252; background:#0f172a; color:#e5e7eb; font-weight:600; font-size:13px; height:32px; padding:0 12px; border-radius:8px; cursor:pointer; box-shadow:none; text-decoration:none; line-height:1.2}
    .btn:hover{background:#0b1220}
    .btn-outline{background:transparent; border-color:rgba(148,163,184,.3)}
    .btn-outline:hover{background:rgba(255,255,255,.05)}
    .msg{margin:8px 0 0; font-size:14px}
    .error{color:#ef4444}
    .ok{color:#22c55e}
    /* centered notification */
    .toast-overlay{position:fixed; inset:0; display:flex; align-items:center; justify-content:center; background:rgba(2,6,23,.55); backdrop-filter: blur(4px); z-index:9999}
    .toast{min-width:300px; max-width:92vw; padding:16px 18px; border-radius:14px; border:1px solid rgba(148,163,184,.18); background: linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02)); color:var(--text); box-shadow: 0 10px 26px rgba(0,0,0,.35)}
    .toast.success{border-color:rgba(34,197,94,.55)}
    .toast.error{border-color:rgba(239,68,68,.55)}
    .toast-head{display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:6px; font-weight:700}
    .toast-close{appearance:none; border:0; background:transparent; color:var(--text); font-size:18px; line-height:1; cursor:pointer; padding:2px 6px; border-radius:8px}
    .toast-close:hover{background:rgba(255,255,255,.06)}
    /* Tabs */
    .tabs{display:flex; gap:8px; border-bottom:1px solid rgba(148,163,184,.18); margin-bottom:20px}
    .tab{appearance:none; background:none; border:none; padding:12px 20px; color:var(--muted); cursor:pointer; font-size:14px; font-weight:500; border-bottom:2px solid transparent; margin-bottom:-1px; transition:all 0.2s}
    .tab:hover{color:var(--text)}
    .tab.active{color:var(--text); border-bottom-color:#3b82f6}
    .tab-content{display:none}
    .tab-content.active{display:block}
    /* Logo upload styles */
    .logo-section{padding:24px; text-align:center}
    .logo-display{width:100%; max-width:400px; margin:0 auto 24px; padding:24px; border:2px dashed rgba(148,163,184,.3); border-radius:12px; background:rgba(255,255,255,.02); min-height:300px; display:flex; align-items:center; justify-content:center}
    .logo-display img{max-width:100%; max-height:280px; object-fit:contain; border-radius:8px}
    .logo-display.empty{color:var(--muted); font-size:16px}
    .logo-actions{display:flex; gap:12px; justify-content:center; flex-wrap:wrap}
    .logo-actions label{display:inline-block; cursor:pointer}
    input[type=file]{padding:8px; border:1px dashed rgba(148,163,184,.3); border-radius:8px; background:rgba(255,255,255,.02); width:100%; max-width:400px; margin:0 auto 12px; display:block}
    input[type=file]:hover{border-color:rgba(148,163,184,.5)}
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
        <a href="dashboard.php" class="btn">Back</a>
      </div>
    </header>
    <section class="card">
      <h2 style="margin-top:0">Company</h2>
      <?php if ($erro): ?><div class="msg error"><?php echo htmlspecialchars($erro, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="msg ok"><?php echo htmlspecialchars($ok, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
      <?php if ($flash_ok): ?><div class="msg ok"><?php echo htmlspecialchars($flash_ok, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
      <?php if (isset($_SESSION['flash_erro'])): ?><div class="msg error"><?php echo htmlspecialchars($_SESSION['flash_erro'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['flash_erro']); ?></div><?php endif; ?>
      
      <!-- Tabs -->
      <div class="tabs">
        <button type="button" class="tab active" onclick="showTab('company')">Company</button>
        <button type="button" class="tab" onclick="showTab('logo')">Logo</button>
      </div>
      
      <!-- Tab: Company -->
      <div id="tab-company" class="tab-content active">
      <form method="post" autocomplete="on" enctype="multipart/form-data">
        <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="current_logo" value="<?php echo htmlspecialchars($data['logo_path'], ENT_QUOTES, 'UTF-8'); ?>">
        
        <div>
          <label>Company name</label>
          <input name="company_name" value="<?php echo htmlspecialchars($data['company_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>
        <div>
          <label>EIN</label>
          <input name="ein" value="<?php echo htmlspecialchars($data['ein'], ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div>
          <label>Email</label>
          <input type="email" name="email" value="<?php echo htmlspecialchars($data['email'], ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div>
          <label>Phone</label>
          <input id="phone" name="phone" inputmode="tel" autocomplete="tel" placeholder="+1 407 680 8976" pattern="^\+1 \d{3} \d{3} \d{4}$" value="<?php echo htmlspecialchars($data['phone'], ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div>
          <label>Require Password Catalog</label>
          <select name="catalog_req_password">
            <option value="0" <?php echo (isset($data['catalog_req_password']) && $data['catalog_req_password']==='0' ? 'selected' : ''); ?>>Inactive</option>
            <option value="1" <?php echo (isset($data['catalog_req_password']) && $data['catalog_req_password']==='1' ? 'selected' : ''); ?>>Active</option>
          </select>
        </div>
        <div style="grid-column: 1 / -1">
          <label>Address line 1</label>
          <input name="address_line1" value="<?php echo htmlspecialchars($data['address_line1'], ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div>
          <label>City</label>
          <input name="city" value="<?php echo htmlspecialchars($data['city'], ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div>
          <label>State</label>
          <input name="state" value="<?php echo htmlspecialchars($data['state'], ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div>
          <label>ZIP code</label>
          <input name="zip_code" value="<?php echo htmlspecialchars($data['zip_code'], ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div>
          <label>Country</label>
          <input name="country" value="<?php echo htmlspecialchars($data['country'], ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div>
          <label>Website</label>
          <input name="website" placeholder="www.tk95it.com" pattern="^([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}(/.*)?$" value="<?php echo htmlspecialchars($data['website'], ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div>
          <label>Contact person</label>
          <input name="contact_person" value="<?php echo htmlspecialchars($data['contact_person'], ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div style="grid-column: 1 / -1; display:flex; gap:14px; align-items:flex-start">
          <div style="flex:0 0 200px">
            <label>Slug</label>
            <div style="display:flex; gap:8px; align-items:flex-start">
              <div style="flex:1">
                <input id="slug" name="slug" placeholder="flpnb" pattern="^[a-z0-9][a-z0-9_-]*[a-z0-9]$|^[a-z0-9]$" value="<?php echo htmlspecialchars($data['slug'], ENT_QUOTES, 'UTF-8'); ?>">
                <small style="color:var(--muted); font-size:11px; display:block; margin-top:4px">URL: www.catalogseller.com/<span id="slug-preview"><?php echo htmlspecialchars($data['slug'], ENT_QUOTES, 'UTF-8'); ?></span></small>
                <div id="slug-validation-msg" style="margin-top:6px; font-size:12px; min-height:18px"></div>
              </div>
              <button type="button" id="validate-slug-btn" class="btn" style="margin-top:0; white-space:nowrap">Validate</button>
            </div>
          </div>
          <div style="flex:0 0 150px">
            <label>Status</label>
            <select name="is_active">
              <option value="1" <?php echo ($data['is_active']==='1' ? 'selected' : ''); ?>>Active</option>
              <option value="0" <?php echo ($data['is_active']==='0' ? 'selected' : ''); ?>>Inactive</option>
            </select>
          </div>
          <div style="flex:0 0 auto; padding-top:24px">
            <button class="btn" type="submit">Save</button>
          </div>
        </div>
      </form>
      </div>
      
      <!-- Tab: Logo -->
      <div id="tab-logo" class="tab-content">
        <?php 
        // Verificar se existe logo no diretório
        $pattern = $logo_dir . '/logo_' . $id . '.*';
        $existing_files = glob($pattern);
        $logo_exists = !empty($existing_files) && is_file($existing_files[0]);
        $logo_display_path = '';
        if ($logo_exists) {
            $logo_file = basename($existing_files[0]);
            $logo_mtime = filemtime($existing_files[0]);
            $logo_display_path = '/img_logo/' . $logo_file . '?v=' . $logo_mtime;
        }
        ?>
        <div class="logo-section">
          <div class="logo-display <?php echo $logo_exists ? '' : 'empty'; ?>">
            <?php if ($logo_exists): ?>
              <img src="<?php echo htmlspecialchars($logo_display_path, ENT_QUOTES, 'UTF-8'); ?>" alt="Company logo" id="logo_preview">
            <?php endif; ?>
            <!-- Preview do novo arquivo selecionado (escondido inicialmente) -->
            <img id="logo_preview_new" style="display:none; max-width:100%; max-height:280px; object-fit:contain; border-radius:8px" alt="Preview">
          </div>
          
          <form method="post" enctype="multipart/form-data" id="logo_form">
            <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="save_logo" value="1">
            
            <div style="text-align:center; margin-bottom:16px">
              <label for="logo_input" class="btn" style="cursor:pointer; margin:0; display:inline-block">
                <?php echo $logo_exists ? 'Change Logo' : 'Upload Logo'; ?>
              </label>
              <input type="file" name="logo" accept="image/jpeg,image/png,image/gif,image/webp" id="logo_input" onchange="handleLogoSelect(this)" style="display:none">
            </div>
            <small style="color:var(--muted); font-size:11px; display:block; margin-bottom:16px; text-align:center">Accepted formats: JPEG, PNG, GIF, WebP. Maximum size: 2MB</small>
            
            <div class="logo-actions">
              <?php if ($logo_exists): ?>
              <form method="post" style="display:inline; margin:0">
                <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="save_logo" value="1">
                <input type="hidden" name="remove_logo" value="1">
                <button type="submit" class="btn" style="background:linear-gradient(180deg, #ef4444, #dc2626); color:#fff; margin:0" onclick="return confirm('Remove logo?');">Remove Logo</button>
              </form>
              <?php endif; ?>
              <button type="submit" form="logo_form" class="btn" id="save_logo_btn" style="display:none; background:linear-gradient(180deg, #22c55e, #16a34a); color:#052e16; margin:0">Save Logo</button>
            </div>
          </form>
        </div>
      </div>
    </section>
  </div>
</body>
</html>

<script>
// Tab functionality
function showTab(tabName) {
  // Hide all tabs
  var tabs = document.querySelectorAll('.tab-content');
  tabs.forEach(function(tab) {
    tab.classList.remove('active');
  });
  
  // Remove active class from all tab buttons
  var tabButtons = document.querySelectorAll('.tab');
  tabButtons.forEach(function(btn) {
    btn.classList.remove('active');
  });
  
  // Show selected tab
  var selectedTab = document.getElementById('tab-' + tabName);
  if (selectedTab) {
    selectedTab.classList.add('active');
  }
  
  // Add active class to clicked button
  if (event && event.target) {
    event.target.classList.add('active');
  }
}

// Check URL parameter and show correct tab on page load
(function() {
  var urlParams = new URLSearchParams(window.location.search);
  var tabParam = urlParams.get('tab');
  if (tabParam === 'logo') {
    // Hide all tabs
    var tabs = document.querySelectorAll('.tab-content');
    tabs.forEach(function(tab) {
      tab.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    var tabButtons = document.querySelectorAll('.tab');
    tabButtons.forEach(function(btn) {
      btn.classList.remove('active');
    });
    
    // Show logo tab
    var logoTab = document.getElementById('tab-logo');
    if (logoTab) {
      logoTab.classList.add('active');
    }
    
    // Activate logo tab button
    var logoTabBtn = document.querySelector('.tab[onclick*="logo"]');
    if (logoTabBtn) {
      logoTabBtn.classList.add('active');
    }
    
    // Remove tab parameter from URL without reload
    if (window.history && window.history.replaceState) {
      var newUrl = window.location.pathname;
      window.history.replaceState({}, '', newUrl);
    }
  }
})();

// Logo preview functionality
function handleLogoSelect(input) {
  if (!input || !input.files || !input.files[0]) return;
  
  var file = input.files[0];
  var maxSize = 2 * 1024 * 1024; // 2MB
  var allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
  
  // Validar tamanho
  if (file.size > maxSize) {
    alert('File is too large. Maximum size is 2MB.');
    input.value = '';
    return;
  }
  
  // Validar tipo
  if (!allowedTypes.includes(file.type)) {
    alert('Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed.');
    input.value = '';
    return;
  }
  
  // Mostrar preview
  var reader = new FileReader();
  reader.onload = function(e) {
    var previewNew = document.getElementById('logo_preview_new');
    var previewCurrent = document.getElementById('logo_preview');
    var logoDisplay = document.querySelector('.logo-display');
    var saveBtn = document.getElementById('save_logo_btn');
    
    if (previewNew) {
      previewNew.src = e.target.result;
      previewNew.style.display = 'block';
      if (previewCurrent) previewCurrent.style.display = 'none';
      if (logoDisplay) logoDisplay.classList.remove('empty');
    }
    
    // Mostrar botão Salvar
    if (saveBtn) {
      saveBtn.style.display = 'inline-block';
    }
  };
  reader.readAsDataURL(file);
}

// Phone formatting
(function(){
  var el = document.getElementById('phone');
  if(!el) return;
  function format(v){
    v = (v||'').replace(/[^0-9]/g,'');
    if (v.charAt(0) !== '1') v = '1' + v; // força código do país 1
    v = v.slice(0,11); // 1 + 10 dígitos
    var d = v.slice(1);
    var a = d.slice(0,3);
    var b = d.slice(3,6);
    var c = d.slice(6,10);
    var out = '+1';
    if (a) out += ' ' + a;
    if (b) out += ' ' + b;
    if (c) out += ' ' + c;
    return out;
  }
  function onInput(){
    var cur = el.selectionStart;
    el.value = format(el.value);
  }
  el.addEventListener('focus', function(){ if(!el.value) el.value = '+1 '; });
  el.addEventListener('input', onInput);
  el.addEventListener('blur', function(){
    if (el.value && !/^\+1 \d{3} \d{3} \d{4}$/.test(el.value)) {
      el.setCustomValidity('Use o formato +1 407 680 8976');
    } else {
      el.setCustomValidity('');
    }
  });
})();

// Slug validation and preview
(function(){
  var slugEl = document.getElementById('slug');
  var previewEl = document.getElementById('slug-preview');
  if(!slugEl || !previewEl) return;
  
  function normalizeSlug(v){
    v = (v || '').toLowerCase().trim();
    // Remove caracteres especiais, mantém apenas letras, números, hífen e underscore
    v = v.replace(/[^a-z0-9_-]/g, '');
    return v;
  }
  
  function updatePreview(){
    var val = normalizeSlug(slugEl.value);
    previewEl.textContent = val || 'slug';
  }
  
  function validateSlug(){
    var val = slugEl.value.trim();
    if(val === '') {
      slugEl.setCustomValidity('');
      return true;
    }
    
    var normalized = normalizeSlug(val);
    // Validar formato: deve começar e terminar com letra ou número
    if(!/^[a-z0-9][a-z0-9_-]*[a-z0-9]$|^[a-z0-9]$/.test(normalized)) {
      slugEl.setCustomValidity('Use only lowercase letters, numbers, hyphens (-) and underscores (_). Must start and end with a letter or number.');
      return false;
    }
    
    slugEl.setCustomValidity('');
    return true;
  }
  
  slugEl.addEventListener('input', function(){
    var val = slugEl.value;
    var normalized = normalizeSlug(val);
    if(val !== normalized) {
      var cursorPos = slugEl.selectionStart;
      slugEl.value = normalized;
      // Ajustar posição do cursor
      var diff = normalized.length - val.length;
      slugEl.setSelectionRange(cursorPos + diff, cursorPos + diff);
    }
    updatePreview();
    validateSlug();
  });
  
  slugEl.addEventListener('blur', validateSlug);
  
  // Atualizar preview inicial
  updatePreview();
})();

// Slug validation button
(function(){
  var validateBtn = document.getElementById('validate-slug-btn');
  var slugEl = document.getElementById('slug');
  var msgEl = document.getElementById('slug-validation-msg');
  if(!validateBtn || !slugEl || !msgEl) return;
  
  function normalizeSlug(v){
    v = (v || '').toLowerCase().trim();
    v = v.replace(/[^a-z0-9_-]/g, '');
    return v;
  }
  
  function showMessage(text, isError){
    msgEl.textContent = text;
    msgEl.style.color = isError ? '#ef4444' : '#22c55e';
  }
  
  function clearMessage(){
    msgEl.textContent = '';
    msgEl.style.color = '';
  }
  
  validateBtn.addEventListener('click', function(){
    var slugValue = slugEl.value.trim();
    
    if(slugValue === '') {
      showMessage('Please enter a slug first.', true);
      return;
    }
    
    // Normalizar antes de enviar
    var normalized = normalizeSlug(slugValue);
    if(slugValue !== normalized) {
      slugEl.value = normalized;
    }
    
    // Desabilitar botão durante validação
    validateBtn.disabled = true;
    validateBtn.textContent = 'Validating...';
    clearMessage();
    
    // Fazer requisição AJAX
    var xhr = new XMLHttpRequest();
    xhr.open('GET', '?action=validate_slug&slug=' + encodeURIComponent(normalized), true);
    xhr.onload = function(){
      validateBtn.disabled = false;
      validateBtn.textContent = 'Validate';
      
      if(xhr.status === 200) {
        try {
          var response = JSON.parse(xhr.responseText);
          if(response.valid) {
            showMessage(response.message, false);
          } else {
            showMessage(response.message, true);
          }
        } catch(e) {
          showMessage('Error parsing response. Please try again.', true);
        }
      } else {
        showMessage('Error validating slug. Please try again.', true);
      }
    };
    xhr.onerror = function(){
      validateBtn.disabled = false;
      validateBtn.textContent = 'Validate';
      showMessage('Network error. Please try again.', true);
    };
    xhr.send();
  });
  
  // Limpar mensagem quando o usuário começar a digitar
  slugEl.addEventListener('input', function(){
    if(msgEl.textContent !== '') {
      clearMessage();
    }
  });
})();
</script>

<script>
// Central overlay notification using existing .msg elements
(function(){
  var existing = document.querySelector('.msg.ok, .msg.error');
  if(!existing) return;
  var text = existing.textContent.trim();
  if(!text) return;
  // hide original inline message
  existing.style.display = 'none';

  var overlay = document.createElement('div');
  overlay.className = 'toast-overlay';
  overlay.setAttribute('role','dialog');
  overlay.setAttribute('aria-live','assertive');

  var box = document.createElement('div');
  box.className = 'toast ' + (existing.classList.contains('error') ? 'error' : 'success');

  var head = document.createElement('div');
  head.className = 'toast-head';
  head.innerHTML = '<span>' + (existing.classList.contains('error') ? 'Erro' : 'Sucesso') + '</span>';

  var btn = document.createElement('button');
  btn.className = 'toast-close';
  btn.setAttribute('aria-label','Fechar');
  btn.innerHTML = '\u00D7';

  var body = document.createElement('div');
  body.textContent = text;

  function close(){
    if(!overlay) return;
    overlay.remove();
    overlay = null;
  }

  btn.addEventListener('click', close);
  overlay.addEventListener('click', function(e){ if(e.target === overlay) close(); });
  document.addEventListener('keydown', function(ev){ if(ev.key === 'Escape') close(); });

  head.appendChild(btn);
  box.appendChild(head);
  box.appendChild(body);
  overlay.appendChild(box);
  document.body.appendChild(overlay);

  // auto close after 3 seconds
  setTimeout(close, 3000);
})();
</script>

