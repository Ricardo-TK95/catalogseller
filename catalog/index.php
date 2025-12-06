<?php
declare(strict_types=1);

// Compat: evita aviso se a constante não existir na versão do PHP do servidor
if (!defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    define('JSON_INVALID_UTF8_SUBSTITUTE', 0);
}

if ((isset($_GET['debug']) && $_GET['debug'] !== '') && (isset($_GET['pedtest']) && $_GET['pedtest'] !== '')) {
    header('Content-Type: text/plain; charset=UTF-8');
    // Carregar configurações do config.php
    $configPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';
    if (file_exists($configPath)) {
        require_once $configPath;
        $host = isset($catalog_host) ? $catalog_host : $host;
        $usuario = isset($catalog_usuario) ? $catalog_usuario : $usuario;
        $senha = isset($catalog_senha) ? $catalog_senha : $senha;
        $port = isset($catalog_port) ? $catalog_port : $port;
        $banco = isset($catalog_banco) ? $catalog_banco : $banco;
    } else {
        $host = 'localhost';
        $usuario = 'root';
        $senha = 'jZa47w_2';
        $port = '3306';
        $banco = 'bdteste';
    }
    echo "PED Test desativado\n";
    exit;
}
if (!defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
    define('JSON_PARTIAL_OUTPUT_ON_ERROR', 0);
}


 $__dbg = isset($_GET['debug']) && $_GET['debug'] !== '';
 error_reporting(E_ALL);
 @ini_set('display_errors', $__dbg ? '1' : '0');
 @ini_set('display_startup_errors', $__dbg ? '1' : '0');
 @ini_set('log_errors', '1');

 @ini_set('error_log', __DIR__ . DIRECTORY_SEPARATOR . 'php-error.log');

 set_exception_handler(function(Throwable $e) use ($__dbg) {
     http_response_code(500);
     if ($__dbg) {
         echo 'Fatal exception: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
     }
     exit;
 });

 set_error_handler(function(int $severity, string $message, string $file = '', int $line = 0) use ($__dbg) {
    if (!(error_reporting() & $severity)) { return false; }
    $fatalLevels = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (in_array($severity, $fatalLevels, true)) {
        http_response_code(500);
    }
    if ($__dbg) {
        echo 'PHP error: ' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' in ' . $file . ':' . $line;
    }
    return true;
});

 register_shutdown_function(function() use ($__dbg) {
     $err = error_get_last();
     if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
         http_response_code(500);
         if ($__dbg) {
             echo 'Fatal shutdown: ' . htmlspecialchars($err['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' in ' . $err['file'] . ':' . $err['line'];
         }
     }
 });


 if ((isset($_GET['debug']) && $_GET['debug'] !== '') && (isset($_GET['ping']) && $_GET['ping'] !== '')) {
     header('Content-Type: text/plain; charset=UTF-8');
     $elog = ini_get('error_log');
     $elog_custom = __DIR__ . DIRECTORY_SEPARATOR . 'php-error.log';
     echo "OK: index.php reached\n";
     echo "PHP: " . PHP_VERSION . " (" . PHP_SAPI . ")\n";
     echo "display_errors: " . ini_get('display_errors') . "\n";
     echo "log_errors: " . ini_get('log_errors') . "\n";
     echo "error_log (ini): " . ($elog !== false ? $elog : '(false)') . "\n";
     echo "error_log (custom target): " . $elog_custom . "\n";
     echo "dir writable (__DIR__): " . (is_writable(__DIR__) ? 'yes' : 'no') . "\n";
     echo "file writable (php-error.log): " . (is_writable(__DIR__) ? 'yes' : 'no') . "\n";
     echo "ext mbstring: " . (extension_loaded('mbstring') ? 'yes' : 'no') . "\n";
     echo "ext iconv: " . (extension_loaded('iconv') ? 'yes' : 'no') . "\n";
     echo "ext pdo_mysql: " . (extension_loaded('pdo_mysql') ? 'yes' : 'no') . "\n";
     echo "ini file: " . (php_ini_loaded_file() ?: 'unknown') . "\n";
     exit;
 }
 if ((isset($_GET['debug']) && $_GET['debug'] !== '') && (isset($_GET['phpinfo']) && $_GET['phpinfo'] !== '')) {
     phpinfo();
     exit;
 }
if ((isset($_GET['debug']) && $_GET['debug'] !== '') && (isset($_GET['dbtest']) && $_GET['dbtest'] !== '')) {
    header('Content-Type: text/plain; charset=UTF-8');
    // Carregar configurações do config.php
    $configPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';
    if (file_exists($configPath)) {
        require_once $configPath;
        $host = isset($catalog_host) ? $catalog_host : $host;
        $usuario = isset($catalog_usuario) ? $catalog_usuario : $usuario;
        $senha = isset($catalog_senha) ? $catalog_senha : $senha;
        $port = isset($catalog_port) ? $catalog_port : $port;
        $banco = isset($catalog_banco) ? $catalog_banco : $banco;
    } else {
        $host = 'localhost';
        $usuario = 'root';
        $senha = 'jZa47w_2';
        $port = '3306';
        $banco = 'bdteste';
    }
    $tableName = 'products'; // Tabela nova
    echo "DB Test (MySQL)\n";
    echo "Server: $host:$port\n";
    echo "Database: $banco\n";
    $dsn = "mysql:host={$host};port={$port};dbname={$banco};charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, $usuario, $senha, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo "Connect: ok\n";
        try {
            $stmt = $pdo->query('SELECT * FROM `'.$tableName.'` LIMIT 1');
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            echo "Query: ok\n";
            if ($row !== false) {
                echo "Row: " . json_encode(array_keys($row)) . "\n";
            } else {
                echo "Row: none\n";
            }
        } catch (Throwable $qe) {
            echo "Query error: " . $qe->getMessage() . "\n";
        }
    } catch (Throwable $e) {
        echo "Connect error: " . $e->getMessage() . "\n";
        echo "Hint: Verifique host, porta, credenciais e se a extensão pdo_mysql está habilitada.\n";
    }
    exit;
 }
 unset($__dbg);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Carregar configurações do banco de dados do config.php
$configPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';
if (!file_exists($configPath)) {
    die('Arquivo config.php não encontrado. Verifique o caminho: ' . htmlspecialchars($configPath, ENT_QUOTES, 'UTF-8'));
}
require_once $configPath;

// Configurações MySQL - usar variáveis do config.php
$preloadItems = [];
$clienteNomeFromOrder = '';
$clienteEmailFromOrder = '';
$clienteTelFromOrder = '';
// Usar variáveis do config.php (catalog_* para o catálogo)
$host = isset($catalog_host) ? $catalog_host : $host;
$usuario = isset($catalog_usuario) ? $catalog_usuario : $usuario;
$senha = isset($catalog_senha) ? $catalog_senha : $senha;
$port = isset($catalog_port) ? $catalog_port : $port;
$banco = isset($catalog_banco) ? $catalog_banco : $banco;
$tableName = 'products'; // Tabela nova: products (em vez de pnb_zap_produtos)

// Parâmetros de filtro
$filtroFabricante = isset($_GET['fabricante']) ? trim((string)$_GET['fabricante']) : '';
$filtroAgrupador = isset($_GET['agrupador']) ? trim((string)$_GET['agrupador']) : '';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
// Flag para exibir todos os fabricantes com produtos (clicando na caixa "TODOS")
$mostrarTodos = isset($_GET['all']) && $_GET['all'] !== '';
// Empresa (classificação) opcional: quando presente, filtra por coluna 'empresa'
$filtroEmpresa = isset($_GET['empresa']) ? trim((string)$_GET['empresa']) : '';
// Regras especiais de empresa
$__empresaParam = lower($filtroEmpresa);
// Quando a seção selecionada for "TODOS" no select (valor __ALL__), manter um sinalizador para expandir todas as seções
$expandAllSections = (isset($_GET['agrupador']) && $_GET['agrupador'] === '__ALL__');
// Se nenhum filtro foi enviado (primeiro carregamento), abrir todas as seções por padrão
if (!$expandAllSections) {
    $noFilters = (count($_GET) === 0) || (
      !isset($_GET['agrupador']) && !isset($_GET['fabricante']) && !isset($_GET['q']) && !isset($_GET['all'])
    );
    if ($noFilters) {
      $expandAllSections = true; // abre todos os <details>
    }
}
$filtroAgrupador = ($filtroAgrupador === '__ALL__') ? '' : $filtroAgrupador;

// Helpers
function h($s): string {
    if (!is_string($s)) { $s = (string)$s; }
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function lower($s): string {
    if (!is_string($s)) { $s = (string)$s; }
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function onlyDigits(string $s): string {
    return preg_replace('/\D+/', '', $s) ?? '';
}

function codeDisplay(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    // Se houver separador decimal, usa apenas os dígitos antes dele
    if (preg_match('/(\d+)(?=[\.,])/', $s, $m)) {
        $code = $m[1];
    } else if (preg_match('/\d+/', $s, $m)) {
        // Caso contrário, primeira sequência contínua de dígitos
        $code = $m[0];
    } else {
        $code = onlyDigits($s);
    }
    
    // Preencher com zeros à esquerda para sempre ter 4 dígitos
    return str_pad($code, 4, '0', STR_PAD_LEFT);
}
function fixText($v) {
    if (!is_string($v)) return $v;
    $s = $v;
    // Se não é UTF-8 válido, assumir Windows-1252 e converter
    if (!function_exists('mb_check_encoding') || !mb_check_encoding($s, 'UTF-8')) {
        if (function_exists('iconv')) {
            $s = @iconv('Windows-1252', 'UTF-8//IGNORE', $s) ?: $s;
        }
    }
    // Corrigir mojibake comum: sequências com 'Ã' (ex.: "SinhÃ¡")
    if (strpos($s, 'Ã') !== false && function_exists('iconv')) {
        $tmp = @iconv('UTF-8', 'Windows-1252//IGNORE', $s);
        if ($tmp !== false) {
            $s2 = @iconv('Windows-1252', 'UTF-8//IGNORE', $tmp);
            if ($s2 !== false) $s = $s2;
        }
    }
    return $s;
}
function normalize(string $s): string {
    $s = lower(trim($s));
    $map = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c'];
    $s = strtr($s, $map);
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
}
function quoteIdent(string $name): string {
    $name = trim($name);
    if ($name === '') return $name;
    if ($name[0] === '[' && substr($name, -1) === ']') return $name;
    return '['.$name.']';
}

// JSON encoder seguro para embutir em JS (retorna literal JSON)
function safe_json($v): string {
    try {
        $json = json_encode(
            $v,
            JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false || $json === null) {
            return is_array($v) ? '[]' : 'null';
        }
        return $json;
    } catch (Throwable $e) {
        return is_array($v) ? '[]' : 'null';
    }
}

function numval($v): ?float {
    if ($v === null) return null;
    if (is_numeric($v)) return (float)$v;
    if (is_string($v)) {
        $s = trim($v);
        if ($s === '') return null;
        $s = str_replace([' ', '.'], ['', ''], $s);
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float)$s : null;
    }
    return null;
}

// Evita alias redundante no Access (que pode causar "circular reference")
function selCol(string $src, string $alias): string {
    if (strcasecmp($src, $alias) === 0) {
        return quoteIdent($src);
    }
    return quoteIdent($src) . ' AS ' . $alias;
}

function hasPdoMysql(): bool { return extension_loaded('pdo_mysql'); }

function pdoMysql(string $host, string $port, string $db, string $user, string $pass): ?PDO {
    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return $pdo;
    } catch (Throwable $e) {
        return null;
    }
}

$erro = '';
$dados = [];
$fabricantes = [];
$agrupadores = [];
$agrupadoresAll = [];
$vendedores = [];
$debug = isset($_GET['debug']) && $_GET['debug'] !== '';

