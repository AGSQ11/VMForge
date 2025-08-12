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
ASSUME_YES=0

# --- Package Lists ---
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

# --- Helper Functions ---
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

log() {
    echo -e "[VMForge Installer] $(date +'%T') - $*"
}

need_root() {
    if [[ $EUID -ne 0 ]]; then
       log "ERROR: This script must be run as root."
       exit 1
    fi
}

confirm() {
    if [[ "$ASSUME_YES" -eq 1 ]]; then
        return 0
    fi
    while true; do
        read -r -p "$1 [y/N] " response
        case "$response" in
            [yY][eE][sS]|[yY]) return 0 ;;
            [nN][oO]|[nN]|"") return 1 ;;
            *) echo "Please answer yes or no." ;;
        esac
    done
}

# --- Core Functions ---
detect_os() {
    log "Starting OS detection..."
    if [ -f /etc/os-release ]; then
        # shellcheck source=/dev/null
        source /etc/os-release
        if [[ "$ID_LIKE" == *"debian"* || "$ID" == "debian" || "$ID" == "ubuntu" ]]; then
            OS_FAMILY="debian"
            PKG_MANAGER="apt-get"
            if [[ "$WEBSERVER" == "nginx" ]]; then
                WEBSERVER_USER="www-data"; WEBSERVER_SERVICE="nginx"; WEBSERVER_CONF_DIR="/etc/nginx/sites-available"
                PHP_FPM_SERVICE="php8.2-fpm"; PHP_FPM_SOCK_PATH="/var/run/php/php8.2-fpm.sock"
            elif [[ "$WEBSERVER" == "apache" ]]; then
                WEBSERVER_USER="www-data"; WEBSERVER_SERVICE="apache2"; WEBSERVER_CONF_DIR="/etc/apache2/sites-available"
                PHP_FPM_SERVICE="php8.2-fpm"; PHP_FPM_SOCK_PATH="/var/run/php/php8.2-fpm.sock"
            fi
        elif [[ "$ID_LIKE" == *"rhel"* || "$ID" == "rhel" || "$ID" == "centos" || "$ID" == "fedora" || "$ID" == "rocky" || "$ID" == "almalinux" ]]; then
            OS_FAMILY="rhel"; PKG_MANAGER="dnf"
            if ! command -v dnf &> /dev/null; then PKG_MANAGER="yum"; fi
            if [[ "$WEBSERVER" == "nginx" ]]; then
                WEBSERVER_USER="nginx"; WEBSERVER_SERVICE="nginx"; WEBSERVER_CONF_DIR="/etc/nginx/conf.d"
                PHP_FPM_SERVICE="php-fpm"; PHP_FPM_SOCK_PATH="/var/run/php-fpm/www.sock"
            elif [[ "$WEBSERVER" == "apache" ]]; then
                WEBSERVER_USER="apache"; WEBSERVER_SERVICE="httpd"; WEBSERVER_CONF_DIR="/etc/httpd/conf.d"
                PHP_FPM_SERVICE="php-fpm"; PHP_FPM_SOCK_PATH="/var/run/php-fpm/www.sock"
            fi
        else
            log "ERROR: Unsupported operating system ($PRETTY_NAME)."; exit 1
        fi
        log "Detected OS: $PRETTY_NAME ($OS_FAMILY family)."
    else
        log "ERROR: /etc/os-release not found."; exit 1
    fi
}

install_dependencies() {
    log "Starting dependency installation..."; local pkgs_to_install=(); local pkgs_str=""
    if [[ "$ROLE" == "master" ]]; then
        if [[ "$OS_FAMILY" == "debian" ]]; then
            if [[ "$WEBSERVER" == "nginx" ]]; then pkgs_to_install+=("${DEBIAN_NGINX_PKGS[@]}"); else pkgs_to_install+=("${DEBIAN_APACHE_PKGS[@]}"); fi
        elif [[ "$OS_FAMILY" == "rhel" ]]; then
            if [[ "$WEBSERVER" == "nginx" ]]; then pkgs_to_install+=("${RHEL_NGINX_PKGS[@]}"); else pkgs_to_install+=("${RHEL_APACHE_PKGS[@]}"); fi
        fi
    elif [[ "$ROLE" == "slave" ]]; then
        if [[ "$OS_FAMILY" == "debian" ]]; then pkgs_to_install+=("${DEBIAN_SLAVE_PKGS[@]}"); else pkgs_to_install+=("${RHEL_SLAVE_PKGS[@]}"); fi
    fi
    pkgs_str="${pkgs_to_install[*]}"
    if ! confirm "About to install the following packages: $pkgs_str. Continue?"; then log "Installation aborted."; exit 1; fi
    log "Updating package repositories..."
    if [[ "$OS_FAMILY" == "debian" ]]; then export DEBIAN_FRONTEND=noninteractive; $PKG_MANAGER update -y; else $PKG_MANAGER makecache; fi
    log "Installing packages...";
    if [[ "$OS_FAMILY" == "debian" ]]; then $PKG_MANAGER install -y $pkgs_str; else $PKG_MANAGER install -y -q $pkgs_str; fi
    log "Dependencies installed successfully."
}

