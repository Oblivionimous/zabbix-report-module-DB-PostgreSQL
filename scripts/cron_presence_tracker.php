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
// Altere conforme seu ambiente
define('ZABBIX_API_URL', 'http://localhost/api_jsonrpc.php');  // URL da API
define('ZABBIX_USER',    'Admin');                              // Usuário admin
define('ZABBIX_PASS',    'zabbix');                             // Senha
define('DB_HOST',        'localhost');                           // Host do MariaDB
define('DB_PORT',        3306);                                 // Porta
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

// 1. Login na API
$loginResp = zabbixApi('user.login', [
    'username' => ZABBIX_USER,  // Zabbix 7.x usa 'username'
    'password' => ZABBIX_PASS,
]);

// Fallback para Zabbix 6.x que usa 'user'
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

// 2. Buscar usuários ativos com informação de sessão
$usersResp = zabbixApi('user.get', [
    'output'          => ['userid', 'username', 'name', 'surname'],
    'selectUsrgrps'   => ['name'],
    'getAccess'       => ['gui_access', 'users_status'],
], $auth);

if (!isset($usersResp['result'])) {
    logMsg('ERROR: Falha ao consultar user.get');
    exit(1);
}

$users = $usersResp['result'];
logMsg("Total de usuários encontrados: " . count($users));

// 3. Conectar ao MariaDB
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    $db->set_charset('utf8mb4');
} catch (Exception $e) {
    logMsg('CRITICAL: Falha ao conectar ao DB: ' . $e->getMessage());
    exit(1);
}

// 4. Para cada usuário, verificar se tem sessão ativa na tabela 'sessions' do Zabbix
$now = date('Y-m-d H:i:s');
$inserted = 0;
$updated = 0;

foreach ($users as $user) {
    $userid   = (int)$user['userid'];
    $username = $user['username'] ?? ($user['alias'] ?? 'unknown');
    $fullname = trim(($user['name'] ?? '') . ' ' . ($user['surname'] ?? ''));
    if (empty($fullname)) $fullname = $username;

    // Consultar sessões ativas do Zabbix diretamente na tabela sessions
    $sessQuery = "SELECT sessionid, lastaccess FROM sessions WHERE userid = $userid AND status = 0 ORDER BY lastaccess DESC LIMIT 1";
    try {
        $sessResult = $db->query($sessQuery);
    } catch (Exception $e) {
        // Se a tabela sessions não existe (versão diferente), pular
        logMsg("WARN: Não foi possível consultar sessions para $username: " . $e->getMessage());
        continue;
    }

    if ($sessResult && $sessResult->num_rows > 0) {
        $sess = $sessResult->fetch_assoc();
        $lastaccess = date('Y-m-d H:i:s', (int)$sess['lastaccess']);

        // Verificar se já temos registro recente (últimos 5 min) para não duplicar
        $checkStmt = $db->prepare(
            "SELECT id FROM custom_user_sessions
             WHERE userid = ? AND lastaccess >= DATE_SUB(?, INTERVAL 5 MINUTE)
             ORDER BY id DESC LIMIT 1"
        );
        $checkStmt->bind_param('is', $userid, $now);
        $checkStmt->execute();
        $existing = $checkStmt->get_result();

        if ($existing->num_rows > 0) {
            // Atualizar lastaccess do registro existente
            $row = $existing->fetch_assoc();
            $updStmt = $db->prepare("UPDATE custom_user_sessions SET lastaccess = ? WHERE id = ?");
            $updStmt->bind_param('si', $lastaccess, $row['id']);
            $updStmt->execute();
            $updated++;
        } else {
            // Inserir novo registro de sessão
            $insStmt = $db->prepare(
                "INSERT INTO custom_user_sessions (userid, username, name, session_start, lastaccess)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $insStmt->bind_param('issss', $userid, $username, $fullname, $now, $lastaccess);
            $insStmt->execute();
            $inserted++;
        }
    }
}

$db->close();

// 5. Logout da API
zabbixApi('user.logout', [], $auth);

logMsg("Resultado: $inserted inseridos, $updated atualizados.");
logMsg('=== Presence Tracker End ===');
