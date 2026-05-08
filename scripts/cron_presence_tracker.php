#!/usr/bin/env php
<?php
/**
 * ============================================================
 *  Cron Presence Tracker — Coleta de Sessões Ativas do Zabbix
 * ============================================================
 *
 * Este script consulta a API do Zabbix para obter usuários com
 * sessões ativas e grava na tabela custom_user_sessions.
 *
 * Configurar no crontab do servidor Zabbix:
 *   * * * * * /usr/bin/php /path/to/cron_presence_tracker.php >> /var/log/presence_tracker.log 2>&1
 *
 * Variáveis de configuração abaixo:
 */

// ── CONFIGURAÇÃO ────────────────────────────────────────────
define('ZABBIX_API_URL', 'http://localhost/api_jsonrpc.php');  // URL da API
define('ZABBIX_USER',    'Admin');                              // Usuário admin
define('ZABBIX_PASS',    'zabbix');                             // Senha
define('DB_HOST',        'localhost');                           // Host do PostgreSQL
define('DB_PORT',        5432);                                 // Porta
define('DB_NAME',        'zabbix');                              // Database
define('DB_USER',        'zabbix');                              // Usuário DB
define('DB_PASS',        '');                                    // Senha DB

// ── FUNÇÕES ─────────────────────────────────────────────────

function zabbixApi(string $method, array $params, ?string $auth = null): array {
    $payload = [
        'jsonrpc' => '2.0',
        'method'  => $method,
        'params'  => $params,
        'id'      => 1,
    ];
    if ($auth !== null) {
        $payload['auth'] = $auth;
    }

    $ch = curl_init(ZABBIX_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        logMsg("API Error: HTTP $httpCode");
        return ['error' => 'HTTP Error'];
    }

    return json_decode($response, true) ?: ['error' => 'JSON decode failed'];
}

function logMsg(string $msg): void {
    $ts = date('Y-m-d H:i:s');
    echo "[$ts] $msg\n";
}

// ── MAIN ────────────────────────────────────────────────────

logMsg('=== Presence Tracker Start ===');

// 1. Login na API (Zabbix 7.x usa 'username', 6.x usa 'user')
$loginResp = zabbixApi('user.login', [
    'username' => ZABBIX_USER,
    'password' => ZABBIX_PASS,
]);

if (isset($loginResp['error'])) {
    $loginResp = zabbixApi('user.login', [
        'user'     => ZABBIX_USER,
        'password' => ZABBIX_PASS,
    ]);
}

if (!isset($loginResp['result'])) {
    logMsg('CRITICAL: Falha no login da API. Verifique credenciais.');
    exit(1);
}

$auth = $loginResp['result'];
logMsg("Login OK (token: " . substr($auth, 0, 8) . "...)");

// 2. Buscar usuários com informação de sessão
$usersResp = zabbixApi('user.get', [
    'output' => ['userid', 'username', 'name', 'surname'],
], $auth);

if (!isset($usersResp['result'])) {
    logMsg('ERROR: Falha ao consultar user.get');
    exit(1);
}

$users = $usersResp['result'];
logMsg("Total de usuários encontrados: " . count($users));

// 3. Conectar ao PostgreSQL
try {
    $dsn = 'pgsql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME;
    $db  = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    logMsg('CRITICAL: Falha ao conectar ao DB: ' . $e->getMessage());
    exit(1);
}

// 4. Para cada usuário, verificar se tem sessão ativa na tabela 'sessions' do Zabbix
$now        = date('Y-m-d H:i:s');
$fiveMinAgo = date('Y-m-d H:i:s', strtotime('-5 minutes'));
$inserted   = 0;
$updated    = 0;

foreach ($users as $user) {
    $userid   = (int)$user['userid'];
    $username = $user['username'] ?? ($user['alias'] ?? 'unknown');
    $fullname = trim(($user['name'] ?? '') . ' ' . ($user['surname'] ?? ''));
    if (empty($fullname)) $fullname = $username;

    // Consultar sessões ativas do Zabbix diretamente na tabela sessions
    try {
        $sessStmt = $db->prepare(
            "SELECT sessionid, lastaccess FROM sessions
             WHERE userid = ? AND status = 0 ORDER BY lastaccess DESC LIMIT 1"
        );
        $sessStmt->execute([$userid]);
        $sess = $sessStmt->fetch();
    } catch (Exception $e) {
        logMsg("WARN: Não foi possível consultar sessions para $username: " . $e->getMessage());
        continue;
    }

    if ($sess) {
        $lastaccess = date('Y-m-d H:i:s', (int)$sess['lastaccess']);

        // Verificar se já temos registro recente (últimos 5 min) para não duplicar
        $checkStmt = $db->prepare(
            "SELECT id FROM custom_user_sessions
             WHERE userid = ? AND lastaccess >= ?
             ORDER BY id DESC LIMIT 1"
        );
        $checkStmt->execute([$userid, $fiveMinAgo]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            $updStmt = $db->prepare("UPDATE custom_user_sessions SET lastaccess = ? WHERE id = ?");
            $updStmt->execute([$lastaccess, $existing['id']]);
            $updated++;
        } else {
            $insStmt = $db->prepare(
                "INSERT INTO custom_user_sessions (userid, username, name, session_start, lastaccess)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $insStmt->execute([$userid, $username, $fullname, $now, $lastaccess]);
            $inserted++;
        }
    }
}

$db = null;

// 5. Logout da API
zabbixApi('user.logout', [], $auth);

logMsg("Resultado: $inserted inseridos, $updated atualizados.");
logMsg('=== Presence Tracker End ===');
