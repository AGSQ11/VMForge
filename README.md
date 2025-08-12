# VMForge

VMForge is an open-source, lightweight, and powerful management panel for KVM and LXC virtualization. It provides a clean and simple interface for managing virtual machines, nodes, storage, and networking.

## Features

- **KVM & LXC Support:** Manage both full virtualization (KVM) and containerization (LXC).
- **Multi-OS Support:** Installer supports Debian, Ubuntu, and RHEL-family (CentOS, Rocky, AlmaLinux) systems.
- **Web-based Management:** A clean and simple web interface for all management tasks.
- **Client Area:** A dedicated area for clients to manage their services and support tickets.
- **Billing & Support:** Foundational billing system and a built-in support ticket system.
- **Role-Based Access Control (RBAC):** Granular control over user permissions.

## Quick Start

To install the VMForge master panel on a fresh server, run the following command as root:

```bash
git clone https://github.com/enginyring/vmforge.git /opt/vmforge
cd /opt/vmforge
./deploy/install.sh --master --domain your.domain.com --db-pass 'your_strong_password' --ssl your-email@example.com --yes
```

## Installation

The `deploy/install.sh` script automates the installation of the VMForge panel (master) or a virtualization node agent (slave). It must be run as root.

### Master Installation

Installs the main control panel, including the web interface, API, and database.

**Example:**
```bash
./deploy/install.sh --master --domain panel.example.com --db-pass 'your_strong_password' --yes
```

### Slave (Node) Installation

Installs the agent on a virtualization node which will be managed by the master panel.

**Example:**
```bash
./deploy/install.sh --slave --controller http://panel.example.com:8080 --token 'YOUR_NODE_TOKEN' --yes
```
*(You can get a node token from the master panel's web interface after installation.)*

### Installer Flags

| Flag | Description | Required | Default |
|---|---|---|---|
| `--master` | Install the master control panel. | Yes (or `--slave`) | |
| `--slave` | Install the slave node agent. | Yes (or `--slave`) | |
| `--domain <host>` | (Master) The domain name for the panel. Required for SSL. | No | `_` (wildcard) |
| `--webserver <name>` | (Master) Choose the web server to install. | No | `nginx` |
| `--ssl <email>` | (Master) Enable SSL with Let's Encrypt using this email. | No | Disabled |
| `--http-port <port>`| (Master) The HTTP port for the web panel. | No | `8080` |
| `--db-name <name>` | (Master) The name for the MariaDB database. | No | `vmforge` |
| `--db-user <user>` | (Master) The username for the MariaDB database. | No | `vmforge` |
| `--db-pass <pass>` | (Master) The password for the MariaDB user. | Yes (if `--master`) | |
| `--controller <url>`| (Slave) The base URL of the master panel. | Yes (if `--slave`) | |
| `--token <token>` | (Slave) The node token for authentication. | Yes (if `--slave`) | |
| `--yes` | Assume "yes" to all prompts. | No | |

## Post-Installation

After the master installation script completes, you can access the web panel at the domain you specified.

The first time you access the panel, you will be greeted with the initial setup page. Here, you will create the first administrator account by providing an email address and a password. Once the account is created, you will be redirected to the login page.

## Supported Operating Systems

The installer script officially supports the following operating systems:
- Debian 11, 12
- Ubuntu 20.04, 22.04
- Rocky Linux 8, 9
- AlmaLinux 8, 9
- RHEL 8, 9
- CentOS Stream 8, 9

## Troubleshooting

- **Installation fails on package installation:** Ensure your server has a working internet connection and that your OS repositories are accessible.
- **SSL certificate request fails:** Certbot requires that your domain name (`--domain`) is a fully qualified domain name (FQDN) and that its DNS A/AAAA record points to the public IP address of the server where you are running the installer.
- **502 Bad Gateway after install:** Check the status of the `php-fpm` service (`systemctl status php8.2-fpm` or `systemctl status php-fpm`). Also, check the webserver error logs for more details.
```
