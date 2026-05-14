<?php

namespace Modules\TurnosNocReport\Actions;

use CController,
    CControllerResponseData;

class TurnosReportView extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $fields = [
            'date'  => 'string',
            'shift' => 'in 24h,manha,tarde,plantao_dia,noite',
        ];

        $ret = $this->validateInput($fields);

        if (!$ret) {
            $this->setResponse(new CControllerResponseData(['error' => 'Parâmetros inválidos.']));
        }

        return $ret;
    }

    protected function checkPermissions(): bool {
        return true;
    }

    // ── Helpers ─────────────────────────────────────────────

    private function getDb(): ?\PDO {
        try {
            $host   = $GLOBALS['DB']['SERVER']   ?? 'localhost';
            $port   = $GLOBALS['DB']['PORT']     ?? '5432';
            $dbname = $GLOBALS['DB']['DATABASE'] ?? 'zabbix';
            $user   = $GLOBALS['DB']['USER']     ?? 'zabbix';
            $pass   = $GLOBALS['DB']['PASSWORD'] ?? '';

            $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
            return new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getShiftBounds(string $date, string $shift): array {
        switch ($shift) {
            case 'manha':
                return [strtotime("$date 06:00:00"), strtotime("$date 11:59:59")];
            case 'tarde':
                return [strtotime("$date 12:00:00"), strtotime("$date 17:59:59")];
            case 'plantao_dia':
                return [strtotime("$date 06:00:00"), strtotime("$date 17:59:59")];
            case 'noite':
                if ($date === date('Y-m-d') && (int)date('H') < 6) {
                    $date = date('Y-m-d', strtotime('-1 day', strtotime($date)));
                }
                $next = date('Y-m-d', strtotime("$date +1 day"));
                return [strtotime("$date 18:00:00"), strtotime("$next 05:59:59")];
            default:
                return [strtotime("$date 00:00:00"), strtotime("$date 23:59:59")];
        }
    }

    // ── Queries ─────────────────────────────────────────────

    private function queryMTTA(\PDO $db, int $s, int $e): array {
        $sql = "SELECT sub.userid, sub.username, sub.fullname,
                COUNT(*) AS total_acks, ROUND(AVG(sub.mtta)::numeric, 0) AS avg_mtta,
                MIN(sub.mtta) AS min_mtta, MAX(sub.mtta) AS max_mtta
            FROM (
                SELECT a.userid, u.username,
                    COALESCE(u.name,'') || ' ' || COALESCE(u.surname,'') AS fullname,
                    a.eventid, (a.clock - ev.clock) AS mtta
                FROM acknowledges a
                INNER JOIN events ev ON ev.eventid = a.eventid
                INNER JOIN users u ON u.userid = a.userid
                WHERE ev.source=0 AND ev.object=0
                  AND ev.clock BETWEEN $s AND $e
                  AND a.acknowledgeid=(SELECT MIN(a2.acknowledgeid) FROM acknowledges a2 WHERE a2.eventid=a.eventid)
            ) sub GROUP BY sub.userid, sub.username, sub.fullname ORDER BY avg_mtta ASC";
        return $db->query($sql)->fetchAll();
    }

    private function queryInheritedAlerts(\PDO $db, int $ts_start): array {
        $sql = "SELECT * FROM (
                SELECT DISTINCT ON (e.eventid)
                    e.eventid, e.clock, e.severity,
                    REPLACE(t.description, '{HOST.NAME}', h.name) AS trigger_desc,
                    h.host, h.name AS host_name, ($ts_start - e.clock) AS age_seconds,
                    CASE WHEN EXISTS (SELECT 1 FROM acknowledges ak WHERE ak.eventid=e.eventid) THEN 1 ELSE 0 END AS has_ack
                FROM events e
                LEFT JOIN event_recovery er ON er.eventid = e.eventid
                LEFT JOIN events re ON re.eventid = er.r_eventid
                INNER JOIN triggers t ON t.triggerid=e.objectid
                INNER JOIN functions f ON f.triggerid=t.triggerid
                INNER JOIN items i ON i.itemid=f.itemid
                INNER JOIN hosts h ON h.hostid=i.hostid
                WHERE e.source=0 AND e.object=0 AND e.value=1
                  AND e.clock < $ts_start AND (er.r_eventid IS NULL OR re.clock > $ts_start)
                ORDER BY e.eventid
            ) sub ORDER BY severity DESC, clock ASC LIMIT 50";
        return $db->query($sql)->fetchAll();
    }

    private function queryUnackedAlerts(\PDO $db, int $s, int $e): array {
        $sql = "SELECT * FROM (
                SELECT DISTINCT ON (ev.eventid)
                    ev.eventid, ev.clock, ev.severity,
                    REPLACE(t.description, '{HOST.NAME}', h.name) AS trigger_desc,
                    h.host, h.name AS host_name
                FROM events ev
                INNER JOIN triggers t ON t.triggerid=ev.objectid
                INNER JOIN functions f ON f.triggerid=t.triggerid
                INNER JOIN items i ON i.itemid=f.itemid
                INNER JOIN hosts h ON h.hostid=i.hostid
                WHERE ev.source=0 AND ev.object=0 AND ev.value=1
                  AND ev.clock BETWEEN $s AND $e
                  AND NOT EXISTS (SELECT 1 FROM acknowledges ak WHERE ak.eventid=ev.eventid)
                ORDER BY ev.eventid
            ) sub ORDER BY severity DESC, clock DESC LIMIT 50";
        return $db->query($sql)->fetchAll();
    }

    private function queryTopHosts(\PDO $db, int $s, int $e, int $limit): array {
        $limitClause = $limit > 0 ? "LIMIT $limit" : "";
        $sql = "SELECT h.hostid, h.host, h.name AS host_name,
                COUNT(DISTINCT ev.eventid) AS event_count, MAX(ev.severity) AS max_severity
            FROM events ev
            INNER JOIN triggers t ON t.triggerid=ev.objectid
            INNER JOIN functions f ON f.triggerid=t.triggerid
            INNER JOIN items i ON i.itemid=f.itemid
            INNER JOIN hosts h ON h.hostid=i.hostid
            WHERE ev.source=0 AND ev.object=0 AND ev.value=1 AND ev.clock BETWEEN $s AND $e
            GROUP BY h.hostid, h.host, h.name ORDER BY event_count DESC $limitClause";
        return $db->query($sql)->fetchAll();
    }

    private function queryTopTriggers(\PDO $db, int $s, int $e, int $limit): array {
        $limitClause = $limit > 0 ? "LIMIT $limit" : "";
        $sql = "SELECT t.triggerid,
                REPLACE(MIN(t.description), '{HOST.NAME}', MIN(h.name)) AS description,
                MAX(t.priority) AS severity, COUNT(DISTINCT ev.eventid) AS event_count
            FROM events ev
            INNER JOIN triggers t ON t.triggerid=ev.objectid
            INNER JOIN functions f ON f.triggerid=t.triggerid
            INNER JOIN items i ON i.itemid=f.itemid
            INNER JOIN hosts h ON h.hostid=i.hostid
            WHERE ev.source=0 AND ev.object=0 AND ev.value=1 AND ev.clock BETWEEN $s AND $e
            GROUP BY t.triggerid ORDER BY severity DESC, event_count DESC $limitClause";
        return $db->query($sql)->fetchAll();
    }

    private function queryEventTotals(\PDO $db, int $s, int $e): array {
        $sql = "SELECT COUNT(DISTINCT ev.eventid) AS total,
                SUM(CASE WHEN ev.severity>=4 THEN 1 ELSE 0 END) AS critical,
                SUM(CASE WHEN ev.severity=3 THEN 1 ELSE 0 END) AS average,
                SUM(CASE WHEN ev.severity<=2 THEN 1 ELSE 0 END) AS low
            FROM events ev
            INNER JOIN triggers t ON t.triggerid=ev.objectid
            WHERE ev.source=0 AND ev.object=0 AND ev.value=1 AND ev.clock BETWEEN $s AND $e";
        return $db->query($sql)->fetch() ?: ['total'=>0,'critical'=>0,'average'=>0,'low'=>0];
    }

    private function queryPresence(\PDO $db, int $s, int $e): array {
        $ds = date('Y-m-d H:i:s', $s);
        $de = date('Y-m-d H:i:s', $e);
        // Overlap: analista aparece no turno se esteve ativo em qualquer momento da janela.
        // Duração recortada ao turno: LEAST(lastaccess, fim) - GREATEST(session_start, inicio)
        $sql = "SELECT cus.userid, cus.username, cus.name AS fullname,
                MIN(cus.session_start) AS first_seen,
                MAX(cus.lastaccess)    AS last_seen,
                GREATEST(
                    EXTRACT(EPOCH FROM (
                        LEAST(MAX(cus.lastaccess), CAST(? AS TIMESTAMP)) -
                        GREATEST(MIN(cus.session_start), CAST(? AS TIMESTAMP))
                    ))::integer / 60,
                    0
                ) AS online_minutes
            FROM custom_user_sessions cus
            WHERE cus.session_start <= CAST(? AS TIMESTAMP)
              AND cus.lastaccess    >= CAST(? AS TIMESTAMP)
            GROUP BY cus.userid, cus.username, cus.name
            ORDER BY first_seen ASC";
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute([$de, $ds, $de, $ds]);
            return $stmt->fetchAll();
        } catch (\Exception $ex) { return []; }
    }

    private function queryNotes(\PDO $db, string $date, string $shift): array {
        $sql = "SELECT id, analyst_name, notes, created_at FROM custom_shift_notes
            WHERE shift_date = ? AND shift_name = ? ORDER BY created_at DESC";
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute([$date, $shift]);
            return $stmt->fetchAll();
        } catch (\Exception $ex) { return []; }
    }

    private function queryMttaTimeline(\PDO $db, int $s, int $e): array {
        $tzOffset = date('Z');
        // Agrupa pelo horário do ACK (não do evento) para mostrar quando a equipe respondeu.
        // a.clock BETWEEN $s AND $e garante apenas horas dentro da janela do turno.
        // GREATEST(ev.clock, $s) limita o MTTA de alertas herdados ao início do turno,
        // evitando que a idade histórica do evento distorça o gráfico.
        // AT TIME ZONE 'UTC' previne dupla conversão quando o PostgreSQL não está em UTC.
        $sql = "SELECT TO_CHAR(TO_TIMESTAMP(a.clock + $tzOffset) AT TIME ZONE 'UTC', 'HH24') AS hora,
                ROUND(AVG(a.clock - GREATEST(ev.clock, $s))::numeric, 0) AS avg_mtta
            FROM acknowledges a
            INNER JOIN events ev ON ev.eventid = a.eventid
            WHERE ev.source = 0 AND ev.object = 0
              AND a.clock BETWEEN $s AND $e
              AND a.acknowledgeid = (SELECT MIN(a2.acknowledgeid) FROM acknowledges a2 WHERE a2.eventid = a.eventid)
            GROUP BY hora ORDER BY hora ASC";
        return $db->query($sql)->fetchAll();
    }

    private function querySeverityDistribution(\PDO $db, int $s, int $e): array {
        $sql = "SELECT ev.severity, COUNT(*) AS cnt FROM events ev
            WHERE ev.source=0 AND ev.object=0 AND ev.value=1 AND ev.clock BETWEEN $s AND $e
            GROUP BY ev.severity ORDER BY ev.severity ASC";
        $rows = [];
        foreach ($db->query($sql)->fetchAll() as $r) {
            $rows[(int)$r['severity']] = (int)$r['cnt'];
        }
        return $rows;
    }

    private function queryCalendarHeatmap(\PDO $db): array {
        $ts30     = strtotime('-30 days 00:00:00');
        $tsNow    = time();
        $tzOffset = date('Z');
        $sql = "SELECT DATE(TO_TIMESTAMP(ev.clock + $tzOffset)) AS dia,
                COUNT(DISTINCT ev.eventid) AS cnt,
                SUM(CASE WHEN ev.severity>=4 THEN 1 ELSE 0 END) AS critical
            FROM events ev
            INNER JOIN triggers t ON t.triggerid=ev.objectid
            WHERE ev.source=0 AND ev.object=0 AND ev.value=1
              AND ev.clock BETWEEN $ts30 AND $tsNow
            GROUP BY dia ORDER BY dia ASC";
        $rows = [];
        foreach ($db->query($sql)->fetchAll() as $r) {
            $rows[$r['dia']] = $r;
        }
        return $rows;
    }

    // ── doAction ────────────────────────────────────────────

    protected function doAction(): void {
        $date  = $this->getInput('date', date('Y-m-d'));
        $shift = $this->getInput('shift', 'plantao_dia');

        $limitStr = $this->getInput('limit', '5');
        $limit = $limitStr === 'all' ? 0 : (int)$limitStr;

        [$ts_start, $ts_end] = $this->getShiftBounds($date, $shift);

        $db = $this->getDb();
        $db_error = null;

        if (!$db) {
            $db_error = 'Erro ao conectar ao banco de dados PostgreSQL.';
            $data_pack = ['mtta'=>[],'inherited'=>[],'unacked'=>[],'top_hosts'=>[],
                'top_triggers'=>[],'totals'=>['total'=>0,'critical'=>0,'average'=>0,'low'=>0],
                'presence'=>[],'notes'=>[],'mtta_timeline'=>[],'sev_dist'=>[],'calendar'=>[],'limit'=>$limitStr];
        } else {
            $data_pack = [
                'mtta'          => $this->queryMTTA($db, $ts_start, $ts_end),
                'inherited'     => $this->queryInheritedAlerts($db, $ts_start),
                'unacked'       => $this->queryUnackedAlerts($db, $ts_start, $ts_end),
                'top_hosts'     => $this->queryTopHosts($db, $ts_start, $ts_end, $limit),
                'top_triggers'  => $this->queryTopTriggers($db, $ts_start, $ts_end, $limit),
                'totals'        => $this->queryEventTotals($db, $ts_start, $ts_end),
                'presence'      => $this->queryPresence($db, $ts_start, $ts_end),
                'notes'         => $this->queryNotes($db, $date, $shift),
                'mtta_timeline' => $this->queryMttaTimeline($db, $ts_start, $ts_end),
                'sev_dist'      => $this->querySeverityDistribution($db, $ts_start, $ts_end),
                'calendar'      => $this->queryCalendarHeatmap($db),
                'limit'         => $limitStr
            ];
            $db = null;
        }

        $global_mtta = 0;
        if (!empty($data_pack['mtta'])) {
            $sum = 0; $cnt = 0;
            foreach ($data_pack['mtta'] as $m) {
                $sum += $m['avg_mtta'] * $m['total_acks'];
                $cnt += $m['total_acks'];
            }
            $global_mtta = $cnt > 0 ? round($sum / $cnt) : 0;
        }

        $current_user = \CWebUser::$data['username'] ?? 'admin';
        $current_fullname = trim((\CWebUser::$data['name'] ?? '').' '.(\CWebUser::$data['surname'] ?? '')) ?: $current_user;

        $data = $data_pack + [
            'date'             => $date,
            'shift'            => $shift,
            'ts_start'         => $ts_start,
            'ts_end'           => $ts_end,
            'db_error'         => $db_error,
            'global_mtta'      => $global_mtta,
            'is_history'       => ($date !== date('Y-m-d')),
            'current_user'     => $current_user,
            'current_fullname' => $current_fullname,
        ];

        $response = new CControllerResponseData($data);
        $response->setTitle(_('Repasse de Plantão'));
        $this->setResponse($response);
    }
}
