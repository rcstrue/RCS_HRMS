<?php
/**
 * RCS HRMS Pro - Client Class
 * Handles all client-related operations
 */

class Client {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // Get all clients
    public function getList($activeOnly = true) {
        $sql = "SELECT c.id, c.client_code, c.name as client_name, c.contact_person, c.contact_email, 
                       c.contact_phone, c.city, c.state, c.address, c.gst_number,
                       c.pincode, c.is_active,
                       (SELECT COUNT(*) FROM units u WHERE u.client_id = c.id) as unit_count,
                       (SELECT COUNT(*) FROM employees e WHERE e.client_id = c.id) as employee_count
                FROM clients c";
        
        if ($activeOnly) {
            $sql .= " WHERE c.is_active = 1";
        }
        
        $sql .= " ORDER BY c.name";
        
        return $this->db->fetchAll($sql);
    }

    // Get all clients (alias for getList)
    public function getAll($activeOnly = true) {
        return $this->getList($activeOnly);
    }

    // Get client by ID
    public function getById($id) {
        return $this->db->fetch(
            "SELECT * FROM clients WHERE id = :id",
            ['id' => $id]
        );
    }

    // Get client by name
    public function getByName($name) {
        return $this->db->fetch(
            "SELECT * FROM clients WHERE name = :name LIMIT 1",
            ['name' => $name]
        );
    }

    // Create new client
    public function create($data) {
        // Generate client code if not provided
        if (empty($data['client_code'])) {
            $data['client_code'] = $this->generateClientCode();
        }
        
        // Map client_name to name (database column)
        if (isset($data['client_name'])) {
            $data['name'] = $data['client_name'];
            unset($data['client_name']);
        }
        
        // Map phone to contact_phone (database column)
        if (isset($data['phone'])) {
            $data['contact_phone'] = $data['phone'];
            unset($data['phone']);
        }
        
        // Map email to contact_email (database column)
        if (isset($data['email'])) {
            $data['contact_email'] = $data['email'];
            unset($data['email']);
        }
        
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['is_active'] = $data['is_active'] ?? 1;

        try {
            $id = $this->db->insert('clients', $data);
            return ['success' => true, 'message' => 'Client created successfully.', 'id' => $id];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to create client: ' . $e->getMessage()];
        }
    }

    // Update client
    public function update($id, $data) {
        // Map client_name to name (database column)
        if (isset($data['client_name'])) {
            $data['name'] = $data['client_name'];
            unset($data['client_name']);
        }
        
        // Map phone to contact_phone (database column)
        if (isset($data['phone'])) {
            $data['contact_phone'] = $data['phone'];
            unset($data['phone']);
        }
        
        // Map email to contact_email (database column)
        if (isset($data['email'])) {
            $data['contact_email'] = $data['email'];
            unset($data['email']);
        }
        
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        try {
            $this->db->update('clients', $data, 'id = :id', ['id' => $id]);
            return ['success' => true, 'message' => 'Client updated successfully.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to update client: ' . $e->getMessage()];
        }
    }

    // Delete client
    public function delete($id) {
        // Check if client has employees
        $empCount = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM employees WHERE client_id = :id",
            ['id' => $id]
        );
        
        if ($empCount > 0) {
            return ['success' => false, 'message' => "Cannot delete client. $empCount employees are assigned to this client."];
        }
        
        try {
            $this->db->delete('clients', 'id = :id', ['id' => $id]);
            return ['success' => true, 'message' => 'Client deleted successfully.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to delete client: ' . $e->getMessage()];
        }
    }

    // Generate client code
    private function generateClientCode() {
        $lastCode = $this->db->fetchColumn(
            "SELECT MAX(client_code) FROM clients WHERE client_code LIKE 'C%'"
        );
        
        if ($lastCode) {
            $num = (int)substr($lastCode, 1) + 1;
            return 'C' . str_pad($num, 4, '0', STR_PAD_LEFT);
        }
        
        return 'C0001';
    }

    // Get client statistics
    public function getStatistics($clientId) {
        return [
            'units' => $this->db->fetchColumn(
                "SELECT COUNT(*) FROM units WHERE client_id = :id",
                ['id' => $clientId]
            ) ?: 0,
            'employees' => $this->db->fetchColumn(
                "SELECT COUNT(*) FROM employees WHERE client_id = :id",
                ['id' => $clientId]
            ) ?: 0,
            'active_employees' => $this->db->fetchColumn(
                "SELECT COUNT(*) FROM employees WHERE client_id = :id AND status = 'active'",
                ['id' => $clientId]
            ) ?: 0
        ];
    }
}
?>
