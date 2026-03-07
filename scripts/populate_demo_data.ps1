# ============================================================
# Populate Demo Data — Repasse de Plantão
# Script PowerShell para popular dados de demo no Zabbix
# ============================================================

$ErrorActionPreference = 'Continue'
$apiUrl = 'http://localhost:8080/api_jsonrpc.php'

function Invoke-ZabbixApi($method, $params, $auth = $null) {
    $body = @{ jsonrpc = '2.0'; method = $method; params = $params; id = 1 }
    if ($auth) { $body['auth'] = $auth }
    $json = $body | ConvertTo-Json -Depth 10
    try {
        $r = Invoke-RestMethod -Uri $apiUrl -Method Post -Body $json -ContentType 'application/json; charset=utf-8'
        if ($r.error) { Write-Host "  [API ERROR] $method : $($r.error.data)" -ForegroundColor Red }
        return $r
    } catch { Write-Host "  [HTTP ERROR] $method : $_" -ForegroundColor Red; return $null }
}

function Run-SQL($sql) {
    $escaped = $sql -replace '"', '\"'
    podman exec zabbix-mariadb mysql -u zabbix -pzabbix zabbix -e "$escaped" 2>$null
}

Write-Host "=== POPULATE DEMO DATA ===" -ForegroundColor Cyan

# 1. Login
Write-Host "[1] Login na API..." -ForegroundColor Yellow
$login = Invoke-ZabbixApi 'user.login' @{ username = 'Admin'; password = 'zabbix' }
$auth = $login.result
Write-Host "  Auth token: $($auth.Substring(0,8))..."

# 2. Criar usuario Vinicius Costa
Write-Host "[2] Criando usuario Vinicius Costa..." -ForegroundColor Yellow
$existing = Invoke-ZabbixApi 'user.get' @{ output = @('userid'); filter = @{ username = 'vinicius.costa' } } $auth
if ($existing.result.Count -gt 0) {
    $viniciusId = $existing.result[0].userid
    Write-Host "  Já existe (ID: $viniciusId)"
} else {
    $newUser = Invoke-ZabbixApi 'user.create' @{
        username = 'vinicius.costa'
        name = 'Vinícius'
        surname = 'Costa'
        passwd = 'P@ssw0rd2026!'
        roleid = '1'
        usrgrps = @(@{ usrgrpid = '7' })
    } $auth
    $viniciusId = $newUser.result.userids[0]
    Write-Host "  Criado (ID: $viniciusId)"
}

# 3. Criar HostGroup
Write-Host "[3] Criando grupo NOC-Servidores..." -ForegroundColor Yellow
$grp = Invoke-ZabbixApi 'hostgroup.get' @{ output = @('groupid'); filter = @{ name = 'NOC-Servidores' } } $auth
if ($grp.result.Count -gt 0) {
    $groupId = $grp.result[0].groupid
    Write-Host "  Já existe (ID: $groupId)"
} else {
    $ng = Invoke-ZabbixApi 'hostgroup.create' @{ name = 'NOC-Servidores' } $auth
    $groupId = $ng.result.groupids[0]
    Write-Host "  Criado (ID: $groupId)"
}

# 4. Criar hosts
Write-Host "[4] Criando hosts..." -ForegroundColor Yellow
$hostDefs = @(
    @{ host = 'srv-web-01';  name = 'SRV-WEB-01';  ip = '192.168.1.10' },
    @{ host = 'srv-db-01';   name = 'SRV-DB-01';   ip = '192.168.1.20' },
    @{ host = 'srv-app-01';  name = 'SRV-APP-01';  ip = '192.168.1.30' },
    @{ host = 'fw-borda-01'; name = 'FW-BORDA-01'; ip = '10.0.0.1' },
    @{ host = 'sw-core-01';  name = 'SW-CORE-01';  ip = '10.0.0.2' }
)

