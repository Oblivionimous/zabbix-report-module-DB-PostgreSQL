#!/usr/bin/env php
<?php
/**
 * ============================================================
 *  Populate Demo Data — Repasse de Plantão
 * ============================================================
 * Cria: user "Vinícius Costa", host com triggers, eventos,
 *       acknowledges, presença, e notas no diário.
 *
 * Uso:  php populate_demo_data.php
 * Ou:   podman exec -i zabbix-web php /tmp/populate.php
 * ============================================================
 */

// ── CONFIGURAÇÃO ────────────────────────────────────────────
define('ZABBIX_API', 'http://localhost/api_jsonrpc.php');
define('ZABBIX_USER', 'Admin');
define('ZABBIX_PASS', 'zabbix');
define('DB_HOST', 'zabbix-mariadb');
define('DB_PORT', 3306);
define('DB_NAME', 'zabbix');
define('DB_USER', 'zabbix');
define('DB_PASS', 'zabbix');

// Data alvo (05/03/2026)
define('TARGET_DATE', '2026-03-05');

// ── HELPERS ─────────────────────────────────────────────────

function api(string $method, array $params, ?string $auth = null): array {
    $payload = ['jsonrpc'=>'2.0','method'=>$method,'params'=>$params,'id'=>1];
    if ($auth !== null) $payload['auth'] = $auth;

    $ch = curl_init(ZABBIX_API);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    $j = json_decode($r, true);
    if (isset($j['error'])) {
        echo "  [API ERROR] {$method}: ".json_encode($j['error'])."\n";
    }
    return $j;
}

function log_msg(string $m): void { echo "[".date('H:i:s')."] $m\n"; }

// ── MAIN ────────────────────────────────────────────────────

log_msg('=== POPULATE DEMO DATA START ===');

// 1. Login API
$login = api('user.login', ['username'=>ZABBIX_USER, 'password'=>ZABBIX_PASS]);
if (!isset($login['result'])) {
    log_msg('Login falhou. Tentando formato 6.x...');
    $login = api('user.login', ['user'=>ZABBIX_USER, 'password'=>ZABBIX_PASS]);
}
$auth = $login['result'] ?? null;
if (!$auth) { log_msg('CRITICAL: Login na API falhou.'); exit(1); }
log_msg("Login OK");

// 2. Conectar DB
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    $db->set_charset('utf8mb4');
    log_msg("DB conectado");
} catch (Exception $e) {
    log_msg("CRITICAL: DB falhou: ".$e->getMessage());
    exit(1);
}

// ── 3. CRIAR USUÁRIO "Vinícius Costa" ───────────────────────

log_msg("--- Criando usuário Vinícius Costa ---");

// Obter grupo de super admins
$groups = api('usergroup.get', ['output'=>['usrgrpid','name']], $auth);
$adminGrpId = null;
foreach ($groups['result'] ?? [] as $g) {
    if (stripos($g['name'], 'Zabbix admin') !== false) {
        $adminGrpId = $g['usrgrpid'];
        break;
    }
}
if (!$adminGrpId) $adminGrpId = '7'; // Default Zabbix administrators

// Verificar se já existe
$existing = api('user.get', ['output'=>['userid'],'filter'=>['username'=>'vinicius.costa']], $auth);
$viniciusId = null;
if (!empty($existing['result'])) {
    $viniciusId = $existing['result'][0]['userid'];
    log_msg("  Usuário já existe (ID: $viniciusId)");
} else {
    $newUser = api('user.create', [
        'username' => 'vinicius.costa',
        'name'     => 'Vinícius',
        'surname'  => 'Costa',
        'passwd'   => 'P@ssw0rd2026!',
        'roleid'   => '1', // Super admin role
        'usrgrps'  => [['usrgrpid' => $adminGrpId]],
    ], $auth);
    $viniciusId = $newUser['result']['userids'][0] ?? null;
    log_msg("  Usuário criado (ID: $viniciusId)");
}

