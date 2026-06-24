'use client';

import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { toast } from 'sonner';
import PageHeader from './PageHeader';
import { uploadFile, getFileUrl } from '@/lib/api/config';
import { fetchUnits, fetchUnitVisits, submitUnitVisit, deleteUnitVisit } from '@/lib/ess-api';
import type { UnitOption, UnitVisit } from '@/lib/ess-types';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import {
  MapPin, Upload, FileText, Trash2, Eye, Loader2, Camera,
  ChevronLeft, ChevronRight, CheckCircle2, AlertCircle, Clock,
  X, Download, Printer,
} from 'lucide-react';

// ══════════════════════════════════════════════════════════════
// Unit Visits Page — Manager submits visit checklists
// ══════════════════════════════════════════════════════════════

const MONTHS = [
  { value: 1, label: 'January' },
  { value: 2, label: 'February' },
  { value: 3, label: 'March' },
  { value: 4, label: 'April' },
  { value: 5, label: 'May' },
  { value: 6, label: 'June' },
  { value: 7, label: 'July' },
  { value: 8, label: 'August' },
  { value: 9, label: 'September' },
  { value: 10, label: 'October' },
  { value: 11, label: 'November' },
  { value: 12, label: 'December' },
];

interface UnitVisitsPageProps {
  employeeId: number;
  employeeName: string;
  unitIds: number[];
}

