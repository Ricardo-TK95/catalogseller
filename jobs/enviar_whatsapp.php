<?php
// enviar_whatsapp.php
// Script CLI para envio automático de mensagens WhatsApp via cron

// Definir que é CLI
if (php_sapi_name() !== 'cli') {
    die('Este script só pode ser executado via linha de comando (CLI).' . PHP_EOL);
}

// 1) Conectar no banco
$arquivoConfig = __DIR__ . '/../config.php';
if (!file_exists($arquivoConfig)) {
    die('Erro: config.php não encontrado.' . PHP_EOL);
}
require_once $arquivoConfig;

// Verificar conexão
if ($conn->connect_error) {
    die('Erro de conexão com o banco de dados: ' . $conn->connect_error . PHP_EOL);
}

// Obter dia da semana atual (1=domingo, 2=segunda, ..., 7=sábado)
$dia_semana_atual = (int)date('w'); 
$dia_semana_atual = $dia_semana_atual === 0 ? 7 : $dia_semana_atual; // Converter para 1-7

// Obter hora atual (0-23)
$hora_atual = (int)date('H');

echo "[" . date('Y-m-d H:i:s') . "] Iniciando envio de mensagens WhatsApp..." . PHP_EOL;
echo "Dia da semana: $dia_semana_atual (1=Dom, 7=Sáb)" . PHP_EOL;
echo "Hora atual: $hora_atual:00" . PHP_EOL . PHP_EOL;

// 2) Buscar representantes ativos
$sql_reps = "SELECT id, full_name, url_token, client_token 
             FROM sales_reps 
             WHERE is_active = 1 
             ORDER BY id ASC";
$reps = [];
if ($stmt = $conn->prepare($sql_reps)) {
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $reps[] = $r;
        }
        $res->free();
    }
    $stmt->close();
}

if (empty($reps)) {
    echo "Nenhum representante ativo encontrado." . PHP_EOL;
    exit(0);
}

echo "Representantes ativos encontrados: " . count($reps) . PHP_EOL . PHP_EOL;

