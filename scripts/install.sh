#!/usr/bin/env bash
set -euo pipefail
# VMForge installer — an ENGINYRING project (with TLS)
# New flags: --letsencrypt-email <email> to auto-provision HTTPS
log() { echo -e "[VMForge] $*"; }
need() { command -v "$1" >/dev/null 2>&1 || (log "Installing $1" && apt-get update && apt-get install -y "$1"); }
parse_args() {
  MODE=
  DOMAIN=vmforge.local
  DB_ROOT=
  DB_PASS=vmforge
  ADMIN_EMAIL=admin@local
  ADMIN_PASS=adminadmin
  CONTROLLER_URL=
  NODE_TOKEN=
  BRIDGE=br0
  LE_EMAIL=
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --master) MODE=master ;;
      --slave) MODE=slave ;;
      --domain) DOMAIN="$2"; shift ;;
      --db-root-pass) DB_ROOT="$2"; shift ;;
      --db-pass) DB_PASS="$2"; shift ;;
      --admin-email) ADMIN_EMAIL="$2"; shift ;;
      --admin-pass) ADMIN_PASS="$2"; shift ;;
      --controller-url) CONTROLLER_URL="$2"; shift ;;
      --node-token) NODE_TOKEN="$2"; shift ;;
      --bridge) BRIDGE="$2"; shift ;;
      --letsencrypt-email) LE_EMAIL="$2"; shift ;;
      *) log "Unknown arg $1"; exit 1 ;;
    esac
    shift
  done
  [[ -z "${MODE}" ]] && { log "Specify --master or --slave"; exit 1; }
}
install_master() {
  log "Installing master (controller)"
  need curl; need php; need php-cli; need php-mbstring; need php-curl; need php-mysql; need php-xml; need mariadb-server; need nginx; need unzip
  systemctl enable --now mariadb
  mysql -uroot -p"${DB_ROOT}" -e "CREATE DATABASE IF NOT EXISTS vmforge CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'vmforge'@'localhost' IDENTIFIED BY '${DB_PASS}'; GRANT ALL PRIVILEGES ON vmforge.* TO 'vmforge'@'localhost'; FLUSH PRIVILEGES;" || {
    log "Attempting without root password"
    mysql -e "CREATE DATABASE IF NOT EXISTS vmforge CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'vmforge'@'localhost' IDENTIFIED BY '${DB_PASS}'; GRANT ALL PRIVILEGES ON vmforge.* TO 'vmforge'@'localhost'; FLUSH PRIVILEGES;"
  }
  mysql vmforge < migrations/0001_initial.sql || true
  for f in migrations/0002_ipam_tokens.sql migrations/0003_console.sql migrations/0004_ipv6.sql migrations/0005_backups.sql migrations/0006_console_hardening.sql migrations/0007_nodes_last_seen.sql migrations/0008_security.sql migrations/0009_projects_rbac.sql; do
    [[ -f "$f" ]] && mysql vmforge < "$f" || true
  done
  need php-fpm
  cat >/etc/nginx/sites-available/vmforge.conf <<EOF
server {
  listen 80;
  server_name ${DOMAIN};
  root /var/www/vmforge/public;
  index index.php index.html;
  location /assets/ { try_files \$uri \$uri/ =404; }
  location / {
    try_files \$uri /index.php?\$query_string;
  }
  location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/var/run/php/php-fpm.sock;
  }
}
EOF
  ln -sf /etc/nginx/sites-available/vmforge.conf /etc/nginx/sites-enabled/vmforge.conf
  rm -f /etc/nginx/sites-enabled/default || true
  systemctl reload nginx || systemctl restart nginx
  mkdir -p /var/www/vmforge
  rsync -a . /var/www/vmforge/
  chown -R www-data:www-data /var/www/vmforge
  mysql vmforge -e "INSERT IGNORE INTO users(email, password_hash, is_admin) VALUES ('${ADMIN_EMAIL}', SHA2('${ADMIN_PASS}', 256), 1)"
  install -m 0755 scripts/scheduler.php /usr/local/bin/vmforge-scheduler || true
  systemctl daemon-reload || true
  if [[ -n "${LE_EMAIL}" ]]; then
    need certbot; need python3-certbot-nginx
    certbot --nginx -d "${DOMAIN}" -m "${LE_EMAIL}" --agree-tos -n --redirect || true
  fi
  log "Master install complete at http(s)://${DOMAIN}/"
}
install_slave() {
  log "Installing slave (agent node)"
  need qemu-kvm; need libvirt-daemon-system; need libvirt-clients; need cloud-image-utils; need lxc; need lxc-templates; need bridge-utils; need novnc; need websockify; need nftables
  systemctl enable --now libvirtd || systemctl enable --now libvirt-daemon || true
  mkdir -p /opt/vmforge
  install -m 0755 agent/agent.php /opt/vmforge/agent.php
  cat >/etc/systemd/system/vmforge-agent.service <<EOF
[Unit]
Description=VMForge Agent
After=network-online.target
[Service]
Type=simple
Environment=AGENT_CONTROLLER_URL=${CONTROLLER_URL}
Environment=AGENT_NODE_TOKEN=${NODE_TOKEN}
Environment=AGENT_BRIDGE=${BRIDGE}
ExecStart=/usr/bin/php /opt/vmforge/agent.php
Restart=always
RestartSec=2
[Install]
WantedBy=multi-user.target
EOF
  systemctl daemon-reload
  systemctl enable --now vmforge-agent
  log "Slave install complete. Agent started."
}
parse_args "$@"
if [[ "${MODE}" == "master" ]]; then install_master; elif [[ "${MODE}" == "slave" ]]; then install_slave; else log "Unknown mode ${MODE}"; exit 1; fi
