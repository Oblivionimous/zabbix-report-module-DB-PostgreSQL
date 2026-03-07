<?php
/**
 * TurnosReportPdf — Gera versão standalone para impressão/PDF
 * Abre em nova aba, página limpa e bonita pronta para Ctrl+P
 */

namespace Modules\TurnosNocReport\Actions;

use CController;

class TurnosReportPdf extends CController {

    protected function init(): void { $this->disableCsrfValidation(); }
    protected function checkInput(): bool {
        return $this->validateInput(['date' => 'string', 'shift' => 'in 24h,manha,tarde,noite']);
    }
    protected function checkPermissions(): bool { return true; }

    private function getDb(): ?\mysqli {
        try {
            $server = $GLOBALS['DB']['SERVER'] ?? 'localhost';
            $port   = $GLOBALS['DB']['PORT'] ?? '3306';
            $dbname = $GLOBALS['DB']['DATABASE'] ?? 'zabbix';
            $user   = $GLOBALS['DB']['USER'] ?? 'zabbix';
            $pass   = $GLOBALS['DB']['PASSWORD'] ?? '';
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $m = new \mysqli($server, $user, $pass, $dbname, (int)$port);
            $m->set_charset('utf8mb4');
            return $m;
        } catch (\Exception $e) { return null; }
    }

    private function getShiftBounds(string $date, string $shift): array {
        switch ($shift) {
            case 'manha': return [strtotime("$date 07:00:00"), strtotime("$date 12:59:59")];
            case 'tarde': return [strtotime("$date 13:00:00"), strtotime("$date 18:59:59")];
            case 'noite':
                $next = date('Y-m-d', strtotime("$date +1 day"));
                return [strtotime("$date 19:00:00"), strtotime("$next 06:59:59")];
            default: return [strtotime("$date 00:00:00"), strtotime("$date 23:59:59")];
        }
    }

    private function sevLabel(int $s): string {
        return [0=>'N/C',1=>'Info',2=>'Atenção',3=>'Média',4=>'Alta',5=>'Desastre'][$s] ?? 'N/A';
    }
    private function sevClass(int $s): string {
        return [0=>'notclass',1=>'info',2=>'warn',3=>'avg',4=>'high',5=>'disaster'][$s] ?? 'info';
    }
    private function fmtDuration(int $s): string {
        if ($s<60) return $s.'s'; if ($s<3600) return floor($s/60).'m '.($s%60).'s';
        return floor($s/3600).'h '.floor(($s%3600)/60).'m';
    }
    private function shiftLabel(string $sh): string {
        return ['manha'=>'Manhã (07h–13h)','tarde'=>'Tarde (13h–19h)','noite'=>'Noite (19h–07h)','24h'=>'24 Horas'][$sh] ?? $sh;
    }