if (!hasPdoMysql()) {
    $erro = 'Extensão pdo_mysql do PHP não está habilitada. Habilite pdo_mysql no php.ini.';
} else {
    $pdo = pdoMysql($host, $port, $banco, $usuario, $senha);
    if (!$pdo) {
        $erro = 'Falha ao conectar ao MySQL. Verifique host, porta, credenciais e banco de dados.';
    } else {
        // Verificar se a empresa requer senha de catálogo
        $current_company_id = isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : 1;
        $company_slug = '';
        if (isset($_GET['slug']) && $_GET['slug'] !== '') {
            // Buscar company_id pelo slug
            $slug = trim((string)$_GET['slug']);
            $company_slug = $slug; // Usar o slug da URL
            $dsn_cata = "mysql:host={$host};port={$port};dbname={$banco};charset=utf8mb4";
            $pdo_cata = new PDO($dsn_cata, $usuario, $senha, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $stmt_comp = $pdo_cata->prepare('SELECT id FROM companies WHERE slug = ? AND is_active = 1 LIMIT 1');
            $stmt_comp->execute([$slug]);
            $comp_row = $stmt_comp->fetch(PDO::FETCH_ASSOC);
            if ($comp_row && isset($comp_row['id'])) {
                $current_company_id = (int)$comp_row['id'];
                $_SESSION['company_id'] = $current_company_id;
            }
            $pdo_cata = null;
        } else {
            // Se não houver slug na URL, buscar pelo company_id
            try {
                $stmt_slug = $pdo->prepare('SELECT slug FROM companies WHERE id = ? AND is_active = 1 LIMIT 1');
                $stmt_slug->execute([$current_company_id]);
                $slug_row = $stmt_slug->fetch(PDO::FETCH_ASSOC);
                if ($slug_row && isset($slug_row['slug']) && $slug_row['slug'] !== '') {
                    $company_slug = trim((string)$slug_row['slug']);
                }
            } catch (Throwable $e) {
                // Ignorar erro
            }
        }
        if ($current_company_id <= 0) {
            $current_company_id = 1;
        }
        // Definir nome da pasta: usar slug da tabela companies (obrigatório)
        // Se não houver slug, não será possível carregar imagens
        $img_folder_name = $company_slug !== '' ? $company_slug : '';
        // Resolver logo para uso como background suave no topo
        $logo_bg_url = '';
        if ($company_slug !== '') {
            foreach (['png','jpg','jpeg','svg','webp'] as $ext) {
                $abs = dirname(__DIR__) . DIRECTORY_SEPARATOR . $company_slug . DIRECTORY_SEPARATOR . 'logo.' . $ext;
                if (file_exists($abs)) { $logo_bg_url = '../' . $company_slug . '/logo.' . $ext; break; }
            }
        }
        if ($logo_bg_url === '') {
            $cid = isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : 0;
            if ($cid > 0) {
                $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'img_logo';
                if (is_dir($dir)) {
                    foreach (glob($dir . DIRECTORY_SEPARATOR . 'logo_' . $cid . '.*') as $file) {
                        $logo_bg_url = '../img_logo/' . basename($file);
                        break;
                    }
                }
            }
        }
        
        // Verificar se catalog_req_password = 1 para esta empresa
        $requiresPassword = false;
        try {
            $stmt_req = $pdo->prepare('SELECT catalog_req_password FROM companies WHERE id = ? AND is_active = 1 LIMIT 1');
            $stmt_req->execute([$current_company_id]);
            $comp_req = $stmt_req->fetch(PDO::FETCH_ASSOC);
            if ($comp_req && isset($comp_req['catalog_req_password'])) {
                $requiresPassword = ((int)$comp_req['catalog_req_password'] === 1);
            }
        } catch (Throwable $e) {
            // Se o campo não existir, assumir que não requer senha
            $requiresPassword = false;
        }
        
        $authOk = (isset($_SESSION['auth_ok']) && $_SESSION['auth_ok'] === true);
        // Só verificar autenticação se a empresa requer senha
        if ($requiresPassword && !$authOk) {
            $authErr = '';
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_key'])) {
                $k = onlyDigits((string)$_POST['auth_key']);
                $k = substr($k, 0, 5);
                if ($k !== '') {
                    try {
                        // Buscar loja na tabela stores por chave de 5 dígitos
                        $stmt = $pdo->prepare('SELECT id, cod, store_name, email, phone FROM stores WHERE `key_access` = ? AND is_active = 1 AND company_id = ? AND (`key_validate` IS NULL OR `key_validate` >= CURDATE()) LIMIT 1');
                        $stmt->execute([$k, $current_company_id]);
                        $cli = $stmt->fetch(PDO::FETCH_ASSOC);
                        if (is_array($cli)) {
                            $_SESSION['auth_ok'] = true;
                            $_SESSION['store_id'] = $cli['id'] ?? null;
                            $_SESSION['store_cod'] = isset($cli['cod']) ? (string)$cli['cod'] : null;
                            $_SESSION['store_name'] = isset($cli['store_name']) ? (string)$cli['store_name'] : null;
                            $_SESSION['store_email'] = isset($cli['email']) ? (string)$cli['email'] : null;
                            // WhatsApp do cliente, se desejar usar posteriormente
                            if (isset($cli['phone'])) {
                                $wh = onlyDigits((string)$cli['phone']);
                                $_SESSION['store_whats'] = $wh !== '' ? $wh : null;
                            }
                            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '#'));
                            exit;
                        } else {
                            $authErr = 'Chave inválida, expirada ou loja inativa.';
                        }
                    } catch (Throwable $e) {
                        $authErr = 'Erro ao validar a chave.';
                    }
                } else {
                    $authErr = 'Informe a chave.';
                }
            }
            // Tentar localizar logo da empresa via slug (../{slug}/logo.{png|jpg|jpeg|svg|webp})
            $logoSrc = '';
            if ($company_slug !== '') {
                foreach (['png','jpg','jpeg','svg','webp'] as $ext) {
                    $abs = dirname(__DIR__) . DIRECTORY_SEPARATOR . $company_slug . DIRECTORY_SEPARATOR . 'logo.' . $ext;
                    if (file_exists($abs)) { $logoSrc = '../' . $company_slug . '/logo.' . $ext; break; }
                }
            }
            // Fallback: procurar em img_logo/logo_<company_id>.* na raiz do app
            if ($logoSrc === '') {
                $cid = isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : 0;
                if ($cid > 0) {
                  $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'img_logo';
                  if (is_dir($dir)) {
                    foreach (glob($dir . DIRECTORY_SEPARATOR . 'logo_' . $cid . '.*') as $file) {
                      $base = basename($file);
                      $logoSrc = '../img_logo/' . $base;
                      break;
                    }
                  }
                }
            }
            $invalidCls = ($authErr !== '') ? ' invalid' : '';
            // Language: read from ?lang=, fallback to Accept-Language, default pt
            $lang = 'pt';
            if (isset($_GET['lang']) && ($_GET['lang'] === 'en' || $_GET['lang'] === 'pt')) {
                $lang = $_GET['lang'];
            } else {
                $al = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? strtolower((string)$_SERVER['HTTP_ACCEPT_LANGUAGE']) : '';
                if (strpos($al, 'en') === 0) { $lang = 'en'; }
            }
            // Texts
            $t = [
                'pt' => [
                    'h1' => 'Acesso aos produtos.',
                    'subtitle' => 'Informe a chave de 5 dígitos enviada ao seu e-mail ou WhatsApp para acessar com segurança todo o nosso catálogo de produtos.',
                    'label' => 'Chave de acesso',
                    'help' => 'Não compartilhe sua chave. Ela expira em breve.',
                    'button' => 'Entrar',
                    'switch' => 'English'
                ],
                'en' => [
                    'h1' => 'Access to products.',
                    'subtitle' => 'Enter the 5-digit code sent to your email or WhatsApp to securely access our product catalog.',
                    'label' => 'Access code',
                    'help' => "Do not share your code. It will expire soon.",
                    'button' => 'Enter',
                    'switch' => 'Português'
                ],
            ];
            $tx = $t[$lang];
            // Build language switch URL preserving current query params
            $qs = $_GET; $qs['lang'] = ($lang === 'pt') ? 'en' : 'pt';
            $base = strtok($_SERVER['REQUEST_URI'], '?');
            $switchUrl = $base . '?' . http_build_query($qs);
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
            echo '<title>Geração de Chave de Acesso e Atualiação da Tabela de Preços</title>';
            echo '<style>body{background:#0b1220;color:#e5e7eb;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif} .box{max-width:520px;margin:12vh auto;background:#111827;border:1px solid #1f2937;border-radius:16px;padding:26px 24px 22px;box-shadow:0 10px 30px rgba(0,0,0,.4);position:relative} .lang-switch{position:absolute;top:10px;right:14px;font-size:.85rem} .lang-switch a{color:#67e8f9;text-decoration:none} .lang-switch a:hover{text-decoration:underline} h1{font-size:1.2rem;margin:8px 0 10px;color:#e5e7eb} .muted{color:#94a3b8;font-size:.95rem;margin-bottom:18px;line-height:1.5} .logo-top{display:block;margin:0 auto 12px;max-height:96px;width:auto;background:#fff;border-radius:12px;padding:10px;border:1px solid rgba(148,163,184,.25)} label{display:block;text-align:center;color:#cbd5e1;font-weight:600;margin:0 0 8px} #auth_key{width:220px;max-width:100%;display:block;margin:0 auto;background:#0b1220;color:#e5e7eb;border:1px solid #1f2937;border-radius:10px;padding:10px 12px;font-size:1.2rem;letter-spacing:6px;text-align:center;outline:none;transition:border-color .15s, box-shadow .15s} #auth_key:focus{border-color:#22d3ee;box-shadow:0 0 0 3px rgba(34,211,238,.15)} .invalid{border-color:#f87171 !important;box-shadow:0 0 0 3px rgba(248,113,113,.15) !important} .help{color:#9ca3af;font-size:.85rem;text-align:center;margin:8px 0 0} .actions{margin-top:16px} button{width:100%;background:#22d3ee;color:#0b1220;border:0;border-radius:10px;padding:12px 10px;font-weight:800;cursor:pointer;font-size:1rem;transition:opacity .15s, transform .02s} button:disabled{opacity:.6;cursor:not-allowed} button:active{transform:translateY(1px)} .err{color:#fca5a5;margin-top:12px;font-size:.9rem;text-align:center} .foot{margin-top:16px;text-align:center;color:#94a3b8;font-size:.8rem} .foot a{color:#67e8f9;text-decoration:none} .foot a:hover{text-decoration:underline}</style></head><body>';
            echo '<div class="box">';
            if ($logoSrc !== '') { echo '<img src="'.h($logoSrc).'" alt="Logo" class="logo-top" />'; }
            echo '<div class="lang-switch"><a href="'.h($switchUrl).'">'.h($tx['switch']).'</a></div>';
            echo '<h1>'.h($tx['h1']).'</h1><div class="muted">'.h($tx['subtitle']).'</div>';
            echo '<form method="post" id="authForm">';
            echo '<label for="auth_key">'.h($tx['label']).'</label>';
            echo '<input type="tel" name="auth_key" id="auth_key" class="codeinput'. $invalidCls .'" inputmode="numeric" autocomplete="one-time-code" placeholder="00000" pattern="\\d{5}" maxlength="5" autofocus required ' . ($authErr !== '' ? 'aria-invalid="true" aria-describedby="err"' : '') . ' />';
            echo '<div class="help" id="help">'.h($tx['help']).'</div>';
            echo '<div class="actions"><button type="submit" id="submitBtn" disabled>'.h($tx['button']).'</button></div>';
            echo '</form>';
            if ($authErr !== '') { echo '<div class="err" id="err">'.h($authErr).'</div>'; }
            echo '<div class="foot">by <a href="https://catalogseller.com" target="_blank" rel="noopener">CatalogSeller.com</a></div>';
            echo '<script>(function(){var i=document.getElementById("auth_key");var b=document.getElementById("submitBtn");function clean(v){return (v||"").replace(/\D+/g,"").slice(0,5);}function update(){i.value=clean(i.value);b.disabled=i.value.length!==5;}i.addEventListener("input",update);i.addEventListener("paste",function(e){var t=(e.clipboardData||window.clipboardData).getData("text")||"";t=clean(t);if(t){e.preventDefault();i.value=t;update();}});update();if(i.classList.contains("invalid")){i.focus();i.select();}document.getElementById("authForm").addEventListener("submit",function(){update();if(i.value.length!==5){b.disabled=true;return false;}b.disabled=true;b.textContent="Entrando…";});})();</script>';
            echo '</div></body></html>';
            exit;
        }
        // Atualizar sempre o modo de envio (zap_email) e WhatsApp do vendedor autenticado a cada requisição
        try {
            if (isset($_SESSION['vendedor_id']) && $_SESSION['vendedor_id']) {
                // Obter company_id da sessão
                $current_company_id = isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : 1;
                if ($current_company_id <= 0) {
                    $current_company_id = 1;
                }
                
                // Buscar telefone do vendedor na tabela sales_reps (nova tabela)
                $stmtW = $pdo->prepare('SELECT phone AS telefone, contact_mode FROM sales_reps WHERE id = ? AND company_id = ? LIMIT 1');
                $stmtW->execute([$_SESSION['vendedor_id'], $current_company_id]);
                $rowVend = $stmtW->fetch(PDO::FETCH_ASSOC);
                if (is_array($rowVend)) {
                    if (isset($rowVend['telefone'])) {
                        $wh = onlyDigits((string)$rowVend['telefone']);
                        $_SESSION['vendedor_whats'] = $wh !== '' ? $wh : null;
                    }
                    if (isset($rowVend['contact_mode'])) {
                        $mode = strtoupper(trim((string)$rowVend['contact_mode']));
                        $_SESSION['vendedor_envio'] = ($mode === 'Z') ? 'Z' : 'E';
                    }
                }
            }
        } catch (Throwable $_) { /* ignore */ }

        // Fallback: quando não há vendedor autenticado e nenhum e-mail configurado na sessão,
        // usar automaticamente o primeiro vendedor ativo da empresa atual
        try {
            if (!isset($_SESSION['vendedor_email']) || !$_SESSION['vendedor_email']) {
                // Obter company_id da sessão
                $current_company_id = isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : 1;
                if ($current_company_id <= 0) { $current_company_id = 1; }
                $defVend = null;
                // 1) Tentar com coluna is_active (quando existir)
                try {
                    $stmt_def = $pdo->prepare('SELECT id, full_name AS nome, email, phone AS telefone, contact_mode FROM sales_reps WHERE company_id = ? AND is_active = 1 AND email IS NOT NULL AND email <> "" ORDER BY id LIMIT 1');
                    $stmt_def->execute([$current_company_id]);
                    $defVend = $stmt_def->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Throwable $__) {
                    $defVend = null;
                }
                // 2) Fallback sem is_active (tabelas antigas)
                if (!$defVend) {
                    try {
                        $stmt_def2 = $pdo->prepare('SELECT id, full_name AS nome, email, phone AS telefone, contact_mode FROM sales_reps WHERE company_id = ? AND (email IS NOT NULL AND email <> "") ORDER BY id LIMIT 1');
                        $stmt_def2->execute([$current_company_id]);
                        $defVend = $stmt_def2->fetch(PDO::FETCH_ASSOC) ?: null;
                    } catch (Throwable $__2) { $defVend = null; }
                }
                // 3) Fallback geral: sem filtro de company_id (quando a coluna não é preenchida)
                if (!$defVend) {
                    try {
                        $stmt_def3 = $pdo->query('SELECT id, full_name AS nome, email, phone AS telefone, contact_mode FROM sales_reps WHERE (email IS NOT NULL AND email <> "") ORDER BY id LIMIT 1');
                        $defVend = $stmt_def3 ? ($stmt_def3->fetch(PDO::FETCH_ASSOC) ?: null) : null;
                    } catch (Throwable $__3) { $defVend = null; }
                }
                if ($defVend && isset($defVend['email']) && trim((string)$defVend['email']) !== '') {
                    $_SESSION['vendedor_id'] = $defVend['id'] ?? null;
                    $_SESSION['vendedor_nome'] = isset($defVend['nome']) ? (string)$defVend['nome'] : null;
                    $_SESSION['vendedor_email'] = (string)$defVend['email'];
                    // Definir modo de envio padrão a partir de zap_email quando disponível
                    if (isset($defVend['contact_mode'])) {
                        $mode = strtoupper(trim((string)$defVend['contact_mode']));
                        $_SESSION['vendedor_envio'] = ($mode === 'Z') ? 'Z' : 'E';
                    } else {
                        $_SESSION['vendedor_envio'] = 'E';
                    }
                    if (isset($defVend['telefone'])) {
                        $wh = preg_replace('/\D+/', '', (string)$defVend['telefone']);
                        $_SESSION['vendedor_whats'] = $wh !== '' ? $wh : null;
                    }
                }
            }
        } catch (Throwable $_) { /* ignore */ }

        // Preload de itens do pedido via parâmetro ?pedido=<id>, ?cod_ped=<id>, ?id_ped=<id> ou ?id_pedido=<id>
        $preloadItems = [];
        $orderParam = '';
        if (isset($_GET['pedido']) && trim((string)$_GET['pedido']) !== '') {
            $orderParam = (string)$_GET['pedido'];
        } elseif (isset($_GET['cod_ped']) && trim((string)$_GET['cod_ped']) !== '') {
            $orderParam = (string)$_GET['cod_ped'];
        } elseif (isset($_GET['id_ped']) && trim((string)$_GET['id_ped']) !== '') {
            $orderParam = (string)$_GET['id_ped'];
        } elseif (isset($_GET['id_pedido']) && trim((string)$_GET['id_pedido']) !== '') {
            $orderParam = (string)$_GET['id_pedido'];
        }
        if ($orderParam !== '') {
            // Ao reabrir por ID de pedido, limpar filtros de busca/seleção
            $q = '';
            $filtroFabricante = '';
            $pedidoId = preg_replace('/\D+/', '', $orderParam);
            if ($pedidoId !== '') {
                try {
                    if ($debug) { error_log('Preload pedido: ' . $pedidoId); }
                    $sqlPre = 'SELECT `Empresa`,`Qtde_Comprada`,`Codigo_do_Produto`,`Descricao_do_Produto`,`Valor_do_Produto` FROM `pnb_zap_pedidos_itens` WHERE `Codigo_do_Pedido` = ? ORDER BY `ID` ASC';
                    $stmt = $pdo->prepare($sqlPre);
                    $stmt->execute([$pedidoId]);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!is_array($rows)) { $rows = []; }
                    if ($debug) { error_log('Preload pedido rows: ' . count($rows)); }
                    foreach ($rows as $r) {
                        $qRaw = isset($r['Qtde_Comprada']) ? $r['Qtde_Comprada'] : 0;
                        $q = (int) (is_numeric($qRaw) ? $qRaw : 0);
                        if ($q <= 0) { $q = 1; }
                        $linhaRaw = isset($r['Valor_do_Produto']) ? $r['Valor_do_Produto'] : 0;
                        $linha = (float) (is_numeric($linhaRaw) ? $linhaRaw : 0.0);
                        $unit = ($q > 0 && is_finite($linha)) ? ($linha / $q) : 0.0;
                        $code = (string)($r['Codigo_do_Produto'] ?? '');
                        $code = preg_replace('/\D+/', '', $code) ?? '';
                        $code = str_pad($code, 4, '0', STR_PAD_LEFT);
                        $name = fixText((string)($r['Descricao_do_Produto'] ?? ''));
                        $emp  = fixText((string)($r['Empresa'] ?? ''));
                        $preloadItems[] = [ 'c'=>$code, 'q'=>$q, 'p'=>round($unit,2), 'n'=>$name, 'e'=>$emp ];
                    }
                    // Carregar também os dados do cliente do log do pedido
                    try {
                        $stmtCli = $pdo->prepare('SELECT `cliente_nome`,`cliente_email`,`cliente_tel` FROM `pnb_zap_log_pedidos` WHERE `id` = ? LIMIT 1');
                        $stmtCli->execute([$pedidoId]);
                        $cliRow = $stmtCli->fetch(PDO::FETCH_ASSOC);
                        if (is_array($cliRow)) {
                            $clienteNomeFromOrder = fixText((string)($cliRow['cliente_nome'] ?? ''));
                            $clienteEmailFromOrder = trim((string)($cliRow['cliente_email'] ?? ''));
                            $clienteTelFromOrder = trim((string)($cliRow['cliente_tel'] ?? ''));
                        }
                    } catch (Throwable $_) { /* ignore */ }

                    // Fallback: se não houver itens gravados na tabela de itens, tentar payload_json do log do pedido
                    if (empty($preloadItems)) {
                        try {
                            if ($debug) { error_log('Preload fallback from log payload for pedido: ' . $pedidoId); }
                            $stmt2 = $pdo->prepare('SELECT `payload_json` FROM `pnb_zap_log_pedidos` WHERE `id` = ? LIMIT 1');
                            $stmt2->execute([$pedidoId]);
                            $payload = (string)($stmt2->fetchColumn());
                            if ($payload) {
                                $data = json_decode($payload, true);
                                if (!is_array($data) || !isset($data['items'])) {
                                    // Tenta urldecode -> JSON
                                    $try = urldecode($payload);
                                    $data = json_decode($try, true);
                                }
                                if (!is_array($data) || !isset($data['items'])) {
                                    // Tenta remover escapes e decodificar
                                    $try = stripslashes($payload);
                                    $data = json_decode($try, true);
                                }
                                if (is_array($data) && isset($data['items']) && is_array($data['items'])) {
                                    foreach ($data['items'] as $it) {
                                        $c = isset($it['c']) ? preg_replace('/\D+/', '', (string)$it['c']) : '';
                                        $c = $c !== '' ? str_pad($c, 4, '0', STR_PAD_LEFT) : '';
                                        $q = isset($it['q']) ? (int)$it['q'] : 0;
                                        if ($q <= 0) { $q = 1; }
                                        $p = isset($it['p']) ? (float)$it['p'] : 0.0;
                                        $n = isset($it['n']) ? fixText((string)$it['n']) : '';
                                        $e = isset($it['e']) ? fixText((string)$it['e']) : '';
                                        if ($c !== '') { $preloadItems[] = ['c'=>$c,'q'=>$q,'p'=>round($p,2),'n'=>$n,'e'=>$e]; }
                                    }
                                }
                            }
                        } catch (Throwable $e2) {
                            if ($debug) { error_log('Preload fallback error: '.$e2->getMessage()); }
                        }
                    }
                } catch (Throwable $e) {
                    error_log('pedido preload error: '.$e->getMessage());
                    $preloadItems = [];
                }
            }
        }

        // Obter company_id do slug ou da sessão
        $current_company_id = isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : 1;
        if (isset($_GET['slug']) && $_GET['slug'] !== '') {
            $slug = trim((string)$_GET['slug']);
            try {
                $stmt_comp = $pdo->prepare('SELECT id FROM companies WHERE slug = ? AND is_active = 1 LIMIT 1');
                $stmt_comp->execute([$slug]);
                $comp_row = $stmt_comp->fetch(PDO::FETCH_ASSOC);
                if ($comp_row && isset($comp_row['id'])) {
                    $current_company_id = (int)$comp_row['id'];
                    $_SESSION['company_id'] = $current_company_id;
                }
            } catch (Throwable $_) {
                // Ignorar erro, usar company_id padrão
            }
        }
        if ($current_company_id <= 0) {
            $current_company_id = 1;
        }

        // Query principal de produtos usando as novas tabelas (products, manufacturers, sections)
        try {
            $sqlRaw = 'SELECT p.id, p.cod AS codigo, p.description AS produto, 
                              p.real_value AS vda_real, 
                              COALESCE(p.unit_value, CASE WHEN p.box_qty IS NOT NULL AND p.box_qty > 0 THEN p.real_value / p.box_qty ELSE NULL END) AS preco_varejo,
                              m.description AS fabricante,
                              s.description AS agrupador,
                              s.display_order AS ordem,
                              p.unit AS un,
                              p.company_id AS empresa,
                              p.is_great_deal
                       FROM `'.$tableName.'` p 
                       LEFT JOIN manufacturers m ON m.id = p.manufacturer_id AND m.company_id = p.company_id AND m.is_active = 1
                       LEFT JOIN sections s ON s.id = p.section_id AND s.company_id = p.company_id AND s.is_active = 1
                       WHERE p.company_id = ? 
                         AND p.is_active = 1
                         AND (p.real_value IS NOT NULL AND p.real_value > 0)';
            
            // Aplicar filtro de empresa (se ainda necessário para compatibilidade)
            if ($filtroEmpresa !== '') {
                // Manter compatibilidade com filtro antigo, mas usar company_id como principal
                // O filtro de empresa antigo pode ser ignorado se company_id já estiver definido
            }
            
            $stmtRaw = $pdo->prepare($sqlRaw);
            $stmtRaw->execute([$current_company_id]);
            $raw = $stmtRaw->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $raw = [];
            $erro = 'Erro ao ler a fonte '.$tableName.': '.$e->getMessage();
            if ($debug) {
                error_log('Erro na query de produtos: ' . $e->getMessage());
            }
        }

        if (!$erro && $raw) {
            // Descobrir mapeamento de colunas por normalização
            $headers = array_keys($raw[0]);
            $norm = function(string $s): string {
                $s = normalize($s);
                $s = str_replace(['  ', '   '], ' ', $s);
                return trim($s);
            };
            $desired = [
                'codigo'       => ['codigo','cod','sku','código','cdigo'],
                'produto'      => ['produto','descricao','descrição','nome'],
                'vda_real'     => ['vda real c/ desc','vda real c desc','vda_real_c_desc','vda real desc','vdareal c desc','vdareal c/ desc','vda real com desconto','vda_real_desc','vda real','venda real','vda_real','vda'],
                'preco_varejo' => ['preco varejo','preco','preco_varejo','preco venda','preço varejo','preço','preo varejo','preo'],
                'fabricante'   => ['fabricante','marca','fornecedor'],
                // coluna opcional para classificação por empresa
                'empresa'      => ['empresa','classificacao','classificação','grupo'],
                // agrupador vindo do join
                'agrupador'    => ['agrupador','grupo','agr'],
                // ordem opcional do agrupador
                'ordem'        => ['ordem','ord','ordem agrupador','ordem_agrupador'],
                'un'           => ['un','unidade','unid','und','uni','quantidade','qtd']
            ];
            $mapCols = [];
            foreach ($headers as $h) {
                $hn = $norm($h);
                foreach ($desired as $k => $aliases) {
                    if (isset($mapCols[$k])) continue;
                    foreach ($aliases as $a) {
                        if ($hn === $norm($a)) { $mapCols[$k] = $h; break; }
                    }
                }
            }

            if (!empty($headers)) {
                foreach ($headers as $h) {
                    $hn = $norm($h);
                    $hasVda = strpos($hn, 'vda') !== false;
                    $hasReal = strpos($hn, 'real') !== false;
                    $hasDesc = (strpos($hn, 'desc') !== false) || (strpos($hn, 'desconto') !== false);
                    if ($hasVda && $hasReal && $hasDesc) { $mapCols['vda_real'] = $h; break; }
                }
            }
            if (!empty($headers)) {
                foreach ($headers as $h) {
                    $hn = $norm($h);
                    $hasVda = strpos($hn, 'vda') !== false;
                    $hasReal = strpos($hn, 'real') !== false;
                    $hasDesc = (strpos($hn, 'desc') !== false) || (strpos($hn, 'desconto') !== false);
                    if ($hasVda && $hasReal && !$hasDesc) { $mapCols['vda_orig'] = $h; break; }
                }
            }

            // Segunda passada: heurística por tokens para lidar com caracteres corrompidos (�) e espaços extras
            $strip = function(string $s): string { return preg_replace('/[^a-z0-9]/', '', $s); };
            $tokens = function(string $s): array {
                $s = preg_replace('/[^a-z0-9 ]/',' ', $s);
                $s = preg_replace('/\s+/', ' ', $s);
                return array_filter(explode(' ', trim($s)));
            };
            $tryMap = function(string $key) use (&$mapCols, $headers, $norm, $strip, $tokens) {
                if (isset($mapCols[$key])) return;
                foreach ($headers as $h) {
                    $hn = $norm($h);
                    $hs = $strip($hn);
                    $toks = $tokens($hn);
                    $ok = false;
                    switch ($key) {
                        case 'codigo':
                            $ok = ((strpos($hs, 'codigo') !== false || strpos($hs, 'cdigo') !== false || strpos($hs, 'cod') === 0)
                                   && stripos($hs, 'codimg') === false);
                            break;
                        case 'produto':
                            $ok = strpos($hs, 'produto') !== false || strpos($hs, 'descricao') !== false || strpos($hs, 'descricao') !== false || in_array('produto', $toks, true);
                            break;
                        case 'vda_real':
                            $ok = (
                                (strpos($hs, 'vdareal') !== false && (strpos($hs, 'desc') !== false || strpos($hs, 'desconto') !== false))
                                || ((in_array('vda', $toks, true) && in_array('real', $toks, true) && (in_array('desc', $toks, true) || in_array('desconto', $toks, true))))
                                || (strpos($hs, 'vdareal') !== false)
                            );
                            break;
                        case 'preco_varejo':
                            $ok = strpos($hs, 'precovarejo') !== false || strpos($hs, 'preovarejo') !== false ||
                                  ((strpos($hs, 'preco') !== false || strpos($hs, 'preo') !== false) && strpos($hs, 'varejo') !== false) ||
                                  ((in_array('preco', $toks, true) || in_array('preo', $toks, true)) && in_array('varejo', $toks, true));
                            break;
                        case 'fabricante':
                            $ok = strpos($hs, 'fabricante') !== false || in_array('marca', $toks, true);
                            break;
                        case 'empresa':
                            $ok = strpos($hs, 'empresa') !== false || strpos($hs, 'classificacao') !== false || strpos($hs, 'classificao') !== false || in_array('grupo', $toks, true);
                            break;
                        case 'agrupador':
                            $ok = strpos($hs, 'agrupador') !== false || in_array('grupo', $toks, true) || in_array('agr', $toks, true);
                            break;
                    }
                    if ($ok) { $mapCols[$key] = $h; return; }
                }
            };
            foreach (array_keys($desired) as $k) { $tryMap($k); }
            // Filtrar, projetar e ordenar em PHP
            $filtrados = [];

            foreach ($raw as $r) {
                $row = [
                    'codigo'       => (string)($mapCols['codigo']       ?? '') !== '' && isset($r[$mapCols['codigo']])       ? fixText((string)$r[$mapCols['codigo']])       : '',
                    'produto'      => (string)($mapCols['produto']      ?? '') !== '' && isset($r[$mapCols['produto']])      ? fixText((string)$r[$mapCols['produto']])      : '',
                    'vda_real'     => (string)($mapCols['vda_real']     ?? '') !== '' && isset($r[$mapCols['vda_real']])     ? $r[$mapCols['vda_real']]              : null,
                    'vda_orig'     => (string)($mapCols['vda_orig']     ?? '') !== '' && isset($r[$mapCols['vda_orig']])     ? $r[$mapCols['vda_orig']]              : null,
                    'preco_varejo' => (string)($mapCols['preco_varejo'] ?? '') !== '' && isset($r[$mapCols['preco_varejo']]) ? $r[$mapCols['preco_varejo']]          : null,
                    'fabricante'   => (string)($mapCols['fabricante']   ?? '') !== '' && isset($r[$mapCols['fabricante']])   ? fixText((string)$r[$mapCols['fabricante']])   : '',
                    'empresa'      => (string)($mapCols['empresa']      ?? '') !== '' && isset($r[$mapCols['empresa']])      ? fixText((string)$r[$mapCols['empresa']])      : '',
                    'agrupador'    => (string)($mapCols['agrupador']    ?? '') !== '' && isset($r[$mapCols['agrupador']])    ? fixText((string)$r[$mapCols['agrupador']])    : '',
                    'ordem'        => (string)($mapCols['ordem']        ?? '') !== '' && isset($r[$mapCols['ordem']])        ? $r[$mapCols['ordem']]                  : (isset($r['ordem']) ? $r['ordem'] : null),
                    'un'           => (string)($mapCols['un']           ?? '') !== '' && isset($r[$mapCols['un']])           ? fixText((string)$r[$mapCols['un']])           : '',
                    'is_great_deal' => isset($r['is_great_deal']) ? (int)$r['is_great_deal'] : 0,
                ];
                if ($orderParam === '' && $filtroEmpresa !== '') {
                    $rowEmp = isset($row['empresa']) ? trim((string)$row['empresa']) : '';
                    if ($rowEmp === '' || strcasecmp($rowEmp, $filtroEmpresa) !== 0) { continue; }
                }
                if ($orderParam === '' && $filtroFabricante !== '') {
                    $fabRow = preg_replace('/\s+/', '', normalize((string)$row['fabricante']));
                    $fabSel = preg_replace('/\s+/', '', normalize((string)$filtroFabricante));
                    if ($fabRow !== $fabSel) continue;
                }
                if ($orderParam === '' && $filtroAgrupador !== '' && trim($row['agrupador']) !== $filtroAgrupador) continue;
                if ($orderParam === '' && $q !== '') {
                    $qN = lower($q);
                    $qd = onlyDigits($qN);
                    $codigoDigits = onlyDigits($row['codigo']);
                    $prodNorm = normalize($row['produto']);
                    $qNorm = normalize($qN);
                    $ok = false;
                    // Se a busca contém dígitos, tente bater pelo código numérico (ignorando pontos/traços)
                    if ($qd !== '') {
                        $ok = strpos($codigoDigits, $qd) !== false;
                    }
                    // Também permitir busca textual por produto
                    if (!$ok) {
                        $ok = strpos($prodNorm, $qNorm) !== false;
                    }
                    if (!$ok) continue;
                }
                $filtrados[] = $row;
            }

            // Remover duplicidades por código (preferencial) ou por nome normalizado
            $seen = [];
            $unique = [];
            foreach ($filtrados as $r2) {
                $codeDigits = onlyDigits((string)($r2['codigo'] ?? ''));
                $codeKey = $codeDigits !== '' ? str_pad($codeDigits, 4, '0', STR_PAD_LEFT) : '';
                $nameKey = normalize((string)($r2['produto'] ?? ''));
                $key = $codeKey !== '' ? ('c:' . $codeKey) : ('n:' . $nameKey);
                if (isset($seen[$key])) { continue; }
                $seen[$key] = true;
                $unique[] = $r2;
            }
            $filtrados = $unique;

            usort($filtrados, function($a, $b){
                $oA = isset($a['ordem']) ? (int)numval((string)$a['ordem']) : null;
                $oB = isset($b['ordem']) ? (int)numval((string)$b['ordem']) : null;
                $oA = $oA !== null ? $oA : PHP_INT_MAX;
                $oB = $oB !== null ? $oB : PHP_INT_MAX;
                if ($oA !== $oB) return $oA <=> $oB;
                $agA = isset($a['agrupador']) ? trim((string)$a['agrupador']) : '';
                $agB = isset($b['agrupador']) ? trim((string)$b['agrupador']) : '';
                $c = strcasecmp($agA, $agB);
                if ($c !== 0) return $c;
                return strcasecmp((string)$a['produto'], (string)$b['produto']);
            });
            $dados = $filtrados;
            // Fabricantes distintos (filtrados por empresa, se aplicável)

            $fabricantes = [];
            $agrupadores = [];

            // Carregar fabricantes usando o script SQL fornecido
            try {
                // Query conforme script fornecido pelo usuário
                $sqlManufacturers = "SELECT DISTINCT b.id, b.description
                                    FROM products a
                                    LEFT JOIN manufacturers b ON a.manufacturer_id = b.id
                                    WHERE a.is_active = 1 
                                      AND b.is_active = 1 
                                      AND a.real_value <> '0.00'
                                    ORDER BY b.description";
                $stmtManufacturers = $pdo->prepare($sqlManufacturers);
                $stmtManufacturers->execute();
                $rowsManufacturers = $stmtManufacturers->fetchAll(PDO::FETCH_ASSOC);
                
                if (is_array($rowsManufacturers)) {
                    foreach ($rowsManufacturers as $rowManufacturer) {
                        $desc = trim(fixText((string)($rowManufacturer['description'] ?? '')));
                        if ($desc !== '' && !in_array($desc, $fabricantes, true)) {
                            $fabricantes[] = $desc;
                        }
                    }
                }
            } catch (Throwable $e) {
                if ($debug) {
                    error_log('Erro ao carregar fabricantes: ' . $e->getMessage());
                }
                // Se falhar, tentar coletar dos produtos como fallback
                $fabricantes = [];
            }

            // Carregar seções usando exatamente o script fornecido pelo usuário
            try {
                // Query exata conforme script fornecido
                $sqlSections = "SELECT DISTINCT c.id, c.description 
                                FROM products a
                                LEFT JOIN manufacturers b ON a.manufacturer_id = b.id
                                LEFT JOIN sections c ON a.section_id = c.id
                                WHERE a.is_active = 1 
                                  AND b.is_active = 1
                                  AND a.real_value<>'0.00'
                                ORDER BY c.description";
                
                $stmtSections = $pdo->prepare($sqlSections);
                $stmtSections->execute();
                $rowsSections = $stmtSections->fetchAll(PDO::FETCH_ASSOC);
                
                // Limpar array e preencher apenas com resultados do script SQL
                $agrupadoresAll = [];
                if (is_array($rowsSections) && count($rowsSections) > 0) {
                    foreach ($rowsSections as $rowSection) {
                        $desc = trim((string)($rowSection['description'] ?? ''));
                        if ($desc !== '' && !in_array($desc, $agrupadoresAll, true)) {
                            $agrupadoresAll[] = $desc;
                        }
                    }
                }
                // A query já ordena por description, então não precisa ordenar novamente
            } catch (Throwable $e) {
                if ($debug) {
                    error_log('Erro ao carregar seções: ' . $e->getMessage());
                }
                // fallback: ficará vazio se houver erro
                $agrupadoresAll = [];
            }
            // Se não conseguiu carregar fabricantes da tabela, coletar dos produtos como fallback
            if (empty($fabricantes)) {
                foreach ($raw as $r) {
                    if ($filtroEmpresa !== '' && isset($mapCols['empresa']) && isset($r[$mapCols['empresa']])) {
                        $empVal = trim(fixText((string)$r[$mapCols['empresa']]));
                        if ($empVal === '' || strcasecmp($empVal, $filtroEmpresa) !== 0) {
                            continue;
                        }
                    }
                    if (isset($mapCols['fabricante']) && isset($r[$mapCols['fabricante']])) {
                        $val = trim(fixText((string)$r[$mapCols['fabricante']]));
                        if ($val !== '' && !in_array($val, $fabricantes, true)) $fabricantes[] = $val;
                    }
                }
            }
            
            // Ordenar fabricantes alfabeticamente
            sort($fabricantes, SORT_NATURAL | SORT_FLAG_CASE);
            sort($agrupadores, SORT_NATURAL | SORT_FLAG_CASE);
        }
        
        // Buscar vendedores ativos da tabela sales_reps (nova tabela)
        $vendedores = [];
        try {
            // Obter company_id da sessão
            $current_company_id = isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : 1;
            if ($current_company_id <= 0) {
                $current_company_id = 1;
            }
            
            if ($debug) {
                error_log('Executando consulta: SELECT id, full_name as nome, email, contact_mode FROM sales_reps WHERE is_active = 1 AND company_id = ' . $current_company_id . ' ORDER BY full_name');
            }
            
            $stmt = $pdo->prepare('SELECT id, full_name AS nome, email, contact_mode FROM sales_reps WHERE is_active = 1 AND company_id = ? ORDER BY full_name');
            $stmt->execute([$current_company_id]);
            $vendedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if ($debug) {
                error_log('Vendedores encontrados na tabela: ' . count($vendedores));
                error_log('Dados dos vendedores: ' . json_encode($vendedores, JSON_UNESCAPED_UNICODE));
            }
        } catch (Throwable $e) {
            if ($debug) {
                error_log('Erro ao buscar vendedores: ' . $e->getMessage());
                error_log('Código do erro: ' . $e->getCode());
            }
            // Se falhar, usar array vazio
            $vendedores = [];
        }
    }
}

