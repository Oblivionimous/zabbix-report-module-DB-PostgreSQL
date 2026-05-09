-- ============================================================
-- Relatório de Repasse de Plantão — Schema SQL (PostgreSQL)
-- Execute este script no banco PostgreSQL do Zabbix:
--   psql -U zabbix -d zabbix -f schema.sql
-- ============================================================

-- 1. Rastreamento de Presença (preenchida pelo cron)
CREATE TABLE IF NOT EXISTS custom_user_sessions (
    id              BIGSERIAL       NOT NULL,
    userid          BIGINT          NOT NULL,
    username        VARCHAR(100)    NOT NULL,
    name            VARCHAR(128)    DEFAULT NULL,
    session_start   TIMESTAMP       NOT NULL,
    lastaccess      TIMESTAMP       NOT NULL,
    ip              VARCHAR(39)     DEFAULT NULL,
    PRIMARY KEY (id)
);
CREATE INDEX IF NOT EXISTS idx_cus_userid        ON custom_user_sessions (userid);
CREATE INDEX IF NOT EXISTS idx_cus_lastaccess    ON custom_user_sessions (lastaccess);
CREATE INDEX IF NOT EXISTS idx_cus_session_start ON custom_user_sessions (session_start);

-- 2. Diário de Bordo (notas escritas pelo analista)
CREATE TABLE IF NOT EXISTS custom_shift_notes (
    id              BIGSERIAL       NOT NULL,
    shift_date      DATE            NOT NULL,
    shift_name      VARCHAR(20)     NOT NULL,
    analyst_userid  BIGINT          NOT NULL,
    analyst_name    VARCHAR(128)    NOT NULL,
    notes           TEXT            NOT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);
CREATE INDEX IF NOT EXISTS idx_csn_shift    ON custom_shift_notes (shift_date, shift_name);
CREATE INDEX IF NOT EXISTS idx_csn_analyst  ON custom_shift_notes (analyst_userid);

-- 3. Relatórios consolidados gerados
CREATE TABLE IF NOT EXISTS custom_shift_reports (
    id              BIGSERIAL       NOT NULL,
    shift_date      DATE            NOT NULL,
    shift_name      VARCHAR(20)     NOT NULL,
    generated_by    BIGINT          NOT NULL,
    generated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    report_json     TEXT            NOT NULL,
    PRIMARY KEY (id)
);
CREATE INDEX IF NOT EXISTS idx_csr_shift ON custom_shift_reports (shift_date, shift_name);