// Também pegar ID do Admin (userid=1) e roberto.silva
$adminUserId = '1';
$robertoRes = api('user.get', ['output'=>['userid'],'filter'=>['username'=>'roberto.silva']], $auth);
$robertoId = $robertoRes['result'][0]['userid'] ?? null;
log_msg("  Admin ID: $adminUserId, Roberto ID: $robertoId, Vinicius ID: $viniciusId");

// ── 4. CRIAR HOST GROUP + HOST ──────────────────────────────

log_msg("--- Criando host e triggers ---");

// Criar grupo NOC-Servidores
$grpRes = api('hostgroup.get', ['output'=>['groupid'],'filter'=>['name'=>'NOC-Servidores']], $auth);
if (empty($grpRes['result'])) {
    $grpRes = api('hostgroup.create', ['name'=>'NOC-Servidores'], $auth);
    $groupId = $grpRes['result']['groupids'][0] ?? null;
    log_msg("  Grupo criado (ID: $groupId)");
} else {
    $groupId = $grpRes['result'][0]['groupid'];
    log_msg("  Grupo já existe (ID: $groupId)");
}

// Criar hosts
$hosts_to_create = [
    ['name'=>'SRV-WEB-01',   'host'=>'srv-web-01',   'ip'=>'192.168.1.10'],
    ['name'=>'SRV-DB-01',    'host'=>'srv-db-01',    'ip'=>'192.168.1.20'],
    ['name'=>'SRV-APP-01',   'host'=>'srv-app-01',   'ip'=>'192.168.1.30'],
    ['name'=>'FW-BORDA-01',  'host'=>'fw-borda-01',  'ip'=>'10.0.0.1'],
    ['name'=>'SW-CORE-01',   'host'=>'sw-core-01',   'ip'=>'10.0.0.2'],
];

$hostIds = [];
foreach ($hosts_to_create as $h) {
    $exists = api('host.get', ['output'=>['hostid'],'filter'=>['host'=>$h['host']]], $auth);
    if (!empty($exists['result'])) {
        $hid = $exists['result'][0]['hostid'];
        log_msg("  Host {$h['name']} já existe (ID: $hid)");
    } else {
        $cr = api('host.create', [
            'host'       => $h['host'],
            'name'       => $h['name'],
            'groups'     => [['groupid' => $groupId]],
            'interfaces' => [[
                'type' => 1, 'main' => 1, 'useip' => 1,
                'ip' => $h['ip'], 'dns' => '', 'port' => '10050'
            ]]
        ], $auth);
        $hid = $cr['result']['hostids'][0] ?? null;
        log_msg("  Host {$h['name']} criado (ID: $hid)");
    }
    $hostIds[$h['host']] = $hid;
}

// ── 5. CRIAR ITEMS + TRIGGERS ───────────────────────────────

log_msg("--- Criando items e triggers ---");

$items_triggers = [
    ['key'=>'system.cpu.util[,user]', 'name'=>'CPU utilization (user)', 'trigger'=>'CPU utilization too high on {HOST.NAME}', 'pri'=>4, 'expr'=>'last(/{HOST}/system.cpu.util[,user])>90'],
    ['key'=>'vm.memory.util',         'name'=>'Memory utilization %',    'trigger'=>'Memory utilization too high on {HOST.NAME}', 'pri'=>4, 'expr'=>'last(/{HOST}/vm.memory.util)>90'],
    ['key'=>'vfs.fs.pused[/]',        'name'=>'Disk / usage %',          'trigger'=>'Disk space critical on {HOST.NAME} /', 'pri'=>5, 'expr'=>'last(/{HOST}/vfs.fs.pused[/])>95'],
    ['key'=>'vfs.fs.pused[/var]',     'name'=>'Disk /var usage %',       'trigger'=>'Disk space critical on {HOST.NAME} /var', 'pri'=>3, 'expr'=>'last(/{HOST}/vfs.fs.pused[/var])>85'],
    ['key'=>'net.if.in[eth0]',        'name'=>'Network inbound eth0',    'trigger'=>'High inbound traffic on {HOST.NAME}', 'pri'=>2, 'expr'=>'last(/{HOST}/net.if.in[eth0])>100000000'],
    ['key'=>'system.uptime',          'name'=>'System uptime',           'trigger'=>'{HOST.NAME} has been restarted', 'pri'=>3, 'expr'=>'last(/{HOST}/system.uptime)<600'],
    ['key'=>'proc.num[mysqld]',       'name'=>'MySQL process count',     'trigger'=>'MySQL is down on {HOST.NAME}', 'pri'=>5, 'expr'=>'last(/{HOST}/proc.num[mysqld])=0'],
    ['key'=>'system.swap.pused',      'name'=>'Swap utilization %',      'trigger'=>'High swap usage on {HOST.NAME}', 'pri'=>3, 'expr'=>'last(/{HOST}/system.swap.pused)>80'],
];

