export type FieldEditRule = 'free' | 'admin_approval' | 'readonly';

export interface FieldRule {
  key: string;
  label: string;
  section: string;
  rule: FieldEditRule;
  inputType?: 'text' | 'email' | 'select' | 'date' | 'textarea' | 'tel';
  options?: string[];
  masked?: boolean;
}

export const FIELD_RULES: FieldRule[] = [
  // READONLY
  { key: 'employee_code', label: 'Employee Code', section: 'employment', rule: 'readonly' },
  { key: 'mobile_number', label: 'Mobile Number', section: 'personal', rule: 'readonly' },
  { key: 'bank_name', label: 'Bank Name', section: 'bank', rule: 'readonly' },
  { key: 'account_number', label: 'Account Number', section: 'bank', rule: 'readonly', masked: true },
  { key: 'ifsc_code', label: 'IFSC Code', section: 'bank', rule: 'readonly' },
  { key: 'account_holder_name', label: 'Account Holder', section: 'bank', rule: 'readonly' },
  { key: 'date_of_joining', label: 'Date of Joining', section: 'employment', rule: 'readonly' },
  { key: 'date_of_leaving', label: 'Date of Leaving', section: 'employment', rule: 'readonly' },
  { key: 'client_name', label: 'Client', section: 'employment', rule: 'readonly' },
  { key: 'unit_name', label: 'Unit / Location', section: 'employment', rule: 'readonly' },
  { key: 'uan_number', label: 'UAN Number', section: 'employment', rule: 'readonly' },
  { key: 'esic_number', label: 'ESIC IP Number', section: 'employment', rule: 'readonly' },
  { key: 'pf_number', label: 'PF Number', section: 'employment', rule: 'readonly' },
  // ADMIN APPROVAL
  { key: 'full_name', label: 'Full Name', section: 'personal', rule: 'admin_approval', inputType: 'text' },
  { key: 'father_name', label: "Father's/Husband's Name", section: 'personal', rule: 'admin_approval', inputType: 'text' },
  { key: 'date_of_birth', label: 'Date of Birth', section: 'personal', rule: 'admin_approval', inputType: 'date' },
  { key: 'gender', label: 'Gender', section: 'personal', rule: 'admin_approval', inputType: 'select', options: ['Male', 'Female', 'Other'] },
  { key: 'designation', label: 'Designation', section: 'employment', rule: 'admin_approval', inputType: 'text' },
  { key: 'department', label: 'Department', section: 'employment', rule: 'admin_approval', inputType: 'text' },
  // FREELY EDITABLE
  { key: 'email', label: 'Email', section: 'personal', rule: 'free', inputType: 'email' },
  { key: 'blood_group', label: 'Blood Group', section: 'personal', rule: 'free', inputType: 'select', options: ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] },
  { key: 'marital_status', label: 'Marital Status', section: 'personal', rule: 'free', inputType: 'select', options: ['Single', 'Married', 'Divorced', 'Widowed'] },
  { key: 'address', label: 'Address', section: 'address', rule: 'free', inputType: 'textarea' },
  { key: 'pin_code', label: 'PIN Code', section: 'address', rule: 'free', inputType: 'text' },
  { key: 'district', label: 'District', section: 'address', rule: 'free', inputType: 'text' },
  { key: 'state', label: 'State', section: 'address', rule: 'free', inputType: 'text' },
  { key: 'emergency_contact_name', label: 'Contact Name', section: 'emergency', rule: 'free', inputType: 'text' },
  { key: 'emergency_contact_relation', label: 'Relationship', section: 'emergency', rule: 'free', inputType: 'select', options: ['Spouse', 'Parent', 'Sibling', 'Friend', 'Other'] },
  { key: 'nominee_name', label: 'Nominee Name', section: 'nominee', rule: 'free', inputType: 'text' },
  { key: 'nominee_relationship', label: 'Relationship', section: 'nominee', rule: 'free', inputType: 'select', options: ['Spouse', 'Parent', 'Sibling', 'Child', 'Other'] },
  { key: 'nominee_dob', label: 'Nominee DOB', section: 'nominee', rule: 'free', inputType: 'date' },
  { key: 'nominee_contact', label: 'Nominee Contact', section: 'nominee', rule: 'free', inputType: 'tel' },
];

export const FIELD_SECTIONS = [
  { key: 'personal', label: 'Personal Details', icon: 'User' },
  { key: 'employment', label: 'Employment Details', icon: 'Building2' },
  { key: 'address', label: 'Address', icon: 'MapPin' },
  { key: 'bank', label: 'Bank Details', icon: 'CreditCard' },
  { key: 'emergency', label: 'Emergency Contact', icon: 'Phone' },
  { key: 'nominee', label: 'Nominee Details', icon: 'UserCheck' },
];

export function getFieldsBySection(section: string): FieldRule[] {
  return FIELD_RULES.filter(f => f.section === section);
}
