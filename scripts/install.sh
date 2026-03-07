#!/bin/bash
# ============================================================
#  Install Script — Módulo Repasse de Plantão
#  Compatível com Zabbix 6.4+ e 7.0+ em Linux nativo
# ============================================================

set -e

# Cores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${GREEN}=== Instalação do Módulo Repasse de Plantão ===${NC}"

# Detectar diretório do Zabbix frontend
ZABBIX_DIR="/usr/share/zabbix"
if [ ! -d "$ZABBIX_DIR" ]; then
    echo -e "${YELLOW}Diretório padrão $ZABBIX_DIR não encontrado.${NC}"
    read -p "Informe o caminho do Zabbix frontend: " ZABBIX_DIR
fi

if [ ! -f "$ZABBIX_DIR/index.php" ]; then
    echo -e "${RED}ERRO: $ZABBIX_DIR não parece ser um Zabbix frontend válido.${NC}"
    exit 1
fi

MODULE_DIR="$ZABBIX_DIR/modules/TurnosNocReport"
SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

echo -e "\n${YELLOW}[1/4] Copiando módulo...${NC}"
if [ -d "$MODULE_DIR" ]; then
    echo "  Módulo já existe. Atualizando..."
    rm -rf "$MODULE_DIR"
fi
cp -r "$SCRIPT_DIR" "$MODULE_DIR"
echo -e "  ${GREEN}✓ Módulo copiado para $MODULE_DIR${NC}"

# Detectar usuário do web server
WEB_USER="www-data"
if id "apache" &>/dev/null; then WEB_USER="apache"; fi
if id "nginx" &>/dev/null; then WEB_USER="nginx"; fi

chown -R "$WEB_USER:$WEB_USER" "$MODULE_DIR"
echo -e "  ${GREEN}✓ Permissões ajustadas ($WEB_USER)${NC}"

echo -e "\n${YELLOW}[2/4] Criando tabelas no banco de dados...${NC}"
read -p "  Host do MariaDB/MySQL [localhost]: " DB_HOST
DB_HOST=${DB_HOST:-localhost}
read -p "  Usuário do banco [zabbix]: " DB_USER
DB_USER=${DB_USER:-zabbix}
read -sp "  Senha do banco: " DB_PASS
echo
read -p "  Nome do banco [zabbix]: " DB_NAME
DB_NAME=${DB_NAME:-zabbix}

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$MODULE_DIR/sql/schema.sql"
echo -e "  ${GREEN}✓ Tabelas criadas${NC}"

echo -e "\n${YELLOW}[3/4] Configurando cron de presença...${NC}"
read -p "  Deseja configurar o cron de presença? [s/N]: " SETUP_CRON
if [[ "$SETUP_CRON" =~ ^[sS]$ ]]; then
    CRON_LINE="*/5 * * * * $WEB_USER php $MODULE_DIR/scripts/cron_presence_tracker.php"
    echo "$CRON_LINE" > /etc/cron.d/turnos-presence
    chmod 644 /etc/cron.d/turnos-presence
    echo -e "  ${GREEN}✓ Cron configurado (/etc/cron.d/turnos-presence)${NC}"
    echo -e "  ${YELLOW}⚠ Edite as credenciais da API em: $MODULE_DIR/scripts/cron_presence_tracker.php${NC}"
else
    echo "  Pulando configuração do cron."
fi

echo -e "\n${YELLOW}[4/4] Finalizando...${NC}"
echo -e "  ${GREEN}✓ Instalação concluída!${NC}"
echo ""
echo -e "${GREEN}Próximos passos:${NC}"
echo "  1. No Zabbix: Administration → General → Modules"
echo "  2. Clique em 'Scan directory'"
echo "  3. Habilite 'Relatório Repasse de Plantão'"
echo "  4. Acesse: Reports → Repasse Plantão"
echo ""
echo -e "${YELLOW}Se usar presença, edite as credenciais em:${NC}"
echo "  $MODULE_DIR/scripts/cron_presence_tracker.php"
