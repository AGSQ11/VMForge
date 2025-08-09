<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use VMForge\Core\Router;
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

$router = new Router();

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

// Health
$router->get('/healthz', [HealthController::class, 'index']);

// Agent
$router->post('/agent/poll', [AgentController::class, 'poll']);
$router->post('/agent/ack', [AgentController::class, 'ack']);

// API
$router->get('/api/v1/nodes', [APIController::class, 'listNodes']);
$router->post('/api/v1/jobs', [APIController::class, 'createJob']);

$router->dispatch();