$triggerMap = []; // host -> [triggerid => severity]
foreach ($hostIds as $hostname => $hostId) {
    $triggerMap[$hostname] = [];
    foreach ($items_triggers as $it) {
        // Criar item
        $existItem = api('item.get', ['output'=>['itemid'], 'hostids'=>[$hostId], 'filter'=>['key_'=>$it['key']]], $auth);
        if (!empty($existItem['result'])) {
            $itemId = $existItem['result'][0]['itemid'];
        } else {
            $createItem = api('item.create', [
                'hostid'      => $hostId,
                'name'        => $it['name'],
                'key_'        => $it['key'],
                'type'        => 2, // Zabbix trapper
                'value_type'  => 0, // Numeric float
                'delay'       => '0',
            ], $auth);
            $itemId = $createItem['result']['itemids'][0] ?? null;
        }

        // Criar trigger
        $expr = str_replace('{HOST}', $hostname, $it['expr']);
        $existTrigger = api('trigger.get', ['output'=>['triggerid'], 'hostids'=>[$hostId], 'filter'=>['description'=>$it['trigger']]], $auth);
        if (!empty($existTrigger['result'])) {
            $triggerId = $existTrigger['result'][0]['triggerid'];
        } else {
            $createTrigger = api('trigger.create', [
                'description' => $it['trigger'],
                'expression'  => $expr,
                'priority'    => $it['pri'],
            ], $auth);
            $triggerId = $createTrigger['result']['triggerids'][0] ?? null;
        }
        if ($triggerId) {
            $triggerMap[$hostname][$triggerId] = $it['pri'];
        }
    }
    log_msg("  {$hostname}: ".count($triggerMap[$hostname])." triggers");
}

// ── 6. INSERIR EVENTOS HISTÓRICOS (05/03/2026) ──────────────

log_msg("--- Inserindo eventos no dia 05/03/2026 ---");

$day_start = strtotime(TARGET_DATE.' 00:00:00');
$day_end   = strtotime(TARGET_DATE.' 23:59:59');

// Pegar próximo eventid disponível
$maxEvt = $db->query("SELECT COALESCE(MAX(eventid),0) AS m FROM events")->fetch_assoc()['m'];
$nextEventId = (int)$maxEvt + 1000; // Margem de segurança

$events_inserted = 0;
$acks_inserted = 0;