$hostIds = @{}
foreach ($h in $hostDefs) {
    $ex = Invoke-ZabbixApi 'host.get' @{ output = @('hostid'); filter = @{ host = $h.host } } $auth
    if ($ex.result.Count -gt 0) {
        $hostIds[$h.host] = $ex.result[0].hostid
        Write-Host "  $($h.name) já existe (ID: $($hostIds[$h.host]))"
    } else {
        $cr = Invoke-ZabbixApi 'host.create' @{
            host = $h.host; name = $h.name
            groups = @(@{ groupid = $groupId })
            interfaces = @(@{ type = 1; main = 1; useip = 1; ip = $h.ip; dns = ''; port = '10050' })
        } $auth
        $hostIds[$h.host] = $cr.result.hostids[0]
        Write-Host "  $($h.name) criado (ID: $($hostIds[$h.host]))"
    }
}

# 5. Criar items e triggers
Write-Host "[5] Criando items e triggers..." -ForegroundColor Yellow
$itemDefs = @(
    @{ key='system.cpu.util'; name='CPU utilization'; trigger='CPU alta em {HOST.NAME}'; pri=4 },
    @{ key='vm.memory.util'; name='Memory utilization'; trigger='Memória alta em {HOST.NAME}'; pri=4 },
    @{ key='vfs.fs.pused.root'; name='Disco / usage %'; trigger='Disco cheio em {HOST.NAME} /'; pri=5 },
    @{ key='vfs.fs.pused.var'; name='Disco /var usage %'; trigger='Disco /var cheio em {HOST.NAME}'; pri=3 },
    @{ key='net.if.in.eth0'; name='Network inbound'; trigger='Tráfego alto em {HOST.NAME}'; pri=2 },
    @{ key='system.uptime.val'; name='System uptime'; trigger='{HOST.NAME} foi reiniciado'; pri=3 },
    @{ key='proc.num.mysqld'; name='MySQL processes'; trigger='MySQL down em {HOST.NAME}'; pri=5 },
    @{ key='system.swap.pused.val'; name='Swap usage %'; trigger='Swap alta em {HOST.NAME}'; pri=3 }
)

$triggerMap = @{} # hostname -> @{ triggerid = severity }
foreach ($hostname in $hostIds.Keys) {
    $hid = $hostIds[$hostname]
    $triggerMap[$hostname] = @{}

    foreach ($it in $itemDefs) {
        # Create item
        $exItem = Invoke-ZabbixApi 'item.get' @{ output = @('itemid'); hostids = @($hid); filter = @{ key_ = $it.key } } $auth
        if ($exItem.result.Count -gt 0) {
            $itemId = $exItem.result[0].itemid
        } else {
            $ci = Invoke-ZabbixApi 'item.create' @{
                hostid = $hid; name = $it.name; key_ = $it.key
                type = 2; value_type = 0; delay = '0'
            } $auth
            $itemId = $ci.result.itemids[0]
        }

        # Create trigger
        $expr = "last(/$hostname/$($it.key))>90"
        $exTrig = Invoke-ZabbixApi 'trigger.get' @{ output = @('triggerid'); hostids = @($hid); filter = @{ description = $it.trigger } } $auth
        if ($exTrig.result.Count -gt 0) {
            $trigId = $exTrig.result[0].triggerid
        } else {
            $ct = Invoke-ZabbixApi 'trigger.create' @{
                description = $it.trigger; expression = $expr; priority = $it.pri
            } $auth
            $trigId = $ct.result.triggerids[0]
        }
        if ($trigId) { $triggerMap[$hostname][$trigId] = $it.pri }
    }
    Write-Host "  $hostname : $($triggerMap[$hostname].Count) triggers"
}

# 6. Inserir eventos via SQL
Write-Host "[6] Inserindo eventos 05/03/2026..." -ForegroundColor Yellow

# Obter proximo eventid
$maxEvtRaw = podman exec zabbix-mariadb mysql -u zabbix -pzabbix zabbix -N -e "SELECT COALESCE(MAX(eventid),0) FROM events;" 2>$null
$nextEvtId = [int]($maxEvtRaw.Trim()) + 10000
$maxAckRaw = podman exec zabbix-mariadb mysql -u zabbix -pzabbix zabbix -N -e "SELECT COALESCE(MAX(acknowledgeid),0) FROM acknowledges;" 2>$null
$nextAckId = [int]($maxAckRaw.Trim()) + 10000

