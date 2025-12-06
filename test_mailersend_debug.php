<?php

$smtpServer = "smtp.mailersend.net";
$smtpPort   = 587;
$username   = "MS_5A9bkF@catalogseller.com";
$password   = "mssp.2mgfmIN.k68zxl2ddd34j905.UUCRni9";

$fromEmail  = "noreply@catalogseller.com";
$toEmail    = "ricardo.tk95@gmail.com";
$subject    = "Teste MailerSend DEBUG";
$body       = "Olá Ricardo! Este é um teste com DEBUG do MailerSend.";

// Função para ler resposta completa do servidor
function readResponse($socket) {
    $data = "";
    while ($str = fgets($socket, 515)) {
        $data .= $str;
        if (strlen($str) < 4 || $str[3] == " ") {
            break;
        }
    }
    return $data;
}

// Envia comando e mostra resposta
function cmd($socket, $command = null) {
    if ($command !== null) {
        fputs($socket, $command . "\r\n");
        echo "<b>&gt;&gt; " . htmlspecialchars($command) . "</b><br>";
    }
    $response = readResponse($socket);
    echo nl2br(htmlspecialchars("<< " . $response)) . "<br><br>";
    return $response;
}

// Conecta ao servidor
$errno = 0;
$errstr = '';
$socket = fsockopen($smtpServer, $smtpPort, $errno, $errstr, 20);

if (!$socket) {
    die("Erro ao conectar: $errno - $errstr");
}

// Mensagem inicial
$banner = fgets($socket, 515);
echo nl2br(htmlspecialchars("<< " . $banner)) . "<br><br>";

// EHLO + STARTTLS
cmd($socket, "EHLO catalogseller.com");
cmd($socket, "STARTTLS");

// Inicia criptografia
$crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
echo "<b>Crypto habilitado? </b>" . ($crypto ? "SIM" : "NÃO") . "<br><br>";

// EHLO após STARTTLS
cmd($socket, "EHLO catalogseller.com");

// Autenticação
cmd($socket, "AUTH LOGIN");
cmd($socket, base64_encode($username));
cmd($socket, base64_encode($password));

// MAIL FROM / RCPT TO / DATA
cmd($socket, "MAIL FROM:<$fromEmail>");
cmd($socket, "RCPT TO:<$toEmail>");
cmd($socket, "DATA");

// Cabeçalhos + corpo
$headers =
    "From: CatalogSeller <$fromEmail>\r\n" .
    "To: <$toEmail>\r\n" .
    "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n" .
    "MIME-Version: 1.0\r\n" .
    "Content-Type: text/html; charset=UTF-8\r\n" .
    "\r\n";

$message = $headers . $body . "\r\n.";

// Envia mensagem
cmd($socket, $message);

// QUIT
cmd($socket, "QUIT");

fclose($socket);

echo "<hr>Fim do teste.";
