'use client';

import { useState, useEffect, useCallback } from 'react';
import { toast } from 'sonner';
import PageHeader from './PageHeader';
import { fetchUnits, fetchUnitVisits } from '@/lib/ess-api';
import type { UnitOption, UnitVisit, PaginatedResponse } from '@/lib/ess-types';

import UnitVisitChecklistForm from './UnitVisitChecklistForm';
import UnitVisitReport from './UnitVisitReport';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
  MapPin, Plus, ChevronLeft, ChevronRight, Loader2, Eye, Trash2,
  List,
} from 'lucide-react';

// ══════════════════════════════════════════════════════════════

const MONTHS = [
  { value: 0, label: 'All Months' }, { value: 1, label: 'January' }, { value: 2, label: 'February' },
  { value: 3, label: 'March' }, { value: 4, label: 'April' }, { value: 5, label: 'May' },
  { value: 6, label: 'June' }, { value: 7, label: 'July' }, { value: 8, label: 'August' },
  { value: 9, label: 'September' }, { value: 10, label: 'October' }, { value: 11, label: 'November' },
  { value: 12, label: 'December' },
];

const STATUS_OPTIONS = [
  { value: '', label: 'All Status' },
  { value: 'submitted', label: 'Submitted' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
];

interface UnitVisitsPageProps {
  employeeId: number;
  employeeName: string;
  unitIds: number[];
}

export default function UnitVisitsPage({ employeeId, employeeName, unitIds }: UnitVisitsPageProps) {
  const [showForm, setShowForm] = useState(false);
  const [selectedVisitId, setSelectedVisitId] = useState<number | null>(null);

  const [visits, setVisits] = useState<UnitVisit[]>([]);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [total, setTotal] = useState(0);

  const [filterMonth, setFilterMonth] = useState(0);
  const [filterYear, setFilterYear] = useState(new Date().getFullYear());
  const [filterStatus, setFilterStatus] = useState('');

  const [units, setUnits] = useState<UnitOption[]>([]);

  // Load units
  useEffect(() => {
    fetchUnits(undefined, employeeId, undefined, unitIds).then(({ data, error }) => {
      if (error) return;
      if (data) setUnits(Array.isArray(data) ? data : []);
    });
  }, [employeeId, unitIds]);

  // Load visits
  const loadVisits = useCallback(async (p = page) => {
    setLoading(true);
    const params: Record<string, string | number> = { employee_id: employeeId, page: p, limit: 20 };
    if (filterMonth > 0) params.month = filterMonth;
    if (filterYear) params.year = filterYear;
    if (filterStatus) params.status = filterStatus;
    const { data, error } = await fetchUnitVisits(params as any);
    if (error) { toast.error(error); setLoading(false); return; }
    const res = data as any;
    setVisits(res?.items || []);
    setTotal(res?.pagination?.total || 0);
    setTotalPages(res?.pagination?.total_pages || 1);
    setLoading(false);
  }, [employeeId, filterMonth, filterYear, filterStatus, page]);

  useEffect(() => { loadVisits(); }, [loadVisits]);

  const handleFormSuccess = () => { setShowForm(false); setPage(1); };
  const handleVisitClick = (id: number) => setSelectedVisitId(id);

  // ── Detail View ──
  if (selectedVisitId) {
    return (
      <div className="pb-24">
        <UnitVisitReport
          visitId={selectedVisitId}
          employeeId={employeeId}
          employeeName={employeeName}
          onBack={() => { setSelectedVisitId(null); loadVisits(); }}
          onDeleted={() => { setSelectedVisitId(null); }}
        />
      </div>
    );
  }

  // ── Form View ──
  if (showForm) {
    return (
      <div className="pb-24">
        <PageHeader title="New Visit" onBack={() => setShowForm(false)} />
        <UnitVisitChecklistForm
          employeeId={employeeId}
          employeeName={employeeName}
          units={units}
          onSuccess={handleFormSuccess}
          onCancel={() => setShowForm(false)}
        />
      </div>
    );
  }

  const statusBadge: Record<string, string> = {
    submitted: 'bg-blue-100 text-blue-700 border-blue-200',
    approved: 'bg-emerald-100 text-emerald-700 border-emerald-200',
    rejected: 'bg-red-100 text-red-700 border-red-200',
  };

  return (
    <div className="pb-24">
      <PageHeader title="Unit Visit Checklist" />

      {/* Filters */}
      <div className="flex gap-2 items-end mb-4">
        <div className="flex-1 min-w-0">
          <Select value={String(filterMonth)} onValueChange={v => { setFilterMonth(parseInt(v)); setPage(1); }}>
            <SelectTrigger className="h-9 text-xs"><SelectValue /></SelectTrigger>
            <SelectContent>{MONTHS.map(m => <SelectItem key={m.value} value={String(m.value)}>{m.label}</SelectItem>)}</SelectContent>
          </Select>
        </div>
        <div className="w-20">
          <Select value={String(filterYear)} onValueChange={v => { setFilterYear(parseInt(v)); setPage(1); }}>
            <SelectTrigger className="h-9 text-xs"><SelectValue /></SelectTrigger>
            <SelectContent>
              {Array.from({ length: 3 }, (_, i) => new Date().getFullYear() - i).map(y => (
                <SelectItem key={y} value={String(y)}>{y}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="w-28">
          <Select value={filterStatus} onValueChange={v => { setFilterStatus(v); setPage(1); }}>
            <SelectTrigger className="h-9 text-xs"><SelectValue /></SelectTrigger>
            <SelectContent>{STATUS_OPTIONS.map(s => <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>)}</SelectContent>
          </Select>
        </div>
      </div>

      {/* Actions */}
      <div className="flex items-center justify-between mb-4">
        <p className="text-xs text-gray-500">{total} visit{total !== 1 ? 's' : ''} found</p>
        <Button size="sm" className="h-8 text-xs gap-1 bg-emerald-600 hover:bg-emerald-700" onClick={() => setShowForm(true)}><Plus className="w-3.5 h-3.5" /> New Visit</Button>
      </div>

      {/* Visit List */}
      {loading ? (
        <div className="space-y-3">{[1, 2, 3].map(i => <Card key={i}><CardContent className="p-4"><Skeleton className="h-4 w-3/4 mb-2" /><Skeleton className="h-3 w-1/2" /></CardContent></Card>)}</div>
      ) : visits.length === 0 ? (
        <Card className="border-dashed"><CardContent className="py-12 text-center">
          <MapPin className="w-10 h-10 text-gray-300 mx-auto mb-3" />
          <p className="text-sm font-medium text-gray-500">No visits found</p>
          <p className="text-xs text-gray-400 mt-1">Submit your first unit visit checklist</p>
          <Button className="mt-4 bg-emerald-600 hover:bg-emerald-700" size="sm" onClick={() => setShowForm(true)}><Plus className="w-4 h-4" /> New Visit</Button>
        </CardContent></Card>
      ) : (
        <div className="space-y-3">
          {visits.map(visit => (
            <Card key={visit.id} className="cursor-pointer hover:shadow-md transition-shadow active:scale-[0.99]" onClick={() => handleVisitClick(visit.id)}>
              <CardContent className="p-4">
                <div className="flex items-start justify-between gap-3">
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 mb-1">
                      <p className="text-sm font-semibold text-gray-900 truncate">{visit.unit_name || 'Unknown Unit'}</p>
                      <Badge variant="outline" className={`text-[10px] shrink-0 ${statusBadge[visit.status] || ''}`}>{visit.status.charAt(0).toUpperCase() + visit.status.slice(1)}</Badge>
                    </div>
                    <p className="text-xs text-gray-500">{visit.client_name || ''}</p>
                    <div className="flex items-center gap-3 mt-2 text-xs text-gray-400">
                      <span>{visit.visit_number === 1 ? '1st' : '2nd'} Visit</span>
                      <span>{MONTHS[visit.visit_month]?.label || ''} {visit.visit_year}</span>
                      <span>{visit.created_at ? new Date(visit.created_at).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) : ''}</span>
                    </div>
                  </div>
                  <Eye className="w-4 h-4 text-gray-400 mt-1 shrink-0" />
                </div>
              </CardContent>
            </Card>
          ))}
          {totalPages > 1 && (
            <div className="flex items-center justify-between">
              <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(p => p - 1)}><ChevronLeft className="w-4 h-4" /></Button>
              <span className="text-xs text-gray-500">Page {page} of {totalPages}</span>
              <Button size="sm" variant="outline" disabled={page >= totalPages} onClick={() => setPage(p => p + 1)}><ChevronRight className="w-4 h-4" /></Button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}