# Roberto ID
$robRes = Invoke-ZabbixApi 'user.get' @{ output = @('userid'); filter = @{ username = 'roberto.silva' } } $auth
$robertoId = if ($robRes.result.Count -gt 0) { $robRes.result[0].userid } else { '1' }

# Timestamp base 05/03/2026
$baseDate = '2026-03-05'

$eventSchedule = @(
    @('07:15','srv-web-01',0, 45,$viniciusId,120),
    @('07:30','srv-db-01', 1, 90,$viniciusId,300),
    @('08:00','fw-borda-01',4,30,'1',60),
    @('08:45','srv-web-01', 2, 0,$null,0),
    @('09:10','srv-app-01', 0, 60,$viniciusId,180),
    @('09:30','srv-db-01',  6,120,$robertoId,420),
    @('10:00','sw-core-01', 5, 15,$viniciusId,90),
    @('10:15','srv-web-01', 3, 60,'1',240),
    @('10:45','srv-app-01', 7, 0,$null,0),
    @('11:00','srv-web-01', 0, 30,$viniciusId,150),
    @('11:30','srv-db-01',  1, 45,$viniciusId,200),
    @('12:00','fw-borda-01',4, 20,'1',100),
    @('13:15','srv-app-01', 0, 60,$robertoId,600),
    @('13:45','srv-web-01', 2, 90,$viniciusId,350),
    @('14:00','srv-db-01',  6, 45,$viniciusId,180),
    @('14:30','sw-core-01', 4, 30,'1',120),
    @('15:00','srv-web-01', 1, 60,$viniciusId,90),
    @('15:30','srv-app-01', 7, 0,$null,0),
    @('16:00','srv-db-01',  0, 45,$robertoId,500),
    @('16:45','fw-borda-01',4, 60,$viniciusId,45),
    @('17:00','srv-web-01', 5, 20,'1',60),
    @('17:30','srv-app-01', 3, 60,$viniciusId,220),
    @('18:00','srv-db-01',  1, 0,$null,0),
    @('19:00','srv-web-01', 0, 50,$viniciusId,130),
    @('19:30','sw-core-01', 5, 10,'1',30),
    @('20:00','srv-db-01',  6,120,$viniciusId,900),
    @('21:00','srv-app-01', 0, 60,$viniciusId,200),
    @('22:00','fw-borda-01',4, 40,$viniciusId,150),
    @('23:00','srv-web-01', 2, 0,$null,0),
    @('23:30','srv-db-01',  7, 30,'1',60)
)

$sqlBatch = ""
$evtCount = 0
$ackCount = 0