install_ssl() {
    if [[ -z "$SSL_EMAIL" ]]; then log "Skipping SSL installation."; return; fi
    log "Starting SSL installation with Certbot..."
    if [[ "$OS_FAMILY" == "debian" ]]; then
        if [[ "$WEBSERVER" == "nginx" ]]; then pkgs=("${DEBIAN_CERTBOT_PKGS_nginx[@]}"); else pkgs=("${DEBIAN_CERTBOT_PKGS_apache[@]}"); fi
        $PKG_MANAGER install -y "${pkgs[@]}"
    elif [[ "$OS_FAMILY" == "rhel" ]]; then
        $PKG_MANAGER install -y snapd; systemctl enable --now snapd.socket; ln -s /var/lib/snapd/snap /snap; sleep 10
        snap install core; snap refresh core; snap install --classic certbot; ln -s /snap/bin/certbot /usr/bin/certbot
    fi
    log "Requesting Let's Encrypt certificate for $DOMAIN..."
    if [[ "$WEBSERVER" == "nginx" ]]; then certbot --nginx -d "$DOMAIN" --email "$SSL_EMAIL" --agree-tos --non-interactive --redirect
    elif [[ "$WEBSERVER" == "apache" ]]; then certbot --apache -d "$DOMAIN" --email "$SSL_EMAIL" --agree-tos --non-interactive --redirect; fi
    if [[ $? -ne 0 ]]; then log "ERROR: SSL certificate installation failed."; exit 1; fi
    log "SSL certificate installed successfully."
}

configure_webserver() {
    log "Configuring webserver ($WEBSERVER)..."; local template_file=""; local dest_file=""
    if [[ "$WEBSERVER" == "nginx" ]]; then
        template_file="deploy/nginx-vmforge.conf.tpl"; dest_file="$WEBSERVER_CONF_DIR/vmforge.conf"
    elif [[ "$WEBSERVER" == "apache" ]]; then
        template_file="deploy/apache-vmforge.conf.tpl"; dest_file="$WEBSERVER_CONF_DIR/vmforge.conf"
    fi
    sed -e "s|{{SERVER_NAME}}|$DOMAIN|g" -e "s|{{ROOT}}|/var/www/vmforge/public|g" -e "s|{{HTTP_PORT}}|$HTTP_PORT|g" -e "s|{{PHP_FPM_SOCK}}|$PHP_FPM_SOCK_PATH|g" "$template_file" > "$dest_file"
    if [[ "$WEBSERVER" == "nginx" ]]; then rm -f /etc/nginx/sites-enabled/default; ln -sf "$dest_file" /etc/nginx/sites-enabled/vmforge.conf
    elif [[ "$WEBSERVER" == "apache" ]] && [[ "$OS_FAMILY" == "debian" ]]; then a2enmod proxy_fcgi setenvif rewrite headers; a2ensite vmforge.conf; a2dissite 000-default.conf; fi
    log "Restarting $WEBSERVER..."; systemctl restart "$WEBSERVER_SERVICE"
}

harden_mariadb() {
    log "Hardening MariaDB installation..."
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
        ufw allow ssh; ufw allow "$HTTP_PORT"/tcp
        if [[ -n "$SSL_EMAIL" ]]; then ufw allow https; fi
        ufw --force enable
    elif [[ "$OS_FAMILY" == "rhel" ]]; then
        systemctl enable --now firewalld
        firewall-cmd --permanent --add-service=ssh
        firewall-cmd --permanent --add-port="$HTTP_PORT"/tcp
        if [[ -n "$SSL_EMAIL" ]]; then firewall-cmd --permanent --add-service=https; fi
        firewall-cmd --reload
    fi
    log "Firewall configured and enabled."
}

