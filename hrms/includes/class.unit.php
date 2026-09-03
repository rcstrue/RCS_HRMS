<?php
/**
 * RCS HRMS Pro - Unit Class
 * Handles all unit/work location-related operations
 */

class Unit {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // Get all units
    public function getAll($clientId = null, $activeOnly = true) {
        $sql = "SELECT u.id, u.unit_code, u.name as unit_name, u.client_id, 
                       u.city, u.state, u.is_active, u.contact_person, u.contact_phone,
                       c.name as client_name,
                       (SELECT COUNT(*) FROM employees e WHERE e.unit_id = u.id AND e.status = 'approved') as employee_count
                FROM units u
                LEFT JOIN clients c ON u.client_id = c.id";
        
        $params = [];
        $where = [];
        
        if ($clientId) {
            $where[] = "u.client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        if ($activeOnly) {
            $where[] = "u.is_active = 1";
        }
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        
        $sql .= " ORDER BY c.name, u.name";
        
        return $this->db->fetchAll($sql, $params);
    }

    // Get units by client
    public function getByClient($clientId, $activeOnly = true) {
        return $this->getAll($clientId, $activeOnly);
    }

    // Get unit by ID
    public function getById($id) {
        return $this->db->fetch(
            "SELECT u.*, c.name as client_name 
             FROM units u
             LEFT JOIN clients c ON u.client_id = c.id
             WHERE u.id = :id",
            ['id' => $id]
        );
    }

    // Get unit by name
    public function getByName($name) {
        return $this->db->fetch(
            "SELECT * FROM units WHERE name = :name LIMIT 1",
            ['name' => $name]
        );
    }

    // Create new unit
    public function create($data) {
        // Map form field names to database columns
        if (isset($data['unit_name']) && !isset($data['name'])) {
            $data['name'] = $data['unit_name'];
            unset($data['unit_name']);
        }
        
        // Map phone to contact_phone
        if (isset($data['phone']) && !isset($data['contact_phone'])) {
            $data['contact_phone'] = $data['phone'];
            unset($data['phone']);
        }
        
        // Generate unit code if not provided
        if (empty($data['unit_code'])) {
            $data['unit_code'] = $this->generateUnitCode($data['client_id'] ?? null);
        }
        
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['is_active'] = $data['is_active'] ?? 1;

        try {
            $id = $this->db->insert('units', $data);
            return ['success' => true, 'message' => 'Unit created successfully.', 'id' => $id, 'unit_code' => $data['unit_code']];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to create unit: ' . $e->getMessage()];
        }
    }

    // Update unit
    public function update($id, $data) {
        // Map form field names to database columns
        if (isset($data['unit_name']) && !isset($data['name'])) {
            $data['name'] = $data['unit_name'];
            unset($data['unit_name']);
        }
        
        // Map phone to contact_phone
        if (isset($data['phone']) && !isset($data['contact_phone'])) {
            $data['contact_phone'] = $data['phone'];
            unset($data['phone']);
        }
        
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        try {
            $this->db->update('units', $data, 'id = :id', ['id' => $id]);
            return ['success' => true, 'message' => 'Unit updated successfully.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to update unit: ' . $e->getMessage()];
        }
    }

    // Delete unit
    public function delete($id) {
        // Check if unit has employees
        $empCount = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM employees WHERE unit_id = :id",
            ['id' => $id]
        );
        
        if ($empCount > 0) {
            return ['success' => false, 'message' => "Cannot delete unit. $empCount employees are assigned to this unit."];
        }
        
        try {
            $this->db->delete('units', 'id = :id', ['id' => $id]);
            return ['success' => true, 'message' => 'Unit deleted successfully.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Failed to delete unit: ' . $e->getMessage()];
        }
    }

    // Generate unit code based on client code + unit number
    private function generateUnitCode($clientId = null) {
        $prefix = 'U';
        
        if ($clientId) {
            $client = $this->db->fetch(
                "SELECT client_code FROM clients WHERE id = :id",
                ['id' => $clientId]
            );
            if ($client && !empty($client['client_code'])) {
                $prefix = $client['client_code'];
            }
        }
        
        // Count existing units for this client to get next unit number
        $unitCount = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM units WHERE client_id = :client_id",
            ['client_id' => $clientId ?: 0]
        );
        
        $nextNum = ($unitCount ?: 0) + 1;
        return $prefix . '-' . str_pad($nextNum, 2, '0', STR_PAD_LEFT);
    }

    // Get unit statistics
    public function getStatistics($unitId) {
        return [
            'employees' => $this->db->fetchColumn(
                "SELECT COUNT(*) FROM employees WHERE unit_id = :id",
                ['id' => $unitId]
            ) ?: 0,
            'active_employees' => $this->db->fetchColumn(
                "SELECT COUNT(*) FROM employees WHERE unit_id = :id AND status = 'approved'",
                ['id' => $unitId]
            ) ?: 0
        ];
    }
}
?>
