# Zabbix — Módulo Repasse de Plantão

Módulo frontend **100% nativo** do Zabbix 6.4+ / 7.0 para relatório automático de repasse de plantão NOC.
Sem dependências externas, sem agentes extras — apenas PHP e o banco de dados do próprio Zabbix.

![Zabbix](https://img.shields.io/badge/Zabbix-6.4%2B%20%7C%207.0-red?logo=zabbix)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-blue?logo=php)
![MariaDB](https://img.shields.io/badge/MariaDB-10.6%2B-brown?logo=mariadb)
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

## Instalação

### Pré-requisitos

- Zabbix **6.4+** ou **7.0+**
- MariaDB **10.6+** / MySQL **8.0+**
- PHP **8.0+** com extensão `mysqli`

### 1. Clonar o repositório

```bash
git clone https://github.com/SEU-USUARIO/zabbix-report-module.git
cd zabbix-report-module
```

### 2. Copiar o módulo para o Zabbix

<details>
<summary><b>Docker / Docker Compose</b></summary>

```bash
# Copiar módulo para o container do frontend
docker cp . SEU_CONTAINER_WEB:/usr/share/zabbix/modules/TurnosNocReport/
docker exec --user root SEU_CONTAINER_WEB \
    chown -R www-data:www-data /usr/share/zabbix/modules/TurnosNocReport
```

Ou via `docker-compose.yml`, no serviço do web:

```yaml
volumes:
  - ./:/usr/share/zabbix/modules/TurnosNocReport:ro
```

</details>

<details>
<summary><b>All-in-One (Zabbix + DB + Web na mesma VM)</b></summary>

```bash
sudo cp -r . /usr/share/zabbix/modules/TurnosNocReport
sudo chown -R www-data:www-data /usr/share/zabbix/modules/TurnosNocReport
```

</details>

<details>
<summary><b>Segmentado (Web e banco em servidores distintos)</b></summary>

No servidor do **Zabbix Web**:

```bash
sudo cp -r . /usr/share/zabbix/modules/TurnosNocReport
sudo chown -R www-data:www-data /usr/share/zabbix/modules/TurnosNocReport
```

</details>

### 3. Criar as tabelas no banco de dados

```bash
# Nativo
mysql -u zabbix -p zabbix < sql/schema.sql

# Docker
docker exec -i SEU_CONTAINER_DB \
    mysql -uzabbix -pSUA_SENHA zabbix < sql/schema.sql
```

### 4. Ativar no Zabbix

1. Acesse **Administration → General → Modules**
2. Clique em **"Scan directory"**
3. Habilite **"Relatório Repasse de Plantão"**
4. Acesse **Reports → Repasse Plantão**

---

## Presença de Analistas (opcional)

O rastreamento de presença usa um script cron que consulta a API do Zabbix a cada 5 minutos.

1. Edite as credenciais em `scripts/cron_presence_tracker.php`:
   ```php
   define('ZABBIX_URL',  'http://localhost/zabbix');
   define('ZABBIX_USER', 'Admin');
   define('ZABBIX_PASS', 'zabbix');
   ```

2. Adicione ao cron:
   ```bash
   # /etc/cron.d/turnos-presence
   */5 * * * * www-data php /usr/share/zabbix/modules/TurnosNocReport/scripts/cron_presence_tracker.php
   ```

---

## Estrutura do Módulo

```
TurnosNocReport/
├── manifest.json           # Registro do módulo no Zabbix
├── Module.php              # Injeção no menu Reports
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
│   ├── schema.sql          # Criação das tabelas customizadas
│   └── queries.sql         # Referência das queries
└── scripts/
    ├── install.sh              # Instalador interativo
    └── cron_presence_tracker.php
```

---

## Turnos Suportados

| Turno | Janela Horária |
|---|---|
| 24 Horas | 00:00 — 23:59 |
| Manhã | 07:00 — 12:59 |
| Tarde | 13:00 — 18:59 |
| Noite | 19:00 — 06:59 (+1d) |

---

## Licença

MIT — use, modifique e distribua livremente.

