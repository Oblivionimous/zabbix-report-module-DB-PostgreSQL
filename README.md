# Zabbix — Módulo Repasse de Plantão

Módulo frontend **100% nativo** do Zabbix 7.0 para relatório automático de repasse de plantão NOC.
Sem dependências externas, sem agentes extras — apenas PHP e o banco de dados PostgreSQL do próprio Zabbix.

> **Fork** do projeto original [JohnnyIver/zabbix-report-module](https://github.com/JohnnyIver/zabbix-report-module),
> adaptado e evoluído por [Mauro Paiva](https://www.linkedin.com/in/mauro-paiva-98b881106/) com foco em:
>
> - **PostgreSQL** como banco de dados (em substituição ao MySQL original)
> - **Autenticação via API Token** do Zabbix (sem necessidade de usuário/senha no script)
> - Turnos de NOC **06h–18h** e melhorias operacionais para ambientes de plantão 24x7
>
> Veja [CHANGELOG.md](CHANGELOG.md) para o detalhamento completo das alterações.

![Zabbix](https://img.shields.io/badge/Zabbix-7.0-red?logo=zabbix)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-blue?logo=php)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-13%2B-336791?logo=postgresql&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green)

> Guia funcional (o que cada seção faz e para que serve): [GUIA_FUNCIONAL.md](GUIA_FUNCIONAL.md)
> Documentação técnica detalhada (arquitetura, código, queries SQL, fluxos): [DOCUMENTATION.md](DOCUMENTATION.md)

---

## Visão do Projeto

> *por [Richardson Miranda](https://www.linkedin.com/in/richardson-miranda-198588141/)*

Este projeto nasceu como um estudo de **arquitetura de sistemas aplicada a ambientes de operação NOC**, tomando como referência o módulo open source de repasse de plantão para Zabbix ([JohnnyIver/zabbix-report-module](https://github.com/JohnnyIver/zabbix-report-module)).

O problema central foi a fragilidade do processo de transição entre turnos em ambientes 24x7: perda de contexto operacional, fragmentação de informações e baixa rastreabilidade de eventos entre operadores. A proposta foi estruturar uma solução de consolidação de informações operacionais com foco em **governança, visibilidade e redução de ruído**.

### Princípios de arquitetura

O principal princípio adotado foi **evitar acoplamento direto ao sistema de monitoramento**, garantindo estabilidade, segurança e facilidade de manutenção. A solução foi desenhada como uma camada desacoplada — sem nenhuma alteração no core do Zabbix — sobre a infraestrutura já existente:

```
Zabbix Server  →  coleta de eventos
Backend dedicado  →  processamento e regras de negócio do repasse
PostgreSQL/MySQL  →  persistência (reaproveitamento da infra existente)
Frontend nativo  →  consumo e visualização operacional
```

Outros princípios aplicados:

- **Integração somente leitura** via API do Zabbix
- **Menor privilégio** no acesso ao banco de dados
- **Backend isolado** como camada central de processamento
- **Controle de acesso** restrito ao grupo operacional do NOC

### Aprendizado principal

> Em ambientes de operação, evoluir um sistema não é apenas adicionar funcionalidades —
> é garantir que cada nova camada preserve **estabilidade, segurança e previsibilidade operacional**.

Todo o ambiente foi implementado em laboratório local, com foco exclusivo em estudo de arquitetura e integração de sistemas.

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

### 1. Autenticação — API Token (recomendado) ou usuário/senha

**Opção A — API Token (recomendado)**

1. Acesse **Administração → API tokens → Criar token de API**
2. **Nome:** `noc-tracker` (ou nome de sua preferência)
3. **Usuário:** escolha um usuário com role `Super admin`
4. Copie o token gerado — ele só é exibido uma vez

**Opção B — Usuário/Senha (fallback)**

1. Acesse **Administração → Usuários → Criar usuário**
2. **Username:** `noc-tracker`
3. **Role:** `Super admin role` *(necessário para listar todos os usuários via API)*
4. Defina uma senha forte

### 2. Verificar a porta do PostgreSQL

O Zabbix pode usar uma porta PostgreSQL diferente de `5432`. Confirme antes de configurar:

```bash
grep -E "^DBPort" /etc/zabbix/zabbix_server.conf
# Exemplo: DBPort=5433
```

### 3. Configurar o script

```bash
nano /usr/share/zabbix/modules/TurnosNocReport/scripts/cron_presence_tracker.php
```

Ajuste as constantes no início do arquivo:

```php
define('ZABBIX_API_URL', 'https://localhost/api_jsonrpc.php');
// Preencha ZABBIX_TOKEN (recomendado) OU ZABBIX_USER + ZABBIX_PASS
define('ZABBIX_TOKEN',   'SEU_API_TOKEN');   // deixe '' para usar usuário/senha
define('ZABBIX_USER',    '');                // necessário apenas sem token
define('ZABBIX_PASS',    '');                // necessário apenas sem token
define('DB_HOST',        'localhost');        // use 'localhost' (TCP) — não deixe vazio
define('DB_PORT',        5432);              // ajuste conforme DBPort no zabbix_server.conf
define('DB_NAME',        'zabbix');
define('DB_USER',        'zabbix');
define('DB_PASS',        'SENHA_DO_BANCO');
```

> **Nota SSL:** se o Zabbix usar HTTPS com certificado que não cobre `localhost`, o script já
> inclui `CURLOPT_SSL_VERIFYHOST => false` para evitar erros de SAN no PHP CLI.

### 4. Testar manualmente antes de agendar

```bash
php /usr/share/zabbix/modules/TurnosNocReport/scripts/cron_presence_tracker.php
```

Saída esperada (com API token):
```
[YYYY-MM-DD HH:MM:SS] === Presence Tracker Start ===
[YYYY-MM-DD HH:MM:SS] Usando API token.
[YYYY-MM-DD HH:MM:SS] Total de usuários encontrados: X
[YYYY-MM-DD HH:MM:SS] Resultado: X inseridos, X atualizados.
[YYYY-MM-DD HH:MM:SS] === Presence Tracker End ===
```

### 5. Adicionar ao cron

Crie o arquivo `/etc/cron.d/turnos-presence`:

```bash
nano /etc/cron.d/turnos-presence
```

Conteúdo:
```
MAILTO=""
*/5 * * * * TZ="America/Sao_Paulo" /usr/bin/php /usr/share/zabbix/modules/TurnosNocReport/scripts/cron_presence_tracker.php >> /var/log/presence_tracker.log 2>&1
```

> **TZ no cron:** o PHP CLI pode herdar UTC do sistema mesmo que o servidor esteja configurado
> com outro timezone. Definir `TZ` diretamente na linha do cron garante horários corretos no log
> e nos registros do banco.

### Troubleshooting

| Sintoma | Causa provável | Solução |
|---|---|---|
| `API Error: HTTP 0` | URL interna não resolvível | Use `https://localhost/api_jsonrpc.php` |
| `SSL: no alternative certificate subject name matches` | Cert sem SAN para `localhost` | `CURLOPT_SSL_VERIFYHOST => false` (já incluído) |
| `Incorrect user name or password` | Senha diferente em homologação | Migre para API Token |
| `Connection refused` porta `5432` | PostgreSQL em porta diferente | Ajuste `DB_PORT` conforme `DBPort` no `zabbix_server.conf` |
| `peer authentication failed` | Conexão via socket UNIX | Defina `DB_HOST=localhost` para forçar TCP |
| Horários errados / tempo online negativo | PHP CLI em UTC | Use `TZ=` na linha do cron ou `date_default_timezone_set` (já incluído) |

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
