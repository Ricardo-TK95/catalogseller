<?php

// CONFIGURAÇÕES SMTP DO MAILERSEND
$smtpServer   = "smtp.mailersend.net";
$smtpPort     = 587;
$username     = "MS_5A9bkF@catalogseller.com";
$password     = "mssp.2mgfmIN.k68zxl2ddd34j905.UUCRni9";

$fromEmail    = "noreply@catalogseller.com";
$toEmail      = "ricardo.tk95@gmail.com";
$subject      = "Teste MailerSend - PHP puro";
$body         = "Olá Ricardo! Este é um teste de envio usando MailerSend sem PHPMailer.";

// Codificação base64
$subject = "=?UTF-8?B?" . base64_encode($subject) . "?=";
$passwordBase64 = base64_encode($password);

// Abre conexão SMTP
$socket = fsockopen($smtpServer, $smtpPort, $errno, $errstr, 20);

if (!$socket) {
    die("Erro ao conectar: $errno - $errstr");
}

function sendCmd($socket, $cmd) {
    fputs($socket, $cmd . "\r\n");
    return fgets($socket, 512);
}

sendCmd($socket, "EHLO catalogseller.com");
sendCmd($socket, "STARTTLS");

// Inicia criptografia STARTTLS
stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

sendCmd($socket, "EHLO catalogseller.com");
sendCmd($socket, "AUTH LOGIN");
sendCmd($socket, base64_encode($username));
sendCmd($socket, base64_encode($password));

sendCmd($socket, "MAIL FROM:<$fromEmail>");
sendCmd($socket, "RCPT TO:<$toEmail>");
sendCmd($socket, "DATA");

// Montagem final do e-mail
$message =
    "From: CatalogSeller <$fromEmail>\r\n" .
    "To: <$toEmail>\r\n" .
    "Subject: $subject\r\n" .
    "MIME-Version: 1.0\r\n" .
    "Content-Type: text/html; charset=UTF-8\r\n" .
    "\r\n" .
    $body .
    "\r\n.\r\n";

sendCmd($socket, $message);

// Finaliza
sendCmd($socket, "QUIT");
fclose($socket);

echo "E-mail enviado! Verifique sua caixa de entrada.";
?>
