# Changelog

## [2.1.0] — Adaptação PostgreSQL + Zabbix 7.0 + Turnos NOC

> Branch: `claude/postgresql-zabbix-adaptation-VkDHs`
> Fork base: `JohnnyIver/zabbix-report-module`

Esta versão adapta o módulo para a realidade de um NOC que utiliza **PostgreSQL** como banco de dados do Zabbix, **Zabbix 7.0** e opera em turnos das **06h às 18h**.

---

### Migração MySQL → PostgreSQL

#### Camada de conexão

Todos os arquivos PHP foram migrados de `mysqli` para **PDO com driver `pgsql`**. A porta padrão foi alterada de `3306` para `5432`.

| Antes | Depois |
|---|---|
| `new \mysqli($host, $user, $pass, $db, $port)` | `new \PDO("pgsql:host=...", $user, $pass)` |
| `$mysqli->set_charset('utf8mb4')` | Removido (não se aplica ao PostgreSQL) |
| `mysqli_report(MYSQLI_REPORT_ERROR \| MYSQLI_REPORT_STRICT)` | `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION` |
| `$db->close()` | `$db = null` |
| `$db->insert_id` | `RETURNING id` na query + `fetch()` |

**Arquivos alterados:** `TurnosReportView.php`, `TurnosReportPdf.php`, `TurnosNotesSave.php`, `TurnosNotesGet.php`, `cron_presence_tracker.php`

#### Funções SQL convertidas

| Função MySQL | Equivalente PostgreSQL |
|---|---|
| `FROM_UNIXTIME(clock, '%H')` | `TO_CHAR(TO_TIMESTAMP(clock), 'HH24')` |
| `DATE(FROM_UNIXTIME(clock + offset))` | `DATE(TO_TIMESTAMP(clock + offset))` |
| `TIMESTAMPDIFF(MINUTE, a, b)` | `EXTRACT(EPOCH FROM (b - a))::integer / 60` |
| `CONCAT(a, ' ', b)` | `a \|\| ' ' \|\| b` |
| `ROUND(AVG(col), 0)` | `ROUND(AVG(col)::numeric, 0)` |
| `DATE_SUB(?, INTERVAL 5 MINUTE)` | Calculado no PHP com `strtotime('-5 minutes')` |

#### Correções de GROUP BY (incompatibilidade PostgreSQL)

O PostgreSQL exige que todas as colunas não-agregadas do `SELECT` estejam no `GROUP BY`. As seguintes correções foram aplicadas:

- **Alertas herdados e sem ACK:** substituído `GROUP BY e.eventid` por `DISTINCT ON (e.eventid)` dentro de subquery, com ordenação externa. Isso elimina duplicatas causadas pelos JOINs com `functions/items/hosts` sem agrupar colunas desnecessariamente.

- **Top Hosts:** `GROUP BY h.hostid` → `GROUP BY h.hostid, h.host, h.name`

- **Top Triggers:** colunas `t.description` e `t.priority` passaram a ser agregadas com `MIN()` e `MAX()` respectivamente, mantendo `GROUP BY t.triggerid`.

- **MTTA por analista:** `GROUP BY sub.userid` → `GROUP BY sub.userid, sub.username, sub.fullname`

#### Schema SQL (`sql/schema.sql`)

| Elemento MySQL | Equivalente PostgreSQL |
|---|---|
| `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` | `BIGSERIAL NOT NULL` |
| `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` | Removido |
| `INDEX idx_... (col)` dentro do `CREATE TABLE` | `CREATE INDEX IF NOT EXISTS idx_... ON tabela (col)` separado |
| `DATETIME` | `TIMESTAMP` |
| `LONGTEXT` | `TEXT` |

#### Instalador (`scripts/install.sh`)

| Antes | Depois |
|---|---|
| `mysql -u $user -p$pass $db < schema.sql` | `PGPASSWORD="$pass" psql -h $host -U $user -d $db -f schema.sql` |
| Porta padrão `3306` | Porta padrão `5432` |
| Container padrão `zabbix-mariadb` | Container padrão `zabbix-postgres` |
| Verificação de dependência `mysql` | Verificação de dependência `psql` |

---

### Ajuste dos Turnos do NOC

Os horários dos turnos foram atualizados para refletir a operação do NOC das **06h às 18h**. Um novo turno **Plantão Dia** foi adicionado para cobrir a janela diurna completa.

| Turno | Antes | Depois |
|---|---|---|
| Manhã | 07:00 → 12:59 | **06:00 → 11:59** |
| Tarde | 13:00 → 18:59 | **12:00 → 17:59** |
| Plantão Dia | — | **06:00 → 17:59** *(novo)* |
| Noite | 19:00 → 06:59 (+1d) | **18:00 → 05:59 (+1d)** |
| 24 Horas | 00:00 → 23:59 | 00:00 → 23:59 *(inalterado)* |

A lógica de detecção automática do turno noturno foi ajustada: se o horário atual for anterior às **06:00** (antes era 07:00), o sistema retroage para o dia anterior ao exibir o turno noturno corrente.

**Arquivos alterados:** `TurnosReportView.php`, `TurnosReportPdf.php`, `views/turnos.report.view.php`

A validação de input em todos os controllers foi atualizada para aceitar o novo valor `plantao_dia`:

```
'shift' => 'in 24h,manha,tarde,plantao_dia,noite'
```

---

### Compatibilidade Zabbix 7.0

O módulo já era compatível com Zabbix 7.0 via fallback de autenticação na API. Nenhuma alteração adicional foi necessária neste ponto — o `cron_presence_tracker.php` tenta primeiro o parâmetro `username` (7.x) e faz fallback para `user` (6.x) automaticamente.

---

### Correção de Segurança

A query de notas do diário de bordo em `TurnosReportView.php` utilizava interpolação de string direta, expondo risco de SQL injection:

```php
// Antes (vulnerável)
$sql = "... WHERE shift_date='$date' AND shift_name='$shift'";
$db->query($sql);

// Depois (seguro)
$stmt = $db->prepare("... WHERE shift_date = ? AND shift_name = ?");
$stmt->execute([$date, $shift]);
```

---

### Arquivos Modificados

| Arquivo | Tipo de Alteração |
|---|---|
| `actions/TurnosReportView.php` | mysqli→PDO, SQL→PostgreSQL, turnos, SQL injection fix |
| `actions/TurnosReportPdf.php` | mysqli→PDO, SQL→PostgreSQL, turnos, labels |
| `actions/TurnosNotesSave.php` | mysqli→PDO, validação `plantao_dia`, RETURNING id |
| `actions/TurnosNotesGet.php` | mysqli→PDO |
| `views/turnos.report.view.php` | Labels e opções do seletor de turno |
| `sql/schema.sql` | Sintaxe completa PostgreSQL |
| `scripts/cron_presence_tracker.php` | mysqli→PDO, SQL→PostgreSQL |
| `scripts/install.sh` | mysql→psql, porta 5432, container PostgreSQL |

---

### Requisitos da versão 2.1.0

| Componente | Versão |
|---|---|
| Zabbix | 7.0+ (compatível com 6.4+) |
| PHP | 8.0+ com extensão `pdo_pgsql` |
| PostgreSQL | 13+ |
