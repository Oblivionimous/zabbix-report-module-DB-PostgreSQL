# Módulo Zabbix — Relatório de Repasse de Plantão

Módulo frontend **100% nativo** do Zabbix 6.x/7.x para relatório automático de repasse de plantão NOC.

## Funcionalidades

| Funcionalidade | Descrição |
|---|---|
| **KPIs Interativos** | Total de eventos, críticos, MTTA, plantão herdado, analistas, com descrições detalhadas (`hover`) e drill-down |
| **MTTA por Analista** | Tempo médio de reconhecimento por usuário com classificação de performance em Horas/Minutos |
| **MTTA por Hora** | Gráfico de barras proporcional (escala 60m) do cálculo do MTTA global |
| **Distribuição Severidade** | Toggle rápido entre gráfico de Doughnut e Barras por nível de criticidade |
| **Heatmap 30 Dias** | Calendário visual elegante estilo GitHub do volume de alertas/plantão |
| **Alertas Herdados** | Problemas passados persistentes no novo turno (Navegáveis) |
| **Alertas Sem ACK** | Tabela rápida de navegação de alertas órfãos |
| **Top Hosts/Triggers** | Listagem expansível (Top 5, 10, Todos) com navegação limpa pro Histórico de Problemas |
| **Zabbix Dark Theme** | Herança CSS nativa instantânea entre sub-temas nativos do Zabbix (Blue/Black) |
| **Diário de Bordo** | Operações limpas AJAX de log para turno atuante sem screen flashing |
| **Export/Reload** | Geração PDF compatível ou soft-reload no Widget Central de Hora |

## Pré-requisitos

- Zabbix **6.4+** ou **7.0+** (frontend PHP)
- MariaDB ou MySQL (banco de dados do Zabbix)
- PHP 8.x com extensão `mysqli`
- Acesso ao servidor onde o Zabbix frontend está instalado

## Instalação

### Opção 1: Servidor Linux Nativo

```bash
# 1. Copiar o módulo para o diretório de módulos do Zabbix
sudo cp -r TurnosNocReport /usr/share/zabbix/modules/

# 2. Ajustar permissões
sudo chown -R www-data:www-data /usr/share/zabbix/modules/TurnosNocReport
# (ou apache:apache dependendo da distro)

# 3. Criar as tabelas customizadas no banco de dados
mysql -u zabbix -p zabbix < /usr/share/zabbix/modules/TurnosNocReport/sql/schema.sql

# 4. No Zabbix web: Administration → General → Modules
#    Clique em "Scan directory" e habilite "Relatório Repasse de Plantão"

# 5. (Opcional) Configurar cron para rastreamento de presença
# Edite as credenciais no script primeiro:
sudo nano /usr/share/zabbix/modules/TurnosNocReport/scripts/cron_presence_tracker.php

# Adicione ao crontab:
echo "*/5 * * * * www-data php /usr/share/zabbix/modules/TurnosNocReport/scripts/cron_presence_tracker.php" | sudo tee /etc/cron.d/turnos-presence
```

### Opção 2: Docker / Podman

```bash
# 1. Copiar módulo para dentro do container do frontend
docker cp TurnosNocReport NOME-CONTAINER-WEB:/usr/share/zabbix/modules/
# ou com Podman:
podman cp TurnosNocReport NOME-CONTAINER-WEB:/usr/share/zabbix/modules/

# 2. Criar tabelas (substitua NOME-CONTAINER-DB pelo nome do container do banco)
docker exec -i NOME-CONTAINER-DB mysql -u zabbix -pzabbix zabbix < TurnosNocReport/sql/schema.sql
# ou com Podman:
podman exec NOME-CONTAINER-DB mysql -u zabbix -pzabbix zabbix -e "$(cat TurnosNocReport/sql/schema.sql)"

# 3. No Zabbix web: Administration → General → Modules
#    Clique em "Scan directory" e habilite "Relatório Repasse de Plantão"

# 4. (Opcional) Presença — rode via cron do host:
# O script precisa acessar a API do Zabbix. Edite as credenciais:
# ZABBIX_URL, ZABBIX_USER, ZABBIX_PASS dentro do script
*/5 * * * * docker exec NOME-CONTAINER-WEB php /usr/share/zabbix/modules/TurnosNocReport/scripts/cron_presence_tracker.php
```

### Script de Instalação Automatizada

Use o script `install.sh` incluído para instalação automática:

