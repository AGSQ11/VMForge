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

# Package lists
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
Usage:
  ./deploy/install.sh --master --domain vmforge.local --db-name vmforge --db-user vmforge --db-pass 'secret' [--ssl email@example.com] [--yes]
  ./deploy/install.sh --slave  --controller http://MASTER:8080 --token 'NODE_TOKEN' [--yes]

Flags:
  --webserver <name>      (master) nginx or apache (default: nginx)
  --ssl <email>           (master) Enable SSL with Let's Encrypt and use this email.
  # ... (rest of usage)
USAGE
}

# ... (confirm, need_root, log, detect_os functions are the same as before) ...
detect_os() {
  if [ -f /etc/os-release ]; then
    . /etc/os-release
  else
    echo "Cannot detect OS: /etc/os-release not found." >&2; exit 1
  fi

  log "Detecting OS..."
  if [[ "$ID_LIKE" == *"debian"* || "$ID" == "debian" || "$ID" == "ubuntu" ]]; then
    OS_FAMILY="debian"
    PKG_MANAGER="apt-get"
    PHP_FPM_SERVICE="php8.2-fpm"
    PHP_FPM_SOCK_PATH="/run/php/php8.2-fpm.sock"
    export DEBIAN_FRONTEND=noninteractive
  elif [[ "$ID_LIKE" == *"rhel"* || "$ID_LIKE" == *"fedora"* ]]; then
    OS_FAMILY="rhel"
    PKG_MANAGER="dnf"
    PHP_FPM_SERVICE="php-fpm"
    PHP_FPM_SOCK_PATH="/var/run/php-fpm/www.sock"
  else
    echo "Unsupported OS: $PRETTY_NAME" >&2; exit 1
  fi
  log "OS Family: $OS_FAMILY"
}


install_dependencies() {
  log "Installing dependencies for $ROLE role on $OS_FAMILY..."
  local -n pkgs_to_install
  local -n certbot_pkgs

  # Select base packages
  if [[ "$ROLE" == "master" ]]; then
    if [[ "$OS_FAMILY" == "debian" ]]; then
      [[ "$WEBSERVER" == "nginx" ]] && pkgs_to_install=DEBIAN_NGINX_PKGS || pkgs_to_install=DEBIAN_APACHE_PKGS
    else # rhel
      [[ "$WEBSERVER" == "nginx" ]] && pkgs_to_install=RHEL_NGINX_PKGS || pkgs_to_install=RHEL_APACHE_PKGS
    fi
  else # slave
    [[ "$OS_FAMILY" == "debian" ]] && pkgs_to_install=DEBIAN_SLAVE_PKGS || pkgs_to_install=RHEL_SLAVE_PKGS
  fi

  # Select certbot packages if needed
  if [[ -n "$SSL_EMAIL" ]]; then
      if [[ "$OS_FAMILY" == "debian" ]]; then
          [[ "$WEBSERVER" == "nginx" ]] && certbot_pkgs=DEBIAN_CERTBOT_PKGS_nginx || certbot_pkgs=DEBIAN_CERTBOT_PKGS_apache
          pkgs_to_install+=("${certbot_pkgs[@]}")
      else # rhel
          pkgs_to_install+=("${RHEL_CERTBOT_PKGS[@]}")
      fi
  fi

  # Perform installation
  if [[ "$OS_FAMILY" == "debian" ]]; then
    $PKG_MANAGER update -y
    $PKG_MANAGER install -y "${pkgs_to_install[@]}"
  elif [[ "$OS_FAMILY" == "rhel" ]]; then
    log "Enabling EPEL and Remi repositories..."
    $PKG_MANAGER install -y 'https://dl.fedoraproject.org/pub/epel/epel-release-latest-9.noarch.rpm'
    $PKG_MANAGER install -y 'http://rpms.remirepo.net/enterprise/remi-release-9.rpm'
    $PKG_MANAGER module reset php -y
    $PKG_MANAGER module enable php:remi-8.2 -y

    $PKG_MANAGER install -y "${pkgs_to_install[@]}"

    log "Enabling services for RHEL..."
    systemctl enable --now "$WEBSERVER_SERVICE"
    systemctl enable --now mariadb
    systemctl enable --now redis
    systemctl enable --now "$PHP_FPM_SERVICE"

    if [[ -n "$SSL_EMAIL" ]]; then
        log "Configuring snapd for Certbot..."
        systemctl enable --now snapd.socket
        ln -s /var/lib/snapd/snap /snap || true
        snap install core
        snap install certbot --classic
        ln -s /snap/bin/certbot /usr/bin/certbot || true
    fi
  fi
}

install_ssl() {
    if [[ -n "$SSL_EMAIL" ]]; then
        log "Requesting SSL certificate with Certbot..."
        if [[ "$DOMAIN" == "_" ]]; then
            log "WARNING: Cannot request SSL for default domain. Please use --domain flag."
            return
        fi
        certbot --"$WEBSERVER" -d "$DOMAIN" --non-interactive --agree-tos -m "$SSL_EMAIL" --redirect
        log "Certbot setup complete."
    fi
}


# --- Main Script ---
# Defaults
WEBSERVER="nginx"
ROLE=""
DOMAIN="_"
HTTP_PORT="8080"
# ... (rest of defaults)

# Parse args
while [[ $# -gt 0 ]]; do
  case "$1" in
    --ssl) SSL_EMAIL="$2"; shift 2;;
    # ... (rest of parsing)
  esac
done

# ... (need_root, detect_os, webserver var setting) ...

install_dependencies

# ... (user creation, rsync) ...

if [[ "$ROLE" == "master" ]]; then
  # ... (db config, .env, migrations, webserver config) ...

  install_ssl # Call the new SSL installation function

  log "Master install done."
  # ... (rest of master install)
fi

# ... (slave install)
log "Installation complete."
