<?php
/**
 * Worker de envio de chave de acesso via WhatsApp
 * Lê a fila em access_key_sending_items, envia via Z-API usando
 * as credenciais do vendedor (sales_reps) e atualiza os status.
 */

// CONFIGURAÇÕES BÁSICAS
$maxMessagesPerRun = 30;   // quantidade máxima de mensagens por execução
$delaySeconds      = 1;    // intervalo entre envios (segundos)
$maxAttempts       = 3;    // tentativas máximas por item com erro

// CONFIGURAÇÃO DO BANCO
$host    = 'localhost';
$usuario = 'cata_usr';
$senha   = 'jZa47w_2';
$port    = '3306';
$banco   = 'cata_bd';

// OPCIONAL: timezone
date_default_timezone_set('America/Sao_Paulo');

// -------- FUNÇÕES AUXILIARES --------

function logMsg($msg) {
    // Se estiver rodando via CLI, imprime no console
    if (php_sapi_name() === 'cli') {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    } else {
        // Se rodar via web, pode comentar para não exibir nada
        echo '[' . date('Y-m-d H:i:s') . '] ' . htmlspecialchars($msg) . "<br>\n";
    }
}

/**
 * Envia uma mensagem via Z-API.
 * Ajuste o endpoint e o formato do payload conforme o seu enviar_whatsapp.php.
 */
function sendWhatsAppMessage($salesRep, $phone, $messageText, &$httpCode, &$responseBody) {
    $httpCode     = 0;
    $responseBody = '';

    if (empty($salesRep['instance_api']) ||
        empty($salesRep['instance_id']) ||
        empty($salesRep['instance_token']) ||
        empty($salesRep['security_token'])) {
        throw new Exception('Credenciais da API incompletas para o vendedor ID ' . $salesRep['id']);
    }

    // Monta a URL base (ex: https://api.z-api.io/instances/{id}/token/{token}/send-text)
    $baseApi = rtrim($salesRep['instance_api'], '/');

    // *** IMPORTANTE ***
    // Ajuste o path abaixo se o seu endpoint for diferente (ex: /send-text-status ou outro):
    $url = $baseApi . '/instances/' . $salesRep['instance_id'] .
           '/token/' . $salesRep['instance_token'] . '/send-text';

    // Monta o payload conforme a API da Z-API que você já usa
    $payload = [
        'phone'   => $phone,
        'message' => $messageText,
    ];

    $headers = [
        'Content-Type: application/json',
        'client-token: ' . $salesRep['security_token'],
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30,
    ]);

    $responseBody = curl_exec($ch);
    if ($responseBody === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception('Erro CURL: ' . $error);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
}

// -------- INÍCIO DO SCRIPT --------

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$banco};charset=utf8mb4";
    $pdo = new PDO($dsn, $usuario, $senha, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    logMsg('Erro ao conectar no banco: ' . $e->getMessage());
    exit(1);
}

logMsg('Iniciando worker_access_key.php');

