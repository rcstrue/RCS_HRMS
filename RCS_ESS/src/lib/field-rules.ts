export type FieldEditRule = 'free' | 'admin_approval' | 'readonly';

export interface FieldRule {
  key: string;
  label: string;
  section: string;
  rule: FieldEditRule;
  inputType?: 'text' | 'email' | 'select' | 'date' | 'textarea' | 'tel' | 'photo';
  options?: string[];
  masked?: boolean;
}

// Relationship options — MUST match the registration form (types/registration.ts)
const RELATIONSHIP_OPTIONS = ['Father', 'Mother', 'Husband', 'Wife', 'Son', 'Daughter', 'Brother', 'Sister'];

export const FIELD_RULES: FieldRule[] = [
  // ── ADMIN APPROVAL (require HR to approve) ──
  { key: 'full_name', label: 'Full Name', section: 'personal', rule: 'admin_approval', inputType: 'text' },
  { key: 'father_name', label: "Father's/Husband's Name", section: 'personal', rule: 'admin_approval', inputType: 'text' },
  { key: 'date_of_birth', label: 'Date of Birth', section: 'personal', rule: 'admin_approval', inputType: 'date' },
  { key: 'gender', label: 'Gender', section: 'personal', rule: 'admin_approval', inputType: 'select', options: ['Male', 'Female', 'Other'] },
  { key: 'designation', label: 'Designation', section: 'employment', rule: 'admin_approval', inputType: 'text' },
  { key: 'department', label: 'Department', section: 'employment', rule: 'admin_approval', inputType: 'text' },
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
  { key: 'emergency_contact_relation', label: 'Relationship', section: 'emergency', rule: 'free', inputType: 'select', options: RELATIONSHIP_OPTIONS },
  { key: 'nominee_name', label: 'Nominee Name', section: 'nominee', rule: 'free', inputType: 'text' },
  { key: 'nominee_relationship', label: 'Relationship', section: 'nominee', rule: 'free', inputType: 'select', options: RELATIONSHIP_OPTIONS },
  { key: 'nominee_dob', label: 'Nominee DOB', section: 'nominee', rule: 'free', inputType: 'date' },
  { key: 'nominee_contact', label: 'Nominee Contact', section: 'nominee', rule: 'free', inputType: 'tel' },
];

// Only the sections that have editable fields (no bank, no pure-readonly employment)
export const FIELD_SECTIONS = [
  { key: 'personal', label: 'Personal Details', icon: 'User' },
  { key: 'employment', label: 'Employment Details', icon: 'Briefcase' },
  { key: 'address', label: 'Address', icon: 'MapPin' },
  { key: 'emergency', label: 'Emergency Contact', icon: 'Phone' },
  { key: 'nominee', label: 'Nominee Details', icon: 'UserCheck' },
];

export function getFieldsBySection(section: string): FieldRule[] {
  return FIELD_RULES.filter(f => f.section === section);
}