// 3) Loop pelos representantes
foreach ($reps as $rep) {
    // IMPORTANTE: Pegar credenciais do vendedor atual (não de outro vendedor)
    $sales_rep_id = (int)$rep['id'];
    $rep_name = $rep['full_name'];
    $url_token = trim($rep['url_token']);      // URL do vendedor atual
    $client_token = trim($rep['client_token']); // Token do vendedor atual
    
    echo "--- Processando: $rep_name (ID: $sales_rep_id) ---" . PHP_EOL;
    
    // Verificar se tem credenciais do vendedor atual
    if (empty($url_token) || empty($client_token)) {
        echo "  ⚠ Aviso: Credenciais WhatsApp não configuradas para este vendedor. Pulando..." . PHP_EOL . PHP_EOL;
        continue;
    }
    
    // 4) Buscar agendamentos do VENDEDOR ATUAL para o dia/hora atual
    // IMPORTANTE: Buscar apenas agendamentos deste vendedor específico
    // Buscar na customer_weekday_schedule onde:
    // - sales_rep_id = vendedor atual (garantindo que é deste vendedor)
    // - weekday_id = dia da semana atual
    // - send_type = 'S' (semanal)
    // - is_active = 1
    
    $sql_schedule = "SELECT DISTINCT customer_id 
                     FROM customer_weekday_schedule 
                     WHERE sales_rep_id = ? 
                     AND weekday_id = ? 
                     AND send_type = 'S' 
                     AND is_active = 1";
    
    $customers = [];
    if ($stmt = $conn->prepare($sql_schedule)) {
        $stmt->bind_param('ii', $sales_rep_id, $dia_semana_atual);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $customers[] = (int)$r['customer_id'];
            }
            $res->free();
        }
        $stmt->close();
    }
    
    if (empty($customers)) {
        echo "  ℹ Nenhum cliente agendado para hoje." . PHP_EOL . PHP_EOL;
        continue;
    }
    
    echo "  Clientes encontrados: " . count($customers) . PHP_EOL;
    
    // 5) Para cada cliente, buscar telefone e mensagens
    foreach ($customers as $customer_id) {
        // Buscar telefone do cliente na tabela stores
        $sql_store = "SELECT phone, store_name 
                      FROM stores 
                      WHERE id = ? 
                      LIMIT 1";
        $phone = '';
        $store_name = '';
        
        if ($stmt = $conn->prepare($sql_store)) {
            $stmt->bind_param('i', $customer_id);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                $store = $res->fetch_assoc();
                if ($store) {
                    $phone = trim($store['phone'] ?? '');
                    $store_name = trim($store['store_name'] ?? '');
                }
                $res->free();
            }
            $stmt->close();
        }
        
        if (empty($phone)) {
            echo "  ⚠ Cliente ID $customer_id: Telefone não encontrado. Pulando..." . PHP_EOL;
            continue;
        }
        
        // Limpar telefone (remover caracteres não numéricos)
        $phone_clean = preg_replace('/\D+/', '', $phone);
        if (empty($phone_clean)) {
            echo "  ⚠ Cliente ID $customer_id ($store_name): Telefone inválido. Pulando..." . PHP_EOL;
            continue;
        }
        
        // 6) Buscar mensagens do VENDEDOR ATUAL para o dia/hora atual
        // IMPORTANTE: Buscar apenas mensagens deste vendedor específico
        // Buscar na sales_rep_messages onde:
        // - sales_rep_id = vendedor atual (garantindo que é deste vendedor)
        // - week_day_id = dia da semana atual
        // - send_time = hora atual (formato HH:00:00)
        // - is_active = 1
        // Ordenar por send_order
        
        $send_time_str = str_pad($hora_atual, 2, '0', STR_PAD_LEFT) . ':00:00';
        
        $sql_messages = "SELECT id, message_text, send_order 
                         FROM sales_rep_messages 
                         WHERE sales_rep_id = ? 
                         AND week_day_id = ? 
                         AND send_time = ? 
                         AND is_active = 1 
                         ORDER BY send_order ASC, id ASC";
        
        $messages = [];
        if ($stmt = $conn->prepare($sql_messages)) {
            $stmt->bind_param('iis', $sales_rep_id, $dia_semana_atual, $send_time_str);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $messages[] = $r;
                }
                $res->free();
            }
            $stmt->close();
        }
        
        if (empty($messages)) {
            echo "  ℹ Cliente ID $customer_id ($store_name): Nenhuma mensagem agendada para esta hora." . PHP_EOL;
            continue;
        }
        
        echo "  📱 Cliente: $store_name (ID: $customer_id, Tel: $phone_clean)" . PHP_EOL;
        echo "    Mensagens encontradas: " . count($messages) . PHP_EOL;
        
        // 7) Enviar cada mensagem
        foreach ($messages as $msg) {
            $message_text = trim($msg['message_text']);
            if (empty($message_text)) {
                continue;
            }
            
            // Preparar mensagem agravada (com nome do cliente)
            $message_final = str_replace('{cliente}', $store_name, $message_text);
            $message_final = str_replace('{CLIENTE}', strtoupper($store_name), $message_final);
            
            echo "    → Enviando mensagem (Order: {$msg['send_order']})..." . PHP_EOL;
            
            // IMPORTANTE: Enviar via WhatsApp usando as credenciais do VENDEDOR ATUAL
            // As variáveis $url_token e $client_token foram definidas no início do loop
            // e pertencem exclusivamente a este vendedor ($sales_rep_id)
            $payload = [
                'phone' => $phone_clean,
                'message' => $message_final
            ];
            
            // Usar url_token e client_token do vendedor atual (definidos na linha 61-62)
            $ch = curl_init($url_token);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Client-Token: ' . $client_token,
                ],
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_TIMEOUT        => 20,
            ]);
            
            $result = curl_exec($ch);
            $http_status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            
            if ($result === false) {
                echo "      ❌ Erro cURL: $curl_error" . PHP_EOL;
            } elseif ($http_status < 200 || $http_status >= 300) {
                echo "      ❌ Erro HTTP $http_status: $result" . PHP_EOL;
            } else {
                echo "      ✅ Mensagem enviada com sucesso!" . PHP_EOL;
            }
            
            // IMPORTANTE: Delay entre mensagens para evitar bloqueio da API WhatsApp
            // Aguardar 3 segundos entre cada mensagem para o mesmo cliente
            // Isso ajuda a evitar rate limiting e bloqueios
            echo "      ⏳ Aguardando 3 segundos antes da próxima mensagem..." . PHP_EOL;
            sleep(3); // 3 segundos
        }
        
        // Delay entre clientes diferentes (5 segundos)
        // Isso ajuda a evitar bloqueios quando há muitos clientes
        if (count($customers) > 1) {
            echo "  ⏳ Aguardando 5 segundos antes do próximo cliente..." . PHP_EOL;
            sleep(5);
        }
    }
    
    // Delay entre vendedores diferentes (10 segundos)
    // Cada vendedor tem suas próprias credenciais, mas é bom dar um tempo
    echo "  ⏳ Aguardando 10 segundos antes do próximo vendedor..." . PHP_EOL;
    sleep(10);
    
    echo PHP_EOL;
}

echo "[" . date('Y-m-d H:i:s') . "] Processamento concluído." . PHP_EOL;
exit(0);

