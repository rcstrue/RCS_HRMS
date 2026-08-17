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
  CreditCard,
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
// EditProfilePage — Simple pre-filled form grouped by section
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
  bank: CreditCard,
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
  // All editable field values (free + admin_approval new values)
  const [formValues, setFormValues] = useState<Record<string, string>>({});
  // Reason fields for admin_approval change requests
  const [reasonForms, setReasonForms] = useState<Record<string, string>>({});
  // Which approval fields are showing the reason textarea
  const [showReason, setShowReason] = useState<Record<string, boolean>>({});
  // Which field is currently submitting a change request
  const [submittingField, setSubmittingField] = useState<string | null>(null);

  // Build a set of field names that have pending change requests
  const pendingFieldNames = useMemo(() => {
    const set = new Set<string>();
    for (const r of pendingChangeRequests) {
      if (r.status === 'pending') set.add(r.field_name);
    }
    return set;
  }, [pendingChangeRequests]);

  // Get current employee value for a field
  const getValue = (key: string): string => {
    const val = (employee as unknown as Record<string, unknown>)[key];
    return (val as string) || '';
  };

  // Initialize form with current values for all editable (free + admin_approval) fields
  useEffect(() => {
    const initial: Record<string, string> = {};
    for (const f of FIELD_RULES) {
      if (f.rule === 'free' || f.rule === 'admin_approval') {
        initial[f.key] = getValue(f.key);
      }
    }
    setFormValues(initial);
  }, [employee]); // eslint-disable-line react-hooks/exhaustive-deps

  // Group fields by section, preserving FIELD_SECTIONS order
  const sectionsWithFields = useMemo(() => {
    return FIELD_SECTIONS.map(sectionDef => {
      const fields = FIELD_RULES.filter(f => f.section === sectionDef.key);
      return { ...sectionDef, fields };
    }).filter(s => s.fields.length > 0);
  }, []);

  // Handle input change
  const updateField = (key: string, value: string) => {
    setFormValues(prev => ({ ...prev, [key]: value }));
  };

  // Save all free fields
  const handleSave = async () => {
    setSaving(true);
    try {
      const freeFields = FIELD_RULES.filter(f => f.rule === 'free');
      const changed: Record<string, string | null> = {};
      for (const f of freeFields) {
        const current = getValue(f.key);
        const newVal = formValues[f.key] || '';
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

  // Submit change request for an admin_approval field
  const handleSubmitRequest = async (field: FieldRule) => {
    const newVal = formValues[field.key];
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
        // Reset field back to current value (pre-filled)
        setFormValues(prev => ({ ...prev, [field.key]: getValue(field.key) }));
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
      // Only pass a valid option value; otherwise undefined (uncontrolled → shows placeholder)
      const validValue = (value && field.options.includes(value)) ? value : undefined;
      return (
        <Select value={validValue} onValueChange={onChange} disabled={disabled}>
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

  // Render a single field row
  const renderField = (field: FieldRule) => {
    const hasPending = pendingFieldNames.has(field.key);
    const isSubmitting = submittingField === field.key;
    const isShowingReason = showReason[field.key];

    if (field.rule === 'readonly') {
      // Read-only field: show current value in a disabled input
      const val = getValue(field.key);
      const displayVal = field.masked && val
        ? val.length > 8
          ? val.slice(0, 4) + ' **** ' + val.slice(-4)
          : '****'
        : val;

      return (
        <div key={field.key} className="flex items-center justify-between py-2.5 border-b border-gray-50 last:border-0">
          <div className="flex items-center gap-2">
            <Lock className="w-3.5 h-3.5 text-gray-300 shrink-0" />
            <span className="text-sm text-gray-500">{field.label}</span>
          </div>
          <span className="text-sm font-medium text-gray-700 truncate max-w-[200px]">
            {displayVal || '—'}
          </span>
        </div>
      );
    }

    if (field.rule === 'admin_approval') {
      // Admin approval field: show current value + new value input + request change
      return (
        <div key={field.key} className="space-y-2 border border-amber-100 rounded-lg p-3 bg-amber-50/30">
          <div className="flex items-center justify-between">
            <Label htmlFor={`approval-${field.key}`} className="text-sm font-medium">{field.label}</Label>
            {hasPending && (
              <Badge variant="outline" className="bg-amber-100 text-amber-600 border-amber-200 text-[10px] px-1.5 py-0">
                <Clock className="w-3 h-3 mr-0.5" />
                Pending
              </Badge>
            )}
          </div>

          {/* Current value (read-only) */}
          <p className="text-xs text-gray-400">Current: <span className="font-medium text-gray-600">{getValue(field.key) || '—'}</span></p>

          {/* New value input */}
          {renderInput(
            field,
            formValues[field.key] || '',
            (v) => updateField(field.key, v),
            hasPending,
          )}

          {/* Action buttons */}
          {!hasPending && (
            <>
              {!isShowingReason ? (
                <Button
                  variant="outline"
                  size="sm"
                  className="w-full border-amber-200 text-amber-700 hover:bg-amber-100"
                  onClick={() => setShowReason(prev => ({ ...prev, [field.key]: true }))}
                  disabled={!formValues[field.key] || formValues[field.key] === getValue(field.key)}
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
                      Submit
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </div>
      );
    }

    // Free field: directly editable
    return (
      <div key={field.key} className="space-y-1.5">
        <Label htmlFor={`field-${field.key}`}>{field.label}</Label>
        {renderInput(field, formValues[field.key] || '', (v) => updateField(field.key, v), false)}
      </div>
    );
  };

  return (
    <div className="space-y-4 pb-6">
      <PageHeader title="Edit Profile" subtitle="Update your information" onBack={onBack} />

      {/* Render each section as a card */}
      {sectionsWithFields.map(section => {
        const Icon = SECTION_ICONS[section.key] || User;
        const hasReadonly = section.fields.some(f => f.rule === 'readonly');
        const hasEditable = section.fields.some(f => f.rule !== 'readonly');

        return (
          <Card key={section.key} className="border-0 shadow-sm">
            <CardContent className="p-4 space-y-3">
              <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wider flex items-center gap-2">
                <Icon className="w-4 h-4" />
                {section.label}
                {hasEditable && (
                  <Badge variant="outline" className="bg-emerald-50 text-emerald-600 border-emerald-200 text-[10px] px-1.5 py-0">
                    Editable
                  </Badge>
                )}
              </h3>

              {/* Render all fields in this section */}
              {section.fields.map(field => renderField(field))}

              {/* Hint for readonly fields */}
              {hasReadonly && (
                <p className="text-xs text-gray-400 pt-1">
                  <Lock className="w-3 h-3 inline mr-1" />
                  Locked fields require admin assistance to update.
                </p>
              )}
            </CardContent>
          </Card>
        );
      })}

      {/* Bottom Buttons */}
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
