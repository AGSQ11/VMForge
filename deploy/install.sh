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

# ... (Package lists are the same) ...
DEBIAN_NGINX_PKGS=(nginx mariadb-server mariadb-client php8.2-cli php8.2-fpm php8.2-curl php8.2-xml php8.2-mbstring php8.2-zip php8.2-mysql php8.2-gd php8.2-intl redis-server git unzip curl ufw)
DEBIAN_APACHE_PKGS=(apache2 mariadb-server mariadb-client php8.2-cli php8.2-fpm php8.2-curl php8.2-xml php8.2-mbstring php8.2-zip php8.2-mysql php8.2-gd php8.2-intl redis-server git unzip curl libapache2-mod-php8.2 ufw)
DEBIAN_SLAVE_PKGS=(php8.2-cli php8.2-curl php8.2-xml php8.2-mbstring php8.2-zip php8.2-mysql redis-server git curl)
DEBIAN_CERTBOT_PKGS_nginx=(certbot python3-certbot-nginx)
DEBIAN_CERTBOT_PKGS_apache=(certbot python3-certbot-apache)

RHEL_NGINX_PKGS=(nginx mariadb-server mariadb php-cli php-fpm php-curl php-xml php-mbstring php-zip php-mysqlnd php-gd php-intl redis git unzip curl firewalld)
RHEL_APACHE_PKGS=(httpd mariadb-server mariadb php-cli php-fpm php-curl php-xml php-mbstring php-zip php-mysqlnd php-gd php-intl redis git unzip curl firewalld)
RHEL_SLAVE_PKGS=(php-cli php-curl php-xml php-mbstring php-zip php-mysqlnd redis git curl)
RHEL_CERTBOT_PKGS=(snapd)


usage() {
  cat <<'USAGE'
VMForge unattended installer
...
Flags:
  --debug                 Enable debug mode (verbose output).
  --webserver <name>      (master) nginx or apache (default: nginx)
  --ssl <email>           (master) Enable SSL with Let's Encrypt and use this email.
  --yes                   Assume "yes" to all prompts.
  ...
USAGE
}

confirm() {
    if [[ "$ASSUME_YES" -eq 1 ]]; then
        return 0
    fi
    read -r -p "$1 [y/N] " response
    case "$response" in
        [yY][eE][sS]|[yY])
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

# ... ( need_root, log functions are the same) ...

detect_os() {
  log "Starting OS detection..."
  # ... (rest of detect_os is the same) ...
}

install_dependencies() {
  log "Starting dependency installation..."
  local pkgs_to_install
  if [[ "$ROLE" == "master" ]]; then
    if [[ "$WEBSERVER" == "nginx" ]]; then
      pkgs_to_install=("${DEBIAN_NGINX_PKGS[@]}")
      if [[ "$OS_FAMILY" == "rhel" ]]; then
        pkgs_to_install=("${RHEL_NGINX_PKGS[@]}")
      fi
    elif [[ "$WEBSERVER" == "apache" ]]; then
      pkgs_to_install=("${DEBIAN_APACHE_PKGS[@]}")
      if [[ "$OS_FAMILY" == "rhel" ]]; then
        pkgs_to_install=("${RHEL_APACHE_PKGS[@]}")
      fi
    fi
  elif [[ "$ROLE" == "slave" ]]; then
    pkgs_to_install=("${DEBIAN_SLAVE_PKGS[@]}")
    if [[ "$OS_FAMILY" == "rhel" ]]; then
      pkgs_to_install=("${RHEL_SLAVE_PKGS[@]}")
    fi
  fi

  if ! confirm "About to install the following packages: ${pkgs_to_install[*]}. Continue?"; then
    log "Installation aborted by user."
    exit 1
  fi

  if [[ "$OS_FAMILY" == "debian" ]]; then
    export DEBIAN_FRONTEND=noninteractive
    $PKG_MANAGER update
    # shellcheck disable=SC2086
    $PKG_MANAGER install -y ${pkgs_to_install[*]}
  elif [[ "$OS_FAMILY" == "rhel" ]]; then
    # shellcheck disable=SC2086
    $PKG_MANAGER install -y ${pkgs_to_install[*]}
  fi
  log "Dependencies installed successfully."
}

harden_mariadb() {
    log "Hardening MariaDB installation..."
    # This is the non-interactive equivalent of mysql_secure_installation
    mysql -u root <<EOF
-- Set root password
UPDATE mysql.user SET Password = PASSWORD('$DB_PASS') WHERE User = 'root';
-- Remove anonymous users
DELETE FROM mysql.user WHERE User='';
-- Disallow remote root login
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
-- Remove test database
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
-- Reload privilege tables
FLUSH PRIVILEGES;
EOF
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
        log "UFW firewall configured."
    elif [[ "$OS_FAMILY" == "rhel" ]]; then
        systemctl enable --now firewalld
        firewall-cmd --permanent --add-service=ssh
        firewall-cmd --permanent --add-port="$HTTP_PORT"/tcp
        if [[ -n "$SSL_EMAIL" ]]; then
            firewall-cmd --permanent --add-service=https
        fi
        firewall-cmd --reload
        log "Firewalld firewall configured."
    fi
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
# ... (rest of defaults) ...

# Parse args
while [[ $# -gt 0 ]]; do
  case "$1" in
    --debug) DEBUG_MODE=1; shift;;
    --webserver) WEBSERVER="$2"; shift 2;;
    --ssl) SSL_EMAIL="$2"; shift 2;;
    --yes) ASSUME_YES=1; shift;;
    # ... (rest of parsing) ...
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

install_dependencies

log "Copying application files..."
# ... (user creation, rsync) ...

if [[ "$ROLE" == "master" ]]; then
  # ... (db config, .env, migrations, webserver config) ...

  harden_mariadb
  setup_firewall
  install_ssl

  log "Master install done."
  # ... (rest of master install)
fi

# ... (slave install)
log "Installation complete."
