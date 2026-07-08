// Designation API service
import { apiRequest } from './config';

export interface Designation {
  id: number;
  name: string;
  desi_view: number;
  created_at: string;
}

// Get all designations
export async function getDesignations() {
  return apiRequest<Designation[]>('/designations');
}

// Get active designations (for registration dropdown)
export async function getActiveDesignations() {
  return apiRequest<Designation[]>('/designations/active');
}

// Create designation
export async function createDesignation(name: string, desiView: number = 1) {
  return apiRequest<Designation>('/designations', {
    method: 'POST',
    body: JSON.stringify({ name, desi_view: desiView }),
  });
}

// Update designation
export async function updateDesignation(id: number, data: { name?: string; desi_view?: number }) {
  return apiRequest<Designation>(`/designations/${id}`, {
    method: 'PUT',
    body: JSON.stringify(data),
  });
}

// Delete designation
export async function deleteDesignation(id: number) {
  return apiRequest<{ success: boolean }>(`/designations/${id}`, {
    method: 'DELETE',
  });
}

// ─── Auto Role Assignment (designation → app_role) ──────────────────

export interface AutoRoleDesignation {
  designation_id: number;
  designation: string;
  total_employees: number;
  proposed_role: string;
  current_roles: Record<string, number>;
  needs_update: boolean;
}

export interface AutoRolePreview {
  designations: AutoRoleDesignation[];
  total_designations: number;
  total_employees: number;
  employees_needing_update: number;
}

export interface AutoRoleApplyResult {
  total_employees: number;
  employees_updated: number;
  employees_unchanged: number;
  admins_skipped: number;
  role_summary: Record<string, number>;
  changes: Array<{
    employee_id: number;
    designation: string;
    from_role: string;
    to_role: string;
  }>;
  errors: string[];
}

// Preview auto-role mapping (GET)
export async function getAutoRolePreview() {
  return apiRequest<AutoRolePreview>('/ess/auto-role');
}

// Apply auto-role mapping (POST)
export async function applyAutoRole(designationIds?: number[]) {
  return apiRequest<AutoRoleApplyResult>('/ess/auto-role', {
    method: 'POST',
    body: JSON.stringify({ designation_ids: designationIds || [] }),
  });
}
