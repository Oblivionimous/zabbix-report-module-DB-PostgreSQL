-- ============================================================
-- Relatório de Repasse de Plantão — Queries SQL de Referência
-- Todas estas queries são usadas internamente pelo controller
-- TurnosReportView.php. Documentadas aqui para referência.
-- ============================================================

-- =========================
-- 1. MTTA — Mean Time to Acknowledge (por usuário)
-- =========================
-- Calcula a média de tempo (em segundos) entre a criação do evento
-- e o primeiro ACK, agrupado por usuário que fez o ACK.
-- Parâmetros: @ts_start e @ts_end (unix timestamp do turno)

SELECT
    a.userid,
    u.username,
    CONCAT(COALESCE(u.name,''), ' ', COALESCE(u.surname,'')) AS fullname,
    COUNT(DISTINCT a.eventid) AS total_acks,
    ROUND(AVG(a.clock - e.clock), 0) AS avg_mtta_seconds,
    MIN(a.clock - e.clock) AS min_mtta_seconds,
    MAX(a.clock - e.clock) AS max_mtta_seconds
FROM acknowledges a
INNER JOIN events e ON e.eventid = a.eventid
INNER JOIN users u ON u.userid = a.userid
WHERE e.source = 0          -- trigger events
  AND e.object = 0          -- triggers
  AND e.clock BETWEEN @ts_start AND @ts_end
  AND a.clock BETWEEN @ts_start AND @ts_end
  -- Pegar apenas o PRIMEIRO ack de cada evento:
  AND a.acknowledgeid = (
      SELECT MIN(a2.acknowledgeid)
      FROM acknowledges a2
      WHERE a2.eventid = a.eventid
  )
GROUP BY a.userid, u.username, u.name, u.surname
ORDER BY avg_mtta_seconds ASC;


-- =========================
-- 2. ALERTAS HERDADOS (pendentes de turnos anteriores)
-- =========================
-- Eventos que iniciaram ANTES do turno atual e ainda estão
-- com status PROBLEM (sem evento de resolução).

SELECT
    e.eventid,
    e.clock AS event_time,
    FROM_UNIXTIME(e.clock) AS event_datetime,
    e.severity,
    t.description AS trigger_desc,
    h.host,
    h.name AS host_name,
    CASE WHEN EXISTS (
        SELECT 1 FROM acknowledges ak WHERE ak.eventid = e.eventid
    ) THEN 1 ELSE 0 END AS has_ack,
    (@ts_start - e.clock) AS age_seconds
FROM events e
LEFT JOIN event_recovery er ON er.eventid = e.eventid
INNER JOIN triggers t ON t.triggerid = e.objectid
INNER JOIN functions f ON f.triggerid = t.triggerid
INNER JOIN items i ON i.itemid = f.itemid
INNER JOIN hosts h ON h.hostid = i.hostid
WHERE e.source = 0
  AND e.object = 0
  AND e.value = 1             -- PROBLEM
  AND e.clock < @ts_start     -- começou ANTES do turno
  AND er.r_eventid IS NULL    -- sem resolução (via event_recovery)
GROUP BY e.eventid
ORDER BY e.severity DESC, e.clock ASC;


-- =========================
-- 3. ALERTAS SEM ACK com usuários logados
-- =========================
-- Eventos PROBLEM no período que NÃO receberam ACK,
-- mas havia usuários logados naquele horário.

SELECT
    e.eventid,
    FROM_UNIXTIME(e.clock) AS event_datetime,
    e.severity,
    t.description AS trigger_desc,
    h.host,
    h.name AS host_name
FROM events e
INNER JOIN triggers t ON t.triggerid = e.objectid
INNER JOIN functions f ON f.triggerid = t.triggerid
INNER JOIN items i ON i.itemid = f.itemid
INNER JOIN hosts h ON h.hostid = i.hostid
WHERE e.source = 0
  AND e.object = 0
  AND e.value = 1
  AND e.clock BETWEEN @ts_start AND @ts_end
  AND NOT EXISTS (
      SELECT 1 FROM acknowledges ak WHERE ak.eventid = e.eventid
  )
GROUP BY e.eventid
ORDER BY e.severity DESC, e.clock DESC;


-- =========================
-- 4. TOP 5 HOSTS que mais alertaram (24h)
-- =========================

SELECT
    h.hostid,
    h.host,
    h.name AS host_name,
    COUNT(e.eventid) AS event_count,
    MAX(e.severity) AS max_severity
FROM events e
INNER JOIN triggers t ON t.triggerid = e.objectid
INNER JOIN functions f ON f.triggerid = t.triggerid
INNER JOIN items i ON i.itemid = f.itemid
INNER JOIN hosts h ON h.hostid = i.hostid
WHERE e.source = 0
  AND e.object = 0
  AND e.value = 1
  AND e.clock BETWEEN @ts_start AND @ts_end
GROUP BY h.hostid, h.host, h.name
ORDER BY event_count DESC
LIMIT 5;


-- =========================
-- 5. TOP 5 TRIGGERS por severidade (24h)
-- =========================

SELECT
    t.triggerid,
    t.description,
    t.priority AS severity,
    COUNT(e.eventid) AS event_count
FROM events e
INNER JOIN triggers t ON t.triggerid = e.objectid
WHERE e.source = 0
  AND e.object = 0
  AND e.value = 1
  AND e.clock BETWEEN @ts_start AND @ts_end
GROUP BY t.triggerid, t.description, t.priority
ORDER BY t.priority DESC, event_count DESC
LIMIT 5;


-- =========================
-- 6. CONTAGEM TOTAL de eventos no período
-- =========================

SELECT
    COUNT(*) AS total_events,
    SUM(CASE WHEN e.severity >= 4 THEN 1 ELSE 0 END) AS critical_events,
    SUM(CASE WHEN e.severity = 3 THEN 1 ELSE 0 END) AS average_events,
    SUM(CASE WHEN e.severity <= 2 THEN 1 ELSE 0 END) AS low_events
FROM events e
WHERE e.source = 0
  AND e.object = 0
  AND e.value = 1
  AND e.clock BETWEEN @ts_start AND @ts_end;


-- =========================
-- 7. PRESENÇA de usuários no turno (da tabela custom)
-- =========================

SELECT
    cus.userid,
    cus.username,
    cus.name AS fullname,
    MIN(cus.session_start) AS first_seen,
    MAX(cus.lastaccess) AS last_seen,
    TIMESTAMPDIFF(MINUTE, MIN(cus.session_start), MAX(cus.lastaccess)) AS online_minutes
FROM custom_user_sessions cus
WHERE cus.lastaccess BETWEEN FROM_UNIXTIME(@ts_start) AND FROM_UNIXTIME(@ts_end)
GROUP BY cus.userid, cus.username, cus.name
ORDER BY first_seen ASC;
