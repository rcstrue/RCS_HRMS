'use client';

import { useState, useEffect, useRef, useMemo } from 'react';
import { toast } from 'sonner';
import { uploadFile } from '@/lib/api/config';
import { submitUnitVisit, fetchUnitVisits } from '@/lib/ess-api';
import type { UnitOption, ClientOption } from '@/lib/ess-types';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Loader2, Upload, X, CheckCircle2, MapPin, Camera,
} from 'lucide-react';

// ══════════════════════════════════════════════════════════════

const MONTHS = [
  { value: 1, label: 'January' }, { value: 2, label: 'February' }, { value: 3, label: 'March' },
  { value: 4, label: 'April' }, { value: 5, label: 'May' }, { value: 6, label: 'June' },
  { value: 7, label: 'July' }, { value: 8, label: 'August' }, { value: 9, label: 'September' },
  { value: 10, label: 'October' }, { value: 11, label: 'November' }, { value: 12, label: 'December' },
];

interface ChecklistFormProps {
  employeeId: number;
  employeeName: string;
  units: UnitOption[];
  onSuccess: () => void;
  onCancel: () => void;
}

export default function UnitVisitChecklistForm({ employeeId, employeeName, units, onSuccess, onCancel }: ChecklistFormProps) {
  // Form state
  const [clientId, setClientId] = useState('');
  const [unitId, setUnitId] = useState('');
  const [visitNumber, setVisitNumber] = useState<string>('1');
  const [visitMonth, setVisitMonth] = useState(new Date().getMonth() + 1);
  const [visitYear, setVisitYear] = useState(new Date().getFullYear());
  const [notes, setNotes] = useState('');
  const [documentUrl, setDocumentUrl] = useState('');
  const [docPreview, setDocPreview] = useState<string | null>(null);

  // Clients derived from allocated units (access-controlled — no separate fetch needed)
  const clients = useMemo<ClientOption[]>(() => {
    const clientMap = new Map<number, ClientOption>();
    for (const u of units) {
      if (u.client_id && !clientMap.has(u.client_id)) {
        clientMap.set(u.client_id, {
          id: u.client_id,
          client_code: '',
          name: u.client_name || `Client ${u.client_id}`,
          is_active: true,
        });
      }
    }
    return Array.from(clientMap.values()).sort((a, b) => a.name.localeCompare(b.name));
  }, [units]);

  const [filteredUnits, setFilteredUnits] = useState<UnitOption[]>([]);

  // Upload & submit
  const [uploadingDoc, setUploadingDoc] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const docInputRef = useRef<HTMLInputElement>(null);

  // Filter units when client changes (units are already filtered by access allocation)
  useEffect(() => {
    if (!clientId) {
      setFilteredUnits(units);
      setUnitId('');
      return;
    }
    const filtered = units.filter(u => u.client_id === parseInt(clientId));
    setFilteredUnits(filtered);
    setUnitId('');
  }, [clientId, units]);

  // Auto-detect visit number when unit + month/year change
  useEffect(() => {
    if (!unitId) return;
    fetchUnitVisits({
      employee_id: employeeId,
      unit_id: parseInt(unitId),
      month: visitMonth,
      year: visitYear,
      limit: 10,
    }).then(({ data }) => {
      const items = (data as any)?.items || [];
      const hasFirst = items.some((v: any) => v.visit_number === 1);
      const hasSecond = items.some((v: any) => v.visit_number === 2);
      if (hasFirst && hasSecond) {
        // Both exist — keep current selection but warn
      } else if (hasFirst) {
        setVisitNumber('2');
      } else {
        setVisitNumber('1');
      }
    });
  }, [unitId, visitMonth, visitYear, employeeId]);

  // Document upload — image only
  const handleDocUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    if (!file.type.startsWith('image/')) {
      toast.error('Only image files are allowed (JPG, PNG, etc.)');
      if (docInputRef.current) docInputRef.current.value = '';
      return;
    }
    setUploadingDoc(true);
    const { url, error } = await uploadFile(file, 'unit-visits');
    if (error) { toast.error(error); setUploadingDoc(false); return; }
    setDocumentUrl(url || '');
    const reader = new FileReader();
    reader.onload = () => setDocPreview(reader.result as string);
    reader.readAsDataURL(file);
    setUploadingDoc(false);
  };

  // Submit
  const handleSubmit = async () => {
    if (!unitId) { toast.error('Please select a unit'); return; }
    if (!documentUrl) { toast.error('Please upload a photo'); return; }

    setSubmitting(true);
    const { data, error } = await submitUnitVisit({
      employee_id: employeeId,
      unit_id: parseInt(unitId),
      visit_number: parseInt(visitNumber) as 1 | 2,
      visit_month: visitMonth,
      visit_year: visitYear,
      document_url: documentUrl,
      document_type: 'image',
      notes: notes || undefined,
    });

    setSubmitting(false);
    if (error) { toast.error(error); return; }
    toast.success(data?.message || 'Checklist submitted successfully!');
    onSuccess();
  };

  const visitLabel = visitNumber === '1' ? '1st Visit' : '2nd Visit';

  return (
    <div className="space-y-4">
      {/* Hidden file input — images only */}
      <input ref={docInputRef} type="file" accept="image/*" capture="environment" className="hidden" onChange={handleDocUpload} />

      {/* Main Form Card */}
      <Card className="border-2 border-emerald-200">
        <CardHeader className="pb-3">
          <CardTitle className="text-base flex items-center gap-2">
            <MapPin className="w-4 h-4 text-emerald-600" />
            New Unit Visit Checklist
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {/* Client Selection */}
          <div className="space-y-1.5">
            <Label className="text-xs font-medium text-gray-500">Client</Label>
            <Select value={clientId} onValueChange={setClientId}>
              <SelectTrigger>
                <SelectValue placeholder="Select client (optional)" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__all__">All Clients</SelectItem>
                {clients.map(c => (
                  <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {/* Unit Selection */}
          <div className="space-y-1.5">
            <Label className="text-xs font-medium text-gray-500">Unit *</Label>
            <Select value={unitId} onValueChange={setUnitId}>
              <SelectTrigger>
                <SelectValue placeholder="Select unit" />
              </SelectTrigger>
              <SelectContent>
                {filteredUnits.map(u => (
                  <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            {filteredUnits.length === 0 && clientId && (
              <p className="text-xs text-amber-600">No units found for this client</p>
            )}
          </div>

          {/* Visit Details Row */}
          <div className="grid grid-cols-3 gap-3">
            <div className="space-y-1.5">
              <Label className="text-xs font-medium text-gray-500">Visit *</Label>
              <Select value={visitNumber} onValueChange={setVisitNumber}>
                <SelectTrigger className="text-xs"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="1">1st Visit</SelectItem>
                  <SelectItem value="2">2nd Visit</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label className="text-xs font-medium text-gray-500">Month *</Label>
              <Select value={String(visitMonth)} onValueChange={v => setVisitMonth(parseInt(v))}>
                <SelectTrigger className="text-xs"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {MONTHS.map(m => <SelectItem key={m.value} value={String(m.value)}>{m.label.slice(0, 3)}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label className="text-xs font-medium text-gray-500">Year *</Label>
              <Input type="number" value={visitYear} onChange={e => setVisitYear(parseInt(e.target.value) || new Date().getFullYear())} min={2020} max={2099} className="text-xs" />
            </div>
          </div>

          {/* Document Upload — Image only */}
          <div className="space-y-1.5">
            <Label className="text-xs font-medium text-gray-500">
              {visitLabel} Photo *
            </Label>
            {docPreview ? (
              <div className="relative rounded-lg border border-gray-200 overflow-hidden bg-gray-50 p-2">
                <img src={docPreview} alt="Checklist photo" className="max-h-48 mx-auto rounded" />
                <button
                  onClick={() => { setDocumentUrl(''); setDocPreview(null); }}
                  className="absolute top-2 right-2 p-1.5 rounded-full bg-white shadow-sm hover:bg-gray-100"
                >
                  <X className="w-3.5 h-3.5" />
                </button>
              </div>
            ) : (
              <Button
                variant="outline"
                className="w-full h-28 border-2 border-dashed border-gray-300 hover:border-emerald-400 hover:bg-emerald-50/50 flex-col gap-2"
                onClick={() => docInputRef.current?.click()}
                disabled={uploadingDoc}
              >
                {uploadingDoc ? (
                  <Loader2 className="w-6 h-6 animate-spin text-gray-400" />
                ) : (
                  <>
                    <Camera className="w-7 h-7 text-gray-400" />
                    <span className="text-sm text-gray-500">Tap to take photo or upload image</span>
                    <span className="text-[10px] text-gray-400">JPG, PNG, HEIC</span>
                  </>
                )}
              </Button>
            )}
          </div>

          {/* Notes */}
          <div className="space-y-1.5">
            <Label className="text-xs font-medium text-gray-500">Notes (optional)</Label>
            <Textarea
              value={notes}
              onChange={e => setNotes(e.target.value)}
              placeholder="Any observations or remarks..."
              rows={2}
              className="text-sm"
            />
          </div>
        </CardContent>
      </Card>

      {/* Submit Buttons */}
      <div className="flex gap-3 sticky bottom-20 bg-white pt-2 pb-2 z-10">
        <Button variant="outline" className="flex-1" onClick={onCancel} disabled={submitting}>
          Cancel
        </Button>
        <Button
          className="flex-1 bg-emerald-600 hover:bg-emerald-700"
          onClick={handleSubmit}
          disabled={submitting || !unitId || !documentUrl}
        >
          {submitting ? (
            <><Loader2 className="w-4 h-4 animate-spin mr-1.5" /> Submitting...</>
          ) : (
            <><CheckCircle2 className="w-4 h-4 mr-1.5" /> Submit {visitLabel}</>
          )}
        </Button>
      </div>
    </div>
  );
}