// Agrupar por agrupador para exibição com quebras
$grupos = [];
if (!$erro && $dados) {
    foreach ($dados as $row) {
        $grp = isset($row['agrupador']) ? trim((string)$row['agrupador']) : '';
        if ($grp === '') { $grp = 'Sem agrupador'; }
        if (!isset($grupos[$grp])) $grupos[$grp] = [];
        $grupos[$grp][] = $row;
    }
}

// Sincronizar opções do combobox: preferir lista completa do SQL; se vazia, usar grupos exibidos
if (!$erro) {
    if (!empty($agrupadoresAll)) {
        $agrupadores = $agrupadoresAll;
    } else {
        $agrupadores = array_keys($grupos);
        sort($agrupadores, SORT_NATURAL | SORT_FLAG_CASE);
    }
}

// Cabeçalhos HTTP simples para evitar cache agressivo enquanto desenvolve
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?>
  <!doctype html>
  <html lang="pt-BR">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tabela de Preços - Novembro 2025</title>
    <style>
      :root {
        --bg: #0f172a; /* slate-900 */
        --panel: #111827; /* gray-900 */
        --muted: #94a3b8; /* slate-400 */
        --text: #e5e7eb; /* gray-200 */
        --accent: #22d3ee; /* cyan-400 */
        --card: #0b1220; /* deep panel */
        --card-bd: #1f2937; /* gray-800 */
        --chip: #0ea5e9; /* sky-500 */
    }
    html, body { height: 100%; }
    body {
      margin: 0; background: radial-gradient(1200px 800px at 80% -10%, #1e293b, transparent), var(--bg);
      color: var(--text); font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, "Helvetica Neue", Arial;
      min-height: 100vh;
    }
    .container { max-width: 1200px; margin: 0 auto; padding: 16px; }
    .navbar {
      display: flex; flex-direction: column; align-items: center; gap: 10px;
      background: #ffffff;
      border: 1px solid #cfe0f9; border-radius: 12px; padding: 14px 16px;
      position: sticky; top: 0; z-index: 2000;
      box-shadow: 0 4px 12px rgba(207, 224, 249, 0.4), 0 2px 4px rgba(207, 224, 249, 0.2);
    }
    .navbar.condensed { gap: 6px; padding: 8px 12px; border-radius: 10px; }
    .navbar.condensed .logo { max-height: 44px; }
    .navbar.condensed .title { font-size: .95rem; }
    .navbar.condensed .title-divider { display: none; }
    .navbar.condensed .controls { gap: 6px; }
    .navbar.condensed select,
    .navbar.condensed button,
    .navbar.condensed a.btn,
    .navbar.condensed input[type="text"] { padding: 6px 10px; border-radius: 10px; font-size: .9rem; }
    .brand { display:flex; align-items:center; gap:10px; }
    .logo { display:block; height:auto; width:auto; max-height:140px; margin-bottom: 0; }
    .title-divider { border-top: 1px solid #243047; width: 60%; max-width: 320px; margin: 4px 0; }
    .title { font-size: 1.1rem; font-weight: 600; letter-spacing: .3px; color: #1f2937; text-align:center; }
    .titlebar { display:flex; gap:0px; align-items:center; justify-content:center; flex-wrap:wrap; flex-direction:column; }
    .grid.fab { gap: 6px; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); margin-top: 12px; }
    @media (min-width: 1100px) { .grid.fab { grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); } }
    .card.fab { padding: 6px; min-height: 56px; gap: 2px; }
    .card.fab .prod-name { font-size: .85rem; line-height: 1.2; }
    .card.fab .muted { font-size: .75rem; text-align: center; }
    .divider-line-fab { border-top: 1px solid #243047; margin: 4px 0 2px; }
    /* Product grid: force 3+ columns on tablets */
    .grid.prod { gap: 12px; grid-template-columns: repeat(2, 1fr); }
    @media (min-width: 640px) { .grid.prod { grid-template-columns: repeat(3, 1fr); } }
    @media (min-width: 1100px) { .grid.prod { grid-template-columns: repeat(4, 1fr); } }
    .controls { display: flex; gap: 8px; align-items: center; justify-content:center; flex-wrap: wrap; }
    select, button, a.btn {
      background: #ffffff;
      color: #0f172a; 
      border: 1px solid #cfe0f9;
      padding: 8px 12px; 
      border-radius: 12px; 
      font-size: 0.95rem;
    }
    /* Smaller font for navbar selects (Seção/Fabricante) */
    .navbar select { font-size: 0.80rem; }
    .navbar select option { font-size: 0.80rem; }
    /* Tamanho fixo para os selects de Seção e Fabricante */
    .navbar select#agrupador,
    .navbar select#fabricante {
      width: 140px;
      min-width: 140px;
      max-width: 140px;
      box-sizing: border-box;
    }
    input[type="text"] {
      background: #ffffff;
      color: #0f172a; 
      border: 1px solid #cfe0f9;
      padding: 8px 12px; 
      border-radius: 12px; 
      font-size: 0.95rem;
    }
    button, a.btn { cursor: pointer; text-decoration: none; font-weight: 600; }
    button:hover, a.btn:hover, select:hover, input[type="text"]:hover { 
      border-color: #93c5fd;
    }
    .section-title {
      margin: 22px 4px 12px; font-size: 1rem; color: var(--muted);
      display: flex; align-items: center; gap: 8px;
      position: relative;
      padding: 10px 12px;
      border: 1px solid rgba(148,163,184,.18);
      border-radius: 14px;
      background: #fffbeb; /* amarelo clarinho */
      box-shadow: 0 10px 24px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.05);
    }
    .chip { background: rgba(14,165,233,0.15); border: 1px solid rgba(14,165,233,0.35); color: #2563eb; padding: 2px 8px; border-radius: 999px; font-size: .8rem; } /* blue-600 - azul mais forte */
    .section-title .sec-name { color:#2563eb; font-weight:600; } /* blue-600 - azul mais forte */

    .section-highlight {
      position: relative;
      padding: 10px 12px;
      margin: 22px 4px 12px;
      border-left: 4px solid #fde68a;
      background: linear-gradient(180deg, rgba(253,230,138,0.08) 0%, rgba(253,230,138,0.04) 100%);
      border-radius: 12px;
      box-shadow: 0 10px 30px rgba(253,230,138,0.10);
      overflow: hidden;
    }
    .section-highlight::before {
      content: '';
      position: absolute; inset: -2px -2px auto auto;
      width: 110px; height: 110px; border-radius: 50%;
      background: radial-gradient(circle at center, rgba(253,230,138,0.18), rgba(253,230,138,0));
      filter: blur(6px);
      animation: glowPulse 2.2s ease-in-out infinite;
      pointer-events: none;
    }
    @keyframes glowPulse {
      0%, 100% { transform: translateY(0); opacity: .75; }
      50% { transform: translateY(3px); opacity: 1; }
    }
    .zero-badge {
      margin-left: 6px;
      background: linear-gradient(135deg, #fde68a 0%, #fef08a 100%);
      color: #0b1220;
      border: 1px solid rgba(253,230,138,0.9);
      padding: 3px 10px;
      border-radius: 999px;
      font-weight: 900;
      font-size: .78rem;
      text-transform: uppercase;
      letter-spacing: .4px;
      box-shadow: 0 6px 16px rgba(253,230,138,0.25);
      display: inline-flex; align-items: center; gap: 6px;
      position: relative;
    }
    .zero-badge::before {
      content: '⚡';
      filter: drop-shadow(0 1px 0 rgba(0,0,0,.15));
      animation: spark 1.6s ease-in-out infinite;
    }
    @keyframes spark { 0%,100%{ transform: rotate(-6deg) scale(1); } 50%{ transform: rotate(6deg) scale(1.08); } }
    .featured-prefix {
      color: #ef4444 !important; /* red-500 */
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: .5px;
      font-size: .95rem;
      margin-right: 6px;
      text-shadow: 0 1px 0 rgba(0,0,0,.15);
      background: rgba(239,68,68,0.12);
      border: 1px solid rgba(239,68,68,0.45);
      border-radius: 6px;
      padding: 2px 8px;
    }
    .theme-pnb .section-title .featured-prefix { color: #dc2626 !important; border-color: rgba(220,38,38,0.5); background: rgba(220,38,38,0.12); }
    .section-highlight-grid {
      position: relative;
      outline: 1px dashed rgba(253,230,138,0.28);
      border-radius: 14px;
      padding: 8px;
      box-shadow: 0 12px 26px rgba(253,230,138,0.06) inset;
      animation: gridGlow 3s ease-in-out infinite;
    }
    @keyframes gridGlow {
      0%,100% { box-shadow: 0 12px 26px rgba(253,230,138,0.06) inset; }
      50% { box-shadow: 0 12px 26px rgba(253,230,138,0.12) inset; }
    }

    .grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
    @media (min-width: 768px) { .grid { grid-template-columns: repeat(3, 1fr); } }
    @media (min-width: 1100px) { .grid { grid-template-columns: repeat(4, 1fr); } }
    /* Featured carousel */
    .feat-wrap { position: relative; margin: 8px 0 16px; }
    .feat-head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin: 6px 6px 8px; }
    .feat-head .label { display:flex; align-items:center; gap:8px; font-weight:800; color:#eab308; letter-spacing:.4px; }
    .feat-carousel { position: relative; background: #ffffff; border:1px solid rgba(148,163,184,.18); border-radius:12px; padding: 8px 12px; }
    .theme-pnb .feat-carousel { background:#ffffff; border:1px solid #cfe0f9; }
    .feat-track { display:flex; gap:8px; overflow-x:auto; scroll-behavior:smooth; padding-bottom: 6px; scrollbar-width: none; -ms-overflow-style: none; }
    .feat-track::-webkit-scrollbar { height: 0; display: none; }
    .feat-track::-webkit-scrollbar-thumb { background: transparent; }
    .theme-pnb .feat-track::-webkit-scrollbar-thumb { background: #cbd5e1; }
    .feat-track .card { min-width: 200px; max-width: 230px; flex: 0 0 auto; padding: 10px; }
    .feat-track .prod-img { max-height: 100px; }
    .feat-track .prod-name { font-size: .7rem; }
    .feat-track .code-inline { font-size: .72rem; }
    .feat-track .name-sep { font-size: .72rem; margin: 0 4px; }
    .feat-track .price-main { font-size: .95rem; }
    .feat-track .qty-btn { width: 30px; height: 30px; font-size: .9rem; }
    .feat-nav { display:none; }
    /* Ajustes extras para tablets (<= 1024px) */
    @media (max-width: 1024px) {
      .feat-carousel { padding: 6px 10px; }
      .feat-track { gap: 8px; }
      .feat-track .card { min-width: 180px; max-width: 200px; padding: 8px; }
      .feat-track .prod-img { max-height: 90px; }
      .feat-track .price-main { font-size: .9rem; }
      .feat-track .qty-btn { width: 28px; height: 28px; }
      .feat-nav { width:24px; height:38px; }
    }

    .card {
      background: linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.015));
      border: 1px solid rgba(148,163,184,.18); border-radius: 14px; padding: 12px; min-height: 120px;
      display: flex; flex-direction: column; gap: 6px; transition: transform 0.2s, box-shadow 0.2s, border-color .15s ease;
      position: relative;
      overflow: hidden;
      box-shadow: 0 10px 24px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.05);
    }
    .card:hover { 
      transform: translateY(-2px);
      box-shadow: 0 12px 28px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.05);
      border-color: rgba(148,163,184,.28);
    }
    .prod-name { font-weight: 600; color: #334155; line-height: 1.3; text-align:center; font-size: .75rem; }
    .muted { color: var(--muted); font-size: .9rem; }
    .price { color: #bef264; font-weight: 700; font-size: 1.05rem; }
    .code { color: #93c5fd; font-size: .85rem; }
    .divider { height: 6px; margin-top: 6px; }
    .divider-line { border-top: 2px solid #fde047; margin-top: 8px; margin-bottom: 4px; }
    .price-main { color:#2563eb; font-weight:800; font-size:1rem; text-align:center; margin-top:10px; }
    .price-unit { color:#0ea5e9; font-weight:500; font-size:.8rem; text-align:center; }
    .prod-img { width: 100%; max-height: 120px; object-fit: contain; display: block; margin: 0 auto 6px; cursor: zoom-in; }
    .price-unit-main { color:#ef4444; font-weight:800; font-size:1rem; }
    .price-unit-sm { color:#0ea5e9; font-weight:500; font-size:.95rem; }
    .price-sep { color:#94a3b8; font-weight:400; font-size:.9rem; margin: 0 6px; }
    .code-inline { color:#64748b; font-size:.75rem; }
    .name-sep { color:#64748b; margin: 0 6px; }
    .qty-row { display:flex; align-items:center; justify-content:center; gap:8px; margin-top:8px; position: relative; z-index: 10; }
    .qty-btn { background:#2563eb; color:#ffffff; border:1px solid #60a5fa; padding:4px 8px; border-radius:8px; font-size:1rem; width:34px; height:34px; display:flex; align-items:center; justify-content:center; box-shadow: 0 2px 4px rgba(37,99,235,0.3); position: relative; z-index: 11; cursor: pointer; pointer-events: auto; user-select: none; }
    .qty-btn:hover { background:#1d4ed8; color:#ffffff; border-color:#3b82f6; box-shadow: 0 3px 6px rgba(37,99,235,0.4); }
    .qty-btn:active { transform: translateY(1px); box-shadow: 0 1px 2px rgba(37,99,235,0.3); }
    .qty-btn:focus-visible { outline: 2px solid #3b82f6; outline-offset: 2px; }
    .qty { min-width: 24px; text-align:center; font-weight:700; color:#0f172a; }
    .sel-badge { display:none; text-align:center; color:#bbf7d0; font-size:.8rem; }
    /* Destacar itens selecionados SEMPRE em amarelo */
    .card.selected { background: #fff9db; border-color:#fde68a !important; box-shadow: 0 0 0 1px rgba(253, 230, 138, .28) inset; }
    .cart-tools { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .qty-btn[disabled] { opacity:.35; cursor:not-allowed; }
    .qty-row.locked { opacity:.6; filter: grayscale(20%); }

    /* Horizontal offer badge on first line inside the card */
    .offer-ribbon {
      position: absolute;
      top: 8px; left: 10px;
      height: 22px;
      padding: 0 10px;
      display: inline-flex; align-items: center; justify-content: center;
      background: #ef4444;
      color: #ffffff;
      font-weight: 800;
      font-size: .72rem;
      letter-spacing: .6px;
      text-transform: uppercase;
      border-radius: 999px;
      box-shadow: 0 4px 12px rgba(0,0,0,.25);
      z-index: 2;
      pointer-events: none;
      user-select: none;
    }
    details#cartPanel { background: #ffffff; border:1px solid #cfe0f9; border-radius:12px; padding:6px 10px; }
    details#cartPanel summary { cursor:pointer; background:#ffffff; color:#0f172a; border:1px solid #cfe0f9; padding:6px 10px; border-radius:999px; display:flex; align-items:center; gap:10px; justify-content:space-between; list-style:none; }
    details#cartPanel summary::-webkit-details-marker { display:none; }
    details#cartPanel summary::marker { display:none; }
    /* Dica de que o resumo é clicável */
    .summary-hint { font-size:.65rem; color:#9ca3af; display:none; }
    details#cartPanel summary:hover .summary-hint { text-decoration: underline; color:#64748b; }
    /* Ponto pulsante como sinalizador */
    .summary-dot { width:8px; height:8px; border-radius:999px; background:#22d3ee; display:none; box-shadow:0 0 0 0 rgba(34,211,238,0.7); }
    @keyframes pulseDot { 0% { box-shadow:0 0 0 0 rgba(34,211,238,0.7);} 70% { box-shadow:0 0 0 8px rgba(34,211,238,0); } 100% { box-shadow:0 0 0 0 rgba(34,211,238,0);} }
    .summary-dot.pulse { animation: pulseDot 1.8s ease-out infinite; }
    #btnClear { background:#dc2626; color:#ffffff; border:1px solid #b91c1c; padding:2px 6px; border-radius:4px; font-size:.45rem; font-weight:600; cursor:pointer; }
    #btnClear:hover { background:#b91c1c; border-color:#991b1b; }
    details#cartPanel:not([open]) #btnClear { display: none !important; }
    details#cartPanel summary.disabled { opacity:.6; cursor:not-allowed; }
    #cartItems { font-size:.85rem; color:#0f172a; margin-top:8px; display:flex; flex-direction:column; gap:4px; }
    .cart-line { display:flex; justify-content:space-between; gap:8px; border-bottom:1px dashed #cfe0f9; padding-bottom:4px; }
    details#cartPanel[open] #cartItems { max-height: 45vh; overflow-y: auto; padding-right: 4px; overscroll-behavior: contain; }
    details#cartPanel[open] { overflow: visible; }
    .cart-prefix { font-size:.6rem; font-weight:700; color:#2563eb; margin-right:4px; }
    .cart-line .cart-remove { align-self:center; margin-left:auto; background:#ffffff; color:#64748b; border:1px solid #cfe0f9; width:18px; height:18px; line-height:16px; border-radius:999px; font-size:.65rem; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; padding:0; }
    .cart-line .cart-remove:hover { background:#f8fafc; color:#334155; border-color:#93c5fd; }
    .cart-line .cart-remove:focus-visible { outline:2px solid #93c5fd; outline-offset:2px; }
    .cart-actions { display:flex; gap:8px; margin-top:8px; justify-content:flex-end; }
    /* Minimal link style for action button inside cart (dark theme) */
    .cart-actions button { background:transparent; color:#64748b; border:0; border-radius:6px; padding:4px 6px; font-weight:600; }
    .cart-actions button:hover { color:#0f172a; text-decoration: underline; }
    .cart-actions button:focus-visible { outline: 2px solid #93c5fd; outline-offset:2px; border-radius:6px; }
    /* Cart total row */
    .cart-total { font-size:1.1rem; font-weight:800; color:#0f172a; margin-top:8px; padding-top:8px; border-top:2px solid #cfe0f9; }
    /* Sticky cart summary */
    .cart-sticky { position: fixed; right: 14px; bottom: 14px; z-index: 1200; display: none;
      background:#111827; color:#e5e7eb; border:1px solid #334155; border-radius:999px; padding:10px 14px; font-weight:700; cursor:pointer; box-shadow: 0 8px 24px rgba(2,6,23,0.45);
    }
    .cart-sticky:hover { background:#0ea5e9; color:#0b1220; border-color:#38bdf8; }
    .cart-sticky .count { margin-right:6px; }
    .theme-pnb .cart-sticky { background:#ffffff; color:#0f172a; border:1px solid #cfe0f9; box-shadow: 0 12px 28px rgba(2,6,23,0.18); }
    .theme-pnb .cart-sticky:hover { background:#eaf2ff; border-color:#93c5fd; color:#0f172a; }
    /* Beautiful send/copy buttons */
    #btnSend { 
      background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
      color:#ffffff; border:1px solid #2563eb; border-radius:10px; padding:8px 12px; font-weight:700; cursor:pointer;
      box-shadow: 0 2px 8px rgba(59, 130, 246, 0.25);
    }
    .navbar.condensed #btnSend { padding: 6px 10px; font-size: .8rem; }
    #btnCopy {
      background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
      color:#ffffff; border:1px solid #0284c7; border-radius:10px; padding:8px 12px; font-weight:700; cursor:pointer;
      box-shadow: 0 2px 8px rgba(14, 165, 233, 0.25);
    }
    .navbar.condensed #btnCopy { padding: 6px 10px; font-size: .8rem; }
      transition: all 0.2s ease;
      box-shadow: 0 3px 8px rgba(34, 197, 94, 0.25);
      text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
    }
    #btnSend:hover {
      background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
      border-color: #1d4ed8;
      box-shadow: 0 6px 16px rgba(59, 130, 246, 0.35);
      transform: translateY(-1px);
    }
    #btnSend:active {
      transform: translateY(0);
      box-shadow: 0 2px 8px rgba(34, 197, 94, 0.25);
    }
    .navbar.condensed #btnSend { padding: 6px 10px; font-size: .8rem; }
    .theme-pnb #btnSend { 
      background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
      border-color: #2563eb;
      box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25);
    }
    .theme-pnb #btnSend:hover {
      background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
      border-color: #1d4ed8;
      box-shadow: 0 6px 16px rgba(59, 130, 246, 0.35);
    }
    .theme-pnb .navbar.condensed #btnSend { padding: 6px 10px; font-size: .8rem; }
    .theme-pnb #btnCopy { 
      background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
      border-color: #0891b2;
      box-shadow: 0 4px 12px rgba(6, 182, 212, 0.25);
    }
    .theme-pnb #btnCopy:hover {
      background: linear-gradient(135deg, #0891b2 0%, #0e7490 100%);
      border-color: #0e7490;
      box-shadow: 0 6px 16px rgba(6, 182, 212, 0.35);
    }
    .theme-pnb .navbar.condensed #btnCopy { padding: 6px 10px; font-size: .8rem; }
    /* Image modal (lightbox) */
    .img-modal { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; z-index: 4000; }
    .img-modal.open { display: flex; }
    .img-modal-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.65); backdrop-filter: blur(2px); }
    .img-modal img { position: relative; max-width: 95vw; max-height: 95vh; object-fit: contain; border-radius: 10px; box-shadow: 0 8px 30px rgba(0,0,0,0.6); border: 1px solid #1f2937; transition: transform .18s ease; transform-origin: center center; }
    .img-modal.open img { transform: scale(1); }
    /* Mobile/tablet: ao abrir, aumentar 50% */
    @media (hover: none) and (pointer: coarse) {
      .img-modal img { max-width: 95vw; max-height: 95vh; }
      .img-modal.open img { transform: scale(1.5); }
    }
    .emp-switch { position: fixed; top: 8px; right: 8px; z-index: 2000; width: 44px; height: 44px; }
    .form-modal { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; z-index: 3000; }
    .form-modal.open { display: flex; }
    .form-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(2px); }
    .form-box { position: relative; background: var(--panel); border:1px solid #1f2937; border-radius: 12px; padding: 24px; width: 92vw; max-width: 400px; box-shadow: 0 20px 50px rgba(0,0,0,0.6); }
    .form-box .row { display: flex; flex-direction: column; gap: 6px; margin-bottom: 18px; }
    .form-box label { font-size: .85rem; color: var(--muted); font-weight: 600; letter-spacing: .2px; margin-bottom: 4px; }
    .form-box input[type="text"],
    .form-box input[type="email"],
    .form-box input[type="tel"],
    .form-box select {
      box-sizing: border-box !important;
      width: 100% !important;
      background: #ffffff !important;
      color:#0f172a !important;
      border:1px solid #d1d5db !important;
      border-radius: 8px !important;
      padding: 12px 16px !important;
      font-size: .9rem !important;
      line-height: 1.4 !important;
      height: 44px !important;
      outline: none !important;
      appearance: none !important;
      transition: all 0.2s ease !important;
    }
    .form-box input[type="text"]:focus,
    .form-box input[type="email"]:focus,
    .form-box input[type="tel"]:focus,
    .form-box select:focus { border-color: #3b82f6 !important; box-shadow: 0 0 0 3px rgba(59,130,246,.15) !important; }
    .form-actions { display:flex; gap:12px; justify-content:flex-end; margin-top: 24px; }
    .form-actions button { background:#ffffff; color:#0f172a; border:1px solid #d1d5db; padding:12px 20px; border-radius:8px; font-weight:600; font-size:.9rem; transition: all 0.2s ease; cursor: pointer; }
    .form-actions button:hover { border-color:#3b82f6; background:#f8fafc; }
    .form-actions .primary { background: #3b82f6; color:#ffffff; border-color: #3b82f6; }
    .form-actions .primary:hover { background: #2563eb; border-color: #2563eb; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59,130,246,0.3); }
    .form-title { font-weight:700; margin-bottom:24px; text-align:center; font-size:1.1rem; color: var(--text); }
    .form-error { color:#ef4444; font-size:.85rem; margin-top:12px; display:none; text-align:center; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; padding:8px; }
    .emp-btn { position: absolute; inset: 0; width: 44px; height: 44px; opacity: 0; cursor: pointer; background: transparent; border: 0; }
    .emp-menu { position: absolute; top: 0; right: 0; display: none; background: rgba(17,24,39,0.9); border: 1px solid #1f2937; border-radius: 8px; padding: 6px; min-width: 160px; box-shadow: 0 8px 24px rgba(0,0,0,0.5); }
    .emp-switch.open .emp-menu { display: block; }
    .emp-menu a { display: block; padding: 6px 10px; color: var(--text); text-decoration: none; border-radius: 6px; }
    .emp-menu a:hover { background: #0b1220; }
    /* Theme overrides: PNB (empresa=flpnb) -> product cards with white background */
    .theme-pnb .card { 
      background: #ffffff; 
      border: 1px solid rgba(148,163,184,.18); 
      border-width: 2px; 
      box-shadow: 0 10px 24px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.05);
    }
    .theme-pnb .card:hover { 
      transform: translateY(-2px);
      box-shadow: 0 12px 28px rgba(0,0,0,.35), inset 0 1px 0 rgba(255,255,255,.05);
      border-color: rgba(148,163,184,.28);
    }
    .theme-pnb .prod-name { color: #334155; }
    /* Quando quantidade > 0, o JS aplica .selected no card (reforço do amarelo no tema PNB) */
    .card.selected { background: #fff9db; border-color: #fde68a !important; box-shadow: 0 6px 16px rgba(253, 230, 138, 0.18); }
    .theme-pnb .divider-line { border-top: 2px solid #fde047; margin-top: 8px; margin-bottom: 4px; }
    .theme-pnb .qty-btn { 
      background:#2563eb; 
      color:#ffffff; 
      border:1px solid #60a5fa; 
      box-shadow: 0 2px 4px rgba(37,99,235,0.3);
      font-weight: 700;
    }
    .theme-pnb .qty-btn:hover { 
      background:#1d4ed8; 
      border-color:#3b82f6; 
      color:#ffffff;
      box-shadow: 0 3px 6px rgba(37,99,235,0.4);
    }
    .theme-pnb .qty-btn:focus-visible { outline-color:#3b82f6; }
    .theme-pnb .qty-btn:active { 
      transform: translateY(1px);
      box-shadow: 0 1px 2px rgba(37,99,235,0.3);
    }
    .theme-pnb .qty { color:#0f172a; }
    /* Friendlier price colors in PNB */
    .theme-pnb .price-main { color:#2563eb; }
    .theme-pnb .price-unit, .theme-pnb .price-unit-sm { color:#0f766e; }
    /* Cart panel restyle for PNB */
    .theme-pnb details#cartPanel { background:#ffffff; border:1px solid #cfe0f9; border-radius:12px; }
    .theme-pnb details#cartPanel summary { background:#f1f5f9; color:#0f172a; border:1px solid #e2e8f0; padding:6px 10px; border-radius:999px; display:flex; align-items:center; justify-content:space-between; list-style:none; }
    .theme-pnb #btnClear { background:#dc2626; color:#ffffff; border:1px solid #b91c1c; }
    .theme-pnb #btnClear:hover { background:#b91c1c; border-color:#991b1b; }
    .theme-pnb details#cartPanel:not([open]) #btnClear { display: none !important; }
    .theme-pnb details#cartPanel summary.disabled { opacity:.75; cursor:not-allowed; }
    .theme-pnb #cartSummary { color:#0f172a; }
    /* Minimal link style in PNB for cart action */
    .theme-pnb .cart-actions button { background:transparent; color:#64748b; border:0; border-radius:6px; padding:4px 6px; font-weight:600; }
    .theme-pnb .cart-actions button:hover { color:#0f172a; text-decoration: underline; }
    .theme-pnb .cart-actions button:focus-visible { outline: 2px solid #93c5fd; outline-offset:2px; border-radius:6px; }
    /* Improve readability inside opened cart list on white */
    .theme-pnb #cartItems { color:#334155; }
    .theme-pnb .cart-line { border-bottom:1px dashed #e2e8f0; }
    .theme-pnb .cart-line span:last-child { color:#0f172a; font-weight:400; }
    .theme-pnb .cart-prefix { color:#3b82f6; }
    .theme-pnb .cart-line .cart-remove { background:#ffffff; color:#64748b; border:1px solid #cbd5e1; width:16px; height:16px; line-height:14px; font-size:.6rem; }
    .theme-pnb .cart-line .cart-remove:hover { background:#f8fafc; color:#334155; border-color:#94a3b8; }
    .theme-pnb .cart-total { color:#0f172a; font-weight:700; }
    /* White content panel for PNB mode */
    .theme-pnb .content-panel {
      background: linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.015));
      border: 1px solid rgba(148,163,184,.18);
      border-radius: 14px;
      padding: 18px;
      box-shadow: 0 10px 24px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.05);
      margin-top: 12px;
    }
    /* Stronger highlight for section titles and item chip in PNB */
    .theme-pnb .section-title {
      position: relative;
      margin: 8px 0; /* espaçamento vertical entre seções */
      padding: 10px 14px;     /* padding interno */
      background: #fffbeb !important; /* yellow-50 - amarelo clarinho */
      background-image: 
        repeating-linear-gradient(0deg, transparent, transparent 2px, rgba(255,255,255,0.5) 2px, rgba(255,255,255,0.5) 4px),
        repeating-linear-gradient(0deg, transparent, transparent 1px, rgba(255,255,255,0.3) 1px, rgba(255,255,255,0.3) 2px);
      border: 1px solid #fef3c7; /* yellow-100 */
      border-radius: 10px;
      box-shadow: 0 2px 4px rgba(207, 224, 249, 0.15);
    }
    .theme-pnb .section-title .sec-name {
      color: #2563eb !important; /* blue-600 - azul mais forte */
      font-size: 1.1rem;
      font-weight: 600;
      letter-spacing: .2px;
    }
    .theme-pnb .section-title::after {
      content: '';
      display: none;
    }
    /* Highlight band for 'Produtos em destaque' header matching section title style */
    .theme-pnb .feat-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin: 10px -4px 14px; /* align with section-title band */
      padding: 10px 12px;
      background: #fffbeb;
      border: 1px solid #fef3c7;
      border-radius: 10px;
    }
    .theme-pnb .feat-head .label .sec-name { color:#ca8a04; font-weight:700; }
    /* Featured carousel layout and spacing */
    .feat-wrap { margin-bottom: 16px; }
    .feat-carousel { position: relative; display: flex; align-items: center; gap: 10px; }
    .feat-track { display: flex; gap: 12px; overflow-x: auto; scroll-snap-type: x mandatory; padding: 4px 4px 8px; scrollbar-width: none; -ms-overflow-style: none; }
    .feat-track::-webkit-scrollbar { height: 0; display: none; }
    .feat-track::-webkit-scrollbar-thumb { background: transparent; }
    .feat-nav { background:#ffffff; color:#0f172a; border:1px solid #cfe0f9; border-radius:999px; width:32px; height:32px; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 4px 10px rgba(2,6,23,.12); }
    .feat-nav:hover { background:#f1f5f9; }
    /* Smaller cards to show more products in featured track */
    .feat-track .card { min-width: 220px; max-width: 240px; scroll-snap-align: start; padding: 10px; }
    .feat-track .prod-img { max-height: 90px; width: auto; }
    .feat-track .prod-name { min-height: 38px; font-size: .85rem; }
    .feat-track .divider-line { margin-top: 8px; }
    .feat-track .price-main { font-size: .95rem; }
    .feat-track .qty-row { margin-top: 6px; }
    .theme-pnb .chip {
      background: #60a5fa; /* blue-400 - azul claro */
      border: 1px solid #3b82f6; /* blue-500 */
      color: #ffffff !important; /* branco */
      font-weight: 600;
      padding: 4px 12px;
      border-radius: 999px;
      box-shadow: 0 1px 2px rgba(37,99,235,0.2);
    }
    .theme-pnb .zero-badge {
      background: #fef3c7;
      color: #ca8a04;
      border: 1px solid #fde047;
      padding: 4px 10px;
      border-radius: 999px;
      font-weight: 800;
      font-size: .75rem;
      letter-spacing: .4px;
      margin-left: auto;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .theme-pnb .zero-badge::before {
      content: '⚡';
      font-size: 1rem;
    }
    /* Navbar base (generic) */
    .navbar {
      position: sticky; top: 0; z-index: 2000;
    }
    /* Make top navbar area white in PNB theme */
    .theme-pnb .navbar {
      background: #ffffff;
      border: 1px solid #cfe0f9;
      border-radius: 12px;
      box-shadow: 0 4px 12px rgba(207, 224, 249, 0.4), 0 2px 4px rgba(207, 224, 249, 0.2);
    }
    /* Ensure full page background is white for PNB theme */
    body.theme-pnb {
      background: #ffffff;
    }
    body {
      background: #ffffff;
    }
    /* Generic condensed tweaks (apply even without theme class) */
    .navbar.condensed { border-radius: 12px; padding: 8px 12px; }
    .navbar.condensed .logo { max-height: 40px; }
    .navbar.condensed .title { font-size: .95rem; }
    .navbar.condensed .title-divider { display:none; }
    /* Theme-specific refinement */
    .theme-pnb .navbar.condensed { box-shadow: 0 6px 16px rgba(2,6,23,0.10); }
    .theme-pnb .navbar.condensed .title { color:#1f2937; }
    /* Esconde/mostra filtros com animação */
    .navbar .controls { overflow: hidden; transition: max-height .22s ease, opacity .22s ease; max-height: 900px; opacity: 1; }
    .navbar.condensed .controls { max-height: 0; opacity: 0; }
    /* Se o carrinho estiver aberto, mantenha os controles visíveis mesmo em modo condensado */
    .navbar.cart-open .controls { max-height: 900px; opacity: 1; }
    /* Botão/indicador (pílula) — só aparece quando condensado */
    .nav-toggle { display:none; background:#ffffff; color:#0f172a; border:1px solid #cfe0f9; border-radius:999px; padding:6px 10px; font-weight:700; cursor:pointer; align-items:center; gap:8px; box-shadow: 0 6px 16px rgba(2,6,23,.10); position: relative; z-index: 100; pointer-events: auto; }
    .navbar.condensed .nav-toggle { display:inline-flex; margin: 6px auto 0; }
    .nav-toggle .chev { display:inline-block; transition: transform .2s ease; font-weight:900; }
    .nav-toggle.open .chev { transform: rotate(180deg); }
    .theme-pnb .title { color: #0f172a; }
    .theme-pnb .title-divider { display: none; }
    .theme-pnb .navbar select,
    .theme-pnb .navbar button,
    .theme-pnb .navbar a.btn { background:#ffffff; color:#0f172a; border:1px solid #cfe0f9; }
    .theme-pnb .navbar button,
    .theme-pnb .navbar a.btn { font-weight: 600; }
    .theme-pnb .navbar input[type="text"] {
      background:#ffffff !important; color:#0f172a; border:1px solid #cfe0f9 !important;
    }
    /* Accordion for sections */
    details.section { 
      margin: 8px 0;
      position: relative;
    }
    details.section > summary.section-title { cursor: pointer; user-select: none; list-style: none; }
    details.section > summary.section-title::-webkit-details-marker { display: none; }
    details.section > summary.section-title::marker { display: none; }
    /* Arrow indicator before the title (left): down when closed, up when open */
    summary.section-title { position: relative; }
    summary.section-title::before {
      content: '▼';
      color: #60a5fa; /* blue-400 - azul claro */
      font-weight: 700;
      margin-right: 8px;
      font-size: 0.9rem;
      line-height: 1;
      transition: transform .2s ease, color .2s ease, opacity .2s ease;
    }
    /* Subtle pulsing to draw attention on closed sections */
    @keyframes attentionPulse { 0%,100%{ transform: translateY(0); opacity: .9; } 50%{ transform: translateY(1px); opacity: 1; } }
    details.section:not([open]) summary.section-title::before { animation: attentionPulse 2.2s ease-in-out infinite; }
    details.section[open] summary.section-title::before { content: '▲'; color: #60a5fa; animation: none; }
    /* Featured band: red background, yellow text (test) */
    details.section.featured > summary.section-title { background:#b91c1c !important; border:1px solid #991b1b !important; color:#facc15 !important; }
    details.section.featured > summary.section-title .sec-name { color:#fde047 !important; }
    details.section.featured > summary.section-title .chip { color:#fde047 !important; border-color: rgba(250,204,21,0.7) !important; background: rgba(250,204,21,0.12) !important; }
    /* Navbar soft background logo */
    .navbar { position: sticky; top: 0; overflow: hidden; }
    .navbar::before {
      content: '';
      position: absolute; inset: 0;
      background-image: url(<?= isset($logo_bg_url) && $logo_bg_url !== '' ? ('"' . htmlspecialchars($logo_bg_url, ENT_QUOTES, 'UTF-8') . '"') : 'none' ?>);
      background-repeat: no-repeat; background-position: center center;
      background-size: cover; /* ocupar todo o fundo do cabeçalho */
      opacity: 0.06; filter: saturate(120%);
      pointer-events: none;
    }
  </style>
</head>
<body class="<?= (isset($__empresaParam) && in_array($__empresaParam, ['flpnb','flzap','ctpnb','ctzap','gapnb','gazap'], true)) ? 'theme-pnb' : '' ?>">
  <div class="emp-switch" id="empSwitch">
    <button type="button" class="emp-btn" aria-label="Trocar empresa"></button>
    <div class="emp-menu">
      <div class="muted" style="padding:4px 8px; font-size:.8rem;">FL</div>
      <a href="?empresa=flpnb">FL - PNB (somente lista)</a>
      <a href="?empresa=flzap">FL - ZAP (exceto lista)</a>
      <div class="muted" style="padding:6px 8px 4px; font-size:.8rem;">CT</div>
      <a href="?empresa=ctpnb">CT - PNB (somente lista)</a>
      <a href="?empresa=ctzap">CT - ZAP (exceto lista)</a>
      <div class="muted" style="padding:6px 8px 4px; font-size:.8rem;">GA</div>
      <a href="?empresa=gapnb">GA - PNB (somente lista)</a>
      <a href="?empresa=gazap">GA - ZAP (exceto lista)</a>
    </div>
  </div>
  <div class="container">
    <div class="navbar">
      <div class="brand"></div>
      <div class="titlebar">
        <?php
          // Obter company_id da sessão ou do slug
          $current_company_id = isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : 1;
          if (isset($_GET['slug']) && $_GET['slug'] !== '') {
            $slug = trim((string)$_GET['slug']);
            try {
              $stmt_comp = $pdo->prepare('SELECT id FROM companies WHERE slug = ? AND is_active = 1 LIMIT 1');
              $stmt_comp->execute([$slug]);
              $comp_row = $stmt_comp->fetch(PDO::FETCH_ASSOC);
              if ($comp_row && isset($comp_row['id'])) {
                $current_company_id = (int)$comp_row['id'];
                $_SESSION['company_id'] = $current_company_id;
              }
            } catch (Throwable $_) {
              // Ignorar erro, usar company_id padrão
            }
          }
          if ($current_company_id <= 0) {
            $current_company_id = 1;
          }
          
          // Buscar logotipo na pasta img_logo com nome logo_{company_id}.png
          $logo_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'img_logo';
          $logo_file = 'logo_' . $current_company_id . '.png';
          $logo_path = $logo_dir . DIRECTORY_SEPARATOR . $logo_file;
          $logoSrc = '';
          
          if (file_exists($logo_path)) {
            // Logo encontrado na pasta img_logo
            $logoSrc = '../img_logo/' . $logo_file;
          } else {
            // Fallback: tentar outros formatos ou locais legados
            $logoPNB = __DIR__ . DIRECTORY_SEPARATOR . 'logo_panebras.png';
            $logoZAP = __DIR__ . DIRECTORY_SEPARATOR . 'logo_zap.png';
            $logoPngLegacy = __DIR__ . DIRECTORY_SEPARATOR . 'logo_Panebras.png';
            $logoSvgLegacy = __DIR__ . DIRECTORY_SEPARATOR . 'logo_Panebras.svg';
            $logoPnbRoot1 = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Logo_Panebras.png';
            $logoPnbRoot2 = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Logo_Panebras2.png';
            $logoZapRoot  = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logo_zap.png';
            $mode = isset($__empresaParam) ? $__empresaParam : '';
            $useZap = in_array($mode, ['flzap','ctzap','gazap'], true);
            $usePnb = in_array($mode, ['flpnb','ctpnb','gapnb'], true);
            if ($useZap && file_exists($logoZAP)) {
              $logoSrc = 'logo_zap.png';
            } elseif ($usePnb && file_exists($logoPNB)) {
              $logoSrc = 'logo_panebras.png';
            } elseif (file_exists($logoPngLegacy)) {
              $logoSrc = 'logo_Panebras.png';
            } elseif (file_exists($logoSvgLegacy)) {
              $logoSrc = 'logo_Panebras.svg';
            } elseif ($usePnb && file_exists($logoPnbRoot1)) {
              $logoSrc = '../Logo_Panebras.png';
            } elseif ($usePnb && file_exists($logoPnbRoot2)) {
              $logoSrc = '../Logo_Panebras2.png';
            } elseif ($useZap && file_exists($logoZapRoot)) {
              $logoSrc = '../logo_zap.png';
            } elseif (file_exists($logoZAP)) {
              $logoSrc = 'logo_zap.png';
            } elseif (file_exists($logoPNB)) {
              $logoSrc = 'logo_panebras.png';
            }
          }
        ?>
        <?php if ($logoSrc !== ''): ?>
          <img src="<?= h($logoSrc) ?>" alt="Panebras" class="logo" />
        <?php endif; ?>
        <div class="title-divider"></div>
        <div class="title">Tabela de Preços - Novembro 2025</div>
        <button type="button" id="navToggle" class="nav-toggle" aria-expanded="false" aria-label="Expandir Cabeçalho" title="Expandir Cabeçalho"><span class="chev">▾</span><span class="label">Expandir Cabeçalho</span></button>
      </div>
      <form method="get" class="controls" id="filters">
        <?php if ($filtroEmpresa !== ''): ?>
          <input type="hidden" name="empresa" value="<?= h($filtroEmpresa) ?>" />
        <?php endif; ?>
        <label for="agrupador" class="muted">Seção</label>
        <select id="agrupador" name="agrupador" onchange="this.form.submit();">
          <option value="">SELECIONE</option>
          <option value="__ALL__" <?= (!empty($expandAllSections)) ? 'selected' : '' ?>>TODOS</option>
          <?php $agrOpts = $agrupadoresAll; ?>
          <?php foreach ($agrOpts as $agr): ?>
            <option value="<?= h($agr) ?>" <?= $filtroAgrupador === $agr ? 'selected' : '' ?>><?= h($agr) ?></option>
          <?php endforeach; ?>
        </select>
        <label for="fabricante" class="muted">Fabricante</label>
        <select id="fabricante" name="fabricante" onchange="this.form.submit();">
          <option value="">SELECIONE</option>
          <?php foreach ($fabricantes as $fabOpt): ?>
            <option value="<?= h($fabOpt) ?>" <?= $filtroFabricante === $fabOpt ? 'selected' : '' ?>><?= h($fabOpt) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por código ou nome" autocomplete="off" style="min-width:180px;" />
        <button type="submit">Filtrar</button>
        <?php if ($filtroAgrupador !== '' || $q !== '' || $filtroFabricante !== ''): ?>
          <a href="<?= $filtroEmpresa !== '' ? ('?empresa=' . urlencode($filtroEmpresa)) : '?' ?>" class="btn">Limpar</a>
        <?php endif; ?>
        <div class="cart-tools">
          <details id="cartPanel">
            <summary title="Clique para ver os produtos selecionados">
              <span id="cartSummary">Cotação - 0 itens - $ 0.00</span>
              <span class="summary-hint" id="cartHint">ver itens ▾</span>
              <span class="summary-dot" id="cartDot" aria-hidden="true"></span>
              <button type="button" id="btnClear" style="display:none" onclick="event.stopPropagation()">Limpar</button>
            </summary>
            <div id="cartItems"></div>
          </details>
          <button type="button" id="btnRestore" style="display:none" title="Recuperar o último pedido salvo neste dispositivo">Recuperar último</button>
          <button type="button" id="btnSend" style="display:none" title="Enviar a cotação via WhatsApp">Enviar ao Vendedor(a)</button>
          <button type="button" id="btnCopy" style="display:none" title="Copiar Pedido">Copiar Pedido</button>
        </div>
      </form>
    </div>

    <div class="content-panel">
    <?php if ($erro): ?>
      <p class="muted" style="margin:16px 4px; color:#fca5a5;"><?= h($erro) ?></p>
      <?php if ($debug): ?>
        <div style="margin:12px 4px; padding:10px; border:1px dashed #334155; border-radius:8px; color:#cbd5e1; font-size:.9rem;">
          <div><strong>Debug</strong></div>
          <div>pdo_mysql loaded: <?= extension_loaded('pdo_mysql') ? 'yes' : 'no' ?></div>
          <div>PHP architecture: <?= PHP_INT_SIZE === 8 ? 'x64' : 'x86' ?></div>
          <div>php.ini: <?= h(php_ini_loaded_file() ?: 'desconhecido') ?></div>
          <div>MySQL: <?= h($host) ?>:<?= h($port) ?> / DB: <?= h($banco) ?></div>
        </div>
      <?php endif; ?>
    <?php elseif (!$grupos): ?>
      <p class="muted" style="margin:16px 4px;">Nenhum produto encontrado.</p>
      <?php if ($debug): ?>
        <div style="margin:12px 4px; padding:10px; border:1px dashed #334155; border-radius:8px; color:#cbd5e1; font-size:.85rem;">
          <div><strong>Debug contadores</strong></div>
          <div>raw (total fonte): <?= isset($raw) && is_array($raw) ? count($raw) : 0 ?></div>
          <div>dados (após filtros): <?= isset($dados) && is_array($dados) ? count($dados) : 0 ?></div>
          <div>grupos (fabricantes exibidos): <?= isset($grupos) && is_array($grupos) ? count($grupos) : 0 ?></div>
          <div>fabricantes (lista filtro): <?= isset($fabricantes) && is_array($fabricantes) ? count($fabricantes) : 0 ?></div>
          <div>preloadItems (pedido): <?= isset($preloadItems) && is_array($preloadItems) ? count($preloadItems) : 0 ?></div>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <?php
        $destaques = [];
        try {
            $codesRaw = [];
            $colCandidates = ['codigo','código','cod','cod_produto','Codigo_do_Produto'];
            $ok = false;
            foreach ($colCandidates as $col) {
                try {
                    // Buscar produtos em destaque usando is_featured na tabela products
                    $current_company_id = isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : 1;
                    if ($current_company_id <= 0) {
                        $current_company_id = 1;
                    }
                    $stmtDx = $pdo->prepare('SELECT cod AS c FROM products WHERE company_id = ? AND is_featured = 1 AND is_active = 1 ORDER BY updated_at DESC, cod');
                    $stmtDx->execute([$current_company_id]);
                    $rowsDx = $stmtDx ? $stmtDx->fetchAll(PDO::FETCH_ASSOC) : [];
                    if ($rowsDx && is_array($rowsDx)) {
                        foreach ($rowsDx as $rDx) { $codesRaw[] = preg_replace('/\D+/', '', (string)($rDx['c'] ?? '')) ?? ''; }
                        $ok = true; break;
                    }
                } catch (Throwable $_e) { /* try next */ }
            }
            if ($ok && $codesRaw) {
                $codes = [];
                foreach ($codesRaw as $c) { $c = trim($c); if ($c !== '') { $codes[] = str_pad($c, 4, '0', STR_PAD_LEFT); } }
                $codes = array_values(array_unique($codes));
                if ($codes) {
                    $byCode = [];
                    foreach ($dados as $r) {
                        $cd = onlyDigits((string)($r['codigo'] ?? ''));
                        if ($cd !== '') { $byCode[str_pad($cd, 4, '0', STR_PAD_LEFT)] = $r; }
                    }
                    foreach ($codes as $cdx) { if (isset($byCode[$cdx])) { $destaques[] = $byCode[$cdx]; } }
                }
            }
        } catch (Throwable $_) { $destaques = []; }
      ?>
      <?php if (!empty($destaques)): ?>
        <details class="section featured" open>
          <summary class="section-title">
            <span class="sec-name">PRODUTOS EM DESTAQUE</span>
            <span class="chip"><?= count($destaques) ?> itens</span>
          </summary>
          <div class="grid prod">
            <?php foreach ($destaques as $p): ?>
              <?php
                $codigo   = (string)($p['codigo'] ?? '');
                $codigoDisp = $codigo !== '' ? codeDisplay($codigo) : '';
                if ($codigoDisp === '') {
                    $h = sprintf('%u', crc32((string)($p['produto'] ?? '')));
                    $n = 9000 + ((int)$h % 1000);
                    $codigoDisp = str_pad((string)$n, 4, '0', STR_PAD_LEFT);
                }
                $codigoSimples = $codigo !== '' ? onlyDigits($codigo) : '';
                $nome     = (string)($p['produto'] ?? '');
                $empresa  = (string)($p['empresa'] ?? '');
                $vda      = $p['vda_real'] ?? null;
                $vdaVal   = numval($vda);
                $vdaOrig  = $p['vda_orig'] ?? null;
                $vdaOrigVal = numval($vdaOrig);
                $precoVarejoVal = numval($p['preco_varejo'] ?? null);
                if ($vdaVal !== null && $precoVarejoVal !== null && (float)$precoVarejoVal > (float)$vdaVal) {
                    if ($vdaOrigVal === null || (float)$precoVarejoVal > (float)$vdaOrigVal) {
                        $vdaOrigVal = (float)$precoVarejoVal;
                    }
                }
                $isOffer  = ($vdaOrigVal !== null && $vdaVal !== null && (float)$vdaOrigVal > (float)$vdaVal);
                $isGreatDeal = isset($p['is_great_deal']) && (int)$p['is_great_deal'] === 1;
                $priceFmt = $vdaVal !== null ? '$ '.number_format($vdaVal, 2, '.', ',') : '';
                $unStr    = (string)($p['un'] ?? '');
                $qty = 0;
                if ($unStr !== '') { if (preg_match('/(\d{1,3})/', $unStr, $m)) { $qty = (int)$m[1]; } }
                if ($qty === 0 && $nome !== '') { if (preg_match('/(\d{1,3})\s*[xX\*]/u', $nome, $m)) { $qty = (int)$m[1]; } }
                $unitFmt = '';
                if ($vdaVal !== null && $precoVarejoVal !== null && (float)$precoVarejoVal < (float)$vdaVal && $qty >= 2) {
                    $unitFmt = '$ '.number_format($vdaVal/$qty, 2, '.', ',') . '/und';
                }
                $imgRel = 'img/' . $codigoSimples . '.png';
                $imgAbs = __DIR__ . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . $codigoSimples . '.png';
                $imgRelParent = '../img/' . $codigoSimples . '.png';
                $imgAbsParent = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . $codigoSimples . '.png';
                $imgSrc = null;
                if ($codigoSimples !== '') {
                    if (file_exists($imgAbs)) { $imgSrc = $imgRel; }
                    elseif (file_exists($imgAbsParent)) { $imgSrc = $imgRelParent; }
                    elseif ($img_folder_name !== '') {
                        $imgAbsProduct = dirname(__DIR__) . DIRECTORY_SEPARATOR . $img_folder_name . DIRECTORY_SEPARATOR . $codigoSimples . '.png';
                        $imgRelProduct = '../' . $img_folder_name . '/' . $codigoSimples . '.png';
                        if (file_exists($imgAbsProduct)) { $imgSrc = $imgRelProduct; }
                    }
                }
                if ($imgSrc === null) {
                    $imgSrc = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200"><rect width="200" height="200" fill="#f1f5f9"/><text x="50%" y="50%" font-family="Arial, sans-serif" font-size="14" fill="#94a3b8" text-anchor="middle" dominant-baseline="middle">Sem Imagem</text></svg>');
                }
              ?>
              <div class="card">
                <?php if ($isGreatDeal): ?>
                  <div class="offer-ribbon">GREAT DEAL</div>
                <?php endif; ?>
                <img src="<?= h($imgSrc) ?>" alt="<?= h($nome) ?>" class="prod-img" />
                <div class="prod-name">
                  <?= h($nome) ?><?php if ($codigoSimples !== ''): ?><span class="name-sep">|</span><span class="code-inline">Cod <?= h($codigoSimples) ?></span><?php endif; ?>
                </div>
                <div class="divider-line"></div>
                <?php if ($priceFmt !== ''): ?>
                  <div class="price-main">
                    <?php if ($unitFmt !== ''): ?><span class="price-unit-main"><?= h($unitFmt) ?></span><span class="price-sep">||</span><?php endif; ?><?= h($priceFmt) ?>
                  </div>
                <?php endif; ?>
                <div class="qty-row" data-code="<?= h($codigoDisp) ?>" data-name="<?= h($nome) ?>" data-price="<?= $vdaVal !== null ? number_format($vdaVal, 2, '.', '') : '' ?>" data-empresa="<?= h($empresa) ?>">
                  <button type="button" class="qty-btn minus" aria-label="Diminuir" onclick="event.stopPropagation(); handleQtyClick(this, -1); return false;">-</button>
                  <span class="qty">0</span>
                  <button type="button" class="qty-btn plus" aria-label="Aumentar" onclick="event.stopPropagation(); handleQtyClick(this, +1); return false;">+</button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endif; ?>
      <?php foreach ($grupos as $fab => $lista): ?>
        <?php $hasZero = false; foreach ($lista as $_it) { $vv = numval($_it['vda_real'] ?? null); if ($vv !== null && (float)$vv == 0.0) { $hasZero = true; break; } } $isFeat = false; foreach ($lista as $_it2) { $oo = numval($_it2['ordem'] ?? null); if ($oo !== null && (int)$oo === 0) { $isFeat = true; break; } } ?>
        <details class="section"<?= (!empty($expandAllSections) || ($q !== '') || ($filtroFabricante !== '') || ($filtroAgrupador !== '' && $filtroAgrupador !== '__ALL__' && strcasecmp(trim((string)$fab), trim((string)$filtroAgrupador)) === 0)) ? ' open' : '' ?>>
          <summary class="section-title<?= $hasZero ? ' section-highlight' : '' ?>">
            <?php if ($isFeat): ?><span class="featured-prefix">DESTAQUE:</span><?php endif; ?>
            <span class="sec-name"><?= h($fab) ?></span>
              <span class="chip"><?= count($lista) ?> itens</span>
              <?php if ($hasZero): ?><span class="zero-badge">Oferta Relâmpago</span><?php endif; ?>
            </summary>
            <div class="grid prod<?= $hasZero ? ' section-highlight-grid' : '' ?>">
            <?php foreach ($lista as $p): ?>
              <?php
                $codigo   = (string)($p['codigo'] ?? '');
                $codigoDisp = $codigo !== '' ? codeDisplay($codigo) : '';
                if ($codigoDisp === '') {
                    $h = sprintf('%u', crc32((string)($p['produto'] ?? '')));
                    $n = 9000 + ((int)$h % 1000); // 9000-9999, reduz colisão com códigos reais
                    $codigoDisp = str_pad((string)$n, 4, '0', STR_PAD_LEFT);
                }
                $codigoSimples = $codigo !== '' ? onlyDigits($codigo) : ''; // Para exibição (sem zeros)
                $nome     = (string)($p['produto'] ?? '');
                $empresa  = (string)($p['empresa'] ?? ''); // Classificação individual do produto
                $vda      = $p['vda_real'] ?? null;
                $vdaVal   = numval($vda);
                $vdaOrig  = $p['vda_orig'] ?? null;
                $vdaOrigVal = numval($vdaOrig);
                $precoVarejoVal = numval($p['preco_varejo'] ?? null);
                if ($vdaVal !== null && $precoVarejoVal !== null && (float)$precoVarejoVal > (float)$vdaVal) {
                    // usa preco_varejo como referência somente se for maior que o vda_real c/ desc
                    if ($vdaOrigVal === null || (float)$precoVarejoVal > (float)$vdaOrigVal) {
                        $vdaOrigVal = (float)$precoVarejoVal;
                    }
                }
                $isOffer  = ($vdaOrigVal !== null && $vdaVal !== null && (float)$vdaOrigVal > (float)$vdaVal);
                $isGreatDeal = isset($p['is_great_deal']) && (int)$p['is_great_deal'] === 1;
                $priceFmt = $vdaVal !== null ? '$ '.number_format($vdaVal, 2, '.', ',') : '';
                $unStr    = (string)($p['un'] ?? '');
                $qty = 0;
                if ($unStr !== '') { if (preg_match('/(\d{1,3})/', $unStr, $m)) { $qty = (int)$m[1]; } }
                if ($qty === 0 && $nome !== '') { if (preg_match('/(\d{1,3})\s*[xX\*]/u', $nome, $m)) { $qty = (int)$m[1]; } }
                $unitFmt = '';
                if ($vdaVal !== null && $precoVarejoVal !== null && (float)$precoVarejoVal < (float)$vdaVal && $qty >= 2) {
                    $unitFmt = '$ '.number_format($vdaVal/$qty, 2, '.', ',') . '/und';
                }
              ?>
              <div class="card">
                <?php if ($isGreatDeal): ?>
                  <div class="offer-ribbon">GREAT DEAL</div>
                <?php endif; ?>
                <?php
                  $imgRel = 'img/' . $codigoSimples . '.png';
                  $imgAbs = __DIR__ . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . $codigoSimples . '.png';
                  $imgRelParent = '../img/' . $codigoSimples . '.png';
                  $imgAbsParent = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . $codigoSimples . '.png';
                  // Buscar também em {slug}/ na raiz (novo padrão: {cod}.png)
                  $imgSrc = null;
                  if ($codigoSimples !== '') {
                      if (file_exists($imgAbs)) {
                          $imgSrc = $imgRel;
                      } elseif (file_exists($imgAbsParent)) {
                          $imgSrc = $imgRelParent;
                      } elseif ($img_folder_name !== '') {
                          // Buscar na pasta do slug da tabela companies (raiz/{slug}/{cod}.png)
                          $imgAbsProduct = dirname(__DIR__) . DIRECTORY_SEPARATOR . $img_folder_name . DIRECTORY_SEPARATOR . $codigoSimples . '.png';
                          $imgRelProduct = '../' . $img_folder_name . '/' . $codigoSimples . '.png';
                          if (file_exists($imgAbsProduct)) {
                              $imgSrc = $imgRelProduct;
                          }
                      }
                  }
                  // Se não houver imagem, usar placeholder
                  if ($imgSrc === null) {
                      $imgSrc = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200"><rect width="200" height="200" fill="#f1f5f9"/><text x="50%" y="50%" font-family="Arial, sans-serif" font-size="14" fill="#94a3b8" text-anchor="middle" dominant-baseline="middle">Sem Imagem</text></svg>');
                  }
                ?>
                <img src="<?= h($imgSrc) ?>" alt="<?= h($nome) ?>" class="prod-img" />
                <div class="prod-name">
                  <?= h($nome) ?><?php if ($codigoSimples !== ''): ?><span class="name-sep">|</span><span class="code-inline">Cod <?= h($codigoSimples) ?></span><?php endif; ?>
                </div>
                <div class="divider-line"></div>
                <?php if ($priceFmt !== ''): ?>
                  <div class="price-main">
                    <?php if ($unitFmt !== ''): ?><span class="price-unit-main"><?= h($unitFmt) ?></span><span class="price-sep">||</span><?php endif; ?><?= h($priceFmt) ?>
                  </div>
                <?php endif; ?>
                <div class="qty-row" data-code="<?= h($codigoDisp) ?>" data-name="<?= h($nome) ?>" data-price="<?= $vdaVal !== null ? number_format($vdaVal, 2, '.', '') : '' ?>" data-empresa="<?= h($empresa) ?>">
                  <button type="button" class="qty-btn minus" aria-label="Diminuir" onclick="event.stopPropagation(); handleQtyClick(this, -1); return false;">-</button>
                  <span class="qty">0</span>
                  <button type="button" class="qty-btn plus" aria-label="Aumentar" onclick="event.stopPropagation(); handleQtyClick(this, +1); return false;">+</button>
                </div>
              </div>
            <?php endforeach; ?>
            </div>
          </details>
      <?php endforeach; ?>
    <?php endif; ?>
    </div>
  </div>
  <div class="img-modal" id="imgModal" aria-hidden="true">
    <div class="img-modal-backdrop" id="imgModalBackdrop"></div>
    <img id="imgModalImg" alt="" />
  </div>
  <div class="form-modal" id="sendModal" aria-hidden="true">
    <div class="form-backdrop" id="sendModalBackdrop"></div>
    <div class="form-box">
      <div class="form-title">Enviar ao Vendedor(a) <span class="seller-name" style="color:#facc15;"><?= h((string)($_SESSION['vendedor_nome'] ?? '')) ?></span></div>
      <div class="row">
        <label for="cliNome">Seu Nome/Loja</label>
        <input type="text" id="cliNome" autocomplete="name" placeholder="Digite seu nome ou nome da loja" required />
      </div>
      <div class="row">
        <label for="cliEmail">Seu e-mail</label>
        <input type="email" id="cliEmail" autocomplete="email" placeholder="seu@email.com" required />
      </div>
      <div class="row">
        <label for="cliTel">Seu telefone/whatsapp</label>
        <input type="tel" id="cliTel" inputmode="tel" placeholder="(xxx) xxx-xxxx" maxlength="14" pattern="\(\d{3}\) \d{3}-\d{4}" />
      </div>
      <div class="row">
        <label>Receber cópia da Cotação por:</label>
        <div style="display:flex; gap:14px; padding:6px 0;">
          <label style="display:flex; align-items:center; gap:6px; cursor:pointer;"><input type="radio" name="canal" id="canalWhats" value="whats" checked /> WhatsApp</label>
          <label style="display:flex; align-items:center; gap:6px; cursor:pointer;"><input type="radio" name="canal" id="canalEmail" value="email" /> E-mail</label> 
        </div>
      </div>
      <div class="form-error" id="sendErr"></div>
      <div class="form-actions">
        <button type="button" id="btnCancelSend">Cancelar</button>
        <button type="button" id="btnConfirmSend" class="primary">Enviar</button>
      </div>
    </div>
  </div>
  <!-- Modal de sucesso pós-envio -->
  <div class="form-modal" id="successModal" aria-hidden="true">
    <div class="form-backdrop" id="successModalBackdrop"></div>
    <div class="form-box">
      <div class="form-title">Cotação enviada</div>
      <div class="row">
        <div id="successMsg" style="color:#e5e7eb">Enviamos sua cotação.<br>Em breve entraremos em contato.</div>
      </div>
      <div class="form-actions">
        <button type="button" id="btnOkSuccess" class="primary">OK</button>
      </div>
    </div>
  </div>
  <button type="button" id="cartSticky" class="cart-sticky" aria-label="Abrir cotação">
    <span class="count">0 itens</span> <span class="total">$ 0.00</span>
  </button>
</body>
<script>
  // Definir funções globais ANTES da IIFE para garantir que estejam disponíveis
  // Estas funções serão atualizadas dentro da IIFE com as referências corretas
  window.handleQtyClick = function(btn, delta) {
    if (typeof window._handleQtyClickImpl === 'function') {
      window._handleQtyClickImpl(btn, delta);
    } else {
      console.error('Sistema ainda não inicializado! handleQtyClick chamado mas _handleQtyClickImpl não existe.');
    }
  };

  window.handleImgClick = function(img) {
    if (typeof window._handleImgClickImpl === 'function') {
      window._handleImgClickImpl(img);
    }
  };

  window.handleCardClick = function(card, ev) {
    if (typeof window._handleCardClickImpl === 'function') {
      window._handleCardClickImpl(card, ev);
    }
  };

  // Verificar se as funções foram definidas
  console.log('handleQtyClick definida?', typeof window.handleQtyClick);
  console.log('handleImgClick definida?', typeof window.handleImgClick);
  console.log('handleCardClick definida?', typeof window.handleCardClick);

(function(){
  console.log('=== SCRIPT INICIADO ===');
  // Base relativa ao arquivo atual para evitar 404 quando hospedado em subpastas
  const API_SEND_EMAIL = new URL('send_email.php', window.location.href).toString();
  const API_SEND_QUOTE = new URL('send_quote.php', window.location.href).toString();
  const API_SEND_WHATS = new URL('send_whatsapp.php', window.location.href).toString();
  const API_SAVE_ORDER = new URL('save_order.php', window.location.href).toString();
  const API_CREATE_ORDER = new URL('create_order_link.php', window.location.href).toString();
  const VENDEDOR_EMAIL = <?= json_encode(isset($_SESSION['vendedor_email']) ? (string)$_SESSION['vendedor_email'] : '') ?>;
  const SEND_MODE = <?= json_encode(isset($_SESSION['vendedor_envio']) ? (string)$_SESSION['vendedor_envio'] : 'E') ?>; // 'E' (e-mail) ou 'Z' (WhatsApp)
  const SELLER_WHATS = <?= json_encode(isset($_SESSION['vendedor_whats']) ? (string)$_SESSION['vendedor_whats'] : '') ?>;
  const EMPRESA_PARAM = <?= json_encode($__empresaParam ?? '') ?>;
  const STORE_AUTH = <?= isset($_SESSION['auth_ok']) && $_SESSION['auth_ok'] === true ? 'true' : 'false' ?>;
  const sessCliente = {
    id: <?= json_encode(isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] : null) ?>,
    nome: <?= json_encode(isset($_SESSION['store_name']) ? (string)$_SESSION['store_name'] : '') ?>,
    email: <?= json_encode(isset($_SESSION['store_email']) ? (string)$_SESSION['store_email'] : '') ?>,
    whats: <?= json_encode(isset($_SESSION['store_whats']) ? (string)$_SESSION['store_whats'] : '') ?>
  };
  const $ = (sel, root=document) => root.querySelector(sel);
  const $$ = (sel, root=document) => Array.from(root.querySelectorAll(sel));
  const storeKey = 'cartV1';
  const lastKey  = 'lastCartV1';
  const FIRST_VISIT_FLAG = 'cartInitSessionV1';

  function formatPhoneForWhats(raw, defaultCountry='55') {
    const s = String(raw||'').trim();
    if (!s) return '';
    // If user typed with +<country>, respect it
    if (/^\+\d{6,16}$/.test(s)) {
      return s.replace(/\D+/g,'');
    }
    let d = s.replace(/\D+/g,'');
    if (!d) return '';
    // Already has common country codes
    if (/^(55|1|351|34|33|44|49|39|351)/.test(d)) {
      return d;
    }
    // Remove leading zeros
    d = d.replace(/^0+/, '');
    // Heuristic: if it's 10 digits like (xxx) xxx-xxxx -> US/Canada
    if (d.length === 10) {
      return '1' + d;
    }
    // 11 digits starting with 1 (US) -> keep
    if (d.length === 11 && d.startsWith('1')) {
      return d;
    }
    // Fallback: prepend provided default country
    return (defaultCountry.replace(/\D+/g,'') || '55') + d;
  }
  const cartGroupKey = 'cartGroupV1';
  const lastEmpresaKey = 'lastEmpresaParamV1';
  try {
    if (!sessionStorage.getItem(FIRST_VISIT_FLAG)) {
      localStorage.removeItem(storeKey);
      localStorage.removeItem(lastKey);
      localStorage.removeItem(cartGroupKey);
      sessionStorage.setItem(FIRST_VISIT_FLAG, '1');
    }
  } catch(e) {}
  // Limpar carrinho ao trocar entre PNB/ZAP na primeira carga da página
  try {
    const currentEmpresa = <?= json_encode($__empresaParam ?? '') ?>;
    const currentGroup = (function(s){ const emp=String(s||'').toLowerCase(); if(emp.includes('zap')) return 'ZAP'; if(emp.includes('pnb')||emp==='ct'||emp==='fl'||emp==='ga') return 'PNB'; return ''; }) (currentEmpresa);
    const lastEmp = localStorage.getItem(lastEmpresaKey) || '';
    const lastGroup = (function(s){ const emp=String(s||'').toLowerCase(); if(emp.includes('zap')) return 'ZAP'; if(emp.includes('pnb')||emp==='ct'||emp==='fl'||emp==='ga') return 'PNB'; return ''; }) (lastEmp);
    // Verifica grupo existente do carrinho pelo conteúdo salvo (compatível com versões antigas)
    let existingGroup = '';
    try {
      const raw = localStorage.getItem(storeKey);
      if (raw) {
        const obj = JSON.parse(raw)||{};
        const values = Object.values(obj||{});
        const hasItems = values.some(it=> (it&&typeof it==='object' && (it.qty|0)>0));
        if (hasItems) {
          for (const it of values){ const g = (it && (it.empresa||'') ? (function(s){ const e=String(s||'').toLowerCase(); if(e.includes('zap')) return 'ZAP'; if(e.includes('pnb')||e==='ct'||e==='fl'||e==='ga') return 'PNB'; return ''; })(it.empresa) : ''); if (g){ existingGroup = g; break; } }
          if (!existingGroup) { existingGroup = localStorage.getItem(cartGroupKey) || ''; }
          // Se grupos divergem OU se não conseguimos determinar o grupo existente, limpar para evitar mistura
          if ((lastGroup && currentGroup && lastGroup !== currentGroup) || (existingGroup && currentGroup && existingGroup !== currentGroup) || (!existingGroup && currentGroup)){
            localStorage.removeItem(storeKey);
            localStorage.removeItem(lastKey);
            localStorage.removeItem(cartGroupKey);
          }
        }
      }
    } catch(e) {}
    // Persistir chaves de referência para próximas navegações
    try { if (currentGroup) localStorage.setItem(cartGroupKey, currentGroup); } catch(e) {}
    localStorage.setItem(lastEmpresaKey, String(currentEmpresa||''));
  } catch(e) {}
  let cart = {};
  const allowQty = true;
  let locked = true; // Declarar locked no início para evitar erro de referência


  // Função para garantir que o código sempre tenha 4 dígitos
  function formatCode(code) {
    if (!code) return '';
    const digits = String(code).replace(/\D/g, '');
    return digits.padStart(4, '0');
  }

  function loadCart(){
    try { 
      cart = JSON.parse(localStorage.getItem(storeKey) || '{}') || {}; 
      // Reformatar códigos existentes para garantir 4 dígitos
      const newCart = {};
      Object.values(cart).forEach(item => {
        if (item.code) {
          const formattedCode = formatCode(item.code);
          newCart[formattedCode] = {
            ...item,
            code: formattedCode
          };
        }
      });
      cart = newCart;
      // Salvar o carrinho reformatado
      if (Object.keys(cart).length > 0) {
        saveCart();
      }
      // Enforçar grupo do carrinho de acordo com a página atual (PNB/ZAP)
      try {
        const currentEmpresa = <?= json_encode($__empresaParam ?? '') ?>;
        const currentGroup = groupFromEmpresa(currentEmpresa);
        const values = Object.values(cart||{});
        const hasItems = values.some(it=> (it && typeof it==='object' && (it.qty|0)>0));
        if (hasItems && currentGroup) {
          let existingGroup = '';
          for (const it of values){ const g = groupFromEmpresa(it && it.empresa); if (g){ existingGroup = g; break; } }
          if (!existingGroup) { try { existingGroup = localStorage.getItem(cartGroupKey) || ''; } catch(e) { existingGroup = ''; } }
          if (!existingGroup || existingGroup !== currentGroup) {
            cart = {};
            localStorage.removeItem(storeKey);
            localStorage.removeItem(lastKey);
          }
          // Persistir grupo atual
          try { if (currentGroup) localStorage.setItem(cartGroupKey, currentGroup); } catch(e) {}
        }
      } catch(e){}
    } catch(e){ 
      cart = {}; 
    }
  }
  // Index de nomes visíveis na página atual por código
  function buildCodeNameIndex(){
    const idx = {};
    $$('.qty-row').forEach(row => {
      const c = formatCode(row.getAttribute('data-code')||'');
      const n = row.getAttribute('data-name')||'';
      if (c && n) idx[c] = n;
    });
    return idx;
  }
  const codeNameIndex = buildCodeNameIndex();
  // Backfill de nomes ausentes logo após carregar carrinho
  (function backfillNamesAfterLoad(){
    let changed = false;
    Object.values(cart).forEach(it=>{
      if (!it.name){
        const n = codeNameIndex[it.code] || '';
        if (n){ it.name = n; if (cart[it.code]) cart[it.code].name = n; changed = true; }
      }
    });
    if (changed) saveCart();
  })();

  // Auto copiar pedido quando abrir com parâmetro copy=1
  (function autoCopyFromParam(){
    try {
      const url = new URL(window.location.href);
      if (url.searchParams.get('copy') !== '1') return;
      const cleanUrl = () => {
        try {
          url.searchParams.delete('copy');
          const qs = url.searchParams.toString();
          window.history.replaceState({}, '', url.pathname + (qs ? ('?' + qs) : ''));
        } catch(e){}
      };
      let tries = 0;
      const MAX_TRIES = 8; // ~4s total
      const doAttempt = () => {
        tries++;
        // Aguarda itens do carrinho/preload
        const hasItems = Object.values(cart||{}).some(it=> (it.qty|0) > 0);
        if (!hasItems && tries < MAX_TRIES){ setTimeout(doAttempt, 500); return; }
        const text = toClipboardText();
        if (!text){ cleanUrl(); return; }
        if (navigator.clipboard && navigator.clipboard.writeText){
          navigator.clipboard.writeText(text).then(cleanUrl).catch(()=>{
            const ta = document.createElement('textarea');
            ta.value = text; document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); } catch(e){}
            ta.remove(); cleanUrl();
          });
        } else {
          const ta = document.createElement('textarea');
          ta.value = text; document.body.appendChild(ta); ta.select();
          try { document.execCommand('copy'); } catch(e){}
          ta.remove(); cleanUrl();
        }
      };
      setTimeout(doAttempt, 250);
    } catch(e){}
  })();
  function saveCart(){ localStorage.setItem(storeKey, JSON.stringify(cart)); }

  function saveLastCartSnapshot(){
    try {
      const snapshot = { t: Date.now(), items: cart };
      localStorage.setItem(lastKey, JSON.stringify(snapshot));
    } catch(e){ /* noop */ }
  }

  function getLastCartSnapshot(){
    try {
      const raw = localStorage.getItem(lastKey);
      if (!raw) return null;
      const obj = JSON.parse(raw);
      if (!obj || typeof obj !== 'object' || !obj.items) return null;
      return obj;
    } catch(e){ return null; }
  }

  function fmtMoney(v){
    if (typeof v !== 'number' || !isFinite(v)) return '';
    return '$ ' + v.toFixed(2);
  }

  function groupFromEmpresa(s){
    try {
      const emp = String(s||'').toLowerCase();
      if (emp.includes('zap')) return 'ZAP';
      if (emp.includes('pnb') || emp === 'ct' || emp === 'fl' || emp === 'ga') return 'PNB';
      return '';
    } catch(e) { return ''; }
  }

  function clearCart(){
    cart = {};
    saveCart();
    try { localStorage.removeItem(cartGroupKey); } catch(e) {}
    updateCardUI();
    renderCartPanel();
  }

  function buildCartPayload(){
    const lines = Object.values(cart).filter(it=>it.qty>0).sort((a,b)=>a.code.localeCompare(b.code));
    const payload = { v:1, emp: <?= json_encode($__empresaParam ?? '') ?>, t: Date.now(),
      items: lines.map(it=>({ c: it.code, q: it.qty, p: (typeof it.price==='number'?it.price:parseFloat(it.price)||0), n: (it.name||''), e: (it.empresa||'') })) };
    try { return JSON.stringify(payload); } catch(e){ return '{}'; }
  }

  function buildCartUrl(){
    const payload = buildCartPayload();
    const deep = new URL(window.location.href);
    deep.searchParams.delete('load');
    deep.searchParams.set('load', encodeURIComponent(payload));
    return deep.href;
  }

  function updateCardUI(){
    console.log('updateCardUI chamado');
    const rows = $$('.qty-row');
    console.log('updateCardUI: encontrou', rows.length, 'qty-row');
    rows.forEach(row => {
      const code = formatCode(row.getAttribute('data-code') || '');
      const qty = cart[code]?.qty || 0;
      console.log('updateCardUI: code =', code, ', qty =', qty);
      const card = row.closest('.card');
      console.log('updateCardUI: card encontrado?', !!card);
      const qEl = $('.qty', row);
      if (qEl) {
        qEl.textContent = String(qty);
        console.log('updateCardUI: quantidade atualizada para', qty);
      }
      const badge = card ? $('.sel-badge', card) : null;
      if (qty > 0) {
        if (card) {
          card.classList.add('selected');
          console.log('updateCardUI: classe selected ADICIONADA ao card');
        }
        if (badge) badge.style.display = 'block';
      } else {
        if (card) {
          card.classList.remove('selected');
          console.log('updateCardUI: classe selected REMOVIDA do card');
        }
        if (badge) badge.style.display = 'none';
      }
    });
    console.log('updateCardUI concluído');
  }
 
  // Flag global: página aberta via ID de pedido?
  const OPENED_BY_ORDER_PARAM = <?= isset($orderParam) && $orderParam !== '' ? 'true' : 'false' ?>;
  // Número do pedido (quando aberto via link do e-mail)
  const ORDER_ID = <?= json_encode(isset($pedidoId) ? (string)$pedidoId : '') ?>;
  // Dados do cliente carregados do pedido (quando aplicável)
  const ORDER_CLIENT_NAME  = <?= json_encode($clienteNomeFromOrder ?? '') ?>;
  const ORDER_CLIENT_EMAIL = <?= json_encode($clienteEmailFromOrder ?? '') ?>;
  const ORDER_CLIENT_TEL   = <?= json_encode($clienteTelFromOrder ?? '') ?>;

  // Persistir flag de abertura por link de pedido para manter comportamento ao trocar de seção
  try {
    if (OPENED_BY_ORDER_PARAM && ORDER_ID) {
      localStorage.setItem('openedByOrder', '1');
      localStorage.setItem('openedOrderId', String(ORDER_ID));
    } else {
      // Quando a página é aberta sem id, não habilitar copiar nem reaproveitar id anterior
      localStorage.removeItem('openedByOrder');
      localStorage.removeItem('openedOrderId');
    }
  } catch(e) {}
  let OPENED_BY_ORDER = OPENED_BY_ORDER_PARAM;
  try {
    // Não promover de localStorage quando não há id na URL
    if (!OPENED_BY_ORDER && ORDER_ID) { OPENED_BY_ORDER = true; }
  } catch(e) {}
  // Resolver ID do pedido persistido para uso em ações (copiar/salvar)
  const ORDER_ID_SAVED = (ORDER_ID && String(ORDER_ID)) || '';
  // ID temporário criado ao gerar link curto (quando a página não foi aberta via pedido)
  let TEMP_ORDER_ID = '';

  function renderCartPanel(){
    const itemsDiv = $('#cartItems');
    const summaryEl = $('#cartSummary');
    const stickyEl = $('#cartSticky');
    const btnClear = $('#btnClear');
    const hintEl = $('#cartHint');
    const btnSend = $('#btnSend');
    const btnCopy = $('#btnCopy');
    const btnRestore = $('#btnRestore');
    const dotEl = $('#cartDot');
    const lines = Object.values(cart)
      .filter(it => it.qty > 0)
      .sort((a,b)=> a.name.localeCompare(b.name));
    itemsDiv.innerHTML = '';
    let total = 0;
    let didBackfill = false;
    lines.forEach(it => {
      const line = document.createElement('div');
      line.className = 'cart-line';
      const price = (typeof it.price === 'number') ? it.price : (parseFloat(it.price)||0);
      total += price * it.qty;
      // Adicionar prefixo baseado na empresa do produto quando foi adicionado
      let prefix = '';
      let productName = it.name;
      if (!productName) {
        const row = document.querySelector(`.qty-row[data-code="${CSS.escape(it.code)}"]`);
        const dn = row ? (row.getAttribute('data-name') || '') : '';
        if (dn) {
          productName = dn;
          it.name = dn;
          if (cart[it.code]) cart[it.code].name = dn;
          didBackfill = true;
        }
      }
      const prodEmpresa = it.empresa || '';
      console.log('Produto:', productName, 'ProdEmpresa:', prodEmpresa, 'EmpresaParam:', EMPRESA_PARAM); // Debug
      
      // Se o produto tem empresa armazenada, usa ela; senão usa o parâmetro atual
      if (prodEmpresa) {
        // Normaliza e cobre valores legados como 'CT', 'FL', 'GA' como PNB
        const emp = String(prodEmpresa).toLowerCase();
        if (emp.includes('zap')) {
          prefix = 'ZAP';
        } else if (emp.includes('pnb') || emp === 'ct' || emp === 'fl' || emp === 'ga') {
          prefix = 'PNB';
        }
      } else {
        // Fallback para produtos antigos sem empresa armazenada
        if (EMPRESA_PARAM.includes('pnb')) {
          prefix = 'PNB';
        } else if (EMPRESA_PARAM.includes('zap')) {
          prefix = 'ZAP';
        }
      }
      
      const prefixHtml = '';
      line.innerHTML = `<span>${it.qty}x (${it.code}) - ${escapeHtml(productName)}</span><span>${fmtMoney(price*it.qty)}</span><button type="button" class="cart-remove" title="Remover este item" onclick="event.stopPropagation(); removeCartItem('${it.code}')">X</button>`;
      itemsDiv.appendChild(line);
    });
    if (didBackfill) { saveCart(); }
    if (lines.length > 0){
      const totalEl = document.createElement('div');
      totalEl.style.marginTop = '6px';
      totalEl.style.textAlign = 'right';
      totalEl.className = 'cart-total';
      totalEl.textContent = 'Total: ' + fmtMoney(total);
      itemsDiv.appendChild(totalEl);
    }
    const count = lines.reduce((s,it)=> s+it.qty, 0);
    const plural = count === 1 ? 'item' : 'itens';
    if (summaryEl) summaryEl.textContent = `Cotação - ${count} ${plural} - ${fmtMoney(total)}`;
    // Disable opening when empty
    const detailsEl = document.getElementById('cartPanel');
    const summaryWrap = detailsEl ? detailsEl.querySelector('summary') : null;
    const canOpen = (typeof allowQty !== 'undefined' && allowQty && count > 0);
    if (summaryWrap) summaryWrap.classList.toggle('disabled', !canOpen);
    if (detailsEl && !canOpen) { detailsEl.open = false; }
    // Toggle action buttons visibility
    const showActions = (typeof allowQty !== 'undefined' && allowQty && count > 0);
    if (btnClear) btnClear.style.display = showActions ? '' : 'none';
    if (hintEl) hintEl.style.display = showActions ? '' : 'none';
    if (btnSend) btnSend.style.display = (!OPENED_BY_ORDER && showActions) ? '' : 'none';
    if (btnCopy) btnCopy.style.display = (OPENED_BY_ORDER && showActions) ? '' : 'none';
    if (dotEl) { dotEl.style.display = showActions ? 'inline-block' : 'none'; dotEl.classList.toggle('pulse', showActions); }
    // Mostrar "Recuperar último" quando não há itens e existe snapshot
    const hasLast = !!getLastCartSnapshot();
    if (btnRestore) btnRestore.style.display = (!showActions && hasLast) ? '' : 'none';
    // Update sticky summary (only when allowed and with items)
    if (stickyEl){
      if (typeof allowQty !== 'undefined' && allowQty && count > 0){
        stickyEl.style.display = '';
        const c = stickyEl.querySelector('.count');
        const t = stickyEl.querySelector('.total');
        if (c) c.textContent = `${count} ${plural}`;
        if (t) t.textContent = fmtMoney(total);
      } else {
        stickyEl.style.display = 'none';
      }
    }
  }

  function escapeHtml(s){
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  function toClipboardText(){
    const items = Object.values(cart).filter(it=>it.qty>0).sort((a,b)=>a.name.localeCompare(b.name));
    if (items.length === 0) return 'Cotação vazia';
    const count = items.reduce((s,it)=> s+it.qty, 0);
    let total = 0;
    const CURRENT_ORDER_ID = (ORDER_ID_SAVED || TEMP_ORDER_ID);
    const header = `PRODUTOS - ${count} ${count===1?'item':'itens'}`;
    // Buscar dados do cliente preferindo localStorage (modal pode estar fechado)
    const nomeEl = document.getElementById('cliNome');
    const emailEl = document.getElementById('cliEmail');
    const telEl = document.getElementById('cliTel');
    const nome = (ORDER_CLIENT_NAME || localStorage.getItem('cliNome') || nomeEl?.value || '').trim();
    const email = (ORDER_CLIENT_EMAIL || localStorage.getItem('cliEmail') || emailEl?.value || '').trim();
    const tel = (ORDER_CLIENT_TEL || localStorage.getItem('cliTel') || telEl?.value || '').trim();
    const telDigits = String(tel||'').replace(/\D+/g,'');
    let waNum = '';
    if (telDigits.length === 10) {
      waNum = '1' + telDigits; // US numbers: add country code 1
    } else if (telDigits.length === 11 && telDigits.startsWith('1')) {
      waNum = telDigits;
    } else {
      waNum = telDigits; // fallback: use as-is, never add 55
    }
    const phoneLine = `${tel}`;
    const titleLine = CURRENT_ORDER_ID ? `*NOVA COTAÇÃO - ${CURRENT_ORDER_ID}*` : '*NOVA COTAÇÃO*';
    const infoBlock = [
      titleLine,
      '',
      `*${nome}*`,
      `${email}`,
      phoneLine,
      ''
    ];
    const lines = [...infoBlock, header, ''];
    items.forEach(it => {
      const price = (typeof it.price === 'number') ? it.price : (parseFloat(it.price)||0);
      const lineTotal = price * it.qty;
      total += lineTotal;
      const nm = it.name || (typeof codeNameIndex!=='undefined' ? (codeNameIndex[it.code]||'') : '');
      const codeDisp = String(it.code||'').replace(/^0+/, '') || '0';
      lines.push(`PROD = ${codeDisp} || QTDE = ${it.qty}`);
      lines.push(`${nm} | ${fmtMoney(lineTotal)}`);
      lines.push('');
    });
    lines.push(`*TOTAL: ${fmtMoney(total)}*`);
    lines.push('');    
    return lines.join('\n');
  }

  // Garante que temos Nome, E-mail e Telefone antes de copiar
  function ensureClientInfo(){
    try {
      let nome = (localStorage.getItem('cliNome') || '').trim();
      let email = (localStorage.getItem('cliEmail') || '').trim();
      let tel = (localStorage.getItem('cliTel') || '').trim();
      if (!nome){
        const v = prompt('Informe o Nome/Loja:','');
        if (v === null) return false;
        nome = v.trim();
        localStorage.setItem('cliNome', nome);
        const el = document.getElementById('cliNome'); if (el) el.value = nome;
      }
      if (!email){
        const v = prompt('Informe o e-mail:','');
        if (v === null) return false;
        email = v.trim();
        localStorage.setItem('cliEmail', email);
        const el = document.getElementById('cliEmail'); if (el) el.value = email;
      }
      if (!tel){
        const v = prompt('Informe o telefone no formato (xxx) xxx-xxxx:','');
        if (v === null) return false;
        tel = maskPhoneUS ? maskPhoneUS(v) : v;
        localStorage.setItem('cliTel', tel);
        const el = document.getElementById('cliTel'); if (el) el.value = tel;
      }
      return true;
    } catch(e){ return true; }
  }

  function setQty(code, name, price, empresa, qty){
    console.log('setQty chamado:', { code, name, price, empresa, qty });
    qty = Math.max(0, qty|0);
    code = formatCode(code);
    if (!code) {
      console.log('setQty: código inválido, retornando');
      return;
    }
    const currGroup = groupFromEmpresa(empresa);
    if (Object.keys(cart).length > 0) {
      let existingGroup = '';
      for (const it of Object.values(cart)) { const g = groupFromEmpresa(it.empresa); if (g) { existingGroup = g; break; } }
      if (!existingGroup) {
        try {
          existingGroup = localStorage.getItem(cartGroupKey) || '';
        } catch(e) {
          existingGroup = '';
        }
      }
      if ((existingGroup && currGroup && existingGroup !== currGroup) || (!existingGroup && currGroup)) { clearCart(); }
    }
    if (!cart[code]) cart[code] = { code, name: name||codeNameIndex[code]||'', price: (price!=='' ? parseFloat(price) : 0), qty: 0, empresa: empresa||'' };
    // Nunca sobrescrever com vazio; se name vier vazio, tente índice da página
    const resolvedName = name || codeNameIndex[code] || cart[code].name;
    if (resolvedName) cart[code].name = resolvedName;
    cart[code].price = (price!=='' ? parseFloat(price) : cart[code].price);
    cart[code].empresa = empresa || cart[code].empresa;
    cart[code].qty = qty;
    if (cart[code].qty === 0) { delete cart[code]; }
    console.log('setQty: cart atualizado:', cart);
    saveCart();
    try {
      const hasItems = Object.values(cart).some(it=> (it.qty|0) > 0);
      if (hasItems && currGroup) localStorage.setItem(cartGroupKey, currGroup);
      else localStorage.removeItem(cartGroupKey);
    } catch(e) {
      // Ignorar erro
    }
    console.log('setQty: chamando updateCardUI...');
    updateCardUI();
    console.log('setQty: chamando renderCartPanel...');
    renderCartPanel();
    console.log('setQty: concluído!');
  }

  function removeCartItem(code){
    code = formatCode(code);
    if (!code) return;
    if (cart[code]){
      delete cart[code];
      saveCart();
      try {
        const values = Object.values(cart||{});
        const hasItems = values.some(it=> (it.qty|0) > 0);
        if (!hasItems) localStorage.removeItem(cartGroupKey);
      } catch(e) {
        // Ignorar erro
      }
      updateCardUI();
      renderCartPanel();
    }
  }
  // Expor globalmente para funcionar com onclick inline nas linhas do carrinho
  window.removeCartItem = removeCartItem;

  function inc(code, name, price, empresa, delta){
    const current = cart[code]?.qty || 0;
    setQty(code, name, price, empresa, current + delta);
  }
  
  // Implementação real da função global (atualiza a referência)
  window._handleQtyClickImpl = function(btn, delta) {
    if (locked) {
      return;
    }
    const row = btn.closest('.qty-row');
    if (!row) {
      return;
    }
    const code = formatCode(row.getAttribute('data-code') || '');
    const name = row.getAttribute('data-name') || '';
    const price = row.getAttribute('data-price') || '';
    
    if (!code) {
      return;
    }
    
    inc(code, name, price, EMPRESA_PARAM, delta);
  };
  
  // Atualizar a função global para usar a implementação
  window.handleQtyClick = window._handleQtyClickImpl;
  
  // Implementação para imagem
  window._handleImgClickImpl = function(img) {
    const src = img.getAttribute('src') || '';
    const alt = img.getAttribute('alt') || '';
    if (src) {
      openImgModal(src, alt);
    }
  };
  window.handleImgClick = window._handleImgClickImpl;
  
  // Implementação para card
  window._handleCardClickImpl = function(card, ev) {
    if (ev.target.closest('.qty-row') || ev.target.closest('button') || ev.target.closest('a') || ev.target.closest('input') || ev.target.closest('select') || ev.target.closest('textarea')) {
      return;
    }
    const imgEl = card.querySelector('.prod-img');
    if (imgEl) {
      const src = imgEl.getAttribute('src') || '';
      const alt = imgEl.getAttribute('alt') || '';
      if (src) {
        openImgModal(src, alt);
      }
    }
  };
  window.handleCardClick = window._handleCardClickImpl;


  function copyQuoteToClipboard(){
    if (!ensureClientInfo()) return;
    const text = toClipboardText();
    navigator.clipboard?.writeText(text).catch(()=>{
      const ta = document.createElement('textarea');
      ta.value = text; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); } catch(e){}
      ta.remove();
    });
  }
  function copyLinkToClipboard(){
    const lines = Object.values(cart).filter(it=>it.qty>0);
    if (lines.length===0) return;
    // Tentar gerar URL curta baseada no id do pedido
    (async ()=>{
      let shortUrl = '';
      try {
        // Usa dados do cliente já digitados/localStorage se existirem
        const nome = (ORDER_CLIENT_NAME || localStorage.getItem('cliNome') || (document.getElementById('cliNome')?.value||'')).trim();
        const email = (ORDER_CLIENT_EMAIL || localStorage.getItem('cliEmail') || (document.getElementById('cliEmail')?.value||'')).trim();
        const tel = (ORDER_CLIENT_TEL || localStorage.getItem('cliTel') || (document.getElementById('cliTel')?.value||'')).trim();
        const payload = buildCartPayload();
        const resShort = await fetch(API_CREATE_ORDER, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            vendedor_nome: (sessVendedor && sessVendedor.nome) ? sessVendedor.nome : 'Vendedor',
            vendedor_email: (sessVendedor && sessVendedor.email) ? sessVendedor.email : '',
            cliente_nome: nome || 'Cliente',
            cliente_email: email || 'cliente@exemplo.com',
            cliente_tel: tel || '(000) 000-0000',
            empresa: <?= json_encode($__empresaParam ?? '') ?>,
            payload_json: payload
          })
        });
        const jsShort = await resShort.json().catch(()=>null);
        if (resShort.ok && jsShort && jsShort.ok && jsShort.url) { shortUrl = String(jsShort.url); }
      } catch(e) {}
      const link = shortUrl || buildCartUrl();
      try {
        await navigator.clipboard.writeText(link);
      } catch(e){
        const ta = document.createElement('textarea');
        ta.value = link; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch(e){}
        ta.remove();
      }
    })();
  }
  const sendModal = document.getElementById('sendModal');
  const sendBackdrop = document.getElementById('sendModalBackdrop');
  const cliNome = document.getElementById('cliNome');
  const cliEmail = document.getElementById('cliEmail');
  const cliTel = document.getElementById('cliTel');
  const canalEmail = document.getElementById('canalEmail');
  const canalWhats = document.getElementById('canalWhats');
  const sendErr = document.getElementById('sendErr');
  const btnCancelSend = document.getElementById('btnCancelSend');
  const btnConfirmSend = document.getElementById('btnConfirmSend');
  // Sucesso
  const successModal = document.getElementById('successModal');
  const successBackdrop = document.getElementById('successModalBackdrop');
  const btnOkSuccess = document.getElementById('btnOkSuccess');

  const vendedores = <?= json_encode($vendedores ?? []) ?>;
  const sessVendedor = <?= json_encode([
    'nome'  => isset($_SESSION['vendedor_nome']) ? (string)$_SESSION['vendedor_nome'] : '',
    'email' => isset($_SESSION['vendedor_email']) ? (string)$_SESSION['vendedor_email'] : '',
    'envio' => isset($_SESSION['vendedor_envio']) ? (string)$_SESSION['vendedor_envio'] : 'E',
    'whats' => isset($_SESSION['vendedor_whats']) ? (string)$_SESSION['vendedor_whats'] : ''
  ], JSON_UNESCAPED_UNICODE) ?>;
  
  // Debug: mostrar vendedores carregados no console
  console.log('=== DEBUG VENDEDORES ===');
  console.log('Total de vendedores:', vendedores.length);
  console.log('Dados dos vendedores:', vendedores);
  if (vendedores.length > 0) {
    console.log('✅ Vendedores carregados da tabela sales_reps');
    vendedores.forEach((v, i) => {
      console.log(`${i+1}. ID: ${v.id}, Nome: ${v.nome}, Email: ${v.email}`);
    });
  } else {
    console.log('❌ Nenhum vendedor encontrado na tabela sales_reps');
  }
  console.log('========================');

  function openSendModal(){
    // Preencher com dados da loja autenticada por chave (sessCliente) quando houver;
    // caso contrário, usar dados locais do dispositivo ou do pedido.
    try {
      const nomePref  = (STORE_AUTH && sessCliente && sessCliente.nome) ? sessCliente.nome : (typeof ORDER_CLIENT_NAME  !== 'undefined' ? ORDER_CLIENT_NAME  : localStorage.getItem('cliNome'));
      const emailPref = (STORE_AUTH && sessCliente && sessCliente.email) ? sessCliente.email : (typeof ORDER_CLIENT_EMAIL !== 'undefined' ? ORDER_CLIENT_EMAIL : localStorage.getItem('cliEmail'));
      const telRaw    = (STORE_AUTH && sessCliente && sessCliente.whats) ? sessCliente.whats : (typeof ORDER_CLIENT_TEL  !== 'undefined' ? ORDER_CLIENT_TEL  : localStorage.getItem('cliTel'));
      if (cliNome)  cliNome.value  = (nomePref  || cliNome.value || '');
      if (cliEmail) cliEmail.value = (emailPref || cliEmail.value || '');
      if (cliTel)   cliTel.value   = (function(v){
        v = String(v||'').replace(/\D+/g,'');
        // Formatar no padrão (xxx) xxx-xxxx quando 10 dígitos
        if (v.length === 10) return `(${v.slice(0,3)}) ${v.slice(3,6)}-${v.slice(6)}`;
        if (v.length === 11 && v.startsWith('1')) { const d=v.slice(1); return `(${d.slice(0,3)}) ${d.slice(3,6)}-${d.slice(6)}`; }
        return cliTel.value || '';
      })(telRaw);
    } catch(e){}
    if (sendErr){ sendErr.style.display = 'none'; sendErr.textContent = ''; }
    if (sendModal){ sendModal.classList.add('open'); sendModal.setAttribute('aria-hidden', 'false'); }
  }
  function closeSendModal(){ if (sendModal){ sendModal.classList.remove('open'); sendModal.setAttribute('aria-hidden', 'true'); } }

  function openSuccessModal(){
    if (successModal){ successModal.classList.add('open'); successModal.setAttribute('aria-hidden', 'false'); }
  }
  function closeSuccessModal(){
    if (successModal){ successModal.classList.remove('open'); successModal.setAttribute('aria-hidden', 'true'); }
  }

  function maskPhoneUS(val){
    const d = String(val||'').replace(/\D+/g,'').slice(0,10);
    const a = d.slice(0,3);
    const b = d.slice(3,6);
    const c = d.slice(6,10);
    if (d.length <= 3) return a ? `(${a}` : '';
    if (d.length <= 6) return `(${a}) ${b}`;
    return `(${a}) ${b}-${c}`;
  }
  cliTel?.addEventListener('input', ()=>{
    const v = cliTel.value;
    const masked = maskPhoneUS(v);
    cliTel.value = masked;
    try { localStorage.setItem('cliTel', cliTel.value); } catch(e){}
  });
  // Persistir campos do cliente conforme digitação
  cliNome?.addEventListener('input', ()=>{ try { localStorage.setItem('cliNome', cliNome.value); } catch(e){} });
  cliEmail?.addEventListener('input', ()=>{ try { localStorage.setItem('cliEmail', cliEmail.value); } catch(e){} });

  $('#btnSend')?.addEventListener('click', openSendModal);
  btnCancelSend?.addEventListener('click', closeSendModal);
  sendBackdrop?.addEventListener('click', closeSendModal);
  // Eventos do modal de sucesso
  btnOkSuccess?.addEventListener('click', closeSuccessModal);
  successBackdrop?.addEventListener('click', closeSuccessModal);
  document.addEventListener('keydown', (ev)=>{
    if (ev.key === 'Escape' && successModal && successModal.classList.contains('open')) closeSuccessModal();
  });
  btnConfirmSend?.addEventListener('click', async ()=>{
    const nome = cliNome ? (cliNome.value||'') : '';
    const email = cliEmail ? (cliEmail.value||'') : '';
    const tel  = cliTel ? (cliTel.value||'') : '';
    const canal = (canalWhats && canalWhats.checked) ? 'whats' : 'email';
    let err = '';
    if (!nome.trim()) err = 'Informe seu nome.';
    else if (canal === 'email' && !/^\S+@\S+\.[A-Za-z]{2,}$/.test(email.trim())) err = 'Informe um e-mail válido.';
    else if (canal === 'whats' && !/^\(\d{3}\) \d{3}-\d{4}$/.test(tel.trim())) err = 'Informe o telefone no formato (xxx) xxx-xxxx.';
    if (err){ if (sendErr){ sendErr.textContent = err; sendErr.style.display = 'block'; } return; }
    if (sendErr){ sendErr.style.display = 'none'; sendErr.textContent = ''; }
    try {
      btnConfirmSend.disabled = true; btnConfirmSend.textContent = 'Enviando...';
      // 1) Solicitar URL curta baseada no id do pedido para evitar link longo no WhatsApp
      let shortUrl = '';
      try {
        const payload = buildCartPayload();
        const resShort = await fetch(API_CREATE_ORDER, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            vendedor_nome: (sessVendedor && sessVendedor.nome) ? sessVendedor.nome : 'Vendedor',
            vendedor_email: (sessVendedor && sessVendedor.email) ? sessVendedor.email : '',
            cliente_nome: nome,
            cliente_email: email,
            cliente_tel: tel,
            empresa: <?= json_encode($__empresaParam ?? '') ?>,
            payload_json: payload
          })
        });
        const jsShort = await resShort.json().catch(()=>null);
        if (resShort.ok && jsShort && jsShort.ok) {
          if (jsShort.url) shortUrl = String(jsShort.url);
          if (jsShort.id)  TEMP_ORDER_ID = String(jsShort.id);
        }
      } catch(e) { /* fallback para link longo abaixo */ }
      // 2) Montar texto AGORA com o ID resolvido para aparecer no título
      const text = toClipboardText();
      const link = shortUrl || buildCartUrl();
      const fullMsg = text; // WhatsApp: sem link no final

      // 2.1) Persistir pedido (header + itens) no momento do envio
      try {
        const orderIdForSave = (TEMP_ORDER_ID || ORDER_ID_SAVED || ORDER_ID || '');
        if (orderIdForSave) {
          const payload = buildCartPayload();
          await fetch(API_SAVE_ORDER, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              order_id: orderIdForSave,
              vendedor_nome: (sessVendedor && sessVendedor.nome) ? sessVendedor.nome : 'Vendedor',
              empresa: <?= json_encode($__empresaParam ?? '') ?>,
              payload_json: payload
            })
          }).catch(()=>null);
        } else {
          vendorSendWarning = vendorSendWarning || 'Aviso: ID do pedido não resolvido para salvar itens.';
        }
      } catch(_) { /* não bloquear o envio */ }
      const subject = (ORDER_ID ? `Nova Cotação - ${ORDER_ID}` : 'Nova Cotação');
      const message_html = `
        <div>
          <div><strong>${escapeHtml(nome)}</strong></div>
          <div>${escapeHtml(email)}</div>
          <div>${escapeHtml(tel)}</div>
          <hr/>
          <pre style="white-space:pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;">${escapeHtml(text)}</pre>
          <div style="margin-top:8px;"><a href="${link}">${escapeHtml(link)}</a></div>
        </div>`;
      let vendorSendWarning = '';
      let vendorWhatsSent = false; // marca se já enviamos ao vendedor por WhatsApp
      if ((SEND_MODE||'E').toUpperCase() === 'Z') {
        // Enviar via integração backend (sem abrir a interface do WhatsApp)
        try {
          const phone = formatPhoneForWhats(SELLER_WHATS||sessVendedor.whats||'', '55');
          if (!phone) { throw new Error('Telefone do vendedor não configurado.'); }
          const res = await fetch(API_SEND_WHATS, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
              phone, 
              message: fullMsg,
              vendedor_email: (sessVendedor && sessVendedor.email) ? sessVendedor.email : ''
            })
          });
          let js = null; try { js = await res.json(); } catch(e) { js = null; }
          if (!res.ok || !js || js.ok !== true) {
            const msg = (js && js.error) ? js.error : ('Erro HTTP ' + res.status);
            vendorSendWarning = 'Falha ao enviar ao vendedor (WhatsApp): ' + msg;
          } else {
            vendorWhatsSent = true;
          }
        } catch (eVend) {
          vendorSendWarning = 'Falha ao enviar ao vendedor (WhatsApp).';
        }
      } else {
        // Envio por e-mail ao vendedor (SMTP) — somente se o canal escolhido NÃO for WhatsApp
        if (canal !== 'whats') {
          // Deixa o backend resolver o e-mail do vendedor via sales_reps.email
          const to = VENDEDOR_EMAIL || (sessVendedor && sessVendedor.email) || '';
          // Usar o ID do pedido criado (TEMP_ORDER_ID) e o email do vendedor
          const orderIdForQuote = TEMP_ORDER_ID || ORDER_ID_SAVED || ORDER_ID || '';
          if (!orderIdForQuote) {
            vendorSendWarning = 'Falha ao enviar ao vendedor (e-mail): ID do pedido não encontrado.';
          } else {
            try {
              const res = await fetch(API_SEND_QUOTE, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  log_id: parseInt(orderIdForQuote, 10),
                  to: to, 
                  banner_text: 'Nova cotação recebida'
                })
              });
              let js = null;
              try { js = await res.json(); } catch(e) { js = null; }
              if (!res.ok || !js || js.ok !== true) { 
                throw new Error(js && js.error ? js.error : ('Erro HTTP ' + res.status)); 
              }
            } catch (eVend) {
              // Fallback: enviar e-mail simples ao vendedor via send_email.php
              try {
                const banner = 'Nova cotação recebida';
                const textCopy = toClipboardText();
                const htmlVend = `<div><div style="background:#0ea5e9;color:#fff;padding:10px 14px;border-radius:8px;margin:0 0 12px 0;font-weight:700;text-align:center;">${banner}</div><pre style="white-space:pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; color:#0f172a;">${escapeHtml(textCopy)}</pre></div>`;
                const subjectVend = `Nova Cotação #${orderIdForQuote}`;
                const resVendFallback = await fetch(API_SEND_EMAIL, {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ 
                    to: to, 
                    subject: subjectVend, 
                    message_html: htmlVend, 
                    from_name: (sessVendedor && sessVendedor.nome) ? sessVendedor.nome : 'Vendedor', 
                    from_email: (sessVendedor && sessVendedor.email) ? sessVendedor.email : '' 
                  })
                });
                let jsVendFallback = null;
                try { jsVendFallback = await resVendFallback.json(); } catch(e) { jsVendFallback = null; }
                if (!resVendFallback.ok || !jsVendFallback || jsVendFallback.ok !== true) {
                  throw new Error(jsVendFallback && jsVendFallback.error ? jsVendFallback.error : ('Erro HTTP ' + resVendFallback.status));
                }
                vendorSendWarning = '';
              } catch (eVend2) {
                vendorSendWarning = 'Falha ao enviar ao vendedor (e-mail).';
              }
            }
          }
        }
      }

      // Enviar código/link ao cliente pelo canal escolhido
      try {
        const CURRENT_ORDER_ID = (ORDER_ID_SAVED || TEMP_ORDER_ID || '');
        if (canal === 'whats') {
          // Escolher país para o cliente: se o formato do campo for (xxx) xxx-xxxx, usar EUA (1), senão BR (55)
          const isUSMask = /^\(\d{3}\) \d{3}-\d{4}$/.test(String(tel||''));
          const clientPhone = formatPhoneForWhats(tel||'', isUSMask ? '1' : '55');
          if (!clientPhone) throw new Error('Telefone do cliente inválido');
          const banner = 'Sr(a) Cliente, você está recebendo uma cópia da cotação enviada ao vendedor(a).';
          const textCopy = toClipboardText();
          const msgCli = banner + '\n\n' + textCopy; // sem link
          const resCli = await fetch(API_SEND_WHATS, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
              phone: clientPhone, 
              message: msgCli,
              vendedor_email: (sessVendedor && sessVendedor.email) ? sessVendedor.email : ''
            })
          });
          let jsCli = null; try { jsCli = await resCli.json(); } catch(e) { jsCli = null; }
          if (!resCli.ok || !jsCli || jsCli.ok !== true) { throw new Error(jsCli && jsCli.error ? jsCli.error : ('Erro HTTP ' + resCli.status)); }

          // Além do cliente: enviar também ao vendedor por WhatsApp, independentemente do SEND_MODE
          if (!vendorWhatsSent) {
            try {
              // Para o vendedor, se já vier com + ou começar com 1, respeitar; caso contrário, BR padrão
              const phoneVend = formatPhoneForWhats(SELLER_WHATS||sessVendedor.whats||'', '55');
              if (phoneVend) {
                const resVend2 = await fetch(API_SEND_WHATS, {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ 
                    phone: phoneVend, 
                    message: fullMsg,
                    vendedor_email: (sessVendedor && sessVendedor.email) ? sessVendedor.email : ''
                  })
                });
                let jsVend2 = null; try { jsVend2 = await resVend2.json(); } catch(e) { jsVend2 = null; }
                if (resVend2.ok && jsVend2 && jsVend2.ok === true) {
                  vendorWhatsSent = true;
                } else {
                  const msg = (jsVend2 && jsVend2.error) ? jsVend2.error : ('Erro HTTP ' + resVend2.status);
                  if (!vendorSendWarning) vendorSendWarning = 'Falha ao enviar ao vendedor (WhatsApp): ' + msg;
                }
              } else {
                if (!vendorSendWarning) vendorSendWarning = 'Telefone do vendedor não configurado.';
              }
            } catch (eVend2) {
              if (!vendorSendWarning) vendorSendWarning = 'Falha ao enviar ao vendedor (WhatsApp).';
            }
          }
        } else {
          const toCli = String(email||'').trim();
          if (!/^\S+@\S+\.[A-Za-z]{2,}$/.test(toCli)) throw new Error('E-mail do cliente inválido');
          // Enviar comprovante ao cliente (tarja + texto da cotação), SEM link
          const banner = 'Sr(a) Cliente, você está recebendo uma cópia da cotação enviada ao vendedor(a).';
          const textCopy = toClipboardText();
          const htmlCli = `<div><div style="background:#0ea5e9;color:#fff;padding:10px 14px;border-radius:8px;margin:0 0 12px 0;font-weight:700;text-align:center;">${banner}</div><pre style="white-space:pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; color:#0f172a;">${escapeHtml(textCopy)}</pre></div>`;
          const subjectCli = 'Sua Cotação';
          const resCli = await fetch(API_SEND_EMAIL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ to: toCli, subject: subjectCli, message_html: htmlCli, from_name: (sessVendedor && sessVendedor.nome) ? sessVendedor.nome : 'Vendedor', from_email: (sessVendedor && sessVendedor.email) ? sessVendedor.email : '' })
          });
          let jsCli = null; try { jsCli = await resCli.json(); } catch(e) { jsCli = null; }
          if (!resCli.ok || !jsCli || jsCli.ok !== true) { throw new Error(jsCli && jsCli.error ? jsCli.error : ('Erro HTTP ' + resCli.status)); }
        }
      } catch (eCli) {
        if (sendErr){ sendErr.textContent = 'Pedido enviado ao vendedor, porém falhou o envio ao cliente: ' + (eCli && eCli.message ? eCli.message : 'erro desconhecido'); sendErr.style.display = 'block'; }
        // Prossegue para sucesso do fluxo principal
      }
      try { saveLastCartSnapshot(); } catch(e){}
      cart = {}; saveCart(); updateCardUI(); renderCartPanel();
      closeSendModal();
      openSuccessModal();
      if (vendorSendWarning) {
        try {
          const msgEl = document.getElementById('successMsg');
          if (msgEl) msgEl.textContent = String(msgEl.textContent||'') + ' ' + vendorSendWarning;
        } catch(e){}
      }
    } catch(e){
      if (sendErr){ sendErr.textContent = 'Erro inesperado ao enviar por e-mail.'; sendErr.style.display = 'block'; }
    } finally {
      btnConfirmSend.disabled = false; btnConfirmSend.textContent = 'Continuar';
    }
  });

  $('#btnClear')?.addEventListener('click', ()=>{
    cart = {}; saveCart(); updateCardUI(); renderCartPanel();
  });

  // Restaurar último pedido salvo
  (function(){
    const btnRestore = document.getElementById('btnRestore');
    if (!btnRestore) return;
    btnRestore.addEventListener('click', ()=>{
      const snap = getLastCartSnapshot();
      if (!snap || !snap.items) { alert('Nenhum pedido salvo encontrado.'); return; }
      if (!confirm('Deseja recuperar o último pedido salvo neste dispositivo? Isso substituirá o carrinho atual.')) return;
      cart = snap.items || {};
      saveCart();
      updateCardUI();
      renderCartPanel();
    });
  })();

  // Copiar Pedido: copia texto formatado da cotação com feedback e fallback
  (function(){
    const btnCopy = document.getElementById('btnCopy');
    if (!btnCopy) return;
    btnCopy.addEventListener('click', async ()=>{
      const text = toClipboardText();
      if (!text) return;
      const prev = btnCopy.textContent;
      const setTmp = (t)=>{ btnCopy.textContent = t; };
      // 1) Copiar para a área de transferência (com fallback)
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          await navigator.clipboard.writeText(text);
        } else {
          const ta = document.createElement('textarea');
          ta.value = text; document.body.appendChild(ta); ta.select();
          try { document.execCommand('copy'); } catch(e){}
          ta.remove();
        }
        setTmp('Copiado!');
        setTimeout(()=>{ btnCopy.textContent = prev || 'Copiar Pedido'; }, 1200);
      } catch(e) {
        // Não bloquear fluxo de salvar no banco se a cópia falhar
      }

      // 2) Salvar no banco: excluir itens antigos do pedido e regravar todos
      try {
        const payload = buildCartPayload();
        if (!ORDER_ID_SAVED) { alert('ID do pedido não encontrado. Abra pelo link do pedido.'); return; }
        btnCopy.disabled = true; setTmp('Salvando...');
        const res = await fetch(API_SAVE_ORDER, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            order_id: ORDER_ID_SAVED,
            vendedor_nome: (sessVendedor && sessVendedor.nome) ? sessVendedor.nome : 'Vendedor',
            empresa: <?= json_encode($__empresaParam ?? '') ?>,
            payload_json: payload
          })
        });
        let js = null;
        const ct = (res.headers.get('content-type')||'').toLowerCase();
        if (ct.includes('application/json')) {
          try { js = await res.json(); } catch(e) { js = null; }
        }
        if (!js) {
          let txt = '';
          try { txt = await res.text(); } catch(e) { txt = ''; }
          try { js = JSON.parse(txt); } catch(e) { js = { ok:false, error: (txt||'Resposta vazia').slice(0,200) }; }
        }
        if (!res.ok || !js.ok) {
          const msg = js.error || ('Erro HTTP '+res.status);
          alert('Falha ao salvar o pedido: ' + msg);
          return;
        }
        // 3) Limpar carrinho após salvar
        try { saveLastCartSnapshot(); } catch(e){}
        cart = {}; saveCart(); updateCardUI(); renderCartPanel();
        setTmp('Pedido salvo');
        setTimeout(()=>{ btnCopy.textContent = prev || 'Copiar Pedido'; }, 1200);
      } catch (e) {
        alert('Erro ao salvar o pedido.');
      } finally {
        btnCopy.disabled = false;
      }
    });
  })();

  // Sticky open handler
  $('#cartSticky')?.addEventListener('click', ()=>{
    const det = document.getElementById('cartPanel');
    if (det){ det.open = true; det.scrollIntoView({behavior:'smooth', block:'center'}); }
  });

  // Intercept summary click: only allow opening when there are items and allowed
  document.addEventListener('click', (ev)=>{
    const sum = ev.target.closest('#cartPanel > summary');
    if (!sum) return;
    const count = Object.values(cart).reduce((s,it)=> s + ((it.qty|0) > 0 ? (it.qty|0) : 0), 0);
    const canOpen = (typeof allowQty !== 'undefined' && allowQty && count > 0);
    if (!canOpen){ ev.preventDefault(); ev.stopPropagation(); const det = document.getElementById('cartPanel'); if (det) det.open = false; }
  }, true);

  loadCart();
  updateCardUI();
  renderCartPanel();

  // If there is a deep-link payload (?load=...), apply it to the cart
  (function applyCartFromUrl(){
    const url = new URL(window.location.href);
    const load = url.searchParams.get('load');
    if (!load) return;
    let jsonStr = '';
    try { jsonStr = decodeURIComponent(load); } catch(e){ jsonStr = load; }
    let data = null;
    try { data = JSON.parse(jsonStr); } catch(e){ data = null; }
    if (data && Array.isArray(data.items)){
      data.items.forEach(it => {
        const code = formatCode(String(it.c || it.code || ''));
        const qty = (it.q || it.qty) | 0;
        if (!code || qty<=0) return;
        // Prefer current page product info when available
        const row = document.querySelector(`.qty-row[data-code="${CSS.escape(code)}"]`);
        const name = row ? (row.getAttribute('data-name')||'') : (it.n||'');
        const priceAttr = row ? (row.getAttribute('data-price')||'') : '';
        const price = priceAttr !== '' ? priceAttr : (typeof it.p==='number'? String(it.p) : String(parseFloat(it.p)||''));
        const empresa = row ? (row.getAttribute('data-empresa')||'') : (it.e||it.empresa||'');
        setQty(code, name, price, empresa, qty);
      });
      // Clean the URL to avoid re-applying on refresh
      url.searchParams.delete('load');
      const qs = url.searchParams.toString();
      window.history.replaceState({}, '', url.pathname + (qs ? ('?' + qs) : ''));
    }
  })();

  // Apply preload items from server when ?pedido=<id> was provided
  (function applyPreloadFromServer(){
    try {
      const pre = <?= isset($preloadItems) ? safe_json($preloadItems) : '[]' ?>;
      const openedByOrder = <?= isset($orderParam) && $orderParam !== '' ? 'true' : 'false' ?>;
      if (Array.isArray(pre) && pre.length) {
        try { if (<?= json_encode($debug ? true : false) ?>) console.log('Apply preload from server, items:', pre.length); } catch(e){}
        pre.forEach(it => {
          if (!it || typeof it !== 'object') return;
          const c = formatCode(String(it.c || ''));
          const q = Math.max(1, parseInt(it.q || 1, 10));
          const p = Number(it.p || 0);
          const n = String(it.n || '');
          const e = String(it.e || '');
          if (!c) return;
          if (!cart[c]) cart[c] = { code: c, name: n, price: p, qty: 0, empresa: e };
          cart[c].qty += q;
        });
        saveCart();
        updateCardUI();
        renderCartPanel();
        // Clean the URL to avoid re-applying on refresh
        const url = new URL(window.location.href);
        url.searchParams.delete('pedido');
        url.searchParams.delete('cod_ped');
        url.searchParams.delete('id_ped');
        url.searchParams.delete('id_pedido');
        const qs = url.searchParams.toString();
        window.history.replaceState({}, '', url.pathname + (qs ? ('?' + qs) : ''));
        // Also clear UI filters on deep-link open to avoid leftover values (e.g., '2')
        if (openedByOrder) {
          const qEl = document.querySelector('input[name="q"]');
          if (qEl) qEl.value = '';
          const agrSel = document.getElementById('agrupador');
          if (agrSel) agrSel.value = '';
        }
      }
    } catch (e) { /* noop */ }
  })();

  // If opened by order but there were 0 preloaded items (edge case), still clear filter controls
  (function clearFiltersOnOrderOpen(){
    const openedByOrder = <?= isset($orderParam) && $orderParam !== '' ? 'true' : 'false' ?>;
    if (!openedByOrder) return;
    try {
      const qEl = document.querySelector('input[name="q"]');
      if (qEl && qEl.value) qEl.value = '';
      const agrSel = document.getElementById('agrupador');
      if (agrSel && agrSel.value) agrSel.value = '';
    } catch(e){}
  })();

  

  (function(){
    // Featured carousel navigation
    document.querySelectorAll('.feat-carousel').forEach(car => {
      const track = car.querySelector('.feat-track');
      const prev = car.querySelector('.feat-nav.prev');
      const next = car.querySelector('.feat-nav.next');
      const step = ()=>{
        const card = track ? track.querySelector('.card') : null;
        return card ? (card.getBoundingClientRect().width + 12) * 2 : 520;
      };
      prev && prev.addEventListener('click', ()=>{ if (track) track.scrollBy({left: -step(), behavior:'smooth'}); });
      next && next.addEventListener('click', ()=>{ if (track) track.scrollBy({left: step(), behavior:'smooth'}); });
    });
    
    // Filtro automático do fabricante - filtrar ao mudar seleção
    function setupFabricanteFilter() {
      const fabSel = document.getElementById('fabricante');
      if (fabSel) {
        fabSel.addEventListener('change', function(){
          const v = fabSel.value;
          const url = new URL(window.location.href);
          if (v === '') {
            url.searchParams.delete('fabricante');
          } else {
            url.searchParams.set('fabricante', v);
          }
          // Manter outros parâmetros do formulário
          const formEl = document.querySelector('form#filters');
          if (formEl) {
            const empresaInput = formEl.querySelector('input[name="empresa"]');
            if (empresaInput && empresaInput.value) {
              url.searchParams.set('empresa', empresaInput.value);
            }
            const qInput = formEl.querySelector('input[name="q"]');
            if (qInput && qInput.value) {
              url.searchParams.set('q', qInput.value);
            }
            const agrupadorSel = formEl.querySelector('select[name="agrupador"]');
            if (agrupadorSel && agrupadorSel.value) {
              url.searchParams.set('agrupador', agrupadorSel.value);
            }
          }
          const qs = url.searchParams.toString();
          window.location.href = url.pathname + (qs ? ('?' + qs) : '');
        });
      }
    }
    
    // Filtro automático do agrupador
    function setupAgrupadorFilter() {
      const sel = document.getElementById('agrupador');
      if (sel) {
        sel.addEventListener('change', function(){
          const v = sel.value;
          const url = new URL(window.location.href);
          if (v === '') {
            url.searchParams.delete('agrupador');
          } else {
            url.searchParams.set('agrupador', v);
          }
          const qs = url.searchParams.toString();
          window.location.href = url.pathname + (qs ? ('?' + qs) : '');
        });
      }
    }
    
    // Executar quando DOM estiver pronto
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function(){
        setupFabricanteFilter();
        setupAgrupadorFilter();
      });
    } else {
      setupFabricanteFilter();
      setupAgrupadorFilter();
    }
  })();

  (function(){
    // Condense navbar on scroll with hysteresis to avoid jitter
    function initNavbarCondense() {
      const navbar = document.querySelector('.navbar');
      if (!navbar) return;
      
      const navToggle = document.getElementById('navToggle');
      let isCondensed = false;
      const ENTER_AT = 10; // recolher assim que rolar um pouco
      
      function setCondensed(val){
        isCondensed = !!val;
        navbar.classList.toggle('condensed', isCondensed);
        if (navToggle){
          const labelSpan = navToggle.querySelector('.label');
          if (isCondensed){
            if (labelSpan) labelSpan.textContent = 'Expandir Cabeçalho';
            navToggle.setAttribute('aria-expanded','false');
            navToggle.setAttribute('title','Expandir Cabeçalho');
          } else {
            if (labelSpan) labelSpan.textContent = 'Recolher Cabeçalho';
            navToggle.setAttribute('aria-expanded','true');
            navToggle.setAttribute('title','Recolher Cabeçalho');
          }
        }
      }
      
      const getScrollY = () => {
        const se = document.scrollingElement || document.documentElement || document.body;
        return (typeof window.scrollY === 'number' ? window.scrollY : (se ? se.scrollTop : 0)) || 0;
      };
      const apply = () => {
        const y = getScrollY();
        setCondensed(y > ENTER_AT);
      };
      
      // Aplicar estado inicial baseado na posição do scroll
      apply();
      let blockScrollUntil = 0;
      let forceExpandedUntil = 0;
      
      // Manter expandido quando o carrinho estiver aberto
      const cartDetails = document.getElementById('cartPanel');
      const isCartOpen = () => !!(cartDetails && cartDetails.open);
      const syncCartOpenState = () => {
        if (isCartOpen()) {
          setCondensed(false);
          navbar.classList.add('cart-open');
          return true;
        }
        navbar.classList.remove('cart-open');
        return false;
      };
      
      cartDetails?.addEventListener('toggle', () => {
        if (!syncCartOpenState()) {
          apply();
        }
      });
      
      // Listener de scroll para compactação automática (vários eventos/fonte de rolagem)
      const onAnyScroll = ()=>{
        // Bloqueio curto após clique para evitar recontração imediata
        const now = Date.now();
        if (now < blockScrollUntil) return;
        if (now < forceExpandedUntil) { setCondensed(false); return; }
        if (syncCartOpenState()) return;
        apply();
      };
      window.addEventListener('scroll', onAnyScroll, { passive: true });
      window.addEventListener('wheel', onAnyScroll, { passive: true });
      window.addEventListener('touchmove', onAnyScroll, { passive: true });
      const se = document.scrollingElement || document.documentElement;
      se && se.addEventListener && se.addEventListener('scroll', onAnyScroll, { passive: true });
      
      // Sentinel via IntersectionObserver (fallback robusto)
      try {
        let sentinel = document.getElementById('navSentinel');
        if (!sentinel) {
          sentinel = document.createElement('div');
          sentinel.id = 'navSentinel';
          sentinel.style.cssText = 'position:relative;height:1px;margin:0;padding:0;';
          // inserir após a navbar
          navbar.parentNode && navbar.parentNode.insertBefore(sentinel, navbar.nextSibling);
        }
        const io = new IntersectionObserver((entries)=>{
          const e = entries && entries[0];
          if (!e) return;
          // Quando o sentinel sai do viewport (isIntersecting=false), significa que rolou para baixo
          setCondensed(!e.isIntersecting);
        }, { root: null, rootMargin: '-10px 0px 0px 0px', threshold: 0 });
        io.observe(sentinel);
      } catch(_){}
      
      // Botão para expandir/recolher cabeçalho
      if (navToggle){
        navToggle.addEventListener('click', function(e){
          e.preventDefault();
          e.stopPropagation();
          // Alternar estado: se está condensado, expandir; se está expandido, condensar
          const currentState = navbar.classList.contains('condensed');
          setCondensed(!currentState);
          // Manter expandido por um curto período quando expandir manualmente
          const now = Date.now();
          blockScrollUntil = now + 400;
          if (currentState) {
            // Se estava condensado e agora vai expandir, manter expandido por um tempo
            forceExpandedUntil = now + 1200;
          }
          // Remover estado de cart-open ao expandir manualmente
          navbar.classList.remove('cart-open');
        });
      }
    }
    
    // Executar quando DOM estiver pronto
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initNavbarCondense);
    } else {
      initNavbarCondense();
    }
  })();

  // Image lightbox: open when clicking only the product image
  const imgModal = document.getElementById('imgModal');
  const imgModalImg = document.getElementById('imgModalImg');
  const imgModalBackdrop = document.getElementById('imgModalBackdrop');

  function openImgModal(src, alt){
    if (!src) return;
    imgModalImg.src = src;
    imgModalImg.alt = alt || '';
    imgModal.classList.add('open');
    imgModal.setAttribute('aria-hidden', 'false');
  }
  function closeImgModal(){
    imgModal.classList.remove('open');
    imgModal.setAttribute('aria-hidden', 'true');
    // Keep src to allow quick reopen; uncomment to clear: imgModalImg.src = '';
  }
  document.addEventListener('click', (ev)=>{
    // 1) Clique diretamente na imagem do produto
    const img = ev.target.closest('.prod-img');
    if (img){
      ev.stopPropagation();
      ev.preventDefault();
      openImgModal(img.getAttribute('src')||'', img.getAttribute('alt')||'');
      return;
    }
    // 2) Clique no card inteiro do produto (exceto controles/links/botoes)
    const card = ev.target.closest('.card');
    if (card && !ev.target.closest('.qty-row') && !ev.target.closest('button') && !ev.target.closest('a') && !ev.target.closest('input') && !ev.target.closest('select') && !ev.target.closest('textarea')){
      const imgEl = card.querySelector('.prod-img');
      if (imgEl){
        ev.stopPropagation();
        ev.preventDefault();
        openImgModal(imgEl.getAttribute('src')||'', imgEl.getAttribute('alt')||'');
        return;
      }
    }
    // 3) Fechar ao clicar no backdrop
    if (imgModal.classList.contains('open')){
      const clickedBackdrop = ev.target === imgModalBackdrop;
      if (clickedBackdrop) closeImgModal();
    }
  });
  document.addEventListener('keydown', (ev)=>{
    if (ev.key === 'Escape' && imgModal.classList.contains('open')) closeImgModal();
  });

  const logoEl = document.querySelector('.logo');
  // locked já foi declarado acima, apenas reatribuir
  locked = true;
  function setLocked(val){
    console.log('setLocked chamado com:', val, 'locked será:', !!val);
    locked = !!val;
    const qtyBtns = document.querySelectorAll('.qty-btn');
    qtyBtns.forEach(b => { 
      b.disabled = locked;
      // Garantir que pointer-events esteja habilitado quando não estiver locked
      if (!locked) {
        b.style.pointerEvents = 'auto';
        b.style.cursor = 'pointer';
      } else {
        b.style.pointerEvents = 'none';
      }
    });
    document.querySelectorAll('.qty-row').forEach(r => {
      r.classList.toggle('locked', locked);
      // NÃO esconder os botões, apenas desabilitar
      // r.style.display = locked ? 'none' : '';
      // Garantir que pointer-events esteja habilitado quando não estiver locked
      if (!locked) {
        r.style.pointerEvents = 'auto';
      } else {
        r.style.pointerEvents = 'none';
      }
    });
    const cartPanel = document.getElementById('cartPanel');
    if (cartPanel) cartPanel.style.display = locked ? 'none' : '';
    const stickyEl = document.getElementById('cartSticky');
    if (stickyEl) stickyEl.style.display = (locked ? 'none' : stickyEl.style.display);
    const btnSend = document.getElementById('btnSend');
    const btnClear = document.getElementById('btnClear');
    if (btnSend) btnSend.style.display = locked ? 'none' : btnSend.style.display;
    if (btnClear) btnClear.style.display = locked ? 'none' : btnClear.style.display;
    // Handle selection badge visibility and card highlight while locked
    const cards = document.querySelectorAll('.card');
    cards.forEach(card => {
      const badge = card.querySelector('.sel-badge');
      if (locked){
        card.classList.remove('selected');
        if (badge) badge.style.display = 'none';
      }
    });
    if (!locked){
      // Re-evaluate UI to reflect current cart quantities
      if (typeof updateCardUI === 'function') updateCardUI();
    }
  }
  // Inicializar estado de bloqueio baseado em allowQty
  // Se allowQty é true, desbloquear; se false, bloquear
  setLocked(!allowQty);
  
  if (logoEl){
    logoEl.addEventListener('dblclick', function(){ 
      if (!allowQty) return; 
      setLocked(!locked); 
    });
  }

  const empSwitch = document.getElementById('empSwitch');
  if (empSwitch){
    empSwitch.addEventListener('click', (e)=>{
      if (!e.target.closest('.emp-menu a')){
        empSwitch.classList.toggle('open');
        e.stopPropagation();
      }
    });
    document.addEventListener('click', ()=>{
      empSwitch.classList.remove('open');
    });
  }


  // Inicializar carrinho e interface
  loadCart();
  updateCardUI();
  renderCartPanel();
})();
</script>
</html>
</html>