// 1) Buscar um lote com itens pendentes ou com erro (até maxAttempts)
try {
    $sqlBatch = "
        SELECT b.*
        FROM access_key_sending_batches b
        WHERE b.status IN ('pending','processing')
          AND EXISTS (
              SELECT 1
              FROM access_key_sending_items i
              WHERE i.batch_id = b.id
                AND i.status IN ('pending','error')
                AND i.attempts < :max_attempts
          )
        ORDER BY FIELD(b.status, 'processing', 'pending'), b.id
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sqlBatch);
    $stmt->execute([':max_attempts' => $maxAttempts]);
    $batch = $stmt->fetch();

    if (!$batch) {
        logMsg('Nenhum lote pendente/processing com itens a enviar.');
        exit(0);
    }

    $batchId    = (int)$batch['id'];
    $salesRepId = (int)$batch['sales_rep_id'];

    logMsg("Processando lote #{$batchId} (sales_rep_id={$salesRepId})");

    // Se o lote ainda está como pending, marcar como processing
    if ($batch['status'] === 'pending') {
        $stmtUpdate = $pdo->prepare("
            UPDATE access_key_sending_batches
            SET status = 'processing',
                started_at = IFNULL(started_at, NOW())
            WHERE id = :batch_id
        ");
        $stmtUpdate->execute([':batch_id' => $batchId]);
        logMsg("Lote #{$batchId} marcado como 'processing'.");
    }

    // 2) Buscar dados do vendedor
    $stmtRep = $pdo->prepare("
        SELECT 
            id,
            full_name,
            email,
            phone,
            instance_api,
            instance_id,
            instance_token,
            security_token
        FROM sales_reps
        WHERE id = :id
          AND is_active = 1
    ");
    $stmtRep->execute([':id' => $salesRepId]);
    $salesRep = $stmtRep->fetch();

    if (!$salesRep) {
        throw new Exception("Vendedor ID {$salesRepId} não encontrado ou inativo.");
    }

    // 3) Buscar itens pendentes/erro (até maxMessagesPerRun)
    $sqlItems = "
        SELECT *
        FROM access_key_sending_items
        WHERE batch_id = :batch_id
          AND status IN ('pending','error')
          AND attempts < :max_attempts
        ORDER BY id
        LIMIT :limit
    ";

    $stmtItems = $pdo->prepare($sqlItems);
    $stmtItems->bindValue(':batch_id', $batchId, PDO::PARAM_INT);
    $stmtItems->bindValue(':max_attempts', $maxAttempts, PDO::PARAM_INT);
    $stmtItems->bindValue(':limit', $maxMessagesPerRun, PDO::PARAM_INT);
    $stmtItems->execute();

    $items = $stmtItems->fetchAll();
    if (!$items) {
        logMsg("Nenhum item pendente/erro para o lote #{$batchId}.");
    }

    $enviados = 0;
    $erros    = 0;

    foreach ($items as $item) {
        $itemId      = (int)$item['id'];
        $phone       = $item['phone'];
        $messageText = $item['message_text'];

        logMsg("Enviando item #{$itemId} para {$phone}...");

        // Inicia transação por item para garantir consistência
        $pdo->beginTransaction();

        try {
            // Atualiza status para 'sending' e incrementa attempts
            $stmtUp = $pdo->prepare("
                UPDATE access_key_sending_items
                SET status = 'sending',
                    attempts = attempts + 1
                WHERE id = :id
                  AND status IN ('pending','error')
            ");
            $stmtUp->execute([':id' => $itemId]);

            // Se nenhuma linha foi afetada, outro processo pegou esse item
            if ($stmtUp->rowCount() === 0) {
                $pdo->commit();
                logMsg("Item #{$itemId} já está sendo processado por outro worker. Pulando.");
                continue;
            }

            // Chama a API da Z-API
            $httpCode = 0;
            $respBody = '';

            try {
                sendWhatsAppMessage($salesRep, $phone, $messageText, $httpCode, $respBody);

                if ($httpCode >= 200 && $httpCode < 300) {
                    // Sucesso
                    $stmtOk = $pdo->prepare("
                        UPDATE access_key_sending_items
                        SET status = 'sent',
                            last_error = NULL,
                            sent_at = NOW()
                        WHERE id = :id
                    ");
                    $stmtOk->execute([':id' => $itemId]);
                    $pdo->commit();

                    $enviados++;
                    logMsg("Item #{$itemId} enviado com sucesso (HTTP {$httpCode}).");
                } else {
                    // Erro retornado pela API
                    $shortError = "HTTP {$httpCode}: " . mb_substr($respBody, 0, 200);
                    $stmtErr = $pdo->prepare("
                        UPDATE access_key_sending_items
                        SET status = 'error',
                            last_error = :err
                        WHERE id = :id
                    ");
                    $stmtErr->execute([
                        ':id'  => $itemId,
                        ':err' => $shortError,
                    ]);
                    $pdo->commit();

                    $erros++;
                    logMsg("Erro ao enviar item #{$itemId}: {$shortError}");
                }

            } catch (Exception $exItem) {
                // Erro de conexão / CURL / exceção inesperada
                $pdo->rollBack();
                $erros++;
                $shortError = mb_substr($exItem->getMessage(), 0, 200);

                $stmtErr2 = $pdo->prepare("
                    UPDATE access_key_sending_items
                    SET status = 'error',
                        last_error = :err
                    WHERE id = :id
                ");
                $stmtErr2->execute([
                    ':id'  => $itemId,
                    ':err' => $shortError,
                ]);

                logMsg("Exceção ao enviar item #{$itemId}: {$shortError}");
            }

        } catch (Exception $eTrans) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $erros++;
            $shortError = mb_substr($eTrans->getMessage(), 0, 200);
            $stmtErr2 = $pdo->prepare("
                UPDATE access_key_sending_items
                SET status = 'error',
                    last_error = :err
                WHERE id = :id
            ");
            $stmtErr2->execute([
                ':id'  => $itemId,
                ':err' => $shortError,
            ]);

            logMsg("Erro na transação do item #{$itemId}: {$shortError}");
        }

        // Delay entre envios para não sobrecarregar a API
        if ($delaySeconds > 0) {
            sleep($delaySeconds);
        }
    }

    // 4) Atualiza contadores do lote
    $stmtCounts = $pdo->prepare("
        UPDATE access_key_sending_batches b
        SET 
            sent_count = (
                SELECT COUNT(*) FROM access_key_sending_items i
                WHERE i.batch_id = b.id AND i.status = 'sent'
            ),
            error_count = (
                SELECT COUNT(*) FROM access_key_sending_items i
                WHERE i.batch_id = b.id AND i.status = 'error'
            )
        WHERE b.id = :batch_id
    ");
    $stmtCounts->execute([':batch_id' => $batchId]);

    // 5) Verifica se ainda existem itens pendentes/erro (com tentativas < maxAttempts)
    $stmtPending = $pdo->prepare("
        SELECT COUNT(*) AS qtd
        FROM access_key_sending_items
        WHERE batch_id = :batch_id
          AND status IN ('pending','error')
          AND attempts < :max_attempts
    ");
    $stmtPending->execute([
        ':batch_id'     => $batchId,
        ':max_attempts' => $maxAttempts,
    ]);
    $qtdPendentes = (int)$stmtPending->fetchColumn();

    if ($qtdPendentes === 0) {
        // Não há mais o que fazer nesse lote ? finaliza
        $stmtFinish = $pdo->prepare("
            UPDATE access_key_sending_batches
            SET status = 'completed',
                finished_at = NOW()
            WHERE id = :batch_id
        ");
        $stmtFinish->execute([':batch_id' => $batchId]);
        logMsg("Lote #{$batchId} finalizado (completed).");
    } else {
        logMsg("Lote #{$batchId} ainda possui {$qtdPendentes} itens pendentes/erro para próximas execuções.");
    }

    logMsg("Execução concluída. Enviados: {$enviados}, Erros: {$erros}.");

} catch (Exception $e) {
    logMsg('Erro geral no worker: ' . $e->getMessage());
    exit(1);
}

exit(0);
