<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use VMForge\Core\Headers;
use VMForge\Core\Router;
use VMForge\Core\RateLimiter;
use VMForge\Core\Middleware\CsrfMiddleware;
use VMForge\Controllers\HomeController;
use VMForge\Controllers\AuthController;
use VMForge\Controllers\SettingsController;
use VMForge\Controllers\NodeController;
use VMForge\Controllers\VMController;
use VMForge\Controllers\VMDetailsController;
use VMForge\Controllers\JobsController;
use VMForge\Controllers\AgentController;
use VMForge\Controllers\APIController;
use VMForge\Controllers\ImagesController;
use VMForge\Controllers\IPPoolController;
use VMForge\Controllers\TokensController;
use VMForge\Controllers\ConsoleController;
use VMForge\Controllers\BackupController;
use VMForge\Controllers\NetworkController;
use VMForge\Controllers\SnapshotController;
use VMForge\Controllers\ProjectsController;
use VMForge\Controllers\RestoreController;
use VMForge\Controllers\HealthController;
use VMForge\Controllers\StorageController;
use VMForge\Controllers\DiskController;
use VMForge\Controllers\MetricsController;
use VMForge\Controllers\ISOController;
use VMForge\Controllers\ReinstallController;
use VMForge\Controllers\SubnetsController;
use VMForge\Controllers\Subnets6Controller;
use VMForge\Controllers\BandwidthController;
use VMForge\Controllers\ZFSReposController;
use VMForge\Controllers\FirewallController;
use VMForge\Controllers\BillingController;
use VMForge\Controllers\RbacController;
use VMForge\Controllers\Client\VMController as ClientVMController;
use VMForge\Controllers\TicketController;
use VMForge\Controllers\SetupController;
use VMForge\Core\DB;

Headers::sendSecurityHeaders();
Headers::initSession();

// --- Initial Setup Check ---
try {
    if (DB::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $controller = new SetupController();

        if ($path === '/setup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $controller->createAdminUser();
        } else {
            $controller->showSetupForm();
        }
        exit();
    }
} catch (\PDOException $e) {
    // Migrations probably haven't run. Force setup.
    $controller = new SetupController();
    $controller->showSetupForm();
    exit();
}


$router = new Router();

CsrfMiddleware::validate();

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (strpos($path, '/api/') === 0) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    RateLimiter::throttle('api:' . $method . ':' . $path . ':' . $ip, 120);
}

$router->get('/', [HomeController::class, 'index']);
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/logout', [AuthController::class, 'logout']);

$router->get('/settings/2fa', [SettingsController::class, 'twofa']);
$router->post('/settings/2fa', [SettingsController::class, 'twofaPost']);

$router->get('/admin/nodes', [NodeController::class, 'index']);
$router->post('/admin/nodes', [NodeController::class, 'store']);

$router->get('/admin/vms', [VMController::class, 'index']);
$router->post('/admin/vms', [VMController::class, 'store']);
$router->get('/admin/vm', [VMDetailsController::class, 'show']);
$router->post('/admin/vm-action', [VMDetailsController::class, 'action']);

$router->get('/admin/jobs', [JobsController::class, 'index']);

$router->get('/admin/images', [ImagesController::class, 'index']);
$router->post('/admin/images', [ImagesController::class, 'store']);
$router->get('/admin/ip-pools', [IPPoolController::class, 'index']);
$router->post('/admin/ip-pools', [IPPoolController::class, 'store']);
$router->get('/admin/api-tokens', [TokensController::class, 'index']);
$router->post('/admin/api-tokens', [TokensController::class, 'store']);

$router->get('/admin/network', [NetworkController::class, 'index']);
$router->post('/admin/network', [NetworkController::class, 'store']);

$router->get('/console/open', [ConsoleController::class, 'open']);
$router->get('/console/redirect', [ConsoleController::class, 'redirect']);
$router->get('/console/close', [ConsoleController::class, 'close']);

$router->get('/admin/backups', [BackupController::class, 'index']);
$router->post('/admin/backups', [BackupController::class, 'create']);
$router->post('/admin/snapshots', [SnapshotController::class, 'create']);
$router->post('/admin/restore', [RestoreController::class, 'create']);
$router->post('/admin/restore-new', [RestoreController::class, 'createNew']);

$router->get('/admin/projects', [ProjectsController::class, 'index']);
$router->post('/admin/projects', [ProjectsController::class, 'store']);
$router->post('/admin/projects/switch', [ProjectsController::class, 'switch']);
$router->post('/admin/projects/quotas', [ProjectsController::class, 'quotas']);

$router->get('/admin/storage', [StorageController::class, 'index']);
$router->post('/admin/storage', [StorageController::class, 'store']);
$router->post('/admin/disk-resize', [DiskController::class, 'resize']);

$router->get('/admin/metrics', [MetricsController::class, 'index']);

$router->get('/admin/isos', [ISOController::class, 'index']);
$router->post('/admin/isos', [ISOController::class, 'store']);
$router->post('/admin/reinstall', [ReinstallController::class, 'create']);

$router->get('/admin/subnets', [SubnetsController::class, 'index']);
$router->post('/admin/subnets', [SubnetsController::class, 'store']);

$router->get('/admin/subnets6', [Subnets6Controller::class, 'index']);
$router->post('/admin/subnets6', [Subnets6Controller::class, 'store']);

$router->get('/admin/bandwidth', [BandwidthController::class, 'index']);
$router->get('/admin/bandwidth.csv', [BandwidthController::class, 'csv']);

$router->get('/admin/zfs-repos', [ZFSReposController::class, 'index']);
$router->post('/admin/zfs-repos', [ZFSReposController::class, 'store']);

$router->get('/admin/firewall', [FirewallController::class, 'index']);
$router->post('/admin/firewall', [FirewallController::class, 'store']);

$router->get('/admin/rbac', [RbacController::class, 'index']);
$router->get('/admin/rbac/role', [RbacController::class, 'editRole']);
$router->post('/admin/rbac/role', [RbacController::class, 'updateRole']);

$router->get('/admin/tickets', [TicketController::class, 'adminIndex']);

// Billing
$router->get('/billing', [BillingController::class, 'index']);
$router->get('/billing/products', [BillingController::class, 'products']);
$router->post('/billing/subscribe', [BillingController::class, 'subscribe']);

// Client Area
$router->get('/client/vms', [ClientVMController::class, 'index']);
$router->get('/tickets', [TicketController::class, 'index']);
$router->get('/tickets/new', [TicketController::class, 'create']);
$router->post('/tickets/new', [TicketController::class, 'store']);
$router->get('/tickets/show', [TicketController::class, 'show']);
$router->post('/tickets/reply', [TicketController::class, 'reply']);

// Health
$router->get('/healthz', [HealthController::class, 'index']);

// Agent
$router->post('/agent/poll', [AgentController::class, 'poll']);
$router->post('/agent/ack', [AgentController::class, 'ack']);

// API
$router->get('/api/v1/nodes', [APIController::class, 'listNodes']);
$router->post('/api/v1/jobs', [APIController::class, 'createJob']);

$router->dispatch();