foreach ($ev in $eventSchedule) {
    $hora = $ev[0]; $hostname = $ev[1]; $trigIdx = $ev[2]
    $resolveMin = $ev[3]; $ackUser = $ev[4]; $ackDelay = $ev[5]

    $trigIds = @($triggerMap[$hostname].Keys)
    if ($trigIdx -ge $trigIds.Count) { continue }
    $triggerId = $trigIds[$trigIdx]
    $severity = $triggerMap[$hostname][$triggerId]

    # Get trigger description
    $trigDescRaw = podman exec zabbix-mariadb mysql -u zabbix -pzabbix zabbix -N -e "SELECT description FROM triggers WHERE triggerid=$triggerId;" 2>$null
    $trigDesc = ($trigDescRaw.Trim() -replace "'", "''")

    # Calculate unix timestamp (UTC)
    $dtStr = "$baseDate $($hora):00"
    $unixTs = [int]([DateTimeOffset]::new([DateTime]::Parse($dtStr)).ToUnixTimeSeconds())

    $evId = $nextEvtId++
    $acked = if ($ackUser) { 1 } else { 0 }

    $sqlBatch += "INSERT IGNORE INTO events (eventid,source,object,objectid,clock,value,acknowledged,ns,name,severity) VALUES ($evId,0,0,$triggerId,$unixTs,1,$acked,0,'$trigDesc',$severity);`n"
    $evtCount++

    if ($resolveMin -gt 0) {
        $rTs = $unixTs + ($resolveMin * 60)
        $rEvId = $nextEvtId++
        $sqlBatch += "INSERT IGNORE INTO events (eventid,source,object,objectid,clock,value,acknowledged,ns,name,severity) VALUES ($rEvId,0,0,$triggerId,$rTs,0,0,0,'$trigDesc',$severity);`n"
        $sqlBatch += "INSERT IGNORE INTO event_recovery (eventid,r_eventid,c_eventid,correlationid,userid) VALUES ($evId,$rEvId,NULL,NULL,NULL);`n"
        $evtCount++
    }

    if ($ackUser) {
        $ackTs = $unixTs + $ackDelay
        $ackId = $nextAckId++
        $sqlBatch += "INSERT IGNORE INTO acknowledges (acknowledgeid,userid,eventid,clock,message,action,old_severity,new_severity,suppress_until,taskid) VALUES ($ackId,$ackUser,$evId,$ackTs,'Verificado. Ação tomada.',6,0,0,0,NULL);`n"
        $ackCount++
    }
}

# Write SQL to temp file and execute
$sqlBatch | Out-File -FilePath "$env:TEMP\events.sql" -Encoding utf8
podman cp "$env:TEMP\events.sql" zabbix-mariadb:/tmp/events.sql
podman exec zabbix-mariadb mysql -u zabbix -pzabbix zabbix -e "source /tmp/events.sql" 2>$null
Write-Host "  Eventos: $evtCount, ACKs: $ackCount"

# 7. Presença
Write-Host "[7] Populando presença..." -ForegroundColor Yellow
$presenceSql = ""
$presenceUsers = @(
    @($viniciusId, 'vinicius.costa', 'Vinícius Costa', '06:55', '19:05'),
    @('1', 'Admin', 'Zabbix Administrator', '07:00', '23:59')
)
if ($robertoId -ne '1') {
    $presenceUsers += ,@($robertoId, 'roberto.silva', 'Roberto Silva', '13:00', '19:10')
}

foreach ($pu in $presenceUsers) {
    $uid = $pu[0]; $uname = $pu[1]; $fname = $pu[2]
    $startHour = [DateTime]::Parse("$baseDate $($pu[3]):00")
    $endHour = [DateTime]::Parse("$baseDate $($pu[4]):00")
    $cur = $startHour
    while ($cur -lt $endHour) {
        $ss = $cur.ToString('yyyy-MM-dd HH:mm:ss')
        $sa = $cur.AddMinutes((Get-Random -Min 1 -Max 4)).ToString('yyyy-MM-dd HH:mm:ss')
        $presenceSql += "INSERT IGNORE INTO custom_user_sessions (userid,username,name,session_start,lastaccess) VALUES ($uid,'$uname','$fname','$ss','$sa');`n"
        $cur = $cur.AddMinutes(5)
    }
}
$presenceSql | Out-File -FilePath "$env:TEMP\presence.sql" -Encoding utf8
podman cp "$env:TEMP\presence.sql" zabbix-mariadb:/tmp/presence.sql
podman exec zabbix-mariadb mysql -u zabbix -pzabbix zabbix -e "source /tmp/presence.sql" 2>$null
Write-Host "  Presença populada"

