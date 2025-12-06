<?php

// ======================================
// CONFIGURAÇÕES
// ======================================

// 1) Copie o API token completo da tela "API token" (não é o SMTP user)
// Ex: mlsn_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
$api_token = 'mlsn.2ccc68cf35d5ba1a4249395ef19e36033985f7e3475cef097dd67c328f4b0152';

// 2) E-mails
$from_email = 'noreply@catalogseller.com';
$from_name  = 'CatalogSeller';
$to_email   = 'ricardo.tk95@gmail.com';
$to_name    = 'Ricardo';

// ======================================
// MONTA O PAYLOAD
// ======================================

$data = [
    'from' => [
        'email' => $from_email,
        'name'  => $from_name,
    ],
    'to' => [
        [
            'email' => $to_email,
            'name'  => $to_name,
        ],
    ],
    'subject' => 'Teste MailerSend via API',
    'text'    => 'Olá Ricardo! Este é um teste usando a API do MailerSend.',
    'html'    => '<p>Olá Ricardo! Este é um <b>teste usando a API</b> do MailerSend.</p>',
];

// ======================================
// ENVIA REQUISIÇÃO COM cURL
// ======================================

$ch = curl_init('https://api.mailersend.com/v1/email');

curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $api_token,
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    echo 'Erro cURL: ' . curl_error($ch);
} else {
    echo "HTTP status: $http_code<br><br>";
    echo "Resposta da API:<br>";
    echo '<pre>' . htmlspecialchars($response) . '</pre>';
}

curl_close($ch);
