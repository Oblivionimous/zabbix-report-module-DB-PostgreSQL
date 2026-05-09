# Documentação Técnica — Módulo Repasse de Plantão

> Versão 2.0.0 · Zabbix 7.0+ · PHP 8.0+ · PostgreSQL 13+

---

## Índice

1. [Visão Geral](#1-visão-geral)
2. [Arquitetura](#2-arquitetura)
3. [Registro do Módulo](#3-registro-do-módulo)
4. [Banco de Dados](#4-banco-de-dados)
5. [Controllers (Actions)](#5-controllers-actions)
6. [Lógica de Turnos](#6-lógica-de-turnos)
7. [Queries SQL](#7-queries-sql)
8. [View e Frontend](#8-view-e-frontend)
9. [Diário de Bordo](#9-diário-de-bordo)
10. [Cron Presence Tracker](#10-cron-presence-tracker)
11. [Export PDF](#11-export-pdf)
12. [Fluxo Completo de uma Requisição](#12-fluxo-completo-de-uma-requisição)

---

## 1. Visão Geral

O módulo adiciona ao Zabbix uma página nativa em **Relatórios → Repasse Plantão** que consolida, para um turno e data escolhidos:

| Seção | O que exibe |
|---|---|
| KPIs | Total de eventos, críticos, MTTA global, sem ACK, herdados, analistas online |
| MTTA por Analista | Tempo médio de reconhecimento por usuário |
| MTTA por Hora | Gráfico de linha ao longo da janela do turno |
| Severidade | Distribuição dos alertas por nível (doughnut / barras) |
| Heatmap 30 dias | Calendário visual do volume de alertas |
| Alertas Herdados | Problemas de turnos anteriores ainda abertos |
| Alertas Sem ACK | Eventos sem reconhecimento |
| Top Hosts / Triggers | Ranking dos que mais alertaram |
| Presença | Analistas online durante o turno (via cron) |
| Diário de Bordo | Anotações AJAX por turno |

Tudo é calculado em tempo real diretamente nas tabelas do Zabbix, sem agentes extras ou modificações no core.

---

## 2. Arquitetura

```
┌─────────────────────────────────────────────────────┐
│                  Navegador do analista               │
│  GET zabbix.php?action=turnos.report.view            │
│  POST zabbix.php?action=turnos.report.notes.save     │
└──────────────┬───────────────────────────────────────┘
               │ HTTP (Zabbix Frontend)
┌──────────────▼───────────────────────────────────────┐
│                  Zabbix Frontend (PHP)                │
│                                                       │
│  Module.php ──► registra item no menu Reports        │
│                                                       │
│  manifest.json ──► mapeia actions → classes PHP      │
│                                                       │
│  actions/                                             │
│    TurnosReportView.php  ◄── requisição principal    │
│    TurnosReportPdf.php   ◄── export PDF              │
│    TurnosNotesSave.php   ◄── AJAX salvar nota        │
│    TurnosNotesGet.php    ◄── AJAX buscar notas       │
│                                                       │
│  views/turnos.report.view.php  ◄── template HTML     │
└──────────────┬───────────────────────────────────────┘
               │ PDO (pgsql)
┌──────────────▼───────────────────────────────────────┐
│               PostgreSQL (banco do Zabbix)            │
│                                                       │
│  Tabelas nativas:          Tabelas customizadas:      │
│  ├─ events                 ├─ custom_user_sessions    │
│  ├─ acknowledges           ├─ custom_shift_notes      │
│  ├─ triggers               └─ custom_shift_reports    │
│  ├─ hosts                                             │
│  ├─ items                                             │
│  ├─ functions                                         │
│  ├─ users                                             │
│  └─ sessions                                          │
└──────────────┬───────────────────────────────────────┘
               │ PHP CLI (cron a cada 5 min)
┌──────────────▼───────────────────────────────────────┐
│           cron_presence_tracker.php                   │
│                                                       │
│  API Zabbix (HTTPS + token) → user.get               │
│  └─► sessions (tabela nativa) → custom_user_sessions │
└─────────────────────────────────────────────────────┘
```

O módulo **nunca modifica** tabelas nativas do Zabbix — apenas lê. Toda persistência própria vai para as três tabelas `custom_*`.

---

## 3. Registro do Módulo

### `manifest.json`

Define o módulo para o Zabbix e mapeia cada rota para sua classe PHP:

```json
{
    "manifest_version": 2.0,
    "id": "turnos-noc-report",
    "name": "Relatório Repasse de Plantão",
    "version": "2.0.0",
    "namespace": "TurnosNocReport",
    "actions": {
        "turnos.report.view":        { "class": "TurnosReportView",  "view": "turnos.report.view" },
        "turnos.report.notes.save":  { "class": "TurnosNotesSave",   "view": null },
        "turnos.report.notes.get":   { "class": "TurnosNotesGet",    "view": null },
        "turnos.report.pdf":         { "class": "TurnosReportPdf",   "view": "turnos.report.view" }
    },
    "assets": {
        "css": ["turnos.report.css"],
        "js":  ["class.turnos.report.js"]
    }
}
```

### `Module.php`

Executado na inicialização do Zabbix. Insere o item **"Repasse Plantão"** dentro do menu **Reports**:

```php
public function init(): void {
    $menu = APP::Component()->get('menu.main');
    $reportsMenu = $menu->find(_('Reports'));

    if ($reportsMenu !== null && $reportsMenu->hasSubMenu()) {
        $reportsMenu->getSubMenu()->add(
            (new CMenuItem(_('Repasse Plantão')))
                ->setAction('turnos.report.view')
                ->setAliases([
                    'turnos.report.notes.save',
                    'turnos.report.notes.get',
                    'turnos.report.pdf'
                ])
        );
    }
}
```

Os aliases garantem que o item do menu fique marcado como ativo ao acessar qualquer sub-rota do módulo.

---

## 4. Banco de Dados

O módulo cria três tabelas no PostgreSQL do Zabbix, todas com prefixo `custom_` para não colidir com tabelas nativas.

### `custom_user_sessions` — Rastreamento de Presença

Preenchida exclusivamente pelo `cron_presence_tracker.php` a cada 5 minutos.

```sql
CREATE TABLE IF NOT EXISTS custom_user_sessions (
    id            BIGSERIAL    NOT NULL,
    userid        BIGINT       NOT NULL,   -- FK lógica para users.userid
    username      VARCHAR(100) NOT NULL,
    name          VARCHAR(128) DEFAULT NULL,
    session_start TIMESTAMP    NOT NULL,   -- quando o analista foi visto pela 1ª vez
    lastaccess    TIMESTAMP    NOT NULL,   -- última vez visto nesta sessão
    ip            VARCHAR(39)  DEFAULT NULL,
    PRIMARY KEY (id)
);
```

**Como funciona:** o cron lê `sessions` (tabela nativa do Zabbix) para cada usuário e verifica se há sessão ativa (`status = 0`). Se sim, registra ou atualiza um registro em `custom_user_sessions`. A lógica de janela de 5 minutos evita duplicatas:

```sql
-- Verifica se já existe registro nos últimos 5 min para o mesmo usuário
SELECT id FROM custom_user_sessions
WHERE userid = ? AND lastaccess >= ?   -- ? = now - 5min
ORDER BY id DESC LIMIT 1
```

- Se existe → `UPDATE` do `lastaccess`
- Se não existe → `INSERT` novo registro (início de nova janela de presença)

---

### `custom_shift_notes` — Diário de Bordo

```sql
CREATE TABLE IF NOT EXISTS custom_shift_notes (
    id             BIGSERIAL    NOT NULL,
    shift_date     DATE         NOT NULL,   -- data do turno (YYYY-MM-DD)
    shift_name     VARCHAR(20)  NOT NULL,   -- 'plantao_dia', 'manha', 'tarde', 'noite', '24h'
    analyst_userid BIGINT       NOT NULL,
    analyst_name   VARCHAR(128) NOT NULL,
    notes          TEXT         NOT NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);
```

Cada nota é imutável após inserida. Não há edição ou exclusão — o histórico é append-only.

---

### `custom_shift_reports` — Relatórios Consolidados

```sql
CREATE TABLE IF NOT EXISTS custom_shift_reports (
    id           BIGSERIAL NOT NULL,
    shift_date   DATE      NOT NULL,
    shift_name   VARCHAR(20) NOT NULL,
    generated_by BIGINT    NOT NULL,
    generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    report_json  TEXT      NOT NULL,   -- snapshot JSON do relatório gerado
    PRIMARY KEY (id)
);
```

Armazena snapshots de relatórios gerados para referência histórica.

---

## 5. Controllers (Actions)

### `TurnosReportView.php` — Controller Principal

Responsável por toda a coleta de dados e preparação para a view.

**Validação de entrada:**
```php
protected function checkInput(): bool {
    $fields = [
        'date'  => 'string',
        'shift' => 'in 24h,manha,tarde,plantao_dia,noite',
    ];
    return $this->validateInput($fields);
}
```

**Fluxo do `doAction()`:**

```
1. Recebe date (padrão: hoje) e shift (padrão: plantao_dia)
2. Calcula ts_start e ts_end (unix timestamps) conforme o turno
3. Conecta ao PostgreSQL via PDO ($GLOBALS['DB'])
4. Executa 10 queries em sequência
5. Formata os dados para a view
6. Chama $this->setResponse(new CControllerResponseData([...]))
```

**Conexão com o banco** — o módulo reutiliza as credenciais já configuradas no Zabbix:

```php
private function getDb(): ?\PDO {
    $host   = $GLOBALS['DB']['SERVER']   ?? 'localhost';
    $port   = $GLOBALS['DB']['PORT']     ?? '5432';
    $dbname = $GLOBALS['DB']['DATABASE'] ?? 'zabbix';
    $user   = $GLOBALS['DB']['USER']     ?? 'zabbix';
    $pass   = $GLOBALS['DB']['PASSWORD'] ?? '';

    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    return new \PDO($dsn, $user, $pass, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    ]);
}
```

---

### `TurnosNotesSave.php` — Salvar Nota (AJAX)

Recebe `POST` com `note`, `shift` e `shift_date`. Duas validações de segurança antes de inserir:

```php
// 1. Bloqueia datas anteriores
if ($shift_date !== date('Y-m-d')) {
    echo json_encode(['success' => false,
        'message' => 'Não é possível adicionar notas em datas anteriores.']);
    die();
}

// 2. Rejeita nota vazia
if (empty($note)) {
    echo json_encode(['success' => false, 'message' => 'A nota não pode ser vazia.']);
    die();
}
```

A inserção usa `RETURNING id` para devolver o ID gerado sem segunda query:

```php
$stmt = $db->prepare(
    "INSERT INTO custom_shift_notes
        (shift_date, shift_name, analyst_userid, analyst_name, notes, created_at)
     VALUES (?, ?, ?, ?, ?, NOW()) RETURNING id"
);
$stmt->execute([$shift_date, $shift, $userid, $fullname, $note]);
```

Identidade do analista vem da sessão autenticada do Zabbix:
```php
$userid   = \CWebUser::$data['userid']   ?? 0;
$username = \CWebUser::$data['username'] ?? 'unknown';
$fullname = trim((\CWebUser::$data['name'] ?? '') . ' ' . (\CWebUser::$data['surname'] ?? ''));
```

---

### `TurnosNotesGet.php` — Buscar Notas (AJAX)

Retorna JSON com as notas do turno/data solicitados:

```php
$shift      = $_GET['shift']      ?? $_POST['shift']      ?? 'plantao_dia';
$shift_date = $_GET['shift_date'] ?? $_POST['shift_date'] ?? date('Y-m-d');

$stmt = $db->prepare(
    "SELECT analyst_name, notes, created_at
     FROM custom_shift_notes
     WHERE shift_date = ? AND shift_name = ?
     ORDER BY created_at ASC"
);
```

---

## 6. Lógica de Turnos

O coração do módulo está no cálculo de `ts_start` e `ts_end` a partir do turno selecionado. Todos os filtros SQL usam esses dois unix timestamps.

```php
$date  = $this->getInput('date', date('Y-m-d'));
$shift = $this->getInput('shift', 'plantao_dia');

switch ($shift) {
    case 'manha':
        // 06:00 → 11:59
        $ts_start = strtotime("$date 06:00:00");
        $ts_end   = strtotime("$date 11:59:59");
        break;

    case 'tarde':
        // 12:00 → 17:59
        $ts_start = strtotime("$date 12:00:00");
        $ts_end   = strtotime("$date 17:59:59");
        break;

    case 'plantao_dia':
        // 06:00 → 17:59 (turno diurno completo)
        $ts_start = strtotime("$date 06:00:00");
        $ts_end   = strtotime("$date 17:59:59");
        break;

    case 'noite':
        // 18:00 do dia selecionado → 05:59 do dia seguinte
        $ts_start = strtotime("$date 18:00:00");
        $ts_end   = strtotime("$date 05:59:59 +1 day");
        break;

    case '24h':
    default:
        // 00:00 → 23:59
        $ts_start = strtotime("$date 00:00:00");
        $ts_end   = strtotime("$date 23:59:59");
        break;
}
```

**Referência completa dos turnos:**

| Turno | `ts_start` | `ts_end` | Duração |
|---|---|---|---|
| `plantao_dia` | `$date 06:00:00` | `$date 17:59:59` | 12h |
| `manha` | `$date 06:00:00` | `$date 11:59:59` | 6h |
| `tarde` | `$date 12:00:00` | `$date 17:59:59` | 6h |
| `noite` | `$date 18:00:00` | `$date+1 05:59:59` | 12h |
| `24h` | `$date 00:00:00` | `$date 23:59:59` | 24h |

---

## 7. Queries SQL

Todas as queries filtram por `e.source = 0` (trigger events) e `e.object = 0` (objeto trigger), que são os alertas operacionais do Zabbix.

### KPIs Totais

```sql
SELECT
    COUNT(*) AS total_events,
    SUM(CASE WHEN severity >= 4 THEN 1 ELSE 0 END) AS critical_events
FROM events
WHERE source = 0 AND object = 0 AND value = 1
  AND clock BETWEEN $ts_start AND $ts_end
```

### MTTA por Analista

Calcula o tempo médio entre a criação do evento (`e.clock`) e o primeiro ACK (`a.clock`). Usa subquery para garantir que só o **primeiro** acknowledgement de cada evento seja considerado:

```sql
SELECT
    a.userid,
    u.username,
    u.name || ' ' || u.surname AS fullname,
    COUNT(DISTINCT a.eventid)         AS total_acks,
    ROUND(AVG(a.clock - e.clock), 0)  AS avg_mtta_seconds
FROM acknowledges a
INNER JOIN events e ON e.eventid = a.eventid
INNER JOIN users  u ON u.userid  = a.userid
WHERE e.source = 0 AND e.object = 0
  AND e.clock BETWEEN $ts_start AND $ts_end
  AND a.acknowledgeid = (
      SELECT MIN(a2.acknowledgeid)
      FROM acknowledges a2 WHERE a2.eventid = a.eventid
  )
GROUP BY a.userid, u.username, u.name, u.surname
ORDER BY avg_mtta_seconds ASC
```

### Alertas Herdados

Eventos que começaram **antes** do turno atual e ainda não foram resolvidos. Usa `event_recovery` para verificar ausência de resolução:

```sql
SELECT DISTINCT ON (e.eventid) e.eventid, e.clock, e.severity,
    t.description, h.host, h.name AS host_name
FROM events e
LEFT JOIN event_recovery er ON er.eventid = e.eventid
INNER JOIN triggers  t ON t.triggerid = e.objectid
INNER JOIN functions f ON f.triggerid  = t.triggerid
INNER JOIN items     i ON i.itemid     = f.itemid
INNER JOIN hosts     h ON h.hostid     = i.hostid
WHERE e.source = 0 AND e.object = 0
  AND e.value  = 1            -- PROBLEM
  AND e.clock  < $ts_start    -- começou ANTES do turno
  AND er.r_eventid IS NULL    -- sem evento de resolução
ORDER BY e.eventid, e.severity DESC
```

`DISTINCT ON (e.eventid)` é necessário por causa dos JOINs com `functions/items/hosts`, que podem gerar múltiplas linhas para o mesmo evento (um trigger pode monitorar múltiplos itens).

### Alertas Sem ACK

```sql
SELECT DISTINCT ON (e.eventid) e.eventid, e.clock, e.severity,
    t.description, h.host, h.name
FROM events e
INNER JOIN triggers  t ON t.triggerid = e.objectid
INNER JOIN functions f ON f.triggerid  = t.triggerid
INNER JOIN items     i ON i.itemid     = f.itemid
INNER JOIN hosts     h ON h.hostid     = i.hostid
WHERE e.source = 0 AND e.object = 0 AND e.value = 1
  AND e.clock BETWEEN $ts_start AND $ts_end
  AND NOT EXISTS (
      SELECT 1 FROM acknowledges ak WHERE ak.eventid = e.eventid
  )
ORDER BY e.eventid, e.severity DESC
```

### Presença de Analistas

Consulta a tabela customizada para encontrar analistas com `lastaccess` dentro da janela do turno:

```sql
SELECT
    userid, username, name AS fullname,
    MIN(session_start) AS first_seen,
    MAX(lastaccess)    AS last_seen,
    EXTRACT(EPOCH FROM (MAX(lastaccess) - MIN(session_start)))::integer / 60
        AS online_minutes
FROM custom_user_sessions
WHERE lastaccess BETWEEN TO_TIMESTAMP($ts_start) AND TO_TIMESTAMP($ts_end)
GROUP BY userid, username, name
ORDER BY first_seen ASC
```

### Heatmap (30 dias)

Agrega eventos por dia nos últimos 30 dias para o calendário visual:

```sql
SELECT
    DATE(TO_TIMESTAMP(clock))            AS day,
    COUNT(*)                             AS total,
    SUM(CASE WHEN severity >= 4 THEN 1 ELSE 0 END) AS critical
FROM events
WHERE source = 0 AND object = 0 AND value = 1
  AND clock >= EXTRACT(EPOCH FROM NOW() - INTERVAL '30 days')
GROUP BY DATE(TO_TIMESTAMP(clock))
ORDER BY day ASC
```

---

## 8. View e Frontend

O template `views/turnos.report.view.php` é um arquivo PHP puro que usa o sistema de templates nativo do Zabbix. Recebe o array `$data` populado pelo controller.

### Variáveis PHP disponíveis na view

```php
$data['total_events']    // int — total de alertas no turno
$data['critical_events'] // int — alertas severos (>=4)
$data['no_ack_count']    // int — sem ACK
$data['inherited_count'] // int — herdados de turnos anteriores
$data['mtta_global']     // string — "Xh Ym" ou "N/A"
$data['analysts_online'] // int — analistas presentes
$data['mtta_users']      // array — MTTA por analista
$data['mtta_by_hour']    // array — MTTA por hora (para o gráfico)
$data['sev_counts']      // array — [0..5] contagem por severidade
$data['inherited']       // array — alertas herdados
$data['no_ack']          // array — alertas sem ACK
$data['top_hosts']       // array — top hosts
$data['top_triggers']    // array — top triggers
$data['presence']        // array — presença dos analistas
$data['notes']           // array — notas do Diário de Bordo
$data['current_fullname']// string — nome do usuário logado
```

### Gráficos (Chart.js)

O Chart.js é carregado localmente (sem CDN), em `assets/js/chart.min.js`. Três gráficos são renderizados:

```javascript
// 1. MTTA por hora — linha
new Chart(ctx, {
    type: 'line',
    data: { labels: MTTA_LABELS, datasets: [{ data: MTTA_DATA }] }
});

// 2. Severidade — doughnut (toggle para barras via botão)
new Chart(ctx, {
    type: 'doughnut',
    data: { labels: SEV_LABELS, datasets: [{ data: SEV_DATA }] }
});

// 3. Heatmap — células HTML coloridas por intensidade
// Renderizado como grid de divs com cores calculadas em JS
```

**Cálculo de cor do heatmap:**
```javascript
function heatColor(count, max) {
    if (count === 0) return '#1e2533';          // vazio
    const ratio = Math.min(count / max, 1);
    if (ratio < 0.33) return '#1a4a2a';         // verde (baixo)
    if (ratio < 0.66) return '#7a5c00';         // amarelo (médio)
    return '#7a1a1a';                           // vermelho (alto)
}
```

### Constantes JavaScript

O PHP injeta dados para o JavaScript no início do bloco `<script>`:

```javascript
const MTTA_LABELS     = <?= $chart_mtta_labels ?>;  // ["06h","07h",...]
const MTTA_DATA       = <?= $chart_mtta_data ?>;    // [120, 95, ...]
const SEV_LABELS      = ['N/C','Info','Atenção','Média','Alta','Desastre'];
const SEV_DATA        = <?= $sev_data ?>;           // [0, 2, 5, 3, 1, 0]
const CALENDAR_DATA   = <?= $calendar_json ?>;      // {"2026-05-01":{t:12,c:3},...}
const NOTE_SHIFT      = '<?= $shift ?>';            // 'plantao_dia'
const NOTE_DATE       = '<?= $date ?>';             // '2026-05-09'
const CURRENT_FULLNAME = '<?= addslashes($data['current_fullname']) ?>';
```

---

## 9. Diário de Bordo

### Fluxo completo de salvar uma nota

```
Analista digita nota → clica "Salvar Nota"
    │
    ▼
[JS] event listener 'submit' em #turnosNoteForm
    │  valida: campo não vazio
    ▼
fetch POST zabbix.php?action=turnos.report.notes.save
    body: { note, shift, shift_date }
    │
    ▼
[PHP] TurnosNotesSave::doAction()
    ├─ shift_date === hoje? → se não: erro 400 JSON
    ├─ note vazio? → erro
    ├─ INSERT INTO custom_shift_notes ... RETURNING id
    └─ retorna JSON { success: true, id: X }
    │
    ▼
[JS] ao receber success:
    └─ cria div .rp-note-item e insere no DOM sem recarregar página
```

### Bloqueio para datas passadas

O controle é duplo: UI oculta o formulário e o backend rejeita o POST.

```php
// views/turnos.report.view.php
<?php if ($date === date('Y-m-d')): ?>
    <form id="turnosNoteForm">...</form>
<?php else: ?>
    <div>🔒 Comentários bloqueados — apenas o dia atual permite novas anotações.</div>
<?php endif; ?>

// TurnosNotesSave.php
if ($shift_date !== date('Y-m-d')) {
    echo json_encode(['success' => false,
        'message' => 'Não é possível adicionar notas em datas anteriores.']);
    die();
}
```

---

## 10. Cron Presence Tracker

Script PHP CLI executado a cada 5 minutos pelo cron. Registra quais analistas estavam online durante o turno.

### Configuração (`/etc/cron.d/turnos-presence`)

```
MAILTO=""
*/5 * * * * TZ="America/Sao_Paulo" /usr/bin/php \
  /usr/share/zabbix/modules/TurnosNocReport/scripts/cron_presence_tracker.php \
  >> /var/log/presence_tracker.log 2>&1
```

### Fluxo de execução

```
1. date_default_timezone_set('America/Sao_Paulo')

2. Autenticação:
   ├─ ZABBIX_TOKEN preenchido? → usa token direto
   └─ caso contrário → user.login (tenta 'username' 7.x, fallback 'user' 6.x)

3. user.get → lista todos os usuários (userid, username, name, surname)

4. Para cada usuário:
   ├─ SELECT sessions WHERE userid=? AND status=0 (sessão ativa no Zabbix)
   ├─ Se tem sessão:
   │   ├─ Existe registro nos últimos 5min? → UPDATE lastaccess
   │   └─ Não existe? → INSERT nova janela de presença
   └─ Se não tem sessão: ignora

5. user.logout (apenas se autenticou via user.login, não via token)

6. Log: "X inseridos, Y atualizados"
```

### Detecção de sessão ativa

```php
$sessStmt = $db->prepare(
    "SELECT sessionid, lastaccess FROM sessions
     WHERE userid = ? AND status = 0
     ORDER BY lastaccess DESC LIMIT 1"
);
```

`status = 0` na tabela `sessions` do Zabbix indica sessão ativa. O campo `lastaccess` é unix timestamp.

### Deduplicação (janela de 5 minutos)

```php
$fiveMinAgo = date('Y-m-d H:i:s', strtotime('-5 minutes'));

$checkStmt = $db->prepare(
    "SELECT id FROM custom_user_sessions
     WHERE userid = ? AND lastaccess >= ?
     ORDER BY id DESC LIMIT 1"
);
$checkStmt->execute([$userid, $fiveMinAgo]);
$existing = $checkStmt->fetch();

if ($existing) {
    // Atualiza o lastaccess do registro existente
    $db->prepare("UPDATE custom_user_sessions SET lastaccess = ? WHERE id = ?")
       ->execute([$lastaccess, $existing['id']]);
} else {
    // Abre nova janela de presença
    $db->prepare("INSERT INTO custom_user_sessions (...) VALUES (...)")
       ->execute([$userid, $username, $fullname, $now, $lastaccess]);
}
```

---

## 11. Export PDF

`TurnosReportPdf.php` usa o mesmo controller base que `TurnosReportView`, mas renderiza o output em HTML formatado para impressão/PDF.

A função `shiftLabel()` é definida localmente (não pode usar `rp_shiftLabel` da view):

```php
private function shiftLabel(string $sh): string {
    return [
        'manha'       => 'Manhã (06h–12h)',
        'tarde'       => 'Tarde (12h–18h)',
        'plantao_dia' => 'Plantão Dia (06h–18h)',
        'noite'       => 'Noite (18h–06h)',
        '24h'         => '24 Horas',
    ][$sh] ?? $sh;
}
```

O browser recebe o HTML com `@media print` no CSS e o analista usa Ctrl+P / "Salvar como PDF" do navegador. Não há dependência de biblioteca de geração de PDF.

---

## 12. Fluxo Completo de uma Requisição

Exemplo: analista acessa **Relatórios → Repasse Plantão** para o dia atual, turno Plantão Dia.

```
GET zabbix.php?action=turnos.report.view&date=2026-05-09&shift=plantao_dia
│
├─ Zabbix core roteia para TurnosReportView::checkInput()
│   └─ valida: date=string, shift in [...] ✓
│
├─ TurnosReportView::doAction()
│   ├─ ts_start = strtotime("2026-05-09 06:00:00") = 1746784800
│   ├─ ts_end   = strtotime("2026-05-09 17:59:59") = 1746827999
│   ├─ getDb() → PDO via $GLOBALS['DB']
│   ├─ query: total_events    → 47
│   ├─ query: mtta_users      → [{mauro.p: 4m32s}, {werick: 7m01s}]
│   ├─ query: mtta_by_hour    → {06: 180, 07: 95, 08: 0, ...}
│   ├─ query: sev_counts      → [0, 3, 12, 18, 10, 4]
│   ├─ query: inherited       → [3 alertas herdados]
│   ├─ query: no_ack          → [5 alertas sem ACK]
│   ├─ query: top_hosts       → [host-a: 12, host-b: 8, ...]
│   ├─ query: top_triggers    → [CPU: 9, Memory: 6, ...]
│   ├─ query: presence        → [{mauro.p: 6h12m}, {werick: 4h05m}]
│   ├─ query: notes           → [nota1, nota2]
│   ├─ query: calendar(30d)   → {"2026-05-01":{t:12,c:3}, ...}
│   └─ setResponse(CControllerResponseData($data))
│
└─ Zabbix core renderiza views/turnos.report.view.php com $data
    ├─ PHP gera HTML com KPIs, tabelas e containers dos gráficos
    ├─ PHP injeta dados como constantes JS (MTTA_DATA, SEV_DATA, etc.)
    └─ Chart.js renderiza os gráficos no browser do analista
```

**Tempo total estimado:** 200–800ms dependendo do volume de eventos e da carga do PostgreSQL.

---

*Documentação gerada em 09/05/2026 · Módulo Repasse Plantão v2.0.0*
