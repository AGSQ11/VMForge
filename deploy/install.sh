#!/usr/bin/env bash
set -euo pipefail

# --- Global variables ---
OS_FAMILY=""
PKG_MANAGER=""
PHP_FPM_SERVICE=""
PHP_FPM_SOCK_PATH=""
WEBSERVER_CONF_DIR=""
WEBSERVER_USER=""
WEBSERVER_SERVICE=""
SSL_EMAIL=""
DEBUG_MODE=0

# ... (Package lists are the same) ...
DEBIAN_NGINX_PKGS=(nginx mariadb-server mariadb-client php8.2-cli php8.2-fpm php8.2-curl php8.2-xml php8.2-mbstring php8.2-zip php8.2-mysql php8.2-gd php8.2-intl redis-server git unzip curl ufw)
DEBIAN_APACHE_PKGS=(apache2 mariadb-server mariadb-client php8.2-cli php8.2-fpm php8.2-curl php8.2-xml php8.2-mbstring php8.2-zip php8.2-mysql php8.2-gd php8.2-intl redis-server git unzip curl libapache2-mod-php8.2 ufw)
DEBIAN_VIRT_PKGS=(qemu-kvm libvirt-daemon-system libvirt-clients cloud-image-utils lxc lxc-templates bridge-utils novnc websockify nftables)
DEBIAN_SLAVE_PKGS=(php8.2-cli php8.2-curl php8.2-xml php8.2-mbstring php8.2-zip php8.2-mysql redis-server git curl "${DEBIAN_VIRT_PKGS[@]}")
DEBIAN_CERTBOT_PKGS_nginx=(certbot python3-certbot-nginx)
DEBIAN_CERTBOT_PKGS_apache=(certbot python3-certbot-apache)

RHEL_NGINX_PKGS=(nginx mariadb-server mariadb php-cli php-fpm php-curl php-xml php-mbstring php-zip php-mysqlnd php-gd php-intl redis git unzip curl firewalld)
RHEL_APACHE_PKGS=(httpd mariadb-server mariadb php-cli php-fpm php-curl php-xml php-mbstring php-zip php-mysqlnd php-gd php-intl redis git unzip curl firewalld)
RHEL_VIRT_PKGS=(qemu-kvm libvirt-daemon libvirt-client lxc lxc-templates bridge-utils novnc websockify nftables)
RHEL_SLAVE_PKGS=(php-cli php-curl php-xml php-mbstring php-zip php-mysqlnd redis git curl "${RHEL_VIRT_PKGS[@]}")
RHEL_CERTBOT_PKGS=(snapd)


usage() {
  cat <<'USAGE'
VMForge unattended installer
...
Flags:
  --debug                 Enable debug mode (verbose output).
  --webserver <name>      (master) nginx or apache (default: nginx)
  --ssl <email>           (master) Enable SSL with Let's Encrypt and use this email.
  ...
USAGE
}

# ... (confirm, need_root, log functions are the same) ...

harden_mariadb() {
    log "Hardening MariaDB installation..."
    # This is the non-interactive equivalent of mysql_secure_installation
    # Note: Using root password on the command line is insecure. This script assumes it's run in a secure env.
    # A better approach for production would be to use a config file.
    mysql -u root -e "UPDATE mysql.user SET Password = PASSWORD('$DB_PASS') WHERE User = 'root';"
    mysql -u root -p"$DB_PASS" -e "DELETE FROM mysql.user WHERE User='';"
    mysql -u root -p"$DB_PASS" -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');"
    mysql -u root -p"$DB_PASS" -e "DROP DATABASE IF EXISTS test;"
    mysql -u root -p"$DB_PASS" -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';"
    mysql -u root -p"$DB_PASS" -e "FLUSH PRIVILEGES;"
    log "MariaDB hardening complete."
}

setup_firewall() {
    log "Setting up firewall..."
    if [[ "$OS_FAMILY" == "debian" ]]; then
        ufw allow ssh
        ufw allow "$HTTP_PORT"/tcp
        if [[ -n "$SSL_EMAIL" ]]; then
            ufw allow https
        fi
        ufw --force enable
        log "UFW firewall configured and enabled."
    elif [[ "$OS_FAMILY" == "rhel" ]]; then
        systemctl enable --now firewalld
        firewall-cmd --permanent --add-service=ssh
        firewall-cmd --permanent --add-port="$HTTP_PORT"/tcp
        if [[ -n "$SSL_EMAIL" ]]; then
            firewall-cmd --permanent --add-service=https
        fi
        firewall-cmd --reload
        log "Firewalld firewall configured and enabled."
    fi
}

detect_os() {
  log "Starting OS detection..."
  # ... (rest of detect_os is the same) ...
}

install_dependencies() {
  log "Starting dependency installation..."
  # ... (rest of install_dependencies is the same) ...
}

install_ssl() {
    log "Starting SSL installation..."
    # ... (rest of install_ssl is the same) ...
}

# --- Main Script ---
# Defaults
WEBSERVER="nginx"
ROLE=""
DOMAIN="_"
HTTP_PORT="8080"
DB_NAME="vmforge"
DB_USER="vmforge"
DB_PASS=""
ASSUME_YES=0
# Slave specific
CONTROLLER_URL=""
NODE_TOKEN=""

