export type FieldEditRule = 'free' | 'admin_approval' | 'readonly' | 'free_if_blank';

export interface FieldRule {
  key: string;
  label: string;
  section: string;
  rule: FieldEditRule;
  inputType?: 'text' | 'email' | 'select' | 'date' | 'textarea' | 'tel' | 'photo';
  options?: string[];
  masked?: boolean;
}

// Relationship options — match the employee registration form (hrms/modules/employee/add.php)
// Emergency contact also includes 'Other'; 'Spouse' added for backward compat with portal form
const EMERGENCY_RELATIONSHIP_OPTIONS = ['Father', 'Mother', 'Husband', 'Wife', 'Son', 'Daughter', 'Brother', 'Sister', 'Spouse', 'Other'];
const NOMINEE_RELATIONSHIP_OPTIONS = ['Father', 'Mother', 'Husband', 'Wife', 'Son', 'Daughter', 'Brother', 'Sister', 'Spouse'];

export const FIELD_RULES: FieldRule[] = [
  // ── ADMIN APPROVAL (require HR to approve) ──
  { key: 'full_name', label: 'Full Name', section: 'personal', rule: 'admin_approval', inputType: 'text' },
  { key: 'father_name', label: "Father's/Husband's Name", section: 'personal', rule: 'admin_approval', inputType: 'text' },
  { key: 'date_of_birth', label: 'Date of Birth', section: 'personal', rule: 'admin_approval', inputType: 'date' },
  { key: 'gender', label: 'Gender', section: 'personal', rule: 'admin_approval', inputType: 'select', options: ['Male', 'Female', 'Other'] },
  // designation and department are HR-managed, not editable by employee
  { key: 'profile_pic_url', label: 'Profile Photo', section: 'personal', rule: 'admin_approval', inputType: 'photo' },

  // ── FREELY EDITABLE (saved directly, no approval needed) ──
  { key: 'email', label: 'Email', section: 'personal', rule: 'free', inputType: 'email' },
  { key: 'blood_group', label: 'Blood Group', section: 'personal', rule: 'free', inputType: 'select', options: ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] },
  { key: 'marital_status', label: 'Marital Status', section: 'personal', rule: 'free', inputType: 'select', options: ['Single', 'Married', 'Divorced', 'Widowed'] },
  { key: 'address', label: 'Address', section: 'address', rule: 'free', inputType: 'textarea' },
  { key: 'pin_code', label: 'PIN Code', section: 'address', rule: 'free', inputType: 'text' },
  { key: 'district', label: 'District', section: 'address', rule: 'free', inputType: 'text' },
  { key: 'state', label: 'State', section: 'address', rule: 'free', inputType: 'text' },
  { key: 'emergency_contact_name', label: 'Emergency Contact Name', section: 'emergency', rule: 'free', inputType: 'text' },
  { key: 'emergency_contact_relation', label: 'Relationship', section: 'emergency', rule: 'free', inputType: 'select', options: EMERGENCY_RELATIONSHIP_OPTIONS },
  { key: 'nominee_name', label: 'Nominee Name', section: 'nominee', rule: 'free', inputType: 'text' },
  { key: 'nominee_relationship', label: 'Relationship', section: 'nominee', rule: 'free', inputType: 'select', options: NOMINEE_RELATIONSHIP_OPTIONS },
  { key: 'nominee_dob', label: 'Nominee DOB', section: 'nominee', rule: 'free', inputType: 'date' },
  { key: 'nominee_contact', label: 'Nominee Contact', section: 'nominee', rule: 'free', inputType: 'tel' },

  // ── SENSITIVE — FILL IF BLANK (manager can fill directly while blank;
  //    once a value exists, editing ALWAYS requires HR approval) ──
  { key: 'uan_number', label: 'UAN Number', section: 'sensitive', rule: 'free_if_blank', inputType: 'text' },
  { key: 'esic_number', label: 'ESIC Number', section: 'sensitive', rule: 'free_if_blank', inputType: 'text' },
  { key: 'aadhaar_number', label: 'Aadhaar Number', section: 'sensitive', rule: 'free_if_blank', inputType: 'text' },
  { key: 'bank_name', label: 'Bank Name', section: 'sensitive', rule: 'free_if_blank', inputType: 'text' },
  { key: 'account_holder_name', label: 'Account Holder Name', section: 'sensitive', rule: 'free_if_blank', inputType: 'text' },
  { key: 'account_number', label: 'Account Number', section: 'sensitive', rule: 'free_if_blank', inputType: 'text' },
  { key: 'ifsc_code', label: 'IFSC Code', section: 'sensitive', rule: 'free_if_blank', inputType: 'text' },
];

// Only the sections that have editable fields (no employment, etc.)
export const FIELD_SECTIONS = [
  { key: 'personal', label: 'Personal Details', icon: 'User' },
  { key: 'address', label: 'Address', icon: 'MapPin' },
  { key: 'emergency', label: 'Emergency Contact', icon: 'Phone' },
  { key: 'nominee', label: 'Nominee Details', icon: 'UserCheck' },
  { key: 'sensitive', label: 'Bank & Statutory Details', icon: 'CreditCard' },
];

/**
 * Resolve the effective edit rule for a field, given the employee's CURRENT value.
 *
 * Must be computed per field, per employee (never cached globally) — the same
 * field can behave differently for two different employees depending on whether
 * each one's value is set:
 *   - 'readonly'       → always readonly
 *   - 'free'           → always saves directly
 *   - 'free_if_blank'  → blank value saves directly; a filled value ALWAYS
 *                        requires approval (nothing-to-overwrite = no risk)
 *   - 'admin_approval' → always requires approval, regardless of blank/filled
 */
export function resolveEffectiveRule(
  rule: FieldEditRule,
  currentValue: string | null | undefined,
): 'free' | 'admin_approval' | 'readonly' {
  if (rule === 'readonly') return 'readonly';
  const isBlank = !currentValue || currentValue.trim() === '';
  if (rule === 'free') return 'free';
  if (rule === 'free_if_blank') {
    return isBlank ? 'free' : 'admin_approval';
  }
  // rule === 'admin_approval' — always needs approval regardless of blank/filled
  return 'admin_approval';
}

export function getFieldsBySection(section: string): FieldRule[] {
  return FIELD_RULES.filter(f => f.section === section);
}
