<?php
// config.php
// Configurações de conexão

// ============================================
// BANCO: cata_bd (Sistema Principal)
// ============================================
// Tabelas: companies, stores, products, manufacturers, sections, sales_reps, etc.
$host    = 'localhost';
$usuario = 'cata_usr';
$senha   = 'jZa47w_2';
$port    = '3306';
$banco   = 'cata_bd';

// Variáveis para catalog (usando o mesmo banco cata_bd)
$catalog_host    = $host;
$catalog_usuario = $usuario;
$catalog_senha   = $senha;
$catalog_port    = $port;
$catalog_banco   = $banco;

define('DB_TABLE', 'users');

// Criar conexão para cata_bd (mysqli - para uso em outras páginas)
// Não interromper o fluxo se falhar, deixar o erro ser tratado pela página que usa
$conn = @new mysqli($host, $usuario, $senha, $banco, $port);

// Verificar erro de conexão (mas não matar o script, apenas logar)
if ($conn && !$conn->connect_error) {
    // Charset para evitar problema com acentos
    $conn->set_charset('utf8mb4');
} elseif ($conn) {
    // Log do erro, mas não interromper o fluxo
    error_log('MySQLi connection error: ' . $conn->connect_error);
    // A conexão mysqli pode falhar, mas o PDO ainda pode funcionar
} else {
    // Se $conn for false/null, logar erro genérico
    error_log('MySQLi connection failed: Could not create connection object');
}
