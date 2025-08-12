#!/bin/bash
set -e

# VMForge Enterprise Installer
# Production-ready installation script

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration defaults
INSTALL_DIR="/opt/vmforge"
WEB_ROOT="/var/www/vmforge"
DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_NAME="vmforge"
DB_USER="vmforge"
WEBSERVER="nginx"
PHP_VERSION="8.2"
NODE_NAME=""
CONTROLLER_URL=""
NODE_TOKEN=""
BRIDGE="br0"
MODE=""
DOMAIN=""
SSL_EMAIL=""
HTTP_PORT="8080"
ASSUME_YES=false

# Functions
log() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1" >&2
    exit 1
}

warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

usage() {
    cat << EOF
Usage: $0 [OPTIONS]

VMForge Enterprise Installer

Options:
    --master                Install master control panel
    --slave                 Install slave node agent
    --domain <domain>       Domain name for the panel
    --webserver <server>    Web server (nginx|apache) [default: nginx]
    --ssl <email>          Enable SSL with Let's Encrypt
    --http-port <port>     HTTP port [default: 8080]
    --db-host <host>       Database host [default: 127.0.0.1]
    --db-port <port>       Database port [default: 3306]
    --db-name <name>       Database name [default: vmforge]
    --db-user <user>       Database user [default: vmforge]
    --db-pass <pass>       Database password (required for master)
    --controller <url>     Controller URL (required for slave)
    --token <token>        Node token (required for slave)
    --node-name <name>     Node name (optional for slave)
    --bridge <bridge>      Network bridge [default: br0]
    --yes                  Assume yes to all prompts
    --help                 Show this help message

Examples:
    Master installation:
    $0 --master --domain panel.example.com --db-pass 'SecurePass123!' --ssl admin@example.com

    Slave installation:
    $0 --slave --controller https://panel.example.com --token 'node-token-here'

EOF
    exit 0
}

detect_os() {
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS=$ID
        OS_VERSION=$VERSION_ID
        OS_FAMILY=""
        
        case $OS in
            ubuntu|debian)
                OS_FAMILY="debian"
                PKG_MANAGER="apt-get"
                ;;
            centos|rocky|almalinux|rhel)
                OS_FAMILY="rhel"
                PKG_MANAGER="dnf"
                if ! command -v dnf &> /dev/null; then
                    PKG_MANAGER="yum"
                fi
                ;;
            *)
                error "Unsupported operating system: $OS"
                ;;
        esac
        
        log "Detected OS: $OS $OS_VERSION (Family: $OS_FAMILY)"
    else
        error "Cannot detect operating system"
    fi
}

check_root() {
    if [ "$EUID" -ne 0 ]; then
        error "This script must be run as root"
    fi
}

confirm() {
    if [ "$ASSUME_YES" = true ]; then
        return 0
    fi
    
    read -p "$1 (y/n): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        return 0
    else
        return 1
    fi
}