# Parse args
while [[ $# -gt 0 ]]; do
  case "$1" in
    --master) ROLE="master"; shift;;
    --slave) ROLE="slave"; shift;;
    --domain) DOMAIN="$2"; shift 2;;
    --webserver) WEBSERVER="$2"; shift 2;;
    --ssl) SSL_EMAIL="$2"; shift 2;;
    --http-port) HTTP_PORT="$2"; shift 2;;
    --db-name) DB_NAME="$2"; shift 2;;
    --db-user) DB_USER="$2"; shift 2;;
    --db-pass) DB_PASS="$2"; shift 2;;
    --controller) CONTROLLER_URL="$2"; shift 2;;
    --token) NODE_TOKEN="$2"; shift 2;;
    --yes) ASSUME_YES=1; shift;;
    --debug) DEBUG_MODE=1; shift;;
    *)
      echo "Unknown argument: $1"
      usage
      exit 1
      ;;
  esac
done

if [[ "$DEBUG_MODE" -eq 1 ]]; then
    log "Debug mode enabled."
    set -x
fi

log "Script execution started."

need_root
detect_os

# ... (rest of script) ...

configure_webserver() {
    log "Configuring webserver ($WEBSERVER)..."
    local template_file=""
    local dest_file=""

    if [[ "$WEBSERVER" == "nginx" ]]; then
        template_file="deploy/nginx-vmforge.conf.tpl"
        dest_file="$WEBSERVER_CONF_DIR/vmforge.conf"
    elif [[ "$WEBSERVER" == "apache" ]]; then
        template_file="deploy/apache-vmforge.conf.tpl"
        dest_file="$WEBSERVER_CONF_DIR/vmforge.conf"
    fi

    log "Creating webserver config from template: $template_file"
    sed -e "s|{{SERVER_NAME}}|$DOMAIN|g" \
        -e "s|{{ROOT}}|/var/www/vmforge/public|g" \
        -e "s|{{HTTP_PORT}}|$HTTP_PORT|g" \
        -e "s|{{PHP_FPM_SOCK}}|$PHP_FPM_SOCK_PATH|g" \
        "$template_file" > "$dest_file"

    if [[ "$WEBSERVER" == "nginx" ]]; then
        rm -f "/etc/nginx/sites-enabled/default"
        ln -sf "$dest_file" "/etc/nginx/sites-enabled/vmforge.conf"
    elif [[ "$WEBSERVER" == "apache" ]]; then
        if [[ "$OS_FAMILY" == "debian" ]]; then
            a2enmod proxy_fcgi setenvif rewrite headers
            a2ensite vmforge.conf
            a2dissite 000-default.conf
        fi
    fi

    log "Restarting $WEBSERVER..."
    systemctl restart "$WEBSERVER_SERVICE"
}

install_dependencies

if [[ "$ROLE" == "master" ]]; then
  log "Starting master installation..."

  if [[ -z "$DB_PASS" ]]; then
    log "ERROR: --db-pass is required for master installation."
    exit 1
  fi

  log "Creating application directory..."
  mkdir -p /var/www/vmforge
  rsync -a . /var/www/vmforge/ --exclude '.git' --exclude '.github'
  chown -R "$WEBSERVER_USER":"$WEBSERVER_USER" /var/www/vmforge

  log "Configuring database..."
  if [[ "$OS_FAMILY" == "debian" ]]; then
    systemctl enable --now mariadb
  elif [[ "$OS_FAMILY" == "rhel" ]]; then
    systemctl enable --now mariadb
  fi
  mysql -u root -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  mysql -u root -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
  mysql -u root -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
  mysql -u root -e "FLUSH PRIVILEGES;"

  harden_mariadb

  log "Creating .env file..."
  cp .env.example /var/www/vmforge/.env
  sed -i "s/DB_HOST=127.0.0.1/DB_HOST=127.0.0.1/" /var/www/vmforge/.env
  sed -i "s/DB_DATABASE=vmforge/DB_DATABASE=$DB_NAME/" /var/www/vmforge/.env
  sed -i "s/DB_USERNAME=vmforge/DB_USERNAME=$DB_USER/" /var/www/vmforge/.env
  sed -i "s/DB_PASSWORD=/DB_PASSWORD=$DB_PASS/" /var/www/vmforge/.env
  # Generate a random APP_KEY
  APP_KEY=$(head /dev/urandom | tr -dc A-Za-z0-9 | head -c 32)
  sed -i "s/APP_KEY=/APP_KEY=$APP_KEY/" /var/www/vmforge/.env

  log "Running database migrations..."
  php /var/www/vmforge/scripts/db/migrate.php

  configure_webserver

  setup_firewall
  install_ssl

  log "Master install done."
  # ... (rest of master install)
fi

if [[ "$ROLE" == "slave" ]]; then
  log "Starting slave installation..."
  if [[ -z "$CONTROLLER_URL" || -z "$NODE_TOKEN" ]]; then
    log "ERROR: --controller and --token are required for slave installation."
    exit 1
  fi

  log "Enabling virtualization services..."
  if [[ "$OS_FAMILY" == "debian" ]]; then
    systemctl enable --now libvirt-daemon-system
  elif [[ "$OS_FAMILY" == "rhel" ]]; then
    systemctl enable --now libvirtd
  fi

  log "Configuring VMForge agent..."
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
ExecStart=/usr/bin/php /opt/vmforge/agent.php
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
EOF

  systemctl daemon-reload
  systemctl enable --now vmforge-agent

  log "Slave install complete. Agent is running."
fi

log "Installation complete."