// Distribuir eventos ao longo do dia
$event_schedule = [
    // [hora, host, trigger_index(0-7), resolve_after_min, ack_user_id, ack_delay_sec]
    ['07:15', 'srv-web-01',  0, 45, $viniciusId, 120],   // CPU alta, ACK em 2min
    ['07:30', 'srv-db-01',   1, 90, $viniciusId, 300],    // Memory, ACK em 5min
    ['08:00', 'fw-borda-01', 4, 30, $adminUserId, 60],    // Network, ACK em 1min
    ['08:45', 'srv-web-01',  2, 0, null, 0],               // Disco / - SEM ACK, NÃO resolvido
    ['09:10', 'srv-app-01',  0, 60, $viniciusId, 180],     // CPU, ACK em 3min
    ['09:30', 'srv-db-01',   6, 120, $robertoId ?? $adminUserId, 420],  // MySQL down, ACK 7min
    ['10:00', 'sw-core-01',  5, 15, $viniciusId, 90],      // Reboot, ACK 1.5min
    ['10:15', 'srv-web-01',  3, 60, $adminUserId, 240],    // Disco /var, ACK 4min
    ['10:45', 'srv-app-01',  7, 0, null, 0],                // Swap alta - SEM ACK (herdado)
    ['11:00', 'srv-web-01',  0, 30, $viniciusId, 150],      // CPU novamente
    ['11:30', 'srv-db-01',   1, 45, $viniciusId, 200],      // Memory novamente
    ['12:00', 'fw-borda-01', 4, 20, $adminUserId, 100],     // Network
    ['13:15', 'srv-app-01',  0, 60, $robertoId ?? $adminUserId, 600],  // CPU turno tarde
    ['13:45', 'srv-web-01',  2, 90, $viniciusId, 350],      // Disco
    ['14:00', 'srv-db-01',   6, 45, $viniciusId, 180],      // MySQL down
    ['14:30', 'sw-core-01',  4, 30, $adminUserId, 120],     // Network
    ['15:00', 'srv-web-01',  1, 60, $viniciusId, 90],       // Memory web
    ['15:30', 'srv-app-01',  7, 0, null, 0],                 // Swap - sem ACK (herdado)
    ['16:00', 'srv-db-01',   0, 45, $robertoId ?? $viniciusId, 500],  // CPU DB
    ['16:45', 'fw-borda-01', 4, 60, $viniciusId, 45],        // Network rápido
    ['17:00', 'srv-web-01',  5, 20, $adminUserId, 60],       // Reboot web
    ['17:30', 'srv-app-01',  3, 60, $viniciusId, 220],       // Disco /var app
    ['18:00', 'srv-db-01',   1, 0, null, 0],                  // Memory DB - sem ack
    ['19:00', 'srv-web-01',  0, 50, $viniciusId, 130],        // CPU noite
    ['19:30', 'sw-core-01',  5, 10, $adminUserId, 30],        // Reboot switch
    ['20:00', 'srv-db-01',   6, 120, $viniciusId, 900],       // MySQL - ACK demorado
    ['21:00', 'srv-app-01',  0, 60, $viniciusId, 200],        // CPU
    ['22:00', 'fw-borda-01', 4, 40, $viniciusId, 150],        // Network
    ['23:00', 'srv-web-01',  2, 0, null, 0],                   // Disco - herdado (não resolvido)
    ['23:30', 'srv-db-01',   7, 30, $adminUserId, 60],        // Swap
];

$maxAck = (int)$db->query("SELECT COALESCE(MAX(acknowledgeid),0) AS m FROM acknowledges")->fetch_assoc()['m'];
$nextAckId = $maxAck + 1000;