install_dependencies() {
    log "Installing dependencies..."
    
    if [ "$OS_FAMILY" = "debian" ]; then
        export DEBIAN_FRONTEND=noninteractive
        apt-get update
        
        # Common packages
        apt-get install -y \
            curl wget git vim htop \
            software-properties-common \
            apt-transport-https \
            ca-certificates \
            gnupg lsb-release \
            ufw fail2ban
        
        if [ "$MODE" = "master" ]; then
            # PHP repository
            if [ "$OS" = "ubuntu" ]; then
                add-apt-repository -y ppa:ondrej/php
            else
                wget -O /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg
                echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list
            fi
            
            apt-get update
            
            # Master dependencies
            apt-get install -y \
                mariadb-server mariadb-client \
                redis-server \
                php${PHP_VERSION} \
                php${PHP_VERSION}-fpm \
                php${PHP_VERSION}-cli \
                php${PHP_VERSION}-common \
                php${PHP_VERSION}-mysql \
                php${PHP_VERSION}-redis \
                php${PHP_VERSION}-curl \
                php${PHP_VERSION}-mbstring \
                php${PHP_VERSION}-xml \
                php${PHP_VERSION}-zip \
                php${PHP_VERSION}-bcmath \
                php${PHP_VERSION}-gd \
                php${PHP_VERSION}-intl
            
            # Web server
            if [ "$WEBSERVER" = "apache" ]; then
                apt-get install -y apache2 libapache2-mod-php${PHP_VERSION}
                a2enmod rewrite headers proxy proxy_fcgi
            else
                apt-get install -y nginx
            fi
            
            # Certbot for SSL
            if [ -n "$SSL_EMAIL" ]; then
                apt-get install -y certbot
                if [ "$WEBSERVER" = "nginx" ]; then
                    apt-get install -y python3-certbot-nginx
                else
                    apt-get install -y python3-certbot-apache
                fi
            fi
        fi
        
        if [ "$MODE" = "slave" ]; then
            # Slave dependencies
            apt-get install -y \
                qemu-kvm libvirt-daemon-system libvirt-clients \
                bridge-utils virt-manager \
                lxc lxc-templates \
                ovmf cloud-image-utils \
                nftables dnsmasq radvd \
                zfsutils-linux ceph-common \
                sysstat iotop iftop
            
            # Enable virtualization
            systemctl enable --now libvirtd
            systemctl enable --now nftables
        fi
        
    elif [ "$OS_FAMILY" = "rhel" ]; then
        # RHEL/CentOS/Rocky/AlmaLinux
        $PKG_MANAGER install -y epel-release
        $PKG_MANAGER install -y \
            curl wget git vim htop \
            firewalld fail2ban
        
        if [ "$MODE" = "master" ]; then
            # PHP repository
            $PKG_MANAGER install -y https://rpms.remirepo.net/enterprise/remi-release-${OS_VERSION%%.*}.rpm
            $PKG_MANAGER module enable php:remi-${PHP_VERSION} -y
            
            # Master dependencies
            $PKG_MANAGER install -y \
                mariadb-server mariadb \
                redis \
                php php-fpm php-cli php-common \
                php-mysqlnd php-redis \
                php-mbstring php-xml php-zip \
                php-bcmath php-gd php-intl
            
            # Web server
            if [ "$WEBSERVER" = "apache" ]; then
                $PKG_MANAGER install -y httpd mod_ssl
            else
                $PKG_MANAGER install -y nginx
            fi
            
            # Certbot
            if [ -n "$SSL_EMAIL" ]; then
                $PKG_MANAGER install -y certbot
                if [ "$WEBSERVER" = "nginx" ]; then
                    $PKG_MANAGER install -y python3-certbot-nginx
                else
                    $PKG_MANAGER install -y python3-certbot-apache
                fi
            fi
            
            # Enable services
            systemctl enable --now mariadb
            systemctl enable --now redis
            systemctl enable --now php-fpm
        fi
        
        if [ "$MODE" = "slave" ]; then
            # Slave dependencies
            $PKG_MANAGER install -y \
                qemu-kvm libvirt virt-install \
                bridge-utils virt-manager \
                lxc lxc-templates \
                cloud-utils-growpart cloud-init \
                nftables dnsmasq radvd
            
            # ZFS (if available)
            if [ "$OS" = "centos" ] && [ "${OS_VERSION%%.*}" -ge 8 ]; then
                $PKG_MANAGER install -y https://zfsonlinux.org/epel/zfs-release-2-2.el${OS_VERSION%%.*}.noarch.rpm
                $PKG_MANAGER install -y zfs
            fi
            
            # Enable virtualization
            systemctl enable --now libvirtd
            systemctl enable --now nftables
        fi
    fi
    
    log "Dependencies installed successfully"
}

setup_database() {
    log "Setting up database..."
    
    # Start MariaDB
    systemctl start mariadb
    systemctl enable mariadb
    
    # Secure installation
    mysql -e "UPDATE mysql.user SET Password=PASSWORD('$DB_PASS') WHERE User='root';"
    mysql -e "DELETE FROM mysql.user WHERE User='';"
    mysql -e "DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');"
    mysql -e "DROP DATABASE IF EXISTS test;"
    mysql -e "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';"
    
    # Create VMForge database and user
    mysql -uroot -p"$DB_PASS" <<EOF
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF
    
    log "Database setup complete"
}