    protected function doAction(): void {
        $date  = $this->getInput('date', date('Y-m-d'));
        $shift = $this->getInput('shift', '24h');
        [$ts_start, $ts_end] = $this->getShiftBounds($date, $shift);

        $db = $this->getDb();
        if (!$db) { echo '<h1>Erro de conexão com o banco de dados.</h1>'; die(); }

        // Run all queries (same as TurnosReportView but inline for PDF)
        $mtta = $this->q($db, "SELECT sub.userid,sub.username,sub.fullname,COUNT(*) AS total_acks,
            ROUND(AVG(sub.mtta),0) AS avg_mtta,MIN(sub.mtta) AS min_mtta,MAX(sub.mtta) AS max_mtta
            FROM (SELECT a.userid,u.username,CONCAT(COALESCE(u.name,''),' ',COALESCE(u.surname,'')) AS fullname,
                a.eventid,(a.clock-ev.clock) AS mtta FROM acknowledges a
                INNER JOIN events ev ON ev.eventid=a.eventid INNER JOIN users u ON u.userid=a.userid
                WHERE ev.source=0 AND ev.object=0 AND ev.clock BETWEEN $ts_start AND $ts_end
                AND a.acknowledgeid=(SELECT MIN(a2.acknowledgeid) FROM acknowledges a2 WHERE a2.eventid=a.eventid)
            ) sub GROUP BY sub.userid ORDER BY avg_mtta ASC");

        $inherited = $this->q($db, "SELECT e.eventid,e.clock,e.severity,t.description AS trigger_desc,
            h.host,h.name AS host_name,($ts_start-e.clock) AS age_seconds,
            CASE WHEN EXISTS(SELECT 1 FROM acknowledges ak WHERE ak.eventid=e.eventid) THEN 1 ELSE 0 END AS has_ack
            FROM events e LEFT JOIN event_recovery er ON er.eventid=e.eventid
            LEFT JOIN events re ON re.eventid=er.r_eventid
            INNER JOIN triggers t ON t.triggerid=e.objectid INNER JOIN functions f ON f.triggerid=t.triggerid
            INNER JOIN items i ON i.itemid=f.itemid INNER JOIN hosts h ON h.hostid=i.hostid
            WHERE e.source=0 AND e.object=0 AND e.value=1 AND e.clock<$ts_start AND (er.r_eventid IS NULL OR re.clock>$ts_start)
            GROUP BY e.eventid ORDER BY e.severity DESC LIMIT 50");

        $unacked = $this->q($db, "SELECT ev.eventid,ev.clock,ev.severity,t.description AS trigger_desc,h.host,h.name AS host_name
            FROM events ev INNER JOIN triggers t ON t.triggerid=ev.objectid INNER JOIN functions f ON f.triggerid=t.triggerid
            INNER JOIN items i ON i.itemid=f.itemid INNER JOIN hosts h ON h.hostid=i.hostid
            WHERE ev.source=0 AND ev.object=0 AND ev.value=1 AND ev.clock BETWEEN $ts_start AND $ts_end
            AND NOT EXISTS(SELECT 1 FROM acknowledges ak WHERE ak.eventid=ev.eventid)
            GROUP BY ev.eventid ORDER BY ev.severity DESC LIMIT 50");

        $top_hosts = $this->q($db, "SELECT h.host,h.name AS host_name,COUNT(DISTINCT ev.eventid) AS event_count,MAX(ev.severity) AS max_severity
            FROM events ev INNER JOIN triggers t ON t.triggerid=ev.objectid INNER JOIN functions f ON f.triggerid=t.triggerid
            INNER JOIN items i ON i.itemid=f.itemid INNER JOIN hosts h ON h.hostid=i.hostid
            WHERE ev.source=0 AND ev.object=0 AND ev.value=1 AND ev.clock BETWEEN $ts_start AND $ts_end
            GROUP BY h.hostid ORDER BY event_count DESC LIMIT 5");

        $top_triggers = $this->q($db, "SELECT t.description,t.priority AS severity,COUNT(DISTINCT ev.eventid) AS event_count
            FROM events ev INNER JOIN triggers t ON t.triggerid=ev.objectid
            WHERE ev.source=0 AND ev.object=0 AND ev.value=1 AND ev.clock BETWEEN $ts_start AND $ts_end
            GROUP BY t.triggerid ORDER BY t.priority DESC,event_count DESC LIMIT 5");

        $totals = $db->query("SELECT COUNT(DISTINCT ev.eventid) AS total,
            SUM(CASE WHEN ev.severity>=4 THEN 1 ELSE 0 END) AS critical,
            SUM(CASE WHEN ev.severity=3 THEN 1 ELSE 0 END) AS average,
            SUM(CASE WHEN ev.severity<=2 THEN 1 ELSE 0 END) AS low
            FROM events ev INNER JOIN triggers t ON t.triggerid=ev.objectid
            WHERE ev.source=0 AND ev.object=0 AND ev.value=1
            AND ev.clock BETWEEN $ts_start AND $ts_end")->fetch_assoc() ?: ['total'=>0,'critical'=>0,'average'=>0,'low'=>0];

        $notes = $this->qSafe($db, "SELECT analyst_name,notes,created_at FROM custom_shift_notes
            WHERE shift_date='$date' AND shift_name='$shift' ORDER BY created_at DESC");

        $user_fn = trim((\CWebUser::$data['name']??'').' '.(\CWebUser::$data['surname']??'')) ?: (\CWebUser::$data['username']??'Admin');
        $db->close();

        // Global MTTA
        $gmtta = 0;
        if ($mtta) { $s=0;$c=0; foreach($mtta as $m){$s+=$m['avg_mtta']*$m['total_acks'];$c+=$m['total_acks'];} $gmtta=$c>0?round($s/$c):0; }

        // ── PDF HTML ────────────────────────────────────────
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">';
        echo '<title>Repasse de Plantão — '.$this->shiftLabel($shift).' — '.$date.'</title>';
        echo '<style>'.file_get_contents(__DIR__.'/../assets/css/turnos.report.css').'
            body{background:#fff!important;font-size:11px}
            .rp-native-container{max-width:100%;padding:20px 30px}
            .rp-native-header{background:#2b3c51!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
            .rp-nh-btn,.rp-note-form{display:none!important}
            .sev-disaster,.sev-high,.sev-avg,.sev-warn,.sev-info,.sev-notclass,
            .bg-blue,.bg-red,.bg-orange,.bg-yellow,.bg-purple,.bg-green,
            .row-disaster,.row-high,.row-avg,.row-warn,.perf-good,.perf-ok,.perf-bad,
            .rp-badge-hist,.rp-kpi-icon{-webkit-print-color-adjust:exact;print-color-adjust:exact}
            .rp-card{break-inside:avoid;box-shadow:none;border:1px solid #ccc}
            @page{size:A4 landscape;margin:10mm}
        </style>';
        echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>';
        echo '</head><body>';

        echo '<div class="rp-native-container">';

        // Header
        echo '<div class="rp-native-header"><div class="rp-nh-left">';
        echo '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14l2 2 4-4"/></svg>';
        echo '<span class="rp-nh-title">Repasse de Plantão</span>';
        echo '<span class="rp-nh-sub">'.$this->shiftLabel($shift).' — '.$date.'</span>';
        echo '</div></div>';

        // KPIs
        echo '<div class="rp-kpi-grid">';
        echo '<div class="rp-kpi"><div class="rp-kpi-icon bg-blue"><i class="fas fa-bell"></i></div><div class="rp-kpi-body"><span class="rp-kpi-val">'.(int)$totals['total'].'</span><span class="rp-kpi-label">Total Eventos</span></div></div>';
        echo '<div class="rp-kpi"><div class="rp-kpi-icon bg-red"><i class="fas fa-fire"></i></div><div class="rp-kpi-body"><span class="rp-kpi-val">'.(int)$totals['critical'].'</span><span class="rp-kpi-label">Críticos</span></div></div>';
        echo '<div class="rp-kpi"><div class="rp-kpi-icon bg-orange"><i class="fas fa-clock"></i></div><div class="rp-kpi-body"><span class="rp-kpi-val">'.$this->fmtDuration($gmtta).'</span><span class="rp-kpi-label">MTTA Global</span></div></div>';
        echo '<div class="rp-kpi"><div class="rp-kpi-icon bg-yellow"><i class="fas fa-exclamation-circle"></i></div><div class="rp-kpi-body"><span class="rp-kpi-val">'.count($unacked).'</span><span class="rp-kpi-label">Sem ACK</span></div></div>';
        echo '<div class="rp-kpi"><div class="rp-kpi-icon bg-purple"><i class="fas fa-history"></i></div><div class="rp-kpi-body"><span class="rp-kpi-val">'.count($inherited).'</span><span class="rp-kpi-label">Herdados</span></div></div>';
        echo '</div>';

        // MTTA table
        if ($mtta) {
            echo '<div class="rp-card"><div class="rp-card-head"><i class="fas fa-stopwatch"></i> MTTA por Analista</div>';
            echo '<table class="rp-table"><thead><tr><th>Analista</th><th>ACKs</th><th>MTTA Médio</th><th>Mín</th><th>Máx</th></tr></thead><tbody>';
            foreach ($mtta as $m) {
                echo '<tr><td class="td-bold">'.$m['fullname'].'</td><td class="td-center">'.$m['total_acks'].'</td>';
                echo '<td class="td-mono">'.$this->fmtDuration((int)$m['avg_mtta']).'</td>';
                echo '<td class="td-mono">'.$this->fmtDuration((int)$m['min_mtta']).'</td>';
                echo '<td class="td-mono">'.$this->fmtDuration((int)$m['max_mtta']).'</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        // Inherited
        if ($inherited) {
            echo '<div class="rp-card"><div class="rp-card-head"><i class="fas fa-history"></i> Alertas Herdados</div>';
            echo '<table class="rp-table"><thead><tr><th>Início</th><th>Severidade</th><th>Host</th><th>Problema</th><th>Idade</th></tr></thead><tbody>';
            foreach ($inherited as $r) {
                echo '<tr class="row-'.$this->sevClass((int)$r['severity']).'">';
                echo '<td class="td-mono">'.date('d/m H:i',(int)$r['clock']).'</td>';
                echo '<td><span class="rp-sev sev-'.$this->sevClass((int)$r['severity']).'">'.$this->sevLabel((int)$r['severity']).'</span></td>';
                echo '<td>'.$r['host'].'</td><td>'.$r['trigger_desc'].'</td>';
                echo '<td class="td-bold">'.$this->fmtDuration((int)$r['age_seconds']).'</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        // Unacked
        if ($unacked) {
            echo '<div class="rp-card"><div class="rp-card-head"><i class="fas fa-exclamation-triangle"></i> Alertas Sem ACK</div>';
            echo '<table class="rp-table"><thead><tr><th>Hora</th><th>Severidade</th><th>Host</th><th>Problema</th></tr></thead><tbody>';
            foreach ($unacked as $r) {
                echo '<tr class="row-'.$this->sevClass((int)$r['severity']).'">';
                echo '<td class="td-mono">'.date('H:i:s',(int)$r['clock']).'</td>';
                echo '<td><span class="rp-sev sev-'.$this->sevClass((int)$r['severity']).'">'.$this->sevLabel((int)$r['severity']).'</span></td>';
                echo '<td>'.$r['host'].'</td><td>'.$r['trigger_desc'].'</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        // Top Hosts + Triggers side by side
        echo '<div class="rp-noise-row">';
        if ($top_hosts) {
            echo '<div class="rp-card"><div class="rp-card-head"><i class="fas fa-server"></i> Top Hosts</div>';
            echo '<table class="rp-table"><thead><tr><th>#</th><th>Host</th><th>Eventos</th><th>Sev.</th></tr></thead><tbody>';
            $i=1; foreach ($top_hosts as $r) {
                echo '<tr><td class="td-center">'.$i++.'</td><td>'.$r['host'].'</td>';
                echo '<td class="td-center td-bold">'.$r['event_count'].'</td>';
                echo '<td><span class="rp-sev sev-'.$this->sevClass((int)$r['max_severity']).'">'.$this->sevLabel((int)$r['max_severity']).'</span></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        if ($top_triggers) {
            echo '<div class="rp-card"><div class="rp-card-head"><i class="fas fa-bolt"></i> Top Triggers</div>';
            echo '<table class="rp-table"><thead><tr><th>#</th><th>Trigger</th><th>Eventos</th><th>Sev.</th></tr></thead><tbody>';
            $i=1; foreach ($top_triggers as $r) {
                echo '<tr><td class="td-center">'.$i++.'</td><td>'.$r['description'].'</td>';
                echo '<td class="td-center td-bold">'.$r['event_count'].'</td>';
                echo '<td><span class="rp-sev sev-'.$this->sevClass((int)$r['severity']).'">'.$this->sevLabel((int)$r['severity']).'</span></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';

        // Notas
        if ($notes) {
            echo '<div class="rp-card"><div class="rp-card-head"><i class="fas fa-book-open"></i> Diário de Bordo</div><div class="rp-card-body">';
            foreach ($notes as $n) {
                echo '<div class="rp-note-item"><div class="rp-note-header"><strong>'.$n['analyst_name'].'</strong> <span class="rp-note-time">'.$n['created_at'].'</span></div>';
                echo '<div class="rp-note-content">'.nl2br(htmlspecialchars($n['notes'])).'</div></div>';
            }
            echo '</div></div>';
        }

        // Footer
        echo '<div class="rp-native-footer"><span>Relatório gerado em '.date('d/m/Y H:i:s').' por '.$user_fn.'</span><span>Módulo Repasse v2.0.0</span></div>';
        echo '</div></body></html>';

        // Auto print
        echo '<script>window.onload=function(){window.print();}</script>';
        die();
    }

    private function q(\mysqli $db, string $sql): array {
        $res = $db->query($sql); $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        return $rows;
    }

    private function qSafe(\mysqli $db, string $sql): array {
        try { return $this->q($db, $sql); } catch (\Exception $e) { return []; }
    }
}
