'use client';

import { useState, useMemo, useEffect } from 'react';
import { toast } from 'sonner';
import {
  Loader2,
  Save,
  X,
  Lock,
  Send,
  Clock,
  User,
  MapPin,
  Phone,
  UserCheck,
  Briefcase,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  FIELD_RULES,
  FIELD_SECTIONS,
  type FieldRule,
} from '@/lib/field-rules';
import type { Employee, ChangeRequest } from '@/lib/ess-types';
import PageHeader from './PageHeader';

// ══════════════════════════════════════════════════════════════
// EditProfilePage Component
// ══════════════════════════════════════════════════════════════

interface EditProfilePageProps {
  employee: Employee;
  pendingChangeRequests?: ChangeRequest[];
  onSaveFreeFields: (fields: Record<string, string | null>) => Promise<{ success: boolean; error?: string }>;
  onSubmitChangeRequest: (data: { field_name: string; old_value: string; new_value: string; reason?: string }) => Promise<{ success: boolean; error?: string }>;
  onBack: () => void;
}

// Section icon mapping
const SECTION_ICONS: Record<string, React.ElementType> = {
  personal: User,
  employment: Briefcase,
  address: MapPin,
  bank: Lock,
  emergency: Phone,
  nominee: UserCheck,
};

export default function EditProfilePage({
  employee,
  pendingChangeRequests = [],
  onSaveFreeFields,
  onSubmitChangeRequest,
  onBack,
}: EditProfilePageProps) {
  const [saving, setSaving] = useState(false);
  const [freeForm, setFreeForm] = useState<Record<string, string>>({});
  const [approvalForms, setApprovalForms] = useState<Record<string, string>>({});
  const [reasonForms, setReasonForms] = useState<Record<string, string>>({});
  const [showReason, setShowReason] = useState<Record<string, boolean>>({});
  const [submittingField, setSubmittingField] = useState<string | null>(null);

  // Categorize fields by rule
  const { freeFields, approvalFields, readonlyFields } = useMemo(() => {
    const free: FieldRule[] = [];
    const approval: FieldRule[] = [];
    const readonly: FieldRule[] = [];
    for (const f of FIELD_RULES) {
      if (f.rule === 'free') free.push(f);
      else if (f.rule === 'admin_approval') approval.push(f);
      else readonly.push(f);
    }
    return { freeFields: free, approvalFields: approval, readonlyFields: readonly };
  }, []);

  // Build a set of field names that have pending change requests
  const pendingFieldNames = useMemo(() => {
    const set = new Set<string>();
    for (const r of pendingChangeRequests) {
      if (r.status === 'pending') set.add(r.field_name);
    }
    return set;
  }, [pendingChangeRequests]);

  // Initialize free form on mount
  useEffect(() => {
    const initial: Record<string, string> = {};
    for (const f of freeFields) {
      const val = (employee as unknown as Record<string, unknown>)[f.key];
      initial[f.key] = (val as string) || '';
    }
    setFreeForm(initial);
  }, [employee, freeFields]);

  // Get current employee value for a field
  const getValue = (key: string): string => {
    const val = (employee as unknown as Record<string, unknown>)[key];
    return (val as string) || '';
  };

  // Group fields by section
  const groupBySection = (fields: FieldRule[]): Record<string, FieldRule[]> => {
    const groups: Record<string, FieldRule[]> = {};
    for (const f of fields) {
      if (!groups[f.section]) groups[f.section] = [];
      groups[f.section].push(f);
    }
    return groups;
  };

  const freeBySection = groupBySection(freeFields);
  const approvalBySection = groupBySection(approvalFields);
  const readonlyBySection = groupBySection(readonlyFields);

  // Handle free field input change
  const updateFreeField = (key: string, value: string) => {
    setFreeForm(prev => ({ ...prev, [key]: value }));
  };

  // Handle approval field input change
  const updateApprovalField = (key: string, value: string) => {
    setApprovalForms(prev => ({ ...prev, [key]: value }));
  };

  // Save free fields
  const handleSave = async () => {
    setSaving(true);
    try {
      // Build the fields object, only including changed values
      const changed: Record<string, string | null> = {};
      for (const f of freeFields) {
        const current = getValue(f.key);
        const newVal = freeForm[f.key] || '';
        if (newVal !== current) {
          changed[f.key] = newVal || null;
        }
      }

      if (Object.keys(changed).length === 0) {
        toast.info('No changes to save.');
        setSaving(false);
        return;
      }

      const result = await onSaveFreeFields(changed);
      if (result.success) {
        toast.success('Profile updated successfully');
        onBack();
      } else {
        toast.error(result.error || 'Failed to save changes');
      }
    } catch {
      toast.error('An unexpected error occurred');
    } finally {
      setSaving(false);
    }
  };

  // Submit change request for admin_approval field
  const handleSubmitRequest = async (field: FieldRule) => {
    const newVal = approvalForms[field.key];
    if (!newVal || newVal === getValue(field.key)) {
      toast.info('Please enter a different value to request a change.');
      return;
    }

    setSubmittingField(field.key);
    try {
      const result = await onSubmitChangeRequest({
        field_name: field.key,
        old_value: getValue(field.key),
        new_value: newVal,
        reason: reasonForms[field.key] || undefined,
      });
      if (result.success) {
        toast.success(`Change request submitted for ${field.label}`);
        setApprovalForms(prev => {
          const next = { ...prev };
          delete next[field.key];
          return next;
        });
        setReasonForms(prev => {
          const next = { ...prev };
          delete next[field.key];
          return next;
        });
        setShowReason(prev => ({ ...prev, [field.key]: false }));
      } else {
        toast.error(result.error || 'Failed to submit change request');
      }
    } catch {
      toast.error('An unexpected error occurred');
    } finally {
      setSubmittingField(null);
    }
  };

  // Render input based on field type
  const renderInput = (
    field: FieldRule,
    value: string,
    onChange: (v: string) => void,
    disabled: boolean,
  ) => {
    const inputId = `field-${field.key}`;

    if (field.inputType === 'select' && field.options) {
      return (
        <Select value={value} onValueChange={onChange} disabled={disabled}>
          <SelectTrigger id={inputId}>
            <SelectValue placeholder={`Select ${field.label.toLowerCase()}`} />
          </SelectTrigger>
          <SelectContent>
            {field.options.map(opt => (
              <SelectItem key={opt} value={opt}>{opt}</SelectItem>
            ))}
          </SelectContent>
        </Select>
      );
    }

    if (field.inputType === 'textarea') {
      return (
        <Textarea
          id={inputId}
          placeholder={`Enter ${field.label.toLowerCase()}`}
          value={value}
          onChange={e => onChange(e.target.value)}
          rows={3}
          disabled={disabled}
        />
      );
    }

    if (field.inputType === 'date') {
      return (
        <Input
          id={inputId}
          type="date"
          value={value}
          onChange={e => onChange(e.target.value)}
          disabled={disabled}
        />
      );
    }

    if (field.inputType === 'tel') {
      return (
        <Input
          id={inputId}
          type="tel"
          placeholder={`Enter ${field.label.toLowerCase()}`}
          value={value}
          onChange={e => onChange(e.target.value)}
          disabled={disabled}
        />
      );
    }

    if (field.inputType === 'email') {
      return (
        <Input
          id={inputId}
          type="email"
          placeholder={`Enter ${field.label.toLowerCase()}`}
          value={value}
          onChange={e => onChange(e.target.value)}
          disabled={disabled}
        />
      );
    }

    // Default: text
    return (
      <Input
        id={inputId}
        type="text"
        placeholder={`Enter ${field.label.toLowerCase()}`}
        value={value}
        onChange={e => onChange(e.target.value)}
        disabled={disabled}
      />
    );
  };

  // Render a section card for free fields
  const renderFreeSection = (sectionKey: string, fields: FieldRule[]) => {
    const sectionDef = FIELD_SECTIONS.find(s => s.key === sectionKey);
    if (!sectionDef) return null;
    const Icon = SECTION_ICONS[sectionKey] || User;

    return (
      <Card key={sectionKey} className="border-0 shadow-sm">
        <CardContent className="p-4 space-y-4">
          <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wider flex items-center gap-2">
            <Icon className="w-4 h-4" />
            {sectionDef.label}
          </h3>
          {fields.map(field => (
            <div key={field.key} className="space-y-2">
              <Label htmlFor={`field-${field.key}`}>{field.label}</Label>
              {renderInput(field, freeForm[field.key] || '', (v) => updateFreeField(field.key, v), false)}
            </div>
          ))}
        </CardContent>
      </Card>
    );
  };

  // Render a section card for approval fields
  const renderApprovalSection = (sectionKey: string, fields: FieldRule[]) => {
    const sectionDef = FIELD_SECTIONS.find(s => s.key === sectionKey);
    if (!sectionDef) return null;
    const Icon = SECTION_ICONS[sectionKey] || User;

    return (
      <Card key={`approval-${sectionKey}`} className="border-0 shadow-sm">
        <CardContent className="p-4 space-y-4">
          <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wider flex items-center gap-2">
            <Icon className="w-4 h-4" />
            {sectionDef.label}
            <Badge variant="outline" className="bg-amber-50 text-amber-600 border-amber-200 text-[10px] px-1.5 py-0 ml-auto">
              Needs Approval
            </Badge>
          </h3>
          {fields.map(field => {
            const hasPending = pendingFieldNames.has(field.key);
            const isSubmitting = submittingField === field.key;
            const isShowingReason = showReason[field.key];

            return (
              <div key={field.key} className="space-y-2 border border-gray-100 rounded-lg p-3">
                <div className="flex items-center justify-between">
                  <Label htmlFor={`approval-${field.key}`} className="text-sm">{field.label}</Label>
                  {hasPending && (
                    <Badge variant="outline" className="bg-amber-50 text-amber-600 border-amber-200 text-[10px] px-1.5 py-0">
                      <Clock className="w-3 h-3 mr-0.5" />
                      Pending Approval
                    </Badge>
                  )}
                </div>

                {/* Current value (read-only) */}
                <div className="text-xs text-gray-400">Current value:</div>
                <Input
                  value={getValue(field.key)}
                  disabled
                  className="bg-gray-50 text-gray-600 text-sm"
                />

                {/* New value input */}
                <div className="text-xs text-gray-400">New value:</div>
                {renderInput(
                  field,
                  approvalForms[field.key] || '',
                  (v) => updateApprovalField(field.key, v),
                  hasPending,
                )}

                {/* Action buttons */}
                {!hasPending && (
                  <>
                    {!isShowingReason ? (
                      <Button
                        variant="outline"
                        size="sm"
                        className="w-full mt-1"
                        onClick={() => setShowReason(prev => ({ ...prev, [field.key]: true }))}
                        disabled={!approvalForms[field.key] || approvalForms[field.key] === getValue(field.key)}
                      >
                        <Send className="w-3.5 h-3.5 mr-1.5" />
                        Request Change
                      </Button>
                    ) : (
                      <div className="space-y-2">
                        <Textarea
                          placeholder="Reason for change (optional)"
                          value={reasonForms[field.key] || ''}
                          onChange={e => setReasonForms(prev => ({ ...prev, [field.key]: e.target.value }))}
                          rows={2}
                        />
                        <div className="flex gap-2">
                          <Button
                            variant="outline"
                            size="sm"
                            className="flex-1"
                            onClick={() => setShowReason(prev => ({ ...prev, [field.key]: false }))}
                          >
                            <X className="w-3.5 h-3.5 mr-1" />
                            Cancel
                          </Button>
                          <Button
                            size="sm"
                            className="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white"
                            onClick={() => handleSubmitRequest(field)}
                            disabled={isSubmitting}
                          >
                            {isSubmitting ? (
                              <Loader2 className="w-3.5 h-3.5 animate-spin mr-1" />
                            ) : (
                              <Send className="w-3.5 h-3.5 mr-1" />
                            )}
                            Submit Request
                          </Button>
                        </div>
                      </div>
                    )}
                  </>
                )}
              </div>
            );
          })}
        </CardContent>
      </Card>
    );
  };

  // Render a section card for readonly fields
  const renderReadonlySection = (sectionKey: string, fields: FieldRule[]) => {
    const sectionDef = FIELD_SECTIONS.find(s => s.key === sectionKey);
    if (!sectionDef) return null;
    const Icon = SECTION_ICONS[sectionKey] || Lock;

    return (
      <Card key={`readonly-${sectionKey}`} className="border-0 shadow-sm">
        <CardContent className="p-4 space-y-2">
          <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wider flex items-center gap-2">
            <Icon className="w-4 h-4" />
            {sectionDef.label}
            <Badge variant="outline" className="bg-gray-100 text-gray-500 border-gray-200 text-[10px] px-1.5 py-0 ml-auto">
              <Lock className="w-3 h-3 mr-0.5" />
              Read-only
            </Badge>
          </h3>
          {fields.map(field => {
            const val = getValue(field.key);
            const displayVal = field.masked && val
              ? val.length > 8
                ? val.slice(0, 4) + ' **** ' + val.slice(-4)
                : '****'
              : val;

            return (
              <div key={field.key} className="flex items-center justify-between py-2 border-b border-gray-50 last:border-0">
                <span className="text-sm text-gray-500">{field.label}</span>
                <span className="text-sm font-medium text-gray-800 truncate max-w-[200px]">
                  {displayVal || '—'}
                </span>
              </div>
            );
          })}
          <p className="text-xs text-gray-400 pt-1">Contact admin to update these fields.</p>
        </CardContent>
      </Card>
    );
  };

  // ── Order sections as per FIELD_SECTIONS ───────────────────
  const orderedSections = FIELD_SECTIONS.map(s => s.key);

  // Sections that have free fields
  const freeSections = orderedSections.filter(s => freeBySection[s]?.length);
  // Sections that have approval fields
  const approvalSections = orderedSections.filter(s => approvalBySection[s]?.length);
  // Sections that have readonly fields
  const readonlySections = orderedSections.filter(s => readonlyBySection[s]?.length);

  return (
    <div className="space-y-4 pb-6">
      <PageHeader title="Edit Profile" subtitle="Update your information" onBack={onBack} />

      {/* ── Section 1: Freely Editable Fields ────────────────── */}
      {freeSections.length > 0 && (
        <>
          <div className="flex items-center gap-2 mb-1">
            <div className="w-2 h-2 rounded-full bg-emerald-500" />
            <h2 className="text-base font-semibold text-gray-800">Editable Fields</h2>
            <span className="text-xs text-gray-400">(Save to update)</span>
          </div>
          <div className="space-y-3">
            {freeSections.map(section => freeBySection[section] && renderFreeSection(section, freeBySection[section]))}
          </div>
        </>
      )}

      {/* ── Section 2: Admin Approval Fields ─────────────────── */}
      {approvalSections.length > 0 && (
        <>
          <div className="flex items-center gap-2 mb-1 pt-2">
            <div className="w-2 h-2 rounded-full bg-amber-400" />
            <h2 className="text-base font-semibold text-gray-800">Fields Needing Approval</h2>
            <span className="text-xs text-gray-400">(Request change)</span>
          </div>
          <div className="space-y-3">
            {approvalSections.map(section => approvalBySection[section] && renderApprovalSection(section, approvalBySection[section]))}
          </div>
        </>
      )}

      {/* ── Section 3: Read-Only Fields ──────────────────────── */}
      {readonlySections.length > 0 && (
        <>
          <div className="flex items-center gap-2 mb-1 pt-2">
            <Lock className="w-3.5 h-3.5 text-gray-400" />
            <h2 className="text-base font-semibold text-gray-800">Read-Only Fields</h2>
          </div>
          <div className="space-y-3">
            {readonlySections.map(section => readonlyBySection[section] && renderReadonlySection(section, readonlyBySection[section]))}
          </div>
        </>
      )}

      {/* ── Bottom Buttons ────────────────────────────────────── */}
      <div className="flex gap-3 pt-2">
        <Button
          variant="outline"
          className="flex-1"
          onClick={onBack}
          disabled={saving}
        >
          <X className="w-4 h-4 mr-1.5" />
          Cancel
        </Button>
        <Button
          className="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white"
          onClick={handleSave}
          disabled={saving}
        >
          {saving ? (
            <Loader2 className="w-4 h-4 animate-spin mr-1.5" />
          ) : (
            <Save className="w-4 h-4 mr-1.5" />
          )}
          {saving ? 'Saving...' : 'Save Changes'}
        </Button>
      </div>
    </div>
  );
}
