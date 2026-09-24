'use client';

import { useState, useMemo, useEffect, useRef } from 'react';
import { toast } from 'sonner';
import {
  Loader2,
  Save,
  X,
  Clock,
  User,
  MapPin,
  Phone,
  UserCheck,
  Briefcase,
  CreditCard,
  Camera,
  CheckCircle2,
  AlertCircle,
  FileText,
  Image as ImageIcon,
  Upload,
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
  resolveEffectiveRule,
  type FieldRule,
} from '@/lib/field-rules';
import type { Employee, ChangeRequest } from '@/lib/ess-types';
import { getFileUrl, uploadBase64Image } from '@/lib/api/config';
import PageHeader from './PageHeader';

// ══════════════════════════════════════════════════════════════
// EditProfilePage — Clean edit form, single Save Changes button
// ══════════════════════════════════════════════════════════════
//
// Design:
//   - All fields render as simple input fields (no per-field buttons)
//   - A small badge next to each label shows "Saves directly" (green)
//     or "Needs approval" (amber) based on resolveEffectiveRule()
//   - One "Save Changes" button at the bottom handles everything:
//     → Free fields are saved directly via onSaveFreeFields
//     → Approval-needed fields are submitted as change requests
//     → A summary toast tells the user what happened
//   - Profile photo: upload button (handled by Save for approval)

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
  emergency: Phone,
  nominee: UserCheck,
  sensitive: CreditCard,
  documents: FileText,
};