foreach ($event_schedule as $ev) {
    [$hora, $hostname, $trigIdx, $resolve_after_min, $ack_user, $ack_delay] = $ev;

    $triggerIds = array_keys($triggerMap[$hostname] ?? []);
    if (!isset($triggerIds[$trigIdx])) continue;
    $triggerId = $triggerIds[$trigIdx];
    $severity = $triggerMap[$hostname][$triggerId];

    $clock = strtotime(TARGET_DATE." $hora:00");
    $ns = rand(0, 999999999);

    // Obter nome descritivo
    $tRes = $db->query("SELECT description FROM triggers WHERE triggerid=$triggerId");
    $trigName = $tRes->fetch_assoc()['description'] ?? 'Alert on host';

    // Inserir PROBLEM event
    $evId = $nextEventId++;
    $db->query("INSERT IGNORE INTO events (eventid, source, object, objectid, clock, value, acknowledged, ns, name, severity)
                VALUES ($evId, 0, 0, $triggerId, $clock, 1, ".($ack_user ? 1 : 0).", $ns, '$trigName', $severity)");
    $events_inserted++;

    // Inserir RECOVERY event (se resolvido)
    if ($resolve_after_min > 0) {
        $rClock = $clock + ($resolve_after_min * 60);
        $rEvId = $nextEventId++;
        $db->query("INSERT IGNORE INTO events (eventid, source, object, objectid, clock, value, acknowledged, ns, name, severity)
                    VALUES ($rEvId, 0, 0, $triggerId, $rClock, 0, 0, $ns, '$trigName', $severity)");

        // Linkar na event_recovery
        $db->query("INSERT IGNORE INTO event_recovery (eventid, r_eventid, c_eventid, correlationid, userid)
                    VALUES ($evId, $rEvId, NULL, NULL, NULL)");
        $events_inserted++;
    }

    // Inserir ACK (se tem user)
    if ($ack_user) {
        $ackClock = $clock + $ack_delay;
        $ackId = $nextAckId++;
        $db->query("INSERT IGNORE INTO acknowledges (acknowledgeid, userid, eventid, clock, message, action, old_severity, new_severity, suppress_until, taskid)
                    VALUES ($ackId, $ack_user, $evId, $ackClock, 'Verificado. Ação tomada.', 6, 0, 0, 0, NULL)");
        $acks_inserted++;
    }
}

log_msg("  Inseridos: $events_inserted eventos, $acks_inserted ACKs");

// ── 7. PRESENÇA (custom_user_sessions) ──────────────────────

log_msg("--- Populando presença dia 05/03 ---");

$presence_data = [
    [$viniciusId ?? 4, 'vinicius.costa', 'Vinícius Costa', TARGET_DATE.' 06:55:00', TARGET_DATE.' 19:05:00'],
    [$adminUserId,     'Admin',          'Zabbix Administrator', TARGET_DATE.' 07:00:00', TARGET_DATE.' 23:59:00'],
];
if ($robertoId) {
    $presence_data[] = [$robertoId, 'roberto.silva', 'Roberto Silva', TARGET_DATE.' 13:00:00', TARGET_DATE.' 19:10:00'];
}

// Popular com registros a cada 5 minutos (simula cron rodando)
$pInserted = 0;
foreach ($presence_data as $p) {
    [$uid, $uname, $fname, $ss, $se] = $p;
    $tStart = strtotime($ss);
    $tEnd   = strtotime($se);

    for ($t = $tStart; $t < $tEnd; $t += 300) { // a cada 5 min
        $sStart = date('Y-m-d H:i:s', $t);
        $sAccess = date('Y-m-d H:i:s', $t + rand(30, 290));
        $db->query("INSERT IGNORE INTO custom_user_sessions (userid, username, name, session_start, lastaccess)
                    VALUES ($uid, '$uname', '$fname', '$sStart', '$sAccess')");
        $pInserted++;
    }
}
log_msg("  Registros de presença: $pInserted");

// ── 8. NOTAS DO DIÁRIO ──────────────────────────────────────

log_msg("--- Populando Diário de Bordo ---");

$notes = [
    [TARGET_DATE, '24h', $viniciusId ?? 4, 'Vinícius Costa',
     "Turno iniciado com 2 alertas herdados do plantão anterior (disco cheio SRV-WEB-01 e swap alto SRV-APP-01).\nRealizei verificação e abri chamado #4521 para limpeza de logs.",
     TARGET_DATE.' 07:30:00'],
    [TARGET_DATE, '24h', $viniciusId ?? 4, 'Vinícius Costa',
     "MySQL parou no SRV-DB-01 por falta de memória. Reiniciado manualmente e ajustado innodb_buffer_pool_size.\nMonitorando estabilidade nas próximas horas.",
     TARGET_DATE.' 09:45:00'],
    [TARGET_DATE, '24h', $adminUserId, 'Zabbix Administrator',
     "FW-BORDA-01 com tráfego acima do normal — possível ataque DDoS. Ativadas regras de rate-limiting.\nEntreguei situação ao Vinícius no turno da tarde.",
     TARGET_DATE.' 12:30:00'],
    [TARGET_DATE, '24h', $robertoId ?? $viniciusId ?? 4, $robertoId ? 'Roberto Silva' : 'Vinícius Costa',
     "Turno da tarde: CPU alta recorrente no SRV-APP-01. Identificado processo Java consumindo 98%. Realizado restart da aplicação e escalonado para equipe de dev.",
     TARGET_DATE.' 15:00:00'],
    [TARGET_DATE, '24h', $viniciusId ?? 4, 'Vinícius Costa',
     "Encerramento do plantão 05/03: 30 alertas gerados, 25 reconhecidos, 5 herdados para o próximo turno.\nPrincipais: disco SRV-WEB-01, swap SRV-APP-01, e memory SRV-DB-01.\nRecomendação: expandir partição /var no SRV-WEB-01 URGENTE.",
     TARGET_DATE.' 23:50:00'],
];

$nInserted = 0;
foreach ($notes as $n) {
    [$ndate, $nshift, $uid, $aname, $noteText, $cat] = $n;
    $safeText = $db->real_escape_string($noteText);
    $safeName = $db->real_escape_string($aname);
    $db->query("INSERT INTO custom_shift_notes (shift_date, shift_name, analyst_userid, analyst_name, notes, created_at)
                VALUES ('$ndate', '$nshift', $uid, '$safeName', '$safeText', '$cat')");
    $nInserted++;
}
log_msg("  Notas inseridas: $nInserted");

// ── 9. DADOS ADICIONAIS PARA HEATMAP (últimos 30 dias) ─────

log_msg("--- Populando eventos dos últimos 30 dias (heatmap) ---");

// Inserir alguns eventos em dias anteriores para o heatmap ter dados
$hmInserted = 0;
for ($d = 29; $d >= 1; $d--) {
    $dayStr = date('Y-m-d', strtotime("-{$d} days"));
    if ($dayStr === TARGET_DATE) continue; // Já tem dados

    $numEvents = rand(0, 20);
    if ($numEvents === 0) continue;

    $dayTs = strtotime("$dayStr 08:00:00");
    foreach ($hostIds as $hostname => $hostId) {
        $trigIds = array_keys($triggerMap[$hostname] ?? []);
        if (empty($trigIds)) continue;

        $evCount = rand(0, min(5, $numEvents));
        for ($i = 0; $i < $evCount; $i++) {
            $eClock = $dayTs + rand(0, 50400); // 14h de intervalo
            $tIdx = array_rand($trigIds);
            $tId = $trigIds[$tIdx];
            $sev = $triggerMap[$hostname][$tId];
            $tRes = $db->query("SELECT description FROM triggers WHERE triggerid=$tId");
            $tName = $db->real_escape_string($tRes->fetch_assoc()['description'] ?? 'Alert');

            $eId = $nextEventId++;
            $db->query("INSERT IGNORE INTO events (eventid, source, object, objectid, clock, value, acknowledged, ns, name, severity)
                        VALUES ($eId, 0, 0, $tId, $eClock, 1, 1, 0, '$tName', $sev)");

            // Resolver após 30-120 min
            $rClock = $eClock + rand(1800, 7200);
            $rId = $nextEventId++;
            $db->query("INSERT IGNORE INTO events (eventid, source, object, objectid, clock, value, acknowledged, ns, name, severity)
                        VALUES ($rId, 0, 0, $tId, $rClock, 0, 0, 0, '$tName', $sev)");
            $db->query("INSERT IGNORE INTO event_recovery (eventid, r_eventid, c_eventid, correlationid, userid)
                        VALUES ($eId, $rId, NULL, NULL, NULL)");
            $hmInserted++;
        }
    }
}
log_msg("  Eventos heatmap (outros dias): $hmInserted");

// ── CLEANUP ─────────────────────────────────────────────────

$db->close();
api('user.logout', [], $auth);

log_msg("=== POPULATE DEMO DATA COMPLETE ===");
log_msg("Acesse o relatório em: zabbix.php?action=turnos.report.view&date=".TARGET_DATE."&shift=24h");
