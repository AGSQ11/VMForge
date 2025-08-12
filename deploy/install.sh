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
DEBIAN_NGINX_PKGS=(nginx mariadb-server mariadb-client php8.2-cli php8.2-fpm php8.2-curl php8.2-xml php8.2-mbstring php8.2-zip php8.2-mysql php8.2-gd php8.2-intl redis-server git unzip curl)
DEBIAN_APACHE_PKGS=(apache2 mariadb-server mariadb-client php8.2-cli php8.2-fpm php8.2-curl php8.2-xml php8.2-mbstring php8.2-zip php8.2-mysql php8.2-gd php8.2-intl redis-server git unzip curl libapache2-mod-php8.2)
DEBIAN_SLAVE_PKGS=(php8.2-cli php8.2-curl php8.2-xml php8.2-mbstring php8.2-zip php8.2-mysql redis-server git curl)
DEBIAN_CERTBOT_PKGS_nginx=(certbot python3-certbot-nginx)
DEBIAN_CERTBOT_PKGS_apache=(certbot python3-certbot-apache)

RHEL_NGINX_PKGS=(nginx mariadb-server mariadb php-cli php-fpm php-curl php-xml php-mbstring php-zip php-mysqlnd php-gd php-intl redis git unzip curl)
RHEL_APACHE_PKGS=(httpd mariadb-server mariadb php-cli php-fpm php-curl php-xml php-mbstring php-zip php-mysqlnd php-gd php-intl redis git unzip curl)
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
  ...
USAGE
}

# ... (confirm, need_root, log functions are the same) ...

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
# ... (rest of defaults) ...

# Parse args
while [[ $# -gt 0 ]]; do
  case "$1" in
    --debug) DEBUG_MODE=1; shift;;
    --webserver) WEBSERVER="$2"; shift 2;;
    --ssl) SSL_EMAIL="$2"; shift 2;;
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

  install_ssl

  log "Master install done."
  # ... (rest of master install)
fi

# ... (slave install)
log "Installation complete."