export default function EditProfilePage({
  employee,
  pendingChangeRequests = [],
  onSaveFreeFields,
  onSubmitChangeRequest,
  onBack,
}: EditProfilePageProps) {
  const [saving, setSaving] = useState(false);
  const [formValues, setFormValues] = useState<Record<string, string>>({});
  const [uploadingPhoto, setUploadingPhoto] = useState(false);
  const [pendingPhotoUrl, setPendingPhotoUrl] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  // ── KYC Document image uploads ──
  // Track pending uploaded URLs for image fields (aadhaar_front_url, aadhaar_back_url, bank_document_url)
  const [pendingImageUrls, setPendingImageUrls] = useState<Record<string, string>>({});
  const [uploadingImageKey, setUploadingImageKey] = useState<string | null>(null);
  const imageInputRefs = useRef<Record<string, HTMLInputElement | null>>({});

  const pendingFieldNames = useMemo(() => {
    const set = new Set<string>();
    for (const r of pendingChangeRequests) {
      if (r.status === 'pending') set.add(r.field_name);
    }
    return set;
  }, [pendingChangeRequests]);

  const getValue = (key: string): string => {
    const val = (employee as unknown as Record<string, unknown>)[key];
    return (val as string) || '';
  };

  // Initialize form with current values for all editable fields
  useEffect(() => {
    const initial: Record<string, string> = {};
    for (const f of FIELD_RULES) {
      if (f.rule !== 'readonly') {
        initial[f.key] = getValue(f.key);
      }
    }
    setFormValues(initial);
  }, [employee]); // eslint-disable-line react-hooks/exhaustive-deps

  // Only show sections that have at least one editable field
  const sectionsWithFields = useMemo(() => {
    return FIELD_SECTIONS.map(sectionDef => {
      const fields = FIELD_RULES.filter(f => f.section === sectionDef.key && f.rule !== 'readonly');
      return { ...sectionDef, fields };
    }).filter(s => s.fields.length > 0);
  }, []);

  const updateField = (key: string, value: string) => {
    setFormValues(prev => ({ ...prev, [key]: value }));
  };

  // Count changes for the save button badge
  const changedCount = useMemo(() => {
    let count = 0;
    for (const f of FIELD_RULES) {
      if (f.rule === 'readonly') continue;
      if (f.inputType === 'photo') continue; // handled separately below
      if (f.inputType === 'image') continue; // handled separately below
      const current = getValue(f.key);
      const newVal = formValues[f.key] ?? '';
      if (newVal !== current) count++;
    }
    // Photo change
    const currentPhoto = getValue('profile_pic_url');
    if (pendingPhotoUrl && pendingPhotoUrl !== currentPhoto) count++;
    // Document image changes
    for (const f of FIELD_RULES) {
      if (f.inputType !== 'image') continue;
      const current = getValue(f.key);
      const pending = pendingImageUrls[f.key];
      if (pending && pending !== current) count++;
    }
    return count;
  }, [formValues, employee, pendingPhotoUrl, pendingImageUrls]); // eslint-disable-line react-hooks/exhaustive-deps

  // ── Single Save Changes handler ──
  // Auto-routes: free fields → direct save, approval fields → change requests
  const handleSave = async () => {
    setSaving(true);
    try {
      const freeChanged: Record<string, string | null> = {};
      const approvalFields: { field: FieldRule; newVal: string }[] = [];

      for (const f of FIELD_RULES) {
        if (f.rule === 'readonly') continue;
        if (f.inputType === 'photo') continue; // handle photo separately
        if (f.inputType === 'image') continue; // handle image uploads separately

        const current = getValue(f.key);
        const newVal = formValues[f.key] ?? '';
        if (newVal === current) continue; // no change

        const effectiveRule = resolveEffectiveRule(f.rule, current);

        if (effectiveRule === 'free') {
          freeChanged[f.key] = newVal || null;
        } else if (effectiveRule === 'admin_approval') {
          if (!newVal) continue; // skip empty values for approval fields
          approvalFields.push({ field: f, newVal });
        }
      }

      // Handle photo (always needs approval)
      const currentPhoto = getValue('profile_pic_url');
      if (pendingPhotoUrl && pendingPhotoUrl !== currentPhoto) {
        approvalFields.push({
          field: FIELD_RULES.find(f => f.key === 'profile_pic_url')!,
          newVal: pendingPhotoUrl,
        });
      }

      // Handle KYC document image uploads (always need approval)
      for (const f of FIELD_RULES) {
        if (f.inputType !== 'image') continue;
        const current = getValue(f.key);
        const pending = pendingImageUrls[f.key];
        if (pending && pending !== current) {
          approvalFields.push({ field: f, newVal: pending });
        }
      }

      const totalChanges = Object.keys(freeChanged).length + approvalFields.length;
      if (totalChanges === 0) {
        toast.info('No changes to save.');
        setSaving(false);
        return;
      }

      // 1. Save free fields directly
      let savedCount = 0;
      if (Object.keys(freeChanged).length > 0) {
        const result = await onSaveFreeFields(freeChanged);
        if (result.success) {
          savedCount = Object.keys(freeChanged).length;
        } else {
          toast.error(result.error || 'Failed to save some fields');
          setSaving(false);
          return;
        }
      }

      // 2. Submit change requests for approval-needed fields
      let requestCount = 0;
      let requestErrors = 0;
      for (const { field, newVal } of approvalFields) {
        if (pendingFieldNames.has(field.key)) continue; // already pending
        const result = await onSubmitChangeRequest({
          field_name: field.key,
          old_value: getValue(field.key),
          new_value: newVal,
        });
        if (result.success) {
          requestCount++;
        } else {
          requestErrors++;
        }
      }

      // 3. Summary toast
      if (requestErrors > 0) {
        toast.error(`Some change requests failed. ${savedCount} field${savedCount !== 1 ? 's' : ''} saved, ${requestCount} request${requestCount !== 1 ? 's' : ''} submitted.`);
      } else if (savedCount > 0 && requestCount > 0) {
        toast.success(`${savedCount} field${savedCount !== 1 ? 's' : ''} saved directly, ${requestCount} change request${requestCount !== 1 ? 's' : ''} submitted for approval.`);
      } else if (savedCount > 0) {
        toast.success('Profile updated successfully');
      } else if (requestCount > 0) {
        toast.success(`${requestCount} change request${requestCount !== 1 ? 's' : ''} submitted for HR approval.`);
      }

      onBack();
    } catch {
      toast.error('An unexpected error occurred');
    } finally {
      setSaving(false);
    }
  };

  // ── Profile Photo Upload ──
  const handlePhotoSelect = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    if (!file.type.startsWith('image/')) {
      toast.error('Please select an image file');
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      toast.error('Image must be less than 5MB');
      return;
    }

    setUploadingPhoto(true);
    try {
      const formData = new FormData();
      formData.append('photo', file);
      formData.append('employee_id', String(employee.id));

      const API_BASE = (import.meta as Record<string, Record<string, string>>).env?.VITE_API_BASE_URL ?? '';
      const API_KEY = (import.meta as Record<string, Record<string, string>>).env?.VITE_API_KEY ?? '';
      const token = typeof window !== 'undefined' ? localStorage.getItem('ess_token') || '' : '';

      const resp = await fetch(`${API_BASE}/api/ess/upload.php`, {
        method: 'POST',
        headers: { 'X-API-KEY': API_KEY, 'Authorization': `Bearer ${token}` },
        body: formData,
      });

      const json = await resp.json();
      if (json.success && json.data?.url) {
        setPendingPhotoUrl(json.data.url);
        setFormValues(prev => ({ ...prev, profile_pic_url: json.data.url }));
        toast.success('Photo uploaded. Will be submitted for approval when you Save.');
      } else {
        toast.error(json.error || 'Upload failed');
      }
    } catch {
      toast.error('Upload failed. Please try again.');
    } finally {
      setUploadingPhoto(false);
      if (fileInputRef.current) fileInputRef.current.value = '';
    }
  };

  // ── KYC Document Image Upload ──
  const handleImageSelect = async (fieldKey: string, folder: string, filename: string, e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    if (!file.type.startsWith('image/')) {
      toast.error('Please select an image file');
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      toast.error('Image must be less than 5MB');
      return;
    }

    setUploadingImageKey(fieldKey);
    try {
      // Convert file to base64 for upload-base64 endpoint
      const base64Data = await new Promise<string>((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result as string);
        reader.onerror = reject;
        reader.readAsDataURL(file);
      });

      const { url, error } = await uploadBase64Image(base64Data, filename, folder);
      if (url) {
        setPendingImageUrls(prev => ({ ...prev, [fieldKey]: url }));
        setFormValues(prev => ({ ...prev, [fieldKey]: url }));
        toast.success('Document uploaded. Will be submitted for approval when you Save.');
      } else {
        toast.error(error || 'Upload failed');
      }
    } catch {
      toast.error('Upload failed. Please try again.');
    } finally {
      setUploadingImageKey(null);
      // Reset file input
      const inputRef = imageInputRefs.current[fieldKey];
      if (inputRef) inputRef.value = '';
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
      const validValue = (value && field.options.includes(value)) ? value : '';
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

  // Render a single field row — clean, no per-field buttons
  const renderField = (field: FieldRule) => {
    if (field.rule === 'readonly') return null;

    const hasPending = pendingFieldNames.has(field.key);
    const effectiveRule = resolveEffectiveRule(field.rule, getValue(field.key));
    const current = getValue(field.key);
    const newVal = formValues[field.key] ?? '';
    const isChanged = newVal !== current;

    // ── Special: Profile Photo ──
    if (field.inputType === 'photo') {
      const currentPhoto = current;
      const displayPhoto = pendingPhotoUrl || currentPhoto;
      const photoChanged = !!pendingPhotoUrl && pendingPhotoUrl !== currentPhoto;

      return (
        <div key={field.key} className="space-y-2">
          <div className="flex items-center justify-between">
            <Label className="text-sm font-medium">{field.label}</Label>
            {hasPending ? (
              <Badge variant="outline" className="bg-amber-50 text-amber-600 border-amber-200 text-[10px] px-1.5 py-0">
                <Clock className="w-3 h-3 mr-0.5" /> Pending
              </Badge>
            ) : (
              <Badge variant="outline" className="bg-amber-50 text-amber-600 border-amber-200 text-[10px] px-1.5 py-0">
                Needs approval
              </Badge>
            )}
          </div>
          <div className="flex items-center gap-4">
            <div className="w-16 h-16 rounded-full bg-gray-100 border-2 border-gray-200 overflow-hidden flex-shrink-0">
              {displayPhoto ? (
                <img
                  src={getFileUrl(displayPhoto)}
                  alt="Profile"
                  className="w-full h-full object-cover"
                  onError={e => { (e.target as HTMLImageElement).style.display = 'none'; }}
                />
              ) : (
                <div className="w-full h-full flex items-center justify-center text-gray-300">
                  <User className="w-7 h-7" />
                </div>
              )}
            </div>
            <div className="flex-1 space-y-1.5">
              {!hasPending && (
                <>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="w-full border-dashed"
                    onClick={() => fileInputRef.current?.click()}
                    disabled={uploadingPhoto}
                  >
                    {uploadingPhoto ? (
                      <Loader2 className="w-3.5 h-3.5 animate-spin mr-1.5" />
                    ) : (
                      <Camera className="w-3.5 h-3.5 mr-1.5" />
                    )}
                    {uploadingPhoto ? 'Uploading...' : 'Change Photo'}
                  </Button>
                  <input
                    ref={fileInputRef}
                    type="file"
                    accept="image/*"
                    className="hidden"
                    onChange={handlePhotoSelect}
                  />
                </>
              )}
              {photoChanged && (
                <p className="text-xs text-emerald-600 flex items-center gap-1">
                  <CheckCircle2 className="w-3 h-3" /> New photo ready
                </p>
              )}
            </div>
          </div>
        </div>
      );
    }

    // ── Special: KYC Document Image ──
    if (field.inputType === 'image') {
      const currentUrl = current;
      const pendingUrl = pendingImageUrls[field.key];
      const displayUrl = pendingUrl || currentUrl;
      const imageChanged = !!pendingUrl && pendingUrl !== currentUrl;
      const isUploading = uploadingImageKey === field.key;
      const folder = field.uploadFolder || 'documents';
      const filename = field.uploadFilename || `${field.key}.jpg`;

      return (
        <div key={field.key} className="space-y-2">
          <div className="flex items-center justify-between">
            <Label className="text-sm font-medium">{field.label}</Label>
            {hasPending ? (
              <Badge variant="outline" className="bg-amber-50 text-amber-600 border-amber-200 text-[10px] px-1.5 py-0">
                <Clock className="w-3 h-3 mr-0.5" /> Pending
              </Badge>
            ) : (
              <Badge variant="outline" className="bg-amber-50 text-amber-600 border-amber-200 text-[10px] px-1.5 py-0">
                Needs approval
              </Badge>
            )}
          </div>
          <div className="flex items-center gap-3">
            {/* Document thumbnail */}
            <div className="w-20 h-14 rounded-lg bg-gray-50 border-2 border-dashed border-gray-200 overflow-hidden flex-shrink-0 flex items-center justify-center">
              {displayUrl ? (
                <img
                  src={getFileUrl(displayUrl)}
                  alt={field.label}
                  className="w-full h-full object-cover"
                  onError={e => { (e.target as HTMLImageElement).style.display = 'none'; (e.target as HTMLImageElement).parentElement!.innerHTML = '<div class="w-full h-full flex items-center justify-center text-gray-300"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></div>'; }}
                />
              ) : (
                <ImageIcon className="w-6 h-6 text-gray-300" />
              )}
            </div>
            <div className="flex-1 space-y-1.5">
              {!hasPending && (
                <>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="w-full border-dashed"
                    onClick={() => imageInputRefs.current[field.key]?.click()}
                    disabled={isUploading}
                  >
                    {isUploading ? (
                      <Loader2 className="w-3.5 h-3.5 animate-spin mr-1.5" />
                    ) : currentUrl ? (
                      <Upload className="w-3.5 h-3.5 mr-1.5" />
                    ) : (
                      <Camera className="w-3.5 h-3.5 mr-1.5" />
                    )}
                    {isUploading ? 'Uploading...' : currentUrl ? 'Replace' : 'Upload'}
                  </Button>
                  <input
                    ref={el => { imageInputRefs.current[field.key] = el; }}
                    type="file"
                    accept="image/*"
                    className="hidden"
                    onChange={e => handleImageSelect(field.key, folder, filename, e)}
                  />
                </>
              )}
              {imageChanged && (
                <p className="text-xs text-emerald-600 flex items-center gap-1">
                  <CheckCircle2 className="w-3 h-3" /> New document ready
                </p>
              )}
              {currentUrl && !displayUrl?.startsWith('pending') && (
                <p className="text-[11px] text-gray-400 truncate">
                  Current document on file
                </p>
              )}
            </div>
          </div>
        </div>
      );
    }

    // ── All other fields — clean single input with badge ──
    const isApproval = effectiveRule === 'admin_approval';

    return (
      <div key={field.key} className="space-y-1">
        <div className="flex items-center justify-between gap-2">
          <Label htmlFor={`field-${field.key}`} className="text-sm font-medium">{field.label}</Label>
          <div className="flex items-center gap-1 shrink-0">
            {hasPending ? (
              <Badge variant="outline" className="bg-amber-50 text-amber-600 border-amber-200 text-[10px] px-1.5 py-0">
                <Clock className="w-3 h-3 mr-0.5" /> Pending
              </Badge>
            ) : isApproval ? (
              <Badge variant="outline" className="bg-amber-50 text-amber-600 border-amber-200 text-[10px] px-1.5 py-0">
                Needs approval
              </Badge>
            ) : (
              <Badge variant="outline" className="bg-emerald-50 text-emerald-600 border-emerald-200 text-[10px] px-1.5 py-0">
                Saves directly
              </Badge>
            )}
          </div>
        </div>
        {renderInput(
          field,
          formValues[field.key] || '',
          (v) => updateField(field.key, v),
          hasPending, // disable input if change is already pending
        )}
        {/* Show current value hint for approval fields that already have data */}
        {isApproval && current && !hasPending && (
          <p className="text-[11px] text-gray-400">
            Current: <span className="text-gray-500 font-medium">{current}</span>
          </p>
        )}
      </div>
    );
  };

  return (
    <div className="space-y-4 pb-6">
      <PageHeader title="Edit Profile" subtitle="Update employee information" onBack={onBack} />

      {sectionsWithFields.map(section => {
        const Icon = SECTION_ICONS[section.key] || User;

        return (
          <Card key={section.key} className="border-0 shadow-sm">
            <CardContent className="p-4 space-y-3">
              <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wider flex items-center gap-2">
                <Icon className="w-4 h-4" />
                {section.label}
              </h3>

              {section.fields.map(field => renderField(field))}
            </CardContent>
          </Card>
        );
      })}

      {/* Info box explaining the two save paths */}
      <div className="flex items-start gap-2.5 px-1 text-xs text-gray-500">
        <AlertCircle className="w-4 h-4 text-gray-400 shrink-0 mt-0.5" />
        <p>
          Fields marked <span className="text-emerald-600 font-medium">"Saves directly"</span> update immediately.
          Fields marked <span className="text-amber-600 font-medium">"Needs approval"</span> will be submitted to HR for review — original values stay unchanged until approved.
        </p>
      </div>

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
          disabled={saving || changedCount === 0}
        >
          {saving ? (
            <Loader2 className="w-4 h-4 animate-spin mr-1.5" />
          ) : (
            <Save className="w-4 h-4 mr-1.5" />
          )}
          {saving
            ? 'Saving...'
            : changedCount > 0
              ? `Save Changes (${changedCount})`
              : 'Save Changes'
          }
        </Button>
      </div>
    </div>
  );
}
