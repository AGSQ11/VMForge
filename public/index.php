<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use VMForge\Core\Router;
use VMForge\Controllers\HomeController;
use VMForge\Controllers\AuthController;
use VMForge\Controllers\NodeController;
use VMForge\Controllers\VMController;
use VMForge\Controllers\AgentController;
use VMForge\Controllers\APIController;
use VMForge\Controllers\ImagesController;
use VMForge\Controllers\IPPoolController;
use VMForge\Controllers\TokensController;
use VMForge\Controllers\ConsoleController;

// Routes
$router = new Router();

// UI
$router->get('/', [HomeController::class, 'index']);
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/logout', [AuthController::class, 'logout']);

$router->get('/admin/nodes', [NodeController::class, 'index']);
$router->post('/admin/nodes', [NodeController::class, 'store']);

$router->get('/admin/vms', [VMController::class, 'index']);
$router->post('/admin/vms', [VMController::class, 'store']);

// Admin extras
$router->get('/admin/images', [ImagesController::class, 'index']);
$router->post('/admin/images', [ImagesController::class, 'store']);
$router->get('/admin/ip-pools', [IPPoolController::class, 'index']);
$router->post('/admin/ip-pools', [IPPoolController::class, 'store']);
$router->get('/admin/api-tokens', [TokensController::class, 'index']);
$router->post('/admin/api-tokens', [TokensController::class, 'store']);

// Console
$router->get('/console/open', [ConsoleController::class, 'open']);
$router->get('/console/redirect', [ConsoleController::class, 'redirect']);
$router->get('/console/close', [ConsoleController::class, 'close']);

// Agent
$router->post('/agent/poll', [AgentController::class, 'poll']);
$router->post('/agent/ack', [AgentController::class, 'ack']);

// API
$router->get('/api/v1/nodes', [APIController::class, 'listNodes']);
$router->post('/api/v1/jobs', [APIController::class, 'createJob']);

$router->dispatch();