```bash
chmod +x TurnosNocReport/scripts/install.sh
sudo ./TurnosNocReport/scripts/install.sh
```

## Estrutura de Arquivos

```
TurnosNocReport/
├── manifest.json                      # Definição do módulo Zabbix
├── Module.php                         # Injeção no menu Reports
├── README.md                          # Esta documentação
├── actions/
│   ├── TurnosReportView.php          # Controller principal (CControllerResponseData)
│   ├── TurnosReportPdf.php           # Export PDF (standalone)
│   ├── TurnosNotesSave.php           # AJAX salvar notas
│   └── TurnosNotesGet.php            # AJAX consultar notas
├── views/
│   └── turnos.report.view.php        # Template nativo Zabbix
├── assets/
│   ├── css/turnos.report.css         # Estilos do relatório
│   └── js/
│       ├── chart.min.js              # Chart.js local (evita bloqueio CSP)
│       └── class.turnos.report.js    # Placeholder JS
├── sql/
│   ├── schema.sql                    # Tabelas custom_shift_notes + custom_user_sessions
│   └── queries.sql                   # Referência de queries SQL
└── scripts/
    ├── cron_presence_tracker.php      # Rastreamento de presença via API
    ├── populate_demo_data.php         # Script PHP para dados demo
    └── populate_demo_data.ps1        # Script PowerShell para dados demo
```

## Turnos (Configuração Padrão)

| Turno | Período |
|---|---|
| 24 Horas | 00:00 — 23:59 |
| Manhã | 07:00 — 12:59 |
| Tarde | 13:00 — 18:59 |
| Noite | 19:00 — 06:59 (+1d) |

## Como o Relatório Funciona

O relatório é **automático** — ele consulta os dados diretamente das tabelas nativas do Zabbix (`events`, `acknowledges`, `triggers`, `hosts`) sem necessidade de geração manual. Ao acessar **Reports → Repasse Plantão**, os dados do dia/turno selecionado são carregados em tempo real.

### Fluxo de Dados

1. **Eventos** → Consulta `events` filtrando por `source=0, object=0, value=1` no período
2. **MTTA** → Cruza `acknowledges` com `events` para calcular tempo de resposta
3. **Herdados** → Eventos `PROBLEM` anteriores ao turno que não têm `event_recovery`
4. **Sem ACK** → Eventos sem registros na tabela `acknowledges`
5. **Top Hosts/Triggers** → Agregação por host/trigger com contagem de eventos
6. **Presença** → Tabela customizada `custom_user_sessions` (requer cron)
7. **Notas** → Tabela customizada `custom_shift_notes` (inserido pelo analista)

### Resolução de Macros

O módulo resolve automaticamente a macro `{HOST.NAME}` nas descrições das triggers, substituindo pelo nome real do host via `REPLACE()` no SQL.

## Tabelas Customizadas

```sql
-- Notas do diário de bordo
CREATE TABLE IF NOT EXISTS custom_shift_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shift_date DATE NOT NULL,
    shift_name VARCHAR(20) NOT NULL,
    analyst_userid BIGINT UNSIGNED NOT NULL,
    analyst_name VARCHAR(128) NOT NULL,
    notes TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_shift (shift_date, shift_name)
);

-- Sessões de presença (populado via cron)
CREATE TABLE IF NOT EXISTS custom_user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    userid BIGINT UNSIGNED NOT NULL,
    username VARCHAR(100) NOT NULL,
    name VARCHAR(128) DEFAULT NULL,
    session_start DATETIME NOT NULL,
    lastaccess DATETIME NOT NULL,
    INDEX idx_user_time (userid, lastaccess)
);
```

## Dados de Demonstração

Para popular o relatório com dados de demonstração, use um dos scripts:

```bash
# PHP (dentro do container ou servidor com PHP CLI):
php TurnosNocReport/scripts/populate_demo_data.php

# PowerShell (Windows, com Podman/Docker rodando):
powershell -ExecutionPolicy Bypass -File TurnosNocReport/scripts/populate_demo_data.ps1
```

## Requisitos Técnicos

- Zabbix 6.4+ ou 7.0+ com frontend PHP
- MariaDB 10.x+ ou MySQL 5.7+
- PHP 8.0+ com extensão mysqli
- Navegador moderno (Chrome, Firefox, Edge)
- \>= 50MB RAM adicional no frontend para queries

## Licença

Uso interno NOC. Baseado no Zabbix module framework.
