$ErrorActionPreference = "Stop"

$ZabbixApiUrl = "http://localhost:8080/api_jsonrpc.php"

function Call-ZabbixApi {
    param([string]$Method, [Hashtable]$Params, [string]$Token = $null)
    $body = @{
        jsonrpc = "2.0"
        method  = $Method
        params  = $Params
        id      = 1
    }
    if ($Token) { $body.auth = $Token }
    
    $req = @{
        Uri     = $ZabbixApiUrl
        Method  = "POST"
        Headers = @{"Content-Type" = "application/json-rpc" }
        Body    = ($body | ConvertTo-Json -Depth 10)
    }
    $res = Invoke-RestMethod @req
    if ($res.error) { throw "API Error: $($res.error.data)" }
    return $res.result
}

Write-Host "1. Firing alerts using zabbix_sender inside container..." -ForegroundColor Cyan
$hosts = @('srv-web-01', 'srv-db-01', 'srv-app-01', 'fw-borda-01', 'sw-core-01')
$items = @{
    'system.cpu.util'       = 95          # High CPU
    'vm.memory.util'        = 98           # High Memory
    'vfs.fs.pused.root'     = 92        # Disk /
    'vfs.fs.pused.var'      = 88         # Disk /var
    'system.uptime.val'     = 300       # Restarted
    'net.if.in.eth0'        = 900000000    # High Traffic
    'proc.num.mysqld'       = 0           # MySQL Down
    'system.swap.pused.val' = 60    # High Swap
}

foreach ($h in $hosts) {
    # Pick 4 random alerts per host
    $keys = $items.Keys | Get-Random -Count 4
    foreach ($k in $keys) {
        $v = $items[$k]
        podman exec zabbix-server zabbix_sender -z 127.0.0.1 -s $h -k $k -o $v *> $null
    }
}

Write-Host "2. Waiting 5s for Zabbix to generate events..." -ForegroundColor Cyan
Start-Sleep -Seconds 5

Write-Host "3. Authenticating..." -ForegroundColor Cyan
$tokenAdmin = Call-ZabbixApi "user.login" @{username = "Admin"; password = "zabbix" }

Write-Host "4. Acknowledging problems..." -ForegroundColor Cyan
$timeFrom = (Get-Date).AddHours(-1).ToUniversalTime()
$unixTime = [int][double]::Parse((Get-Date -Date $timeFrom -UFormat %s))

$problems = Call-ZabbixApi "problem.get" @{
    time_from    = $unixTime
    source       = 0
    object       = 0
    acknowledged = $false
} -Token $tokenAdmin

if ($problems.Count -gt 0) {
    Write-Host "Found $($problems.Count) problems."
    $count = 0
    foreach ($p in $problems) {
        $count++
        # Leave some unacknowledged
        if ($count % 5 -eq 0) { continue }
        
        # Acknowledge logic
        Call-ZabbixApi "event.acknowledge" @{
            eventids = @($p.eventid)
            action   = 2 # ACK
            message  = ""
        } -Token $tokenAdmin | Out-Null
    }
}

Write-Host "5. Faking MTTA times & Analyst Presence in Database..." -ForegroundColor Cyan
$sql = @"
-- Adjusting clock times for older ACKs to simulate MTTA
UPDATE acknowledges SET clock = clock + FLOOR(30 + (RAND() * 900)) WHERE clock > UNIX_TIMESTAMP() - 300;

-- Reassign ACKs from Admin to Vinicius Costa (4) and Roberto Silva (3)
UPDATE acknowledges SET userid = 4 WHERE acknowledgeid % 2 = 0 AND clock > UNIX_TIMESTAMP() - 300;
UPDATE acknowledges SET userid = 3 WHERE acknowledgeid % 3 = 0 AND clock > UNIX_TIMESTAMP() - 300;

-- Populating analyst presence for today
DELETE FROM custom_user_sessions WHERE lastaccess >= CURRENT_DATE;
INSERT INTO custom_user_sessions (userid, username, name, session_start, lastaccess) VALUES 
(4, 'vinicius.costa', 'Vinícius Costa', CONCAT(CURRENT_DATE, ' 06:55:00'), CONCAT(CURRENT_DATE, ' 19:01:00')),
(1, 'Admin', 'Zabbix Administrator', CONCAT(CURRENT_DATE, ' 07:00:00'), CONCAT(CURRENT_DATE, ' 23:57:00')),
(3, 'roberto.silva', 'Roberto Silva', CONCAT(CURRENT_DATE, ' 13:00:00'), CONCAT(CURRENT_DATE, ' 19:08:00'));

-- Removing diary notes
DELETE FROM custom_shift_notes WHERE shift_date=CURRENT_DATE;
"@

$sqlFile = "C:\Users\moise\OneDrive\Desktop\MODULO-RELATORIO\TurnosNocReport\scripts\temp_pssql.sql"
Set-Content -Path $sqlFile -Value $sql
cmd.exe /c "cat $sqlFile | podman exec -i zabbix-mariadb mysql -u zabbix -pzabbix zabbix"
Remove-Item $sqlFile

Write-Host "Process completed." -ForegroundColor Green