# --- Main Script ---
# Defaults
WEBSERVER="nginx"; ROLE=""; DOMAIN=""; HTTP_PORT="80"; DB_NAME="vmforge"; DB_USER="vmforge"; DB_PASS=""; ASSUME_YES=0; CONTROLLER_URL=""; NODE_TOKEN=""

# Parse args
while [[ $# -gt 0 ]]; do
  case "$1" in
    --master) ROLE="master";; --slave) ROLE="slave";; --domain) DOMAIN="$2"; shift;; --webserver) WEBSERVER="$2"; shift;; --ssl) SSL_EMAIL="$2"; shift;;
    --http-port) HTTP_PORT="$2"; shift;; --db-name) DB_NAME="$2"; shift;; --db-user) DB_USER="$2"; shift;; --db-pass) DB_PASS="$2"; shift;;
    --controller) CONTROLLER_URL="$2"; shift;; --token) NODE_TOKEN="$2"; shift;; --yes) ASSUME_YES=1;; --debug) DEBUG_MODE=1;;
    *) log "Unknown argument: $1"; usage; exit 1;;
  esac
  shift
done

if [[ "$DEBUG_MODE" -eq 1 ]]; then log "Debug mode enabled."; set -x; fi
log "Script execution started."
if [[ -z "$ROLE" ]]; then log "ERROR: You must specify a role with either --master or --slave."; usage; exit 1; fi

need_root
detect_os
install_dependencies

if [[ "$ROLE" == "master" ]]; then
  log "Starting master installation..."
  if [[ -z "$DB_PASS" ]]; then log "ERROR: --db-pass is required for master."; exit 1; fi
  mkdir -p /var/www/vmforge; rsync -a . /var/www/vmforge/ --exclude '.git' --exclude '.github'; chown -R "$WEBSERVER_USER":"$WEBSERVER_USER" /var/www/vmforge
  systemctl enable --now mariadb
  mysql -u root -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  mysql -u root -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
  mysql -u root -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;"
  harden_mariadb
  cp .env.example /var/www/vmforge/.env
  sed -i "s/DB_DATABASE=vmforge/DB_DATABASE=$DB_NAME/" /var/www/vmforge/.env
  sed -i "s/DB_USERNAME=vmforge/DB_USERNAME=$DB_USER/" /var/www/vmforge/.env
  sed -i "s/DB_PASSWORD=/DB_PASSWORD=$DB_PASS/" /var/www/vmforge/.env
  APP_KEY=$(head /dev/urandom | tr -dc A-Za-z0-9 | head -c 32); sed -i "s/APP_KEY=/APP_KEY=$APP_KEY/" /var/www/vmforge/.env
  php /var/www/vmforge/scripts/db/migrate.php
  configure_webserver
  setup_firewall
  install_ssl
  log "Master install done."
elif [[ "$ROLE" == "slave" ]]; then
  log "Starting slave installation..."
  if [[ -z "$CONTROLLER_URL" || -z "$NODE_TOKEN" ]]; then log "ERROR: --controller and --token are required for slave."; exit 1; fi
  if [[ "$OS_FAMILY" == "debian" ]]; then systemctl enable --now libvirt-daemon-system; else systemctl enable --now libvirtd; fi
  mkdir -p /opt/vmforge; install -m 0755 agent/agent.php /opt/vmforge/agent.php
  cat >/etc/systemd/system/vmforge-agent.service <<EOF
[Unit]
Description=VMForge Agent
After=network-online.target
[Service]
Type=simple
Environment=AGENT_CONTROLLER_URL=${CONTROLLER_URL}
Environment=AGENT_NODE_TOKEN=${NODE_TOKEN}
ExecStart=/usr/bin/php /opt/vmforge/agent.php
Restart=always; RestartSec=5
[Install]
WantedBy=multi-user.target
EOF
  systemctl daemon-reload; systemctl enable --now vmforge-agent
  log "Slave install complete. Agent is running."
fi
log "Installation complete."
