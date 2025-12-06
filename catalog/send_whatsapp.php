<?php
// Simple WhatsApp sender using Z-API
// Expects JSON: { "phone": "55DDDNNNNNNN", "message": "...", "vendedor_email": "..." }
header('Content-Type: application/json; charset=UTF-8');

// Configurações MySQL (usar o mesmo config do app)
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
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'DB connection error']);
    exit;
}

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) { throw new Exception('JSON inválido'); }
    $phone = isset($data['phone']) ? preg_replace('/\D+/', '', (string)$data['phone']) : '';
    $message = isset($data['message']) ? (string)$data['message'] : '';
    $vendedor_email = isset($data['vendedor_email']) ? trim((string)$data['vendedor_email']) : '';
    if ($phone === '' || $message === '') { throw new Exception('Parâmetros ausentes'); }

    // Buscar credenciais do vendedor na tabela sales_reps
    $url = null;
    $clientToken = null;
    
    if ($vendedor_email !== '') {
        try {
            $stmt = $pdo->prepare('SELECT url_token, client_token FROM sales_reps WHERE email = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$vendedor_email]);
            $vendCreds = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($vendCreds && !empty($vendCreds['url_token']) && !empty($vendCreds['client_token'])) {
                $url = trim((string)$vendCreds['url_token']);
                $clientToken = trim((string)$vendCreds['client_token']);
            }
        } catch (Throwable $e) {
            // Se houver erro, usar valores padrão
        }
    }
    
    // Se não encontrou credenciais do vendedor, usar valores padrão (fallback)
    if ($url === null || $clientToken === null) {
        $url = 'https://api.z-api.io/instances/3EA0BBF2A4410108E57F26C865D6B97F/token/B6707E26D42A4B0245619A29/send-text';
        $clientToken = 'Ffed080cd301a4a6780657f5fcd8d3e1fS';
    }

    $payload = [ 'phone' => $phone, 'message' => $message ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Client-Token: ' . $clientToken,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 20,
    ]);

    $result = curl_exec($ch);
    if ($result === false) {
        $err = curl_error($ch);
        curl_close($ch);
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'cURL: '.$err]);
        exit;
    }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        http_response_code($status);
        echo json_encode(['ok'=>false,'error'=>'HTTP '.$status,'response'=>$result]);
        exit;
    }
    echo json_encode(['ok'=>true,'response'=>$result]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
