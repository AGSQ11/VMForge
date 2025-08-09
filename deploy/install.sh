\
    #!/usr/bin/env bash
    set -euo pipefail
    export DEBIAN_FRONTEND=noninteractive

    usage() {
      cat <<'USAGE'
    VMForge unattended installer
    Usage:
      ./deploy/install.sh --master --domain vmforge.local --db-name vmforge --db-user vmforge --db-pass 'secret' [--yes]
      ./deploy/install.sh --slave  --controller http://MASTER:8080 --token 'NODE_TOKEN' [--yes]

    Flags:
      --master | --slave       Install control plane (web/API) or node agent only
      --domain <host>         (master) server_name for nginx (default: _)
      --http-port <port>      (master) listen port (default: 8080)
      --controller <url>      (slave)  controller base URL (e.g., http://master:8080)
      --token <token>         (slave)  node token
      --db-name <name>        (master) MariaDB database name (default: vmforge)
      --db-user <user>        (master) MariaDB user (default: vmforge)
      --db-pass <pass>        (master) MariaDB password (required if creating user)
      --php-fpm <sock>        (master) PHP-FPM socket (default: /run/php/php-fpm.sock autodetects)
      --yes                   Assume yes to prompts
    USAGE
    }

    confirm() {
      if [[ "${ASSUME_YES:-0}" == "1" ]]; then return 0; fi
      read -rp "$1 [y/N]: " a; [[ "${a,,}" == "y" || "${a,,}" == "yes" ]]
    }

    need_root() {
      if [[ "$EUID" -ne 0 ]]; then echo "Run as root."; exit 1; fi
    }
    detect_os() {
      . /etc/os-release
      OS_FAMILY=""
      if [[ "$ID" =~ (debian|ubuntu) || "$ID_LIKE" =~ (debian|ubuntu) ]]; then
        OS_FAMILY="debian"
      fi
      [[ -z "$OS_FAMILY" ]] && { echo "Unsupported OS. Use Debian 12/Ubuntu 22.04+."; exit 1; }
    }
    autodetect_php_fpm() {
      local sock
      for sock in /run/php/php*-fpm.sock /run/php/php*-fpm-*.sock /run/php/php-fpm.sock; do
        [[ -S "$sock" ]] && { echo "$sock"; return; }
      done
      echo "/run/php/php-fpm.sock"
    }

    # Defaults
    ROLE=""
    DOMAIN="_"
    HTTP_PORT="8080"
    DB_NAME="vmforge"
    DB_USER="vmforge"
    DB_PASS=""
    PHP_FPM_SOCK=""
    CONTROLLER_URL=""
    NODE_TOKEN=""
    ASSUME_YES=0

    # Parse args
    while [[ $# -gt 0 ]]; do
      case "$1" in
        --master) ROLE="master"; shift;;
        --slave)  ROLE="slave"; shift;;
        --domain) DOMAIN="$2"; shift 2;;
        --http-port) HTTP_PORT="$2"; shift 2;;
        --controller) CONTROLLER_URL="$2"; shift 2;;
        --token) NODE_TOKEN="$2"; shift 2;;
        --db-name) DB_NAME="$2"; shift 2;;
        --db-user) DB_USER="$2"; shift 2;;
        --db-pass) DB_PASS="$2"; shift 2;;
        --php-fpm) PHP_FPM_SOCK="$2"; shift 2;;
        --yes|-y) ASSUME_YES=1; shift;;
        -h|--help) usage; exit 0;;
        *) echo "Unknown arg: $1"; usage; exit 1;;
      esac
    done

    need_root; detect_os

    if [[ -z "$ROLE" ]]; then echo "Specify --master or --slave"; usage; exit 1; fi

    # Install packages
    apt-get update -y
    if [[ "$ROLE" == "master" ]]; then
      apt-get install -y nginx mariadb-server mariadb-client php-cli php-fpm php-curl php-xml php-mbstring php-zip php-mysql php-gd php-intl redis-server git unzip curl
    else
      apt-get install -y php-cli php-curl php-xml php-mbstring php-zip php-mysql redis-server git curl
    fi

    # Ensure user
    id vmforge >/dev/null 2>&1 || useradd -r -s /usr/sbin/nologin -d /var/www/vmforge vmforge

    install_dir="/var/www/vmforge"
    mkdir -p "$install_dir"
    rsync -a --delete --exclude ".git" ./ "$install_dir/"
    chown -R vmforge:vmforge "$install_dir"

    if [[ "$ROLE" == "master" ]]; then
      # Configure DB
      if [[ -z "$DB_PASS" ]]; then
        echo "DB_PASS is required for master."; exit 1;
      fi

      systemctl enable --now mariadb
      mysql -uroot <<SQL
    CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
    GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
    FLUSH PRIVILEGES;
    SQL

      # .env
      if [[ ! -f "$install_dir/.env" ]]; then
        cp "$install_dir/.env.example" "$install_dir/.env" || true
      fi
      sed -i "s~^DB_HOST=.*~DB_HOST=127.0.0.1~" "$install_dir/.env" || true
      sed -i "s~^DB_NAME=.*~DB_NAME=${DB_NAME}~" "$install_dir/.env" || echo "DB_NAME=${DB_NAME}" >> "$install_dir/.env"
      sed -i "s~^DB_USER=.*~DB_USER=${DB_USER}~" "$install_dir/.env" || echo "DB_USER=${DB_USER}" >> "$install_dir/.env"
      sed -i "s~^DB_PASS=.*~DB_PASS=${DB_PASS}~" "$install_dir/.env" || echo "DB_PASS=${DB_PASS}" >> "$install_dir/.env"
      sed -i "s~^REDIS_HOST=.*~REDIS_HOST=127.0.0.1~" "$install_dir/.env" || echo "REDIS_HOST=127.0.0.1" >> "$install_dir/.env"

      # Run migrations
      sudo -u vmforge PHP_INI_SCAN_DIR="" php "$install_dir/scripts/db/migrate.php"

      # nginx
      PHP_FPM_SOCK="${PHP_FPM_SOCK:-$(autodetect_php_fpm)}"
      sed "s#{{SERVER_NAME}}#${DOMAIN}#g; s#{{ROOT}}#${install_dir}/public#g; s#{{PHP_FPM_SOCK}}#${PHP_FPM_SOCK}#g; s#{{HTTP_PORT}}#${HTTP_PORT}#g" \
        "$install_dir/deploy/nginx-vmforge.conf.tpl" > /etc/nginx/sites-available/vmforge.conf
      ln -sf /etc/nginx/sites-available/vmforge.conf /etc/nginx/sites-enabled/vmforge.conf
      rm -f /etc/nginx/sites-enabled/default || true
      systemctl reload nginx

      echo "[+] Master install done: http://${DOMAIN}:${HTTP_PORT}"
    else
      # Slave: agent service
      if [[ -z "$CONTROLLER_URL" || -z "$NODE_TOKEN" ]]; then
        echo "Slave requires --controller and --token"; exit 1;
      fi
      install -o root -g root -m 0644 "$install_dir/deploy/vmforge-agent.service" /etc/systemd/system/vmforge-agent.service
      install -o root -g root -m 0644 "$install_dir/deploy/agent.env.sample" /etc/vmforge-agent.env
      sed -i "s#^AGENT_CONTROLLER_URL=.*#AGENT_CONTROLLER_URL=${CONTROLLER_URL}#; s#^AGENT_NODE_TOKEN=.*#AGENT_NODE_TOKEN=${NODE_TOKEN}#;" /etc/vmforge-agent.env
      systemctl daemon-reload
      systemctl enable --now vmforge-agent.service
      echo "[+] Slave install done; agent running."
    fi