# 8. Notas
Write-Host "[8] Populando diário de bordo..." -ForegroundColor Yellow
$notesSql = @"
INSERT INTO custom_shift_notes (shift_date,shift_name,analyst_userid,analyst_name,notes,created_at) VALUES
('$baseDate','24h',$viniciusId,'Vinícius Costa','Turno iniciado com 2 alertas herdados. Disco cheio SRV-WEB-01 e swap alto SRV-APP-01. Abri chamado #4521.','$baseDate 07:30:00'),
('$baseDate','24h',$viniciusId,'Vinícius Costa','MySQL parou no SRV-DB-01 por falta de memória. Reiniciado e ajustado innodb_buffer_pool_size.','$baseDate 09:45:00'),
('$baseDate','24h',1,'Zabbix Administrator','FW-BORDA-01 com tráfego acima do normal. Possível DDoS. Ativadas regras de rate-limiting.','$baseDate 12:30:00'),
('$baseDate','24h',$robertoId,'Roberto Silva','CPU alta recorrente no SRV-APP-01. Processo Java consumindo 98%%. Restart realizado.','$baseDate 15:00:00'),
('$baseDate','24h',$viniciusId,'Vinícius Costa','Encerramento: 30 alertas, 25 ACKs, 5 herdados. Recomendação: expandir /var no SRV-WEB-01.','$baseDate 23:50:00');
"@
$notesSql | Out-File -FilePath "$env:TEMP\notes.sql" -Encoding utf8
podman cp "$env:TEMP\notes.sql" zabbix-mariadb:/tmp/notes.sql
podman exec zabbix-mariadb mysql -u zabbix -pzabbix zabbix -e "source /tmp/notes.sql" 2>$null
Write-Host "  5 notas inseridas"

# 9. Heatmap (últimos 30 dias)
Write-Host "[9] Populando heatmap 30 dias..." -ForegroundColor Yellow
$hmSql = ""
for ($d = 29; $d -ge 1; $d--) {
    $dayStr = (Get-Date).AddDays(-$d).ToString('yyyy-MM-dd')
    if ($dayStr -eq $baseDate) { continue }
    $numEvt = Get-Random -Min 0 -Max 15
    if ($numEvt -eq 0) { continue }
    $dayTs = [int]([DateTimeOffset]::new([DateTime]::Parse("$dayStr 08:00:00")).ToUnixTimeSeconds())
    foreach ($hostname in $hostIds.Keys | Select-Object -First 3) {
        $trigIds = @($triggerMap[$hostname].Keys)
        if ($trigIds.Count -eq 0) { continue }
        $evCount = Get-Random -Min 1 -Max ([Math]::Min(4, $numEvt))
        for ($i = 0; $i -lt $evCount; $i++) {
            $eClock = $dayTs + (Get-Random -Min 0 -Max 50400)
            $tId = $trigIds[(Get-Random -Min 0 -Max $trigIds.Count)]
            $sev = $triggerMap[$hostname][$tId]
            $eId = $nextEvtId++
            $hmSql += "INSERT IGNORE INTO events (eventid,source,object,objectid,clock,value,acknowledged,ns,name,severity) VALUES ($eId,0,0,$tId,$eClock,1,1,0,'Alert',$sev);`n"
            $rClock = $eClock + (Get-Random -Min 1800 -Max 7200)
            $rId = $nextEvtId++
            $hmSql += "INSERT IGNORE INTO events (eventid,source,object,objectid,clock,value,acknowledged,ns,name,severity) VALUES ($rId,0,0,$tId,$rClock,0,0,0,'Alert',$sev);`n"
            $hmSql += "INSERT IGNORE INTO event_recovery (eventid,r_eventid,c_eventid,correlationid,userid) VALUES ($eId,$rId,NULL,NULL,NULL);`n"
        }
    }
}
$hmSql | Out-File -FilePath "$env:TEMP\heatmap.sql" -Encoding utf8
podman cp "$env:TEMP\heatmap.sql" zabbix-mariadb:/tmp/heatmap.sql
podman exec zabbix-mariadb mysql -u zabbix -pzabbix zabbix -e "source /tmp/heatmap.sql" 2>$null
Write-Host "  Heatmap populado"

# Logout
Invoke-ZabbixApi 'user.logout' @{} $auth | Out-Null

Write-Host ""
Write-Host "=== CONCLUÍDO ===" -ForegroundColor Green
Write-Host "Acesse: http://localhost:8080/zabbix.php?action=turnos.report.view&date=$baseDate&shift=24h" -ForegroundColor Cyan
