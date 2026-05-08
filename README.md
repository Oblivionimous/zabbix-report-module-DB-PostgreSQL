# Zabbix — Módulo Repasse de Plantão

Módulo frontend **100% nativo** do Zabbix 7.0 para relatório automático de repasse de plantão NOC.
Sem dependências externas, sem agentes extras — apenas PHP e o banco de dados PostgreSQL do próprio Zabbix.

> **Fork** de [JohnnyIver/zabbix-report-module](https://github.com/JohnnyIver/zabbix-report-module)
> adaptado para **PostgreSQL**, **Zabbix 7.0** e turnos de NOC **06h–18h**.
> Veja [CHANGELOG.md](CHANGELOG.md) para o detalhamento completo das alterações.

![Zabbix](https://img.shields.io/badge/Zabbix-7.0-red?logo=zabbix)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-blue?logo=php)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-13%2B-336791?logo=postgresql&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green)

---

## Funcionalidades

| # | Funcionalidade | Descrição |
|---|---|---|
| 1 | **KPIs Interativos** | Total de eventos, críticos, MTTA global, sem ACK, herdados e analistas online |
| 2 | **MTTA por Analista** | Tempo médio de reconhecimento por usuário com classificação de performance |
| 3 | **MTTA por Hora** | Gráfico de linha ao longo da janela do plantão |
| 4 | **Distribuição por Severidade** | Toggle entre Doughnut e Barras por nível de criticidade |
| 5 | **Heatmap 30 Dias** | Calendário visual estilo GitHub do volume de alertas |
| 6 | **Alertas Herdados** | Problemas de turnos anteriores ainda abertos |
| 7 | **Alertas Sem ACK** | Eventos sem reconhecimento para passagem de plantão |
| 8 | **Top Hosts / Triggers** | Ranking expansível com link direto ao histórico de problemas |
| 9 | **Diário de Bordo** | Anotações AJAX por turno sem recarregar a página |
| 10 | **Dark Theme** | Herança automática do tema nativo do Zabbix |
| 11 | **Export PDF** | Geração de PDF com os dados do turno |

---

## Pré-requisitos

| Componente | Versão mínima |
|---|---|
| Zabbix | 7.0+ (compatível com 6.4+) |
| PostgreSQL | 13+ |
| PHP | 8.0+ com extensão `pdo_pgsql` |

```bash
# Verificar se a extensão PDO PostgreSQL está habilitada
php -m | grep pdo_pgsql
```

---

## Instalação

### 1. Clonar o repositório

```bash
git clone https://github.com/Oblivionimous/zabbix-report-module.git
```

### 2. Copiar o módulo para o diretório do Zabbix

O módulo deve ficar dentro do diretório `modules` do frontend Zabbix, com o nome `TurnosNocReport`.

```bash
# Localizar o diretório do frontend Zabbix (geralmente um dos abaixo)
ls /usr/share/zabbix/modules/

# Copiar o módulo
cp -r zabbix-report-module /usr/share/zabbix/modules/TurnosNocReport
```

### 3. Aplicar permissões

```bash
chown -R www-data:www-data /usr/share/zabbix/modules/TurnosNocReport
chmod -R 755 /usr/share/zabbix/modules/TurnosNocReport
```

> Se o seu servidor web usa outro usuário (ex: `apache` ou `nginx`), substitua `www-data` pelo usuário correto.

### 4. Obter as configurações do banco de dados

O Zabbix armazena as configurações do banco no arquivo `zabbix_server.conf`:

```bash
grep -E "^DB" /etc/zabbix/zabbix_server.conf
```

Exemplo de saída:
```
DBHost=localhost
DBName=zabbix
DBUser=zabbix
DBPassword=********
DBPort=5432
```

### 5. Criar as tabelas no PostgreSQL

```bash
PGPASSWORD=SUA_SENHA psql -h SEU_HOST -p SUA_PORTA -U SEU_USUARIO -d zabbix \
    -f /usr/share/zabbix/modules/TurnosNocReport/sql/schema.sql
```

Saída esperada:
```
CREATE TABLE
CREATE INDEX
CREATE INDEX
CREATE INDEX
CREATE TABLE
CREATE INDEX
CREATE INDEX
CREATE TABLE
CREATE INDEX
```

### 6. Ativar o módulo no Zabbix

1. Acesse **Administração → Geral → Módulos**
2. Clique em **"Scan directory"**
3. Localize **"Relatório Repasse de Plantão"** e clique em **Ativar**
4. O módulo aparecerá em **Relatórios → Repasse Plantão**

---

## Presença de Analistas (opcional)

O rastreamento de presença usa um script cron que consulta a API do Zabbix a cada 5 minutos e registra quais analistas estavam online durante cada turno.

### 1. Criar usuário dedicado no Zabbix

Crie um usuário exclusivo para o tracker para não usar o Admin principal:

- Acesse **Administração → Usuários → Criar usuário**
- **Username:** `noc-tracker` (ou nome de sua preferência)
- **Role:** `Super admin role` *(necessário para listar todos os usuários via API)*
- Defina uma senha forte

### 2. Configurar o script

```bash
nano /usr/share/zabbix/modules/TurnosNocReport/scripts/cron_presence_tracker.php
```

Ajuste as constantes no início do arquivo:

```php
define('ZABBIX_API_URL', 'http://SEU_ZABBIX/api_jsonrpc.php');
define('ZABBIX_USER',    'noc-tracker');
define('ZABBIX_PASS',    'SENHA_DO_USUARIO_API');
define('DB_HOST',        'SEU_HOST_DB');
define('DB_PORT',        5432);              // ajuste se usar porta diferente
define('DB_NAME',        'zabbix');
define('DB_USER',        'SEU_USUARIO_DB');
define('DB_PASS',        'SENHA_DO_BANCO');
```

### 3. Testar manualmente antes de agendar

```bash
php /usr/share/zabbix/modules/TurnosNocReport/scripts/cron_presence_tracker.php
```

Saída esperada:
```
[YYYY-MM-DD HH:MM:SS] === Presence Tracker Start ===
[YYYY-MM-DD HH:MM:SS] Login OK (token: xxxxxxxx...)
[YYYY-MM-DD HH:MM:SS] Total de usuários encontrados: X
[YYYY-MM-DD HH:MM:SS] Resultado: X inseridos, X atualizados.
[YYYY-MM-DD HH:MM:SS] === Presence Tracker End ===
```

### 4. Adicionar ao cron

```bash
nano /etc/cron.d/turnos-presence
```

Conteúdo do arquivo:
```
*/5 * * * * www-data php /usr/share/zabbix/modules/TurnosNocReport/scripts/cron_presence_tracker.php >> /var/log/zabbix_presence.log 2>&1
```

---

## Turnos Suportados

| Turno | Janela Horária | Descrição |
|---|---|---|
| **24 Horas** | 00:00 — 23:59 | Visão completa do dia |
| **Plantão Dia** | 06:00 — 17:59 | Turno diurno completo do NOC |
| **Manhã** | 06:00 — 11:59 | Primeira metade do plantão diurno |
| **Tarde** | 12:00 — 17:59 | Segunda metade do plantão diurno |
| **Noite** | 18:00 — 05:59 (+1d) | Turno noturno |

---

## Estrutura do Módulo

```
TurnosNocReport/
├── manifest.json               # Registro do módulo no Zabbix
├── Module.php                  # Injeção no menu Reports
├── actions/
│   ├── TurnosReportView.php    # Controller principal
│   ├── TurnosReportPdf.php     # Export PDF
│   ├── TurnosNotesSave.php     # AJAX: salvar notas
│   └── TurnosNotesGet.php      # AJAX: consultar notas
├── views/
│   └── turnos.report.view.php  # Template nativo Zabbix
├── assets/
│   ├── css/turnos.report.css
│   └── js/
│       ├── chart.min.js        # Chart.js (local, sem CDN)
│       └── class.turnos.report.js
├── sql/
│   ├── schema.sql              # Criação das tabelas (PostgreSQL)
│   └── queries.sql             # Referência das queries
└── scripts/
    ├── install.sh              # Instalador interativo
    └── cron_presence_tracker.php
```

---

## Licença

MIT — use, modifique e distribua livremente.
