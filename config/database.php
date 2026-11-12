<?php
declare(strict_types=1);
// Local (XAMPP / MySQL / MariaDB): localhost / 3306 / bistro_devops / root / senha vazia.
// Se o seu root tiver senha, ou a porta for outra, ajuste abaixo.
// InfinityFree: substitua pelos dados exatos exibidos no painel da hospedagem
// (o host remoto normalmente NÃO é localhost e o banco/usuário têm prefixo, ex.: if0_123_bistro).
// Opcional: também aceita variáveis de ambiente DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS.
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'bistro_devops';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : '';
try {
    $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    error_log('Erro de conexão: '.$e->getMessage());
    http_response_code(500);
    $codigo = (int)($e->errorInfo[1] ?? 0);
    if ($codigo === 1049) {
        exit("O banco '{$dbName}' não existe. Importe o arquivo database.sql (ele cria o banco e as tabelas).");
    } elseif ($codigo === 1045) {
        exit("Usuário ou senha do banco incorretos. Confira config/database.php.");
    } elseif ($codigo === 2002) {
        exit("Servidor MySQL inacessível em {$dbHost}:{$dbPort}. Confira se o MySQL está iniciado e se host/porta estão corretos.");
    }
    exit('Não foi possível conectar ao banco. Confira config/database.php e importe database.sql.');
}