install_vmforge() {
    log "Installing VMForge..."
    
    # Create directories
    mkdir -p $INSTALL_DIR
    mkdir -p $WEB_ROOT
    mkdir -p /var/lib/vmforge/{vms,backups,isos,images}
    mkdir -p /var/log/vmforge
    
    # Clone or copy VMForge files
    if [ -d "./src" ]; then
        # Installing from local directory
        cp -r ./* $INSTALL_DIR/
    else
        # Clone from repository (adjust as needed)
        git clone https://github.com/yourusername/vmforge.git $INSTALL_DIR
    fi
    
    # Set up web root
    ln -sf $INSTALL_DIR/public $WEB_ROOT/public
    
    # Create .env file
    cat > $INSTALL_DIR/.env <<EOF
APP_ENV=production
APP_URL=http://${DOMAIN:-localhost}:${HTTP_PORT}
APP_NAME="VMForge Enterprise"
APP_SECRET=$(openssl rand -hex 32)
APP_TIMEZONE=UTC

DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS

AGENT_POLL_INTERVAL=5
AGENT_NODE_TOKEN=$NODE_TOKEN
AGENT_NODE_NAME=$NODE_NAME
AGENT_CONTROLLER_URL=$CONTROLLER_URL
AGENT_BRIDGE=$BRIDGE

BACKUP_DIR=/var/lib/vmforge/backups
ISO_DIR=/var/lib/vmforge/isos
IMAGE_DIR=/var/lib/vmforge/images
ISO_BASE_URL=http://${DOMAIN:-localhost}:${HTTP_PORT}/isos

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

MAIL_DRIVER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@${DOMAIN:-localhost}
MAIL_FROM_NAME="VMForge"
EOF
    
    # Set permissions
    useradd -r -s /bin/false vmforge 2>/dev/null || true
    chown -R vmforge:vmforge $INSTALL_DIR
    chown -R vmforge:vmforge /var/lib/vmforge
    chown -R vmforge:vmforge /var/log/vmforge
    chmod 600 $INSTALL_DIR/.env
    
    # Run migrations
    if [ "$MODE" = "master" ]; then
        log "Running database migrations..."
        cd $INSTALL_DIR
        php scripts/db/migrate.php
    fi
    
    log "VMForge installation complete"
}

configure_webserver() {
    log "Configuring web server..."
    
    if [ "$WEBSERVER" = "nginx" ]; then
        # Nginx configuration
        cat > /etc/nginx/sites-available/vmforge <<EOF
server {
    listen $HTTP_PORT;
    server_name $DOMAIN;
    
    root $WEB_ROOT/public;
    index index.php;
    
    access_log /var/log/nginx/vmforge_access.log;
    error_log /var/log/nginx/vmforge_error.log;
    
    client_max_body_size 256M;
    
    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    
    location / {
        try_files \$uri /index.php\$is_args\$args;
    }
    
    location ~ \\.php$ {
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_buffer_size 128k;
        fastcgi_buffers 256 16k;
        fastcgi_busy_buffers_size 256k;
    }
    
    location ~ /\\.ht {
        deny all;
    }
    
    # ISO downloads
    location /isos/ {
        alias /var/lib/vmforge/isos/;
        autoindex off;
    }
}
EOF
        
        ln -sf /etc/nginx/sites-available/vmforge /etc/nginx/sites-enabled/
        rm -f /etc/nginx/sites-enabled/default
        
        # Test and reload
        nginx -t && systemctl reload nginx
        
    elif [ "$WEBSERVER" = "apache" ]; then
        # Apache configuration
        cat > /etc/apache2/sites-available/vmforge.conf <<EOF
<VirtualHost *:$HTTP_PORT>
    ServerName $DOMAIN
    DocumentRoot $WEB_ROOT/public
    
    <Directory $WEB_ROOT/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    <FilesMatch \\.php$>
        SetHandler "proxy:unix:/run/php/php${PHP_VERSION}-fpm.sock|fcgi://localhost/"
    </FilesMatch>
    
    # Security headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    
    ErrorLog /var/log/apache2/vmforge_error.log
    CustomLog /var/log/apache2/vmforge_access.log combined
    
    # ISO downloads
    Alias /isos /var/lib/vmforge/isos
    <Directory /var/lib/vmforge/isos>
        Options -Indexes
        Require all granted
    </Directory>
</VirtualHost>
EOF
        
        # Update ports.conf if needed
        if ! grep -q "Listen $HTTP_PORT" /etc/apache2/ports.conf; then
            echo "Listen $HTTP_PORT" >> /etc/apache2/ports.conf
        fi
        
        a2ensite vmforge
        a2dissite 000-default
        
        # Test and reload
        apache2ctl configtest && systemctl reload apache2
    fi
    
    log "Web server configured"
}

setup_ssl() {
    if [ -z "$SSL_EMAIL" ] || [ -z "$DOMAIN" ]; then
        return
    fi
    
    log "Setting up SSL certificate..."
    
    if [ "$WEBSERVER" = "nginx" ]; then
        certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$SSL_EMAIL"
    else
        certbot --apache -d "$DOMAIN" --non-interactive --agree-tos -m "$SSL_EMAIL"
    fi
    
    # Set up auto-renewal
    cat > /etc/systemd/system/certbot-renewal.service <<EOF
[Unit]
Description=Certbot Renewal
After=network.target

[Service]
Type=oneshot
ExecStart=/usr/bin/certbot renew --quiet --deploy-hook "systemctl reload $WEBSERVER"

[Install]
WantedBy=multi-user.target
EOF

    cat > /etc/systemd/system/certbot-renewal.timer <<EOF
[Unit]
Description=Twice daily renewal of Let's Encrypt certificates

[Timer]
OnCalendar=0/12:00:00
RandomizedDelaySec=1h
Persistent=true

[Install]
WantedBy=timers.target
EOF
    
    systemctl enable --now certbot-renewal.timer
    
    log "SSL certificate installed"
}

setup_firewall() {
    log "Configuring firewall..."
    
    if [ "$OS_FAMILY" = "debian" ]; then
        ufw --force enable
        ufw default deny incoming
        ufw default allow outgoing
        ufw allow ssh
        ufw allow $HTTP_PORT/tcp
        
        if [ -n "$SSL_EMAIL" ]; then
            ufw allow 443/tcp
        fi
        
        if [ "$MODE" = "slave" ]; then
            # VNC ports for console access
            ufw allow 5900:6999/tcp
            # Migration ports
            ufw allow 49152:49216/tcp
        fi
        
    elif [ "$OS_FAMILY" = "rhel" ]; then
        systemctl enable --now firewalld
        
        firewall-cmd --permanent --add-service=ssh
        firewall-cmd --permanent --add-port=$HTTP_PORT/tcp
        
        if [ -n "$SSL_EMAIL" ]; then
            firewall-cmd --permanent --add-service=https
        fi
        
        if [ "$MODE" = "slave" ]; then
            firewall-cmd --permanent --add-port=5900-6999/tcp
            firewall-cmd --permanent --add-port=49152-49216/tcp
        fi
        
        firewall-cmd --reload
    fi
    
    log "Firewall configured"
}

setup_systemd_services() {
    log "Setting up systemd services..."
    
    if [ "$MODE" = "slave" ]; then
        # VMForge Agent service
        cat > /etc/systemd/system/vmforge-agent.service <<EOF
[Unit]
Description=VMForge Agent
After=network.target libvirtd.service

[Service]
Type=simple
User=root
WorkingDirectory=$INSTALL_DIR
ExecStart=/usr/bin/php $INSTALL_DIR/agent/agent.php
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF
        
        systemctl daemon-reload
        systemctl enable --now vmforge-agent
    fi
    
    # Metrics collector
    cat > /etc/systemd/system/vmforge-metrics.service <<EOF
[Unit]
Description=VMForge Metrics Collector
After=network.target

[Service]
Type=simple
User=vmforge
WorkingDirectory=$INSTALL_DIR
ExecStart=/usr/bin/php $INSTALL_DIR/scripts/metrics/collector.php
Restart=always
RestartSec=60

[Install]
WantedBy=multi-user.target
EOF
    
    # Scheduler
    cat > /etc/systemd/system/vmforge-scheduler.service <<EOF
[Unit]
Description=VMForge Scheduler
After=network.target

[Service]
Type=simple
User=vmforge
WorkingDirectory=$INSTALL_DIR
ExecStart=/usr/bin/php $INSTALL_DIR/scripts/scheduler.php
Restart=always
RestartSec=60

[Install]
WantedBy=multi-user.target
EOF
    
    systemctl daemon-reload
    systemctl enable --now vmforge-metrics
    systemctl enable --now vmforge-scheduler
    
    log "Systemd services configured"
}

print_summary() {
    echo
    echo "========================================"
    echo "   VMForge Installation Complete!"
    echo "========================================"
    echo
    
    if [ "$MODE" = "master" ]; then
        echo "Master Panel URL: http://${DOMAIN:-localhost}:${HTTP_PORT}"
        echo
        echo "Database:"
        echo "  Host: $DB_HOST"
        echo "  Name: $DB_NAME"
        echo "  User: $DB_USER"
        echo
        echo "Next steps:"
        echo "1. Access the web panel and create your admin account"
        echo "2. Add compute nodes from the Nodes section"
        echo "3. Configure storage pools and networks"
        echo "4. Upload or import VM images"
        echo
    else
        echo "Node Agent installed successfully!"
        echo
        echo "Controller: $CONTROLLER_URL"
        echo "Node Name: ${NODE_NAME:-$(hostname)}"
        echo
        echo "The agent is running as a systemd service:"
        echo "  systemctl status vmforge-agent"
        echo
        echo "Add this node in the master panel using the token provided"
        echo
    fi
    
    echo "Documentation: https://docs.vmforge.com"
    echo "Support: support@vmforge.com"
    echo
}

# Main script
main() {
    # Parse arguments
    while [[ $# -gt 0 ]]; do
        case $1 in
            --master)
                MODE="master"
                shift
                ;;
            --slave)
                MODE="slave"
                shift
                ;;
            --domain)
                DOMAIN="$2"
                shift 2
                ;;
            --webserver)
                WEBSERVER="$2"
                shift 2
                ;;
            --ssl)
                SSL_EMAIL="$2"
                shift 2
                ;;
            --http-port)
                HTTP_PORT="$2"
                shift 2
                ;;
            --db-host)
                DB_HOST="$2"
                shift 2
                ;;
            --db-port)
                DB_PORT="$2"
                shift 2
                ;;
            --db-name)
                DB_NAME="$2"
                shift 2
                ;;
            --db-user)
                DB_USER="$2"
                shift 2
                ;;
            --db-pass)
                DB_PASS="$2"
                shift 2
                ;;
            --controller)
                CONTROLLER_URL="$2"
                shift 2
                ;;
            --token)
                NODE_TOKEN="$2"
                shift 2
                ;;
            --node-name)
                NODE_NAME="$2"
                shift 2
                ;;
            --bridge)
                BRIDGE="$2"
                shift 2
                ;;
            --yes)
                ASSUME_YES=true
                shift
                ;;
            --help)
                usage
                ;;
            *)
                error "Unknown option: $1"
                ;;
        esac
    done
    
    # Validate arguments
    if [ -z "$MODE" ]; then
        error "Please specify --master or --slave"
    fi
    
    if [ "$MODE" = "master" ] && [ -z "$DB_PASS" ]; then
        error "Database password is required for master installation (--db-pass)"
    fi
    
    if [ "$MODE" = "slave" ]; then
        if [ -z "$CONTROLLER_URL" ] || [ -z "$NODE_TOKEN" ]; then
            error "Controller URL and token are required for slave installation"
        fi
        if [ -z "$NODE_NAME" ]; then
            NODE_NAME=$(hostname)
        fi
    fi
    
    # Start installation
    echo
    echo "========================================"
    echo "   VMForge Enterprise Installer"
    echo "========================================"
    echo
    echo "Mode: $MODE"
    [ "$MODE" = "master" ] && echo "Domain: ${DOMAIN:-localhost}"
    [ "$MODE" = "master" ] && echo "Web Server: $WEBSERVER"
    [ "$MODE" = "master" ] && echo "HTTP Port: $HTTP_PORT"
    [ "$MODE" = "slave" ] && echo "Controller: $CONTROLLER_URL"
    [ "$MODE" = "slave" ] && echo "Node Name: $NODE_NAME"
    echo
    
    if ! confirm "Proceed with installation?"; then
        echo "Installation cancelled"
        exit 0
    fi
    
    # Run installation steps
    check_root
    detect_os
    install_dependencies
    
    if [ "$MODE" = "master" ]; then
        setup_database
    fi
    
    install_vmforge
    
    if [ "$MODE" = "master" ]; then
        configure_webserver
        setup_ssl
    fi
    
    setup_firewall
    setup_systemd_services
    
    print_summary
}

# Run main function
main "$@"
