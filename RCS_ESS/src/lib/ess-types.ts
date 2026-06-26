// ===== Employee =====
export interface Employee {
  id: number;
  employee_code: string;
  full_name: string;
  father_name?: string;
  date_of_birth?: string;
  gender?: string;
  blood_group?: string;
  mobile_number: string;
  alternate_mobile?: string;
  email?: string;
  marital_status?: string;
  aadhaar_number?: string;
  uan_number?: string;
  esic_number?: string;
  address?: string;
  pin_code?: string;
  state?: string;
  district?: string;
  bank_name?: string;
  account_number?: string;
  ifsc_code?: string;
  account_holder_name?: string;
  client_name?: string;
  client_id?: number;
  unit_name?: string;
  unit_id?: number;
  city?: string;
  date_of_joining?: string;
  confirmation_date?: string;
  probation_period?: string;
  date_of_leaving?: string;
  profile_pic_url?: string;
  profile_pic_cropped_url?: string;
  aadhaar_front_url?: string;
  aadhaar_back_url?: string;
  bank_document_url?: string;
  profile_completion?: number;
  employee_role?: string;
  worker_category?: string;
  designation?: string;
  department?: string;
  employment_type?: string;
  status?: string;
  nominee_name?: string;
  nominee_relationship?: string;
  nominee_dob?: string;
  nominee_contact?: string;
  emergency_contact_name?: string;
  emergency_contact_relation?: string;
  approved_at?: string;
  approved_by?: number;
  created_at?: string;
  updated_at?: string;
}

export type EmployeeRole = 'employee' | 'supervisor' | 'manager' | 'regional_manager' | 'field_officer' | 'admin';

// ===== Auth =====
export interface LoginResponse {
  employee: Employee;
  role: EmployeeRole;
  token: string;                // JWT token from backend
  token_expires_at?: string;    // ISO date when token expires
  has_custom_pin: boolean;      // false = must change PIN on first login
  is_locked?: boolean;          // true = account is locked (server-side)
  lockout_remaining?: number;   // seconds until lockout expires
  rate_limit_remaining?: number;// seconds until next attempt allowed
  rate_limit_attempts_left?: number; // attempts remaining in current window
}

export interface ESSSession {
  employee: Employee;
  role: EmployeeRole;
  token?: string;                // JWT stored in session for apiRequest
  token_expires_at?: string;
  has_custom_pin?: boolean;      // true = user has set their own PIN
}

// ===== Attendance =====
export interface AttendanceRecord {
  id: number;
  employee_id: number;
  date: string;
  status: 'present' | 'checked_in' | 'checked_out' | 'late' | 'absent' | 'leave' | 'holiday' | 'half_day';
  check_in?: string;
  check_out?: string;
  latitude?: number;
  longitude?: number;
  note?: string;
}

export interface AttendanceSummary {
  total_days: number;
  present_days: number;
  absent_days: number;
  late_days: number;
  leave_days: number;
}

// ===== Leaves =====
export interface LeaveRequest {
  id: number;
  employee_id: number;
  employee_name?: string;
  employee_unit?: string;
  type: 'CL' | 'SL' | 'EL' | 'WFH' | 'Comp_Off' | 'LWP';
  start_date: string;
  end_date: string;
  days: number;
  reason: string;
  status: 'pending' | 'approved' | 'rejected' | 'cancelled';
  rejection_reason?: string;
  approved_by?: number;
  created_at?: string;
  updated_at?: string;
}

export interface LeaveBalance {
  id: number;
  leave_type: 'CL' | 'SL' | 'EL' | 'WFH' | 'Comp_Off' | 'LWP';
  total: number;
  used: number;
  balance: number;
}

// ===== Tasks =====
export interface Task {
  id: number;
  title: string;
  description?: string;
  priority: 'high' | 'medium' | 'low';
  status: 'pending' | 'in_progress' | 'completed';
  deadline?: string;
  assigned_to?: number;
  assigned_to_name?: string;
  assigned_by?: number;
  assigned_by_name?: string;
  created_at?: string;
  updated_at?: string;
}

// ===== Expenses =====
export interface Expense {
  id: number;
  employee_id: number;
  employee_name?: string;
  category?: string;
  type: string;
  amount: number;
  expense_date: string;
  description?: string;
  bill_url?: string;
  bill_type?: string;
  status: 'pending' | 'approved' | 'rejected' | 'reimbursed';
  rejection_reason?: string;
  approved_by?: number;
  created_at?: string;
  updated_at?: string;
}

// ===== Advance Allocations =====
export interface AdvanceAllocation {
  id: number;
  amount: number;
  month: number;
  year: number;
  remarks?: string;
  allocated_by?: number;
  created_at?: string;
}

// ===== Helpdesk =====
export interface HelpdeskTicket {
  id: number;
  employee_id: number;
  employee_name?: string;
  category: 'IT' | 'HR' | 'Admin' | 'Facility' | 'Payroll' | 'Other';
  subject: string;
  description?: string;
  priority: 'high' | 'medium' | 'low';
  status: 'open' | 'in_progress' | 'resolved' | 'closed';
  resolution?: string;
  created_at?: string;
  updated_at?: string;
}

// ===== Unit Visit Checklist Master Data =====
export interface ChecklistCategory {
  id: number;
  name: string;
  display_order: number;
  is_active: boolean;
  items: ChecklistItem[];
}

