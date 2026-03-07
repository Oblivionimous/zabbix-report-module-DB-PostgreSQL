-- ============================================================
-- Relatório de Repasse de Plantão — Schema SQL
-- Execute este script no MariaDB/MySQL do Zabbix:
--   mysql -u zabbix -p zabbix < schema.sql
-- ============================================================

-- 1. Rastreamento de Presença (preenchida pelo cron)
CREATE TABLE IF NOT EXISTS custom_user_sessions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    userid          BIGINT UNSIGNED NOT NULL COMMENT 'FK → users.userid do Zabbix',
    username        VARCHAR(100)    NOT NULL,
    name            VARCHAR(128)    DEFAULT NULL COMMENT 'Nome completo (name + surname)',
    session_start   DATETIME        NOT NULL,
    lastaccess      DATETIME        NOT NULL,
    ip              VARCHAR(39)     DEFAULT NULL,
    PRIMARY KEY (id),
    INDEX idx_cus_userid (userid),
    INDEX idx_cus_lastaccess (lastaccess),
    INDEX idx_cus_session_start (session_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Diário de Bordo (notas escritas pelo analista)
CREATE TABLE IF NOT EXISTS custom_shift_notes (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shift_date      DATE            NOT NULL,
    shift_name      VARCHAR(20)     NOT NULL COMMENT 'Manhã, Tarde, Noite',
    analyst_userid  BIGINT UNSIGNED NOT NULL COMMENT 'FK → users.userid do Zabbix',
    analyst_name    VARCHAR(128)    NOT NULL,
    notes           TEXT            NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_csn_shift (shift_date, shift_name),
    INDEX idx_csn_analyst (analyst_userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Relatórios consolidados gerados
CREATE TABLE IF NOT EXISTS custom_shift_reports (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shift_date      DATE            NOT NULL,
    shift_name      VARCHAR(20)     NOT NULL,
    generated_by    BIGINT UNSIGNED NOT NULL,
    generated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    report_json     LONGTEXT        NOT NULL COMMENT 'Snapshot JSON do relatório completo',
    PRIMARY KEY (id),
    INDEX idx_csr_shift (shift_date, shift_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