export default function UnitVisitsPage({ employeeId, employeeName, unitIds }: UnitVisitsPageProps) {
  // ── State ──
  const now = new Date();
  const [selectedMonth, setSelectedMonth] = useState(now.getMonth() + 1);
  const [selectedYear, setSelectedYear] = useState(now.getFullYear());
  const [selectedUnit, setSelectedUnit] = useState<string>('');
  const [documentFile, setDocumentFile] = useState<File | null>(null);
  const [documentPreview, setDocumentPreview] = useState<string | null>(null);
  const [notes, setNotes] = useState('');
  const [units, setUnits] = useState<UnitOption[]>([]);
  const [visits, setVisits] = useState<UnitVisit[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [loading, setLoading] = useState(false);
  const [submitting, setUploading] = useState(false);
  const [previewVisit, setPreviewVisit] = useState<UnitVisit | null>(null);
  const [deleteConfirm, setDeleteConfirm] = useState<UnitVisit | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const PAGE_SIZE = 20;

  // ── Load Units ──
  const loadUnits = useCallback(async () => {
    try {
      const { data, error } = await fetchUnits('unit', employeeId, undefined, unitIds.length > 0 ? unitIds : undefined);
      if (error) {
        toast.error('Failed to load units: ' + error);
        return;
      }
      if (data) {
        setUnits(Array.isArray(data) ? data : []);
      }
    } catch {
      toast.error('Failed to load units');
    }
  }, [employeeId, unitIds]);

  // ── Load Visits ──
  const loadVisits = useCallback(async () => {
    setLoading(true);
    try {
      const { data, error } = await fetchUnitVisits({
        employee_id: employeeId,
        month: selectedMonth,
        year: selectedYear,
        page,
        limit: PAGE_SIZE,
      });
      if (error) {
        toast.error('Failed to load visits: ' + error);
        return;
      }
      if (data) {
        const res = data as Record<string, unknown>;
        const items = (res?.items as UnitVisit[]) ?? [];
        const rawTotal = res?.total as number | undefined;
        const rawTotalPages = res?.total_pages as number | undefined;
        setVisits(items);
        setTotal(typeof rawTotal === 'number' ? rawTotal : items.length);
        setTotalPages(typeof rawTotalPages === 'number' ? rawTotalPages : 1);
      }
    } catch {
      toast.error('Failed to load visits');
    } finally {
      setLoading(false);
    }
  }, [employeeId, selectedMonth, selectedYear, page]);

  useEffect(() => {
    loadUnits();
  }, [loadUnits]);

  useEffect(() => {
    loadVisits();
  }, [loadVisits]);

  // Reset page when month/year changes
  useEffect(() => {
    setPage(1);
  }, [selectedMonth, selectedYear]);

  // ── Auto-assign visit number for selected unit/month/year ──
  const autoVisitNumber = useMemo(() => {
    if (!selectedUnit || !selectedMonth || !selectedYear) return 0;

    const unitVisits = visits.filter(
      (v) =>
        v.unit_id === Number(selectedUnit) &&
        v.visit_month === selectedMonth &&
        v.visit_year === selectedYear
    );

    const hasFirst = unitVisits.some((v) => v.visit_number === 1);
    const hasSecond = unitVisits.some((v) => v.visit_number === 2);

    if (!hasFirst) return 1;
    if (!hasSecond) return 2;
    return 0; // Both visits already submitted
  }, [selectedUnit, selectedMonth, selectedYear, visits]);

  const visitLabel = useMemo(() => {
    if (autoVisitNumber === 1) return 'First Visit';
    if (autoVisitNumber === 2) return 'Second Visit';
    if (autoVisitNumber === 0 && selectedUnit) return 'Both visits done';
    return '';
  }, [autoVisitNumber, selectedUnit]);

  // ── File Handling ──
  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    // Validate file type
    const isImage = file.type.startsWith('image/');
    const isPdf = file.type === 'application/pdf';
    if (!isImage && !isPdf) {
      toast.error('Only JPG/PNG images or PDF files are allowed');
      return;
    }

    // Validate file size (max 10MB)
    if (file.size > 10 * 1024 * 1024) {
      toast.error('File size must be under 10MB');
      return;
    }

    setDocumentFile(file);

    // Preview for images
    if (isImage) {
      const reader = new FileReader();
      reader.onload = () => setDocumentPreview(reader.result as string);
      reader.readAsDataURL(file);
    } else {
      setDocumentPreview(null);
    }

    // Reset input so same file can be re-selected
    e.target.value = '';
  };

  const clearFile = () => {
    setDocumentFile(null);
    setDocumentPreview(null);
    if (fileInputRef.current) fileInputRef.current.value = '';
  };

  // ── Submit ──
  const handleSubmit = async () => {
    if (!selectedUnit) {
      toast.error('Please select a unit');
      return;
    }
    if (autoVisitNumber === 0) {
      toast.error('Both visits for this unit this month are already submitted');
      return;
    }
    if (!documentFile) {
      toast.error('Please upload the checklist document');
      return;
    }

    setUploading(true);
    try {
      // Step 1: Upload file
      const uploadResult = await uploadFile(documentFile, 'unit-visits');
      if (uploadResult.error || !uploadResult.url) {
        toast.error('Failed to upload document: ' + uploadResult.error);
        return;
      }

      // Step 2: Submit visit
      const { error } = await submitUnitVisit({
        employee_id: employeeId,
        unit_id: Number(selectedUnit),
        visit_number: autoVisitNumber,
        visit_month: selectedMonth,
        visit_year: selectedYear,
        document_url: uploadResult.url,
        document_type: documentFile.type === 'application/pdf' ? 'pdf' : 'image',
        notes: notes.trim() || undefined,
      });

      if (error) {
        toast.error(error);
      } else {
        toast.success(`${visitLabel} submitted successfully!`);
        // Reset form
        setSelectedUnit('');
        setDocumentFile(null);
        setDocumentPreview(null);
        setNotes('');
        if (fileInputRef.current) fileInputRef.current.value = '';
        // Reload visits
        loadVisits();
      }
    } catch {
      toast.error('Something went wrong. Please try again.');
    } finally {
      setUploading(false);
    }
  };

  // ── Delete ──
  const handleDelete = async (visit: UnitVisit) => {
    try {
      const { error } = await deleteUnitVisit(visit.id);
      if (error) {
        toast.error(error);
      } else {
        toast.success('Visit deleted successfully');
        setDeleteConfirm(null);
        loadVisits();
      }
    } catch {
      toast.error('Failed to delete visit');
    }
  };

  // ── Preview document in new tab ──
  const handlePreview = (visit: UnitVisit) => {
    const url = getFileUrl(visit.document_url);
    if (!url) {
      toast.error('Document not found');
      return;
    }
    window.open(url, '_blank');
  };

  // ── Year options ──
  const yearOptions = useMemo(() => {
    const currentYear = now.getFullYear();
    return [
      { value: currentYear, label: String(currentYear) },
      { value: currentYear - 1, label: String(currentYear - 1) },
    ];
  }, []);

  // ── Month summary for selected month ──
  const monthSummary = useMemo(() => {
    const allMonthVisits = visits.filter(
      (v) => v.visit_month === selectedMonth && v.visit_year === selectedYear
    );
    const visitedUnits = new Set(allMonthVisits.map((v) => v.unit_id));
    return {
      totalVisits: allMonthVisits.length,
      visitedUnits: visitedUnits.size,
    };
  }, [visits, selectedMonth, selectedYear]);

  return (
    <div className="space-y-4">
      <PageHeader
        title="Unit Visits"
        subtitle="Submit visit checklists for your assigned units"
      />

      {/* ── Download Checklist Card ── */}
      <a
        href="/CHECKLIST.pdf"
        download="CHECKLIST.pdf"
        target="_blank"
        rel="noopener noreferrer"
        className="block"
      >
        <Card className="border-blue-200 bg-gradient-to-r from-blue-50 to-indigo-50 hover:from-blue-100 hover:to-indigo-100 transition-all active:scale-[0.99]">
          <CardContent className="p-4">
            <div className="flex items-center gap-3">
              <div className="flex items-center justify-center w-12 h-12 rounded-xl bg-white shadow-sm border shrink-0">
                <FileText className="w-6 h-6 text-blue-600" />
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-sm font-semibold text-gray-900">Visit Checklist (PDF)</p>
                <p className="text-xs text-gray-500">Download, print, fill during visit, then upload signed copy</p>
              </div>
              <div className="flex items-center gap-1 shrink-0">
                <div className="flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-blue-600 text-white">
                  <Download className="w-3.5 h-3.5" />
                  <span className="text-xs font-medium">Download</span>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>
      </a>

      {/* ── Submit Section ── */}
      <Card className="border-emerald-200 bg-gradient-to-br from-emerald-50 to-white">
        <CardContent className="p-4 space-y-4">
          <div className="flex items-center gap-2">
            <div className="flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-100">
              <MapPin className="w-4 h-4 text-emerald-600" />
            </div>
            <h3 className="font-semibold text-gray-900">Submit Visit Checklist</h3>
          </div>

          {/* Month / Year Selector */}
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <label className="text-xs font-medium text-gray-600">Month</label>
              <Select value={String(selectedMonth)} onValueChange={(v) => setSelectedMonth(Number(v))}>
                <SelectTrigger className="h-10">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {MONTHS.map((m) => (
                    <SelectItem key={m.value} value={String(m.value)}>{m.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <label className="text-xs font-medium text-gray-600">Year</label>
              <Select value={String(selectedYear)} onValueChange={(v) => setSelectedYear(Number(v))}>
                <SelectTrigger className="h-10">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {yearOptions.map((y) => (
                    <SelectItem key={y.value} value={String(y.value)}>{y.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          {/* Unit Selector */}
          <div className="space-y-1.5">
            <label className="text-xs font-medium text-gray-600">Select Unit</label>
            <Select value={selectedUnit} onValueChange={setSelectedUnit}>
              <SelectTrigger className="h-10">
                <SelectValue placeholder={units.length === 0 ? 'Loading units...' : 'Choose a unit'} />
              </SelectTrigger>
              <SelectContent>
                {units.map((unit) => (
                  <SelectItem key={unit.id} value={String(unit.id)}>{unit.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {/* Auto Visit Number */}
          {selectedUnit && (
            <div className={`flex items-center gap-2 px-3 py-2.5 rounded-lg border ${
              autoVisitNumber === 0
                ? 'bg-amber-50 border-amber-200'
                : 'bg-emerald-50 border-emerald-200'
            }`}>
              {autoVisitNumber === 0 ? (
                <>
                  <AlertCircle className="w-4 h-4 text-amber-600 shrink-0" />
                  <span className="text-sm text-amber-700 font-medium">Both visits already submitted for this unit this month</span>
                </>
              ) : (
                <>
                  <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0" />
                  <span className="text-sm text-emerald-700 font-medium">
                    Auto-assigned: <strong>{visitLabel}</strong>
                  </span>
                </>
              )}
            </div>
          )}

          {/* Document Upload */}
          {selectedUnit && autoVisitNumber > 0 && (
            <div className="space-y-2">
              <label className="text-xs font-medium text-gray-600">
                Upload Checklist (JPG / PDF)
              </label>

              <input
                ref={fileInputRef}
                type="file"
                accept="image/*,.pdf"
                onChange={handleFileSelect}
                className="hidden"
              />

              {!documentFile ? (
                <button
                  onClick={() => fileInputRef.current?.click()}
                  className="w-full flex flex-col items-center justify-center gap-3 p-6 border-2 border-dashed rounded-xl border-gray-300 hover:border-emerald-400 hover:bg-emerald-50/50 transition-colors"
                >
                  <div className="flex gap-2">
                    <div className="flex items-center justify-center w-10 h-10 rounded-full bg-emerald-100">
                      <Camera className="w-5 h-5 text-emerald-600" />
                    </div>
                    <div className="flex items-center justify-center w-10 h-10 rounded-full bg-blue-100">
                      <FileText className="w-5 h-5 text-blue-600" />
                    </div>
                  </div>
                  <div className="text-center">
                    <p className="text-sm font-medium text-gray-700">Tap to upload photo or PDF</p>
                    <p className="text-xs text-gray-400 mt-0.5">JPG, PNG, PDF — max 10MB</p>
                  </div>
                </button>
              ) : (
                <div className="flex items-center gap-3 p-3 border rounded-xl bg-gray-50">
                  {documentPreview ? (
                    <div className="w-14 h-14 rounded-lg overflow-hidden shrink-0 bg-gray-200">
                      <img src={documentPreview} alt="Preview" className="w-full h-full object-cover" />
                    </div>
                  ) : (
                    <div className="flex items-center justify-center w-14 h-14 rounded-lg bg-red-100 shrink-0">
                      <FileText className="w-6 h-6 text-red-600" />
                    </div>
                  )}
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium text-gray-900 truncate">{documentFile.name}</p>
                    <p className="text-xs text-gray-500">
                      {(documentFile.size / 1024).toFixed(1)} KB
                      {documentFile.type === 'application/pdf' && ' · PDF'}
                    </p>
                  </div>
                  <button
                    onClick={clearFile}
                    className="flex items-center justify-center w-8 h-8 rounded-lg hover:bg-gray-200 transition-colors"
                  >
                    <X className="w-4 h-4 text-gray-500" />
                  </button>
                </div>
              )}
            </div>
          )}

          {/* Notes */}
          {selectedUnit && autoVisitNumber > 0 && (
            <div className="space-y-1.5">
              <label className="text-xs font-medium text-gray-600">
                Notes <span className="text-gray-400">(optional)</span>
              </label>
              <Textarea
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                placeholder="Any remarks about the visit..."
                rows={2}
                className="resize-none text-sm"
              />
            </div>
          )}

          {/* Submit Button */}
          {selectedUnit && autoVisitNumber > 0 && documentFile && (
            <Button
              onClick={handleSubmit}
              disabled={submitting}
              className="w-full h-11 bg-emerald-600 hover:bg-emerald-700 text-white font-medium"
            >
              {submitting ? (
                <>
                  <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                  {submitting ? 'Uploading...' : 'Submitting...'}
                </>
              ) : (
                <>
                  <Upload className="w-4 h-4 mr-2" />
                  Submit {visitLabel}
                </>
              )}
            </Button>
          )}
        </CardContent>
      </Card>

      {/* ── Month Summary ── */}
      <div className="flex gap-3">
        <div className="flex-1 bg-emerald-50 rounded-xl p-3 text-center">
          <p className="text-2xl font-bold text-emerald-700">{monthSummary.totalVisits}</p>
          <p className="text-xs text-emerald-600">Visits This Month</p>
        </div>
        <div className="flex-1 bg-blue-50 rounded-xl p-3 text-center">
          <p className="text-2xl font-bold text-blue-700">{monthSummary.visitedUnits}</p>
          <p className="text-xs text-blue-600">Units Visited</p>
        </div>
      </div>

      {/* ── Visit History ── */}
      <div>
        <h3 className="text-sm font-semibold text-gray-700 mb-2">Visit History</h3>

        {loading ? (
          <div className="flex items-center justify-center py-10">
            <Loader2 className="w-6 h-6 animate-spin text-emerald-600" />
          </div>
        ) : visits.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-10 text-center">
            <MapPin className="w-10 h-10 text-gray-300 mb-3" />
            <p className="text-sm font-medium text-gray-500">No visits found</p>
            <p className="text-xs text-gray-400 mt-1">
              Submit your first visit for {MONTHS[selectedMonth - 1]?.label} {selectedYear}
            </p>
          </div>
        ) : (
          <>
            <div className="space-y-2">
              {visits.map((visit) => (
                <Card key={visit.id} className="overflow-hidden">
                  <CardContent className="p-3">
                    <div className="flex items-start gap-3">
                      {/* Visit Number Badge */}
                      <div className={`flex flex-col items-center justify-center w-12 h-12 rounded-xl shrink-0 ${
                        visit.visit_number === 1
                          ? 'bg-emerald-100 text-emerald-700'
                          : 'bg-blue-100 text-blue-700'
                      }`}>
                        <span className="text-[10px] font-bold leading-none">VISIT</span>
                        <span className="text-lg font-bold leading-none">{visit.visit_number}</span>
                      </div>

                      {/* Visit Info */}
                      <div className="flex-1 min-w-0">
                        <p className="text-sm font-semibold text-gray-900 truncate">{visit.unit_name}</p>
                        {visit.client_name && (
                          <p className="text-xs text-gray-500 truncate">{visit.client_name}</p>
                        )}
                        <div className="flex items-center gap-1.5 mt-1">
                          <Clock className="w-3 h-3 text-gray-400" />
                          <span className="text-xs text-gray-500">
                            {MONTHS[visit.visit_month - 1]?.label} {visit.visit_year}
                          </span>
                          <span className="text-xs text-gray-300">·</span>
                          <span className={`text-xs font-medium ${
                            visit.status === 'approved' ? 'text-emerald-600'
                            : visit.status === 'rejected' ? 'text-red-600'
                            : 'text-amber-600'
                          }`}>
                            {visit.status.charAt(0).toUpperCase() + visit.status.slice(1)}
                          </span>
                        </div>
                        {visit.notes && (
                          <p className="text-xs text-gray-400 mt-1 line-clamp-2">{visit.notes}</p>
                        )}
                      </div>

                      {/* Actions */}
                      <div className="flex items-center gap-1 shrink-0">
                        <button
                          onClick={() => handlePreview(visit)}
                          className="flex items-center justify-center w-9 h-9 rounded-lg hover:bg-gray-100 transition-colors"
                          title="View document"
                        >
                          <Eye className="w-4 h-4 text-gray-500" />
                        </button>
                        <button
                          onClick={() => setDeleteConfirm(visit)}
                          className="flex items-center justify-center w-9 h-9 rounded-lg hover:bg-red-50 transition-colors"
                          title="Delete visit"
                        >
                          <Trash2 className="w-4 h-4 text-red-400" />
                        </button>
                      </div>
                    </div>

                    {/* Document Preview Thumbnail */}
                    {visit.document_url && visit.document_type === 'image' && (
                      <div className="mt-2">
                        <img
                          src={getFileUrl(visit.document_url) || ''}
                          alt="Checklist"
                          className="w-full max-h-40 object-contain rounded-lg bg-gray-100"
                        />
                      </div>
                    )}
                    {visit.document_url && visit.document_type === 'pdf' && (
                      <div className="mt-2 flex items-center gap-2 p-2 bg-gray-100 rounded-lg">
                        <FileText className="w-4 h-4 text-red-500" />
                        <span className="text-xs text-gray-600 flex-1">PDF Document</span>
                        <button
                          onClick={() => handlePreview(visit)}
                          className="text-xs text-emerald-600 font-medium"
                        >
                          Open PDF
                        </button>
                      </div>
                    )}
                  </CardContent>
                </Card>
              ))}
            </div>

            {/* Pagination */}
            {totalPages > 1 && (
              <div className="flex items-center justify-between mt-3 px-1">
                <p className="text-xs text-muted-foreground">
                  {(page - 1) * PAGE_SIZE + 1}–{Math.min(page * PAGE_SIZE, total)} of {total}
                </p>
                <div className="flex items-center gap-1.5">
                  <Button
                    variant="outline" size="sm" className="h-7 px-2"
                    disabled={page <= 1}
                    onClick={() => setPage((p) => Math.max(1, p - 1))}
                  >
                    <ChevronLeft className="h-3.5 w-3.5" />
                  </Button>
                  <span className="text-sm font-medium">{page} / {totalPages}</span>
                  <Button
                    variant="outline" size="sm" className="h-7 px-2"
                    disabled={page >= totalPages}
                    onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                  >
                    <ChevronRight className="h-3.5 w-3.5" />
                  </Button>
                </div>
              </div>
            )}
          </>
        )}
      </div>

      {/* ── Delete Confirmation Dialog ── */}
      <Dialog open={!!deleteConfirm} onOpenChange={() => setDeleteConfirm(null)}>
        <DialogContent className="max-w-sm">
          <DialogHeader>
            <DialogTitle>Delete Visit?</DialogTitle>
          </DialogHeader>
          <p className="text-sm text-gray-600">
            Are you sure you want to delete the{' '}
            <strong>{deleteConfirm?.visit_number === 1 ? 'First' : 'Second'} Visit</strong>
            {' '}for{' '}
            <strong>{deleteConfirm?.unit_name}</strong>
            {' '}in {MONTHS[(deleteConfirm?.visit_month ?? 1) - 1]?.label} {deleteConfirm?.visit_year}?
            This action cannot be undone.
          </p>
          <DialogFooter className="gap-2">
            <Button variant="outline" onClick={() => setDeleteConfirm(null)}>Cancel</Button>
            <Button variant="destructive" onClick={() => deleteConfirm && handleDelete(deleteConfirm)}>
              <Trash2 className="w-4 h-4 mr-1" />
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
