<?php
/**
 * RCS HRMS Pro - Employee API Endpoint
 * For syncing employees from external portal
 */

// This file is routed through index.php which already loads config and classes
// No need to re-include config, database or class files

header('Content-Type: application/json');

// Authentication check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$employeeObj = new Employee();

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Get employees
        $id = $_GET['id'] ?? null;
        
        if ($id) {
            // Get single employee
            $emp = $employeeObj->getById((int)$id);
            if ($emp) {
                echo json_encode(['success' => true, 'data' => $emp]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Employee not found']);
            }
        } else {
            // Get all employees with filters
            $filters = [
                'status' => $_GET['status'] ?? 'Active',
                'client_id' => $_GET['client_id'] ?? null,
                'unit_id' => $_GET['unit_id'] ?? null,
            ];
            
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $result = $employeeObj->getAll($filters, $page, 100);
            
            echo json_encode([
                'success' => true,
                'data' => $result['data'],
                'pagination' => [
                    'total' => $result['total'],
                    'page' => $result['page'],
                    'per_page' => $result['per_page'],
                    'total_pages' => $result['total_pages']
                ]
            ]);
        }
        break;
        
    case 'POST':
        // Handle approve action
        $action = $_GET['action'] ?? '';
        $id = (int)($_GET['id'] ?? 0);
        
        if ($action === 'approve' && $id) {
            // Approve employee - change status from pending to approved
            try {
                $db = Database::getInstance();
                $db->update('employees', [
                    'status' => 'approved',
                    'approved_at' => date('Y-m-d H:i:s'),
                    'approved_by' => $_SESSION['user_id'] ?? null,
                    'updated_at' => date('Y-m-d H:i:s')
                ], 'id = :id', ['id' => $id]);
                
                echo json_encode(['success' => true, 'message' => 'Employee approved successfully']);
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to approve: ' . $e->getMessage()]);
            }
            break;
        }
        
        // Create or update employee
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            $input = $_POST;
        }
        
        if (isset($input['id']) && $input['id']) {
            // Update
            $result = $employeeObj->update((int)$input['id'], $input);
        } else {
            // Create
            $result = $employeeObj->create($input);
        }
        
        if (isset($result['success'])) {
            echo json_encode(['success' => true, 'message' => 'Employee saved', 'data' => $result]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => $result['error'] ?? 'Failed to save employee']);
        }
        break;
        
    case 'DELETE':
        // Delete employee
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Employee ID required']);
            exit;
        }
        
        $result = $employeeObj->delete((int)$id);
        
        if (isset($result['success'])) {
            echo json_encode(['success' => true, 'message' => 'Employee deleted']);
        } else {
            http_response_code(400);
            echo json_encode(['error' => $result['error'] ?? 'Failed to delete employee']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
