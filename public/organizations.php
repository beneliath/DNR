<?php

require_once __DIR__ . '/../config/config.php';

use DNR\Utils\Security;
use DNR\Controllers\OrganizationController;

// Initialize secure session
Security::secureSession();

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Create controller instance
$controller = new OrganizationController();

// Route the request
$action = isset($_GET['action']) ? $_GET['action'] : 'create';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

try {
    switch ($action) {
        case 'index':
            $controller->index();
            break;
        
        case 'create':
            $controller->create();
            break;
        
        case 'edit':
            if (!$id) {
                throw new \Exception('ID is required');
            }
            $controller->edit($id);
            break;
        
        case 'delete':
            if (!$id) {
                throw new \Exception('ID is required');
            }
            $controller->delete($id);
            break;
        
        case 'view':
            if (!$id) {
                throw new \Exception('ID is required');
            }
            $controller->view($id);
            break;
        
        case 'search':
            $controller->search();
            break;
        
        default:
            throw new \Exception('Invalid action');
    }
} catch (\Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: organizations.php');
    exit;
} 