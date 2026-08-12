<?php
/**
 * ESS API — Employee Profile Endpoint
 * GET:  Fetch full employee profile by employee_id (JWT-authenticated)
 * PUT:  Update freely-editable profile fields (whitelist)
 *
 * Auth: JWT via auth-guard.php (requireAuth)
 */

require_once __DIR__ . '/cors.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth-guard.php';

$method = $_SERVER['REQUEST_METHOD'];

// ─── GET: Full Profile ──────────────────────────────────────────────────
if ($method === 'GET') {
    try {
        validateApiKey();
        $authId = requireAuth();
        $conn = getDbConnection();

        // IDOR: employee can only view own profile; managers/admins can view others
        $employeeId = scopedEmployeeId($authId, ESS_GUARD_ROLES_MANAGER, $conn);

        $stmt = $conn->prepare("
            SELECT e.id, e.employee_code, e.full_name, e.father_name, e.date_of_birth,
                   e.gender, e.blood_group, e.marital_status,
                   e.mobile_number, e.alternate_mobile, e.email,
                   e.aadhaar_number, e.uan_number, e.esic_number, e.pf_number,
                   e.designation, e.department, e.employment_type, e.worker_category,
                   e.employee_role, e.app_role,
                   e.date_of_joining, e.confirmation_date, e.probation_period,
                   e.date_of_leaving, e.profile_pic_url, e.profile_pic_cropped_url,
                   e.aadhaar_front_url, e.aadhaar_back_url, e.bank_document_url,
                   e.status, e.profile_completion,
                   e.bank_name, e.account_number, e.ifsc_code, e.account_holder_name,
                   e.address, e.pin_code, e.district, e.state AS emp_state,
                   e.client_id, e.unit_id,
                   e.emergency_contact_name, e.emergency_contact_number, e.emergency_contact_relation,
                   e.nominee_name, e.nominee_relationship, e.nominee_dob, e.nominee_contact,
                   e.approved_at, e.approved_by,
                   e.created_at, e.updated_at,
                   c.name AS client_name,
                   u.name AS unit_name, u.city AS unit_city, u.state AS unit_state
            FROM employees e
            LEFT JOIN clients c ON c.id = e.client_id
            LEFT JOIN units u   ON u.id = e.unit_id
            WHERE e.id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            jsonOutput(array('success' => false, 'error' => 'Database query error'), 500);
        }
        $stmt->bind_param('s', $employeeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            jsonOutput(array('success' => false, 'error' => 'Employee not found'), 404);
        }

        jsonOutput(array(
            'success' => true,
            'data' => array(
                'id'                          => (int)$row['id'],
                'employee_code'               => $row['employee_code'],
                'full_name'                   => $row['full_name'],
                'father_name'                 => $row['father_name'] ?? null,
                'date_of_birth'               => $row['date_of_birth'] ?? null,
                'gender'                      => $row['gender'] ?? null,
                'blood_group'                 => $row['blood_group'] ?? null,
                'marital_status'              => $row['marital_status'] ?? null,
                'mobile_number'               => $row['mobile_number'],
                'alternate_mobile'            => $row['alternate_mobile'] ?? null,
                'email'                       => $row['email'] ?? null,
                'aadhaar_number'              => $row['aadhaar_number'] ?? null,
                'uan_number'                  => $row['uan_number'] ?? null,
                'esic_number'                 => $row['esic_number'] ?? null,
                'pf_number'                   => $row['pf_number'] ?? null,
                'designation'                 => $row['designation'] ?? null,
                'department'                  => $row['department'] ?? null,
                'employment_type'             => $row['employment_type'] ?? null,
                'worker_category'             => $row['worker_category'] ?? null,
                'employee_role'               => $row['employee_role'] ?? null,
                'app_role'                    => $row['app_role'] ?? null,
                'date_of_joining'             => $row['date_of_joining'] ?? null,
                'confirmation_date'           => $row['confirmation_date'] ?? null,
                'probation_period'            => $row['probation_period'] ?? null,
                'date_of_leaving'             => $row['date_of_leaving'] ?? null,
                'profile_pic_url'             => $row['profile_pic_url'] ?? null,
                'profile_pic_cropped_url'     => $row['profile_pic_cropped_url'] ?? null,
                'aadhaar_front_url'           => $row['aadhaar_front_url'] ?? null,
                'aadhaar_back_url'            => $row['aadhaar_back_url'] ?? null,
                'bank_document_url'           => $row['bank_document_url'] ?? null,
                'status'                      => $row['status'] ?? null,
                'profile_completion'          => $row['profile_completion'] ?? null,
                'bank_name'                   => $row['bank_name'] ?? null,
                'account_number'              => $row['account_number'] ?? null,
                'ifsc_code'                   => $row['ifsc_code'] ?? null,
                'account_holder_name'         => $row['account_holder_name'] ?? null,
                'address'                     => $row['address'] ?? null,
                'pin_code'                    => $row['pin_code'] ?? null,
                'district'                    => $row['district'] ?? null,
                'state'                       => $row['emp_state'] ?? null,
                'client_id'                   => $row['client_id'] ? (int)$row['client_id'] : null,
                'client_name'                 => $row['client_name'] ?? null,
                'unit_id'                     => $row['unit_id'] ? (int)$row['unit_id'] : null,
                'unit_name'                   => $row['unit_name'] ?? null,
                'city'                        => $row['unit_city'] ?? null,
                'emergency_contact_name'      => $row['emergency_contact_name'] ?? null,
                'emergency_contact_relation'  => $row['emergency_contact_relation'] ?? null,
                'nominee_name'                => $row['nominee_name'] ?? null,
                'nominee_relationship'        => $row['nominee_relationship'] ?? null,
                'nominee_dob'                 => $row['nominee_dob'] ?? null,
                'nominee_contact'             => $row['nominee_contact'] ?? null,
                'approved_at'                 => $row['approved_at'] ?? null,
                'approved_by'                 => $row['approved_by'] ?? null,
                'created_at'                  => $row['created_at'],
                'updated_at'                  => $row['updated_at'],
            ),
        ));
    } catch (\Throwable $e) {
        error_log('[ESS profile GET] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        jsonOutput(array('success' => false, 'error' => 'Internal server error'), 500);
    }
}

// ─── PUT: Update Free Fields ──────────────────────────────────────────────
if ($method === 'PUT' || $method === 'POST') {
    try {
        validateApiKey();
        $authId = requireAuth();
        $conn = getDbConnection();

        $input = getInput();
        $employeeId = (int)($input['employee_id'] ?? 0);
        $fields     = $input['fields'] ?? [];

        if ($employeeId <= 0) {
            jsonOutput(array('success' => false, 'error' => 'employee_id is required'), 400);
        }
        if (!is_array($fields) || empty($fields)) {
            jsonOutput(array('success' => false, 'error' => 'fields object is required'), 400);
        }

        // Ownership: can only update own profile (or manager/admin)
        requireOwnershipOrRole($authId, (string)$employeeId, ESS_GUARD_ROLES_MANAGER, $conn);

        // Whitelist: only freely-editable fields
        $ALLOWED = array(
            'email', 'blood_group', 'marital_status', 'profile_pic_url',
            'address', 'pin_code', 'district', 'state',
            'emergency_contact_name', 'emergency_contact_relation',
            'nominee_name', 'nominee_relationship', 'nominee_dob', 'nominee_contact',
        );

        $updateParts = array();
        $types = '';
        $params = array();
        $rejected = array();

        foreach ($fields as $key => $value) {
            if (in_array($key, $ALLOWED, true)) {
                $updateParts[] = "`$key` = ?";
                $types .= 's';
                $params[] = ($value !== null && $value !== '') ? $value : null;
            } else {
                $rejected[] = $key;
            }
        }

        if (empty($updateParts)) {
            jsonOutput(array(
                'success' => false,
                'error' => 'No valid fields to update.',
                'rejected_fields' => $rejected,
            ), 400);
        }

        // Add updated_at
        $updateParts[] = "`updated_at` = NOW()";

        $sql = "UPDATE employees SET " . implode(', ', $updateParts) . " WHERE id = ?";
        $types .= 's';
        $params[] = (string)$employeeId;

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            jsonOutput(array('success' => false, 'error' => 'Database error'), 500);
        }
        bindDynamicParams($stmt, $types, $params);
        $stmt->execute();
        $stmt->close();

        $response = array(
            'success' => true,
            'message' => 'Profile updated successfully.',
            'updated_fields' => array_keys(array_intersect_key($fields, array_flip($ALLOWED))),
        );
        if (!empty($rejected)) {
            $response['rejected_fields'] = $rejected;
        }
        jsonOutput($response);

    } catch (\Throwable $e) {
        error_log('[ESS profile PUT] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        jsonOutput(array('success' => false, 'error' => 'Internal server error'), 500);
    }
}

// ─── Method not allowed ───────────────────────────────────────────────────
jsonOutput(array('success' => false, 'error' => 'Method not allowed. Use GET or PUT.'), 405);