export interface ChecklistItem {
  id: number;
  category_id: number;
  name: string;
  weight: number;
  display_order: number;
  is_active: boolean;
}

// ===== Unit Visits (Enhanced with Checklist) =====
export interface UnitVisit {
  id: number;
  employee_id: number;
  employee_name?: string;
  employee_code?: string;
  unit_id: number;
  unit_name?: string;
  client_name?: string;
  visit_number: 1 | 2;
  visit_month: number;
  visit_year: number;
  document_url: string;
  document_type: 'image' | 'pdf';
  notes?: string;
  status: 'submitted' | 'approved' | 'rejected';
  rejection_reason?: string;
  approved_by?: number;
  approved_at?: string;
  // Checklist data
  checklist_items?: VisitChecklistItem[];
  // Computed scores
  total_score?: number;
  max_score?: number;
  score_percent?: number;
  created_at?: string;
  updated_at?: string;
}

export interface VisitChecklistItem {
  id: number;
  visit_id: number;
  checklist_item_id: number;
  category_id: number;
  category_name?: string;
  item_name?: string;
  weight: number;
  status: 'yes' | 'no' | 'na';
  remarks?: string;
  photo_url?: string;
}

export interface VisitDashboardData {
  total_visits: number;
  pending_visits: number;
  approved_visits: number;
  rejected_visits: number;
  this_month_visits: number;
  this_month_score_avg: number;
  score_trend: { month: string; avg_score: number }[];
  category_scores: { category: string; avg_score: number }[];
  unit_compliance: { unit_name: string; score: number; visits: number }[];
}

// ===== Announcements =====
export interface Announcement {
  id: number;
  creator_name?: string;
  title: string;
  content: string;
  priority: 'urgent' | 'high' | 'normal' | 'low';
  target_scope: 'all' | 'unit' | 'city';
  target_id?: number;
  created_at?: string;
}

// ===== Filters =====
export interface ClientOption {
  id: number;
  name: string;
}

export interface UnitOption {
  id: number;
  name: string;
  client_id?: number;
}

// ===== Paginated Response =====
export interface PaginatedResponse<T> {
  items: T[];
  pagination: {
    page: number;
    limit: number;
    total: number;
    total_pages?: number;
  };
}

// ===== Enum Labels =====
export const LEAVE_TYPES = [
  { value: 'CL', label: 'Casual Leave' },
  { value: 'SL', label: 'Sick Leave' },
  { value: 'EL', label: 'Earned Leave' },
  { value: 'WFH', label: 'Work From Home' },
  { value: 'Comp_Off', label: 'Compensatory Off' },
  { value: 'LWP', label: 'Leave Without Pay' },
] as const;

export const EXPENSE_TYPES = [
  { value: 'advance', label: 'Advance' },
  { value: 'expense', label: 'Expense' },
] as const;

export const HELPDESK_CATEGORIES = [
  { value: 'IT', label: 'IT Support' },
  { value: 'HR', label: 'HR Query' },
  { value: 'Admin', label: 'Administration' },
  { value: 'Facility', label: 'Facility' },
  { value: 'Payroll', label: 'Payroll' },
  { value: 'Other', label: 'Other' },
] as const;

export const TASK_PRIORITIES = [
  { value: 'high', label: 'High' },
  { value: 'medium', label: 'Medium' },
  { value: 'low', label: 'Low' },
] as const;

export const TASK_STATUSES = ['pending', 'in_progress', 'completed'] as const;
export const LEAVE_STATUSES = ['pending', 'approved', 'rejected', 'cancelled'] as const;
export const EXPENSE_STATUSES = ['pending', 'approved', 'rejected', 'reimbursed'] as const;
export const HELPDESK_STATUSES = ['open', 'in_progress', 'resolved', 'closed'] as const;

// ===== Manpower Status =====
export interface ManpowerShiftData {
  worker_budget: number;
  worker_actual: number;
  supervisor_budget: number;
  supervisor_actual: number;
  total_budget: number;
  total_actual: number;
  shortage: number;
}

export interface ManpowerEntry {
  id: number;
  employee_id: number;
  unit_id: number;
  client_id: number;
  unit_name: string;
  client_name: string;
  report_date: string;
  morning: ManpowerShiftData;
  evening: ManpowerShiftData;
  total_budget: number;
  total_actual: number;
  overall_shortage: number;
  remarks: string;
  created_at?: string;
  updated_at?: string;
}

export interface ManpowerDashboardUnit {
  unit_id: number;
  unit_name: string;
  client_name: string;
  client_id: number;
  days_reported: number;
  morning: { total_budget: number; total_actual: number; shortage: number };
  evening: { total_budget: number; total_actual: number; shortage: number };
  total_budget: number;
  total_actual: number;
  shortage: number;
}

export interface ManpowerDailyBreakdown {
  date: string;
  total_budget: number;
  total_actual: number;
  shortage: number;
}

export interface ManpowerDashboardData {
  period: 'daily' | 'weekly' | 'monthly' | 'yearly';
  label: string;
  start_date: string;
  end_date: string;
  total_units: number;
  days_in_period: number;
  days_reported: number;
  grand_total: { total_budget: number; total_actual: number; shortage: number };
  units: ManpowerDashboardUnit[];
  daily_breakdown: ManpowerDailyBreakdown[];
}
