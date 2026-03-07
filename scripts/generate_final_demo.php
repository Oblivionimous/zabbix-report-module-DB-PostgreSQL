<?php
/**
 * Generate Final Demo Data for Zabbix Noc Report
 * Triggers alerts via zabbix_sender, ACKs them via API.
 */

$hosts = ['srv-web-01', 'srv-db-01', 'srv-app-01', 'fw-borda-01', 'sw-core-01'];
$items = [
    'system.cpu.util' => 95,          // High CPU (Average)
    'vm.memory.util' => 98,           // High Memory (High)
    'vfs.fs.pused.root' => 92,        // Disk / (Disaster)
    'vfs.fs.pused.var' => 88,         // Disk /var (Warning)
    'system.uptime.val' => 300,       // Restarted (Warning)
    'net.if.in.eth0' => 900000000,    // High Traffic (Warning)
    'proc.num.mysqld' => 0,           // MySQL Down (Disaster/High)
    'system.swap.pused.val' => 60     // High Swap (Warning)
];

echo "1. Sending data via zabbix_sender to trigger alerts...\n";
foreach ($hosts as $h) {
    // Generate 3-5 random alerts per host
    $keys = array_keys($items);
    shuffle($keys);
    $num_alerts = rand(3, 5);
    for ($i=0; $i<$num_alerts; $i++) {
        $k = $keys[$i];
        $v = $items[$k];
        $cmd = "zabbix_sender -z 127.0.0.1 -s '{$h}' -k '{$k}' -o {$v}";
        exec($cmd);
    }
}

echo "2. Waiting 5 seconds for Zabbix to process events...\n";
sleep(5);

// API generic call
function api_call($method, $params, $auth=null) {
    $data = ['jsonrpc'=>'2.0','method'=>$method,'params'=>$params,'id'=>1];
    if($auth) $data['auth']=$auth;
    $ch = curl_init('http://127.0.0.1:8080/api_jsonrpc.php');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json-rpc']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = json_decode(curl_exec($ch), true);
    return $res['result'] ?? null;
}

echo "3. Authenticating users for ACK...\n";
$token_vini = api_call('user.login', ['username'=>'vinicius.costa','password'=>'zabbix']);
$token_rob = api_call('user.login', ['username'=>'roberto.silva','password'=>'zabbix']);
$token_admin = api_call('user.login', ['username'=>'Admin','password'=>'zabbix']);

echo "4. Fetching newly generated problems...\n";
// Get problems from the last hour
$probs = api_call('problem.get', [
    'time_from' => time() - 3600,
    'source' => 0,
    'object' => 0,
    'acknowledged' => false
], $token_admin);

if ($probs) {
    echo "Found " . count($probs) . " problems to process.\n";
    $count = 0;
    foreach ($probs as $p) {
        $count++;
        // Leave ~20% unacknowledged (every 5th)
        if ($count % 5 == 0) continue;
        
        $token = $token_vini;
        if ($count % 3 == 0) $token = $token_admin;
        if ($count % 4 == 0) $token = $token_rob;
        
        // Wait 1-10 seconds of simulated MTTA
        $mtta = rand(30, 600);
        
        // Acknowledge API (message empty as requested)
        api_call('event.acknowledge', [
            'eventids' => [$p['eventid']],
            'action' => 2, // ACKNOWLEDGE action
            'message' => ''
        ], $token);
        
        // Unfortunately API uses current time for ACK. We can't fake MTTA easily via API.
        // We will fake MTTA by updating the acknowledges table directly later.
    }
}

echo "5. Faking MTTA times in database & populating presence/notes...\n";
$db = new mysqli('127.0.0.1', 'zabbix', 'zabbix', 'zabbix');
if ($db->connect_error) die("DB Error: " . $db->connect_error);

// Update acknowledges to have older clocks so MTTA is realistic (instead of 0 seconds)
$res = $db->query("SELECT acknowledgeid, eventid FROM acknowledges WHERE clock > UNIX_TIMESTAMP() - 300");
while($row = $res->fetch_assoc()) {
    $ev = $db->query("SELECT clock FROM events WHERE eventid={$row['eventid']}")->fetch_assoc();
    if ($ev) {
        $fake_mtta = rand(45, 1200); // 45s to 20m
        $new_clock = $ev['clock'] + $fake_mtta;
        $db->query("UPDATE acknowledges SET clock={$new_clock} WHERE acknowledgeid={$row['acknowledgeid']}");
    }
}

// Presence
$today = date('Y-m-d');
$db->query("DELETE FROM custom_user_sessions WHERE lastaccess >= '{$today} 00:00:00'");
$times = [
    [4, 'vinicius.costa', 'Vinícius Costa', '06:55:00', '19:01:00'],
    [1, 'Admin', 'Zabbix Administrator', '07:00:00', '23:57:00'],
    [3, 'roberto.silva', 'Roberto Silva', '13:00:00', '19:08:00']
];
foreach ($times as $t) {
    $db->query("INSERT INTO custom_user_sessions (userid, username, name, session_start, lastaccess) 
                VALUES ({$t[0]}, '{$t[1]}', '{$t[2]}', '{$today} {$t[3]}', '{$today} {$t[4]}')");
}

// Notes
$db->query("DELETE FROM custom_shift_notes WHERE shift_date='{$today}'");
$db->query("INSERT INTO custom_shift_notes (shift_date, shift_name, analyst_userid, analyst_name, notes, created_at) VALUES
('{$today}','24h',4,'Vinícius Costa','Rotina normal, alertas de disco no DB tratados.','{$today} 09:45:00'),
('{$today}','24h',4,'Vinícius Costa','Repasse: Tudo OK, atenção com uso de swap.','{$today} 18:50:00')");

echo "Done!\n";
