# Changelog

## [2.2.1] — Correções pós-implantação em homologação (08/05/2026)

> Ambiente: `dcsaanzabbixh` — Zabbix Homologação

### Correção de syntax error no cabeçalho do script

O bloco de comentário `/** ... */` continha a sequência `*/5` (linha do cron),
que fechava o bloco prematuramente. O PHP tentava executar `5 * * * *` como código
e retornava `Parse error: syntax error, unexpected token "*"` na linha 16.

**Correção:** cabeçalho convertido de bloco `/* */` para comentários de linha `//`.

```php
// Antes (quebrado — */5 fecha o bloco):
/**
 *   */5 * * * * TZ="America/Sao_Paulo" ...
 */

// Depois (correto):
//   */5 * * * * TZ="America/Sao_Paulo" ...
```

### Nota sobre DB_PORT por ambiente

O `DB_PORT` no script é definido como `5432` (padrão PostgreSQL), mas ambientes
Zabbix podem usar porta diferente. Sempre confirme antes de executar:

```bash
grep -E "^DBPort" /etc/zabbix/zabbix_server.conf
```

Ajuste direto no servidor (não versionado, pois varia por ambiente):

```bash
sed -i "s|define('DB_PORT', 5432)|define('DB_PORT', 5433)|" \
  /usr/share/zabbix/modules/TurnosNocReport/scripts/cron_presence_tracker.php
```

### Resultado após correções

```
[2026-05-08 21:58:35] === Presence Tracker Start ===
[2026-05-08 21:58:35] Usando API token.
[2026-05-08 21:58:35] Total de usuários encontrados: 11
[2026-05-08 21:58:35] Resultado: 4 inseridos, 0 atualizados.
[2026-05-08 21:58:35] === Presence Tracker End ===
```

---

## [2.2.0] — Hardening do cron_presence_tracker (homologação)

> Branch: `claude/update-cron-tracker-docs-9syjZ`
> Ambiente validado: `dcsaanzabbixh` — Zabbix Homologação

Conjunto de correções identificadas durante a implantação em ambiente de homologação.
Todas as alterações são retrocompatíveis com instalações existentes.

---

### Suporte a API Token no cron_presence_tracker

O script agora aceita **API Token** como método de autenticação preferencial, eliminando a
necessidade de manter credenciais de usuário/senha no arquivo.

```php
// Novo — preencha ZABBIX_TOKEN e deixe USER/PASS vazios
define('ZABBIX_TOKEN', 'seu-token-aqui');
define('ZABBIX_USER',  '');
define('ZABBIX_PASS',  '');
```

O script detecta automaticamente qual método usar: se `ZABBIX_TOKEN` não estiver vazio, usa o
token diretamente; caso contrário faz fallback para `user.login` com usuário/senha (comportamento
anterior, compatível com Zabbix 6.x e 7.x).

O `user.logout` passou a ser chamado **somente** em sessões criadas via `user.login` — tokens de
API não precisam (e não devem) ser invalidados pelo script.

---

### Correção de SSL no PHP CLI

Adicionado `CURLOPT_SSL_VERIFYHOST => false` ao bloco de opções do cURL.

O PHP CLI aplica validação de SAN mesmo com `CURLOPT_SSL_VERIFYPEER => false`. Quando o
certificado não cobre `localhost`, isso causava `HTTP 0` silencioso no terminal e bloqueava
toda a execução. O `curl` de linha de comando não apresentava o mesmo comportamento, dificultando
o diagnóstico.

---

### Logging de erro do cURL

O erro textual do cURL (`curl_error()`) passou a ser incluído na mensagem de log:

```
[2026-05-08 21:24:17] API Error: HTTP 0 — SSL: no alternative certificate subject name matches target host name 'localhost'
```

---

### Correção de timezone

Adicionado `date_default_timezone_set('America/Sao_Paulo')` no topo do script.

O PHP CLI herda UTC por padrão, independente do timezone do sistema operacional. Isso causava
registros com horário 3 horas adiantado e exibição de **Tempo Online negativo** no módulo.

A linha do cron foi atualizada para incluir `TZ="America/Sao_Paulo"` como segunda camada de
garantia, cobrindo casos onde o `php.ini` do CLI ainda não define o timezone.

---

### Correção do cron — formato e variável TZ

O exemplo de cron no cabeçalho do script e na documentação foi corrigido:

| | Antes | Depois |
|---|---|---|
| Arquivo | `(não existia)` | `/etc/cron.d/turnos-presence` |
| Formato | `CMD (www-data php ...)` | entrada padrão do `/etc/cron.d/` |
| Timezone | ausente | `TZ="America/Sao_Paulo"` na linha |
| `MAILTO` | ausente | `MAILTO=""` (evita e-mails de log) |

---

### Ajuste da URL padrão e conexão TCP ao PostgreSQL

| Constante | Antes | Depois |
|---|---|---|
| `ZABBIX_API_URL` | `http://localhost/...` | `https://localhost/...` |
| `DB_HOST` | `localhost` | `localhost` *(explicitado — não deixar vazio)* |
| `DB_PORT` | `5432` | `5432` *(com nota para verificar `DBPort` no `zabbix_server.conf`)* |

> **DB_HOST vazio** causava conexão via socket UNIX, que falha com `peer authentication`. Definir
> `DB_HOST=localhost` força TCP e permite autenticação `scram-sha-256`.

---

### Arquivos modificados

| Arquivo | Alteração |
|---|---|
| `scripts/cron_presence_tracker.php` | Token auth, timezone, SSL, curl error log, logout condicional, cron comment |
| `README.md` | Seção "Presença de Analistas" reescrita com API Token, troubleshooting, nota TZ |

---

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
