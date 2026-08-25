'use client';

import { useState, useEffect, useCallback } from 'react';
import {
  CalendarDays, CheckCircle2, XCircle, Clock,
  Save, ChevronLeft, ChevronRight, Loader2, Users, Building2
} from 'lucide-react';
import { toast } from 'sonner';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

import PageHeader from './PageHeader';
import { fetchDailyAttendance, saveDailyAttendance, fetchClients, fetchUnits } from '@/lib/ess-api';
import { useAccess } from '@/contexts/AccessContext';
import type {
  DailyAttendanceEmployee,
  DailyAttendanceSummary,
  DailyAttendanceStatus,
  ClientOption,
  UnitOption,
} from '@/lib/ess-types';

interface Props {
  employeeId: number;
}

const STATUS_OPTIONS: { value: DailyAttendanceStatus; label: string; color: string; icon: React.ReactNode }[] = [
  { value: 'present',     label: 'Present',    color: 'bg-emerald-100 text-emerald-700 border-emerald-300',  icon: <CheckCircle2 className="w-3.5 h-3.5" /> },
  { value: 'absent',     label: 'Absent',    color: 'bg-red-100 text-red-700 border-red-300',                icon: <XCircle className="w-3.5 h-3.5" /> },
  { value: 'half_day',   label: 'Half Day', color: 'bg-amber-100 text-amber-700 border-amber-300',        icon: <Clock className="w-3.5 h-3.5" /> },
  { value: 'leave',      label: 'Leave',     color: 'bg-sky-100 text-sky-700 border-sky-300',              icon: <Clock className="w-3.5 h-3.5" /> },
  { value: 'weekly_off', label: 'Weekly Off',color: 'bg-gray-100 text-gray-600 border-gray-300',           icon: <Clock className="w-3.5 h-3.5" /> },
  { value: 'holiday',    label: 'Holiday',   color: 'bg-purple-100 text-purple-700 border-purple-300',      icon: <CalendarDays className="w-3.5 h-3.5" /> },
];

function getToday(): string {
  return new Date().toLocaleDateString('sv-SE'); // YYYY-MM-DD
}

function shiftDate(dateStr: string, days: number): string {
  const d = new Date(dateStr + 'T00:00:00');
  d.setDate(d.getDate() + days);
  return d.toLocaleDateString('sv-SE');
}

function formatDateDisplay(dateStr: string): string {
  const d = new Date(dateStr + 'T00:00:00');
  return d.toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
}

export default function DailyAttendancePage({ employeeId }: Props) {
  const { allocation, accessLevel, isLoaded } = useAccess();
  const unitIds = allocation?.units ?? [];
  const scope = accessLevel === 'full' ? 'all' : accessLevel;

  // ── Client / Unit dropdowns ──
  const [clients, setClients] = useState<ClientOption[]>([]);
  const [units, setUnits] = useState<UnitOption[]>([]);
  const [selectedClientId, setSelectedClientId] = useState<number | null>(null);
  const [selectedUnitId, setSelectedUnitId] = useState<number | null>(null);
  const [filtersLoading, setFiltersLoading] = useState(true);

  // ── Date ──
  const [date, setDate] = useState(getToday);

  // ── Attendance data ──
  const [employees, setEmployees] = useState<DailyAttendanceEmployee[]>([]);
  const [summary, setSummary] = useState<DailyAttendanceSummary | null>(null);
  const [unitName, setUnitName] = useState('');
  const [localStatuses, setLocalStatuses] = useState<Record<number, DailyAttendanceStatus | ''>>({});
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);

  // ── Load clients + units (like TeamMonthlyPage) ──
  const loadFilters = useCallback(async () => {
    if (unitIds.length === 0) { setFiltersLoading(false); return; }
    setFiltersLoading(true);
    try {
      const [clientsRes, unitsRes] = await Promise.all([
        fetchClients(scope, employeeId, unitIds.length > 0 ? unitIds : undefined),
        fetchUnits(scope, employeeId, undefined, unitIds.length > 0 ? unitIds : undefined),
      ]);
      setClients(clientsRes.data ?? []);
      setUnits(unitsRes.data ?? []);
      // Auto-select first client + unit if not already selected
      if (clientsRes.data && clientsRes.data.length > 0 && !selectedClientId) {
        setSelectedClientId(clientsRes.data[0].id);
      }
    } catch {
      toast.error('Failed to load filters');
    } finally {
      setFiltersLoading(false);
    }
  }, [scope, employeeId, unitIds, selectedClientId]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => { loadFilters(); }, [loadFilters]);

  // Filter units by selected client
  const filteredUnits = selectedClientId
    ? units.filter(u => u.client_id === Number(selectedClientId))
    : units;

  // Auto-select first unit when filtered units change and none selected
  useEffect(() => {
    if (filteredUnits.length > 0 && !selectedUnitId) {
      setSelectedUnitId(filteredUnits[0].id);
    }
  }, [filteredUnits, selectedUnitId]); // eslint-disable-line react-hooks/exhaustive-deps

  // ── Client change handler ──
  const handleClientChange = (val: string) => {
    const cid = parseInt(val);
    setSelectedClientId(cid);
    setSelectedUnitId(null);
    setEmployees([]);
    setSummary(null);
    setLocalStatuses({});
  };

  // ── Unit change handler ──
  const handleUnitChange = (val: string) => {
    setSelectedUnitId(parseInt(val));
    setEmployees([]);
    setSummary(null);
    setLocalStatuses({});
  };

  // ── No units after access loaded ──
  const noUnits = isLoaded && unitIds.length === 0;

  // ── Load attendance data ──
  const loadData = useCallback(async () => {
    if (!selectedUnitId) return;
    setLoading(true);
    const { data, error } = await fetchDailyAttendance(selectedUnitId, date);
    if (error) { toast.error(error); setLoading(false); return; }
    if (data) {
      setEmployees(data.items);
      setSummary(data.summary);
      setUnitName(data.unit_name);
      const init: Record<number, DailyAttendanceStatus | ''> = {};
      for (const emp of data.items) {
        init[emp.employee_id] = (emp.status || '') as DailyAttendanceStatus | '';
      }
      setLocalStatuses(init);
    }
    setLoading(false);
  }, [selectedUnitId, date]);

  useEffect(() => { loadData(); }, [loadData]);

  const setEmployeeStatus = (empId: number, status: DailyAttendanceStatus | '') => {
    setLocalStatuses(prev => ({ ...prev, [empId]: status }));
  };

  const markAll = (status: DailyAttendanceStatus) => {
    const all: Record<number, DailyAttendanceStatus | ''> = {};
    for (const emp of employees) { all[emp.employee_id] = status; }
    setLocalStatuses(all);
    toast.info(`All ${employees.length} employees marked as ${status.replace('_', ' ')}`);
  };

  const handleSave = async () => {
    if (!selectedUnitId) return;
    const records = employees
      .map(emp => ({
        employee_id: emp.employee_id,
        status: localStatuses[emp.employee_id] || emp.status,
      }))
      .filter(r => r.status);

    if (records.length === 0) {
      toast.error('No attendance marked to save');
      return;
    }

    setSaving(true);
    const { data, error } = await saveDailyAttendance(selectedUnitId, date, records as { employee_id: number; status: DailyAttendanceStatus }[]);
    if (error) { toast.error(error); setSaving(false); return; }
    if (data) {
      if (data.errors.length > 0) {
        toast.warning(`${data.saved}/${data.total} saved. ${data.errors.length} errors.`);
      } else {
        toast.success(`${data.saved} attendance records saved`);
      }
      loadData();
    }
    setSaving(false);
  };

  const unmarkedCount = employees.filter(
    e => !localStatuses[e.employee_id] && !e.status
  ).length;

  return (
    <div className="space-y-4 pb-20 md:pb-6">
      <PageHeader title="Daily Attendance" subtitle="Mark employee attendance for your units" />

      {/* ── Date / Client / Unit Card ── */}
      <Card className="border-0 shadow-sm">
        <CardContent className="p-3 space-y-2">
          {/* Date row */}
          <div className="flex items-center gap-2">
            <CalendarDays className="w-4 h-4 text-gray-500 shrink-0" />
            <span className="text-[13px] font-semibold text-gray-700 w-12 shrink-0">Date</span>
            <div className="flex-1 flex items-center justify-between bg-gray-50 rounded-lg px-2 py-1.5">
              <button onClick={() => setDate(d => shiftDate(d, -1))} className="p-1 rounded hover:bg-gray-200 transition-colors active:scale-90">
                <ChevronLeft className="w-5 h-5 text-gray-600" />
              </button>
              <input
                type="date"
                value={date}
                onChange={e => setDate(e.target.value)}
                className="w-full text-center text-[13px] font-bold text-gray-900 bg-transparent border-none outline-none p-0 tabular-nums"
              />
              <button onClick={() => setDate(d => shiftDate(d, 1))} className="p-1 rounded hover:bg-gray-200 transition-colors active:scale-90">
                <ChevronRight className="w-5 h-5 text-gray-600" />
              </button>
            </div>
          </div>

          {/* Client + Unit row */}
          {filtersLoading ? (
            <div className="flex gap-2">
              <Skeleton className="h-9 flex-1 rounded-lg" />
              <Skeleton className="h-9 flex-1 rounded-lg" />
            </div>
          ) : noUnits ? null : (
            <div className="grid grid-cols-2 gap-2">
              {/* Client */}
              <div className="flex items-center gap-1.5">
                <Building2 className="w-4 h-4 text-gray-500 shrink-0" />
                <div className="flex-1 min-w-0">
                  <Select
                    value={selectedClientId ? String(selectedClientId) : ''}
                    onValueChange={handleClientChange}
                  >
                    <SelectTrigger className="w-full text-[13px] h-9">
                      <SelectValue placeholder="Client" />
                    </SelectTrigger>
                    <SelectContent>
                      {clients.map((c) => (
                        <SelectItem key={c.id} value={String(c.id)} className="text-[13px]">{c.name}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>

              {/* Unit */}
              <div className="flex items-center gap-1.5">
                <Building2 className="w-4 h-4 text-gray-500 shrink-0" />
                <div className="flex-1 min-w-0">
                  <Select
                    value={selectedUnitId ? String(selectedUnitId) : ''}
                    onValueChange={handleUnitChange}
                    disabled={!selectedClientId || filteredUnits.length === 0}
                  >
                    <SelectTrigger className="w-full text-[13px] h-9">
                      <SelectValue placeholder={selectedClientId ? 'Unit' : '—'} />
                    </SelectTrigger>
                    <SelectContent>
                      {filteredUnits.map((u) => (
                        <SelectItem key={u.id} value={String(u.id)} className="text-[13px]">{u.name}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {/* ── Quick actions bar ── */}
      {selectedUnitId && (
        <div className="flex gap-2 overflow-x-auto pb-1">
          <button onClick={() => markAll('present')} className="shrink-0 flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 transition">
            <CheckCircle2 className="w-3.5 h-3.5" /> All Present
          </button>
          <button onClick={() => markAll('absent')} className="shrink-0 flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg bg-red-50 text-red-700 border border-red-200 hover:bg-red-100 transition">
            <XCircle className="w-3.5 h-3.5" /> All Absent
          </button>
          <button onClick={handleSave} disabled={saving} className="shrink-0 ml-auto flex items-center gap-1.5 px-4 py-1.5 text-xs font-semibold rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 disabled:opacity-50 transition">
            {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />}
            Save
          </button>
        </div>
      )}

      {/* ── Summary chips ── */}
      {summary && (
        <div className="flex gap-2 flex-wrap">
          <SummaryChip label="Present" count={summary.present} color="emerald" />
          <SummaryChip label="Absent" count={summary.absent} color="red" />
          <SummaryChip label="Half Day" count={summary.half_day} color="amber" />
          <SummaryChip label="Leave" count={summary.leave} color="sky" />
          {unmarkedCount > 0 && <SummaryChip label="Unmarked" count={unmarkedCount} color="gray" />}
        </div>
      )}

      {/* ── No units message (after access loaded) ── */}
      {noUnits && (
        <div className="text-center py-16 text-gray-500">
          <Users className="w-12 h-12 mx-auto mb-3 text-gray-300" />
          <p className="font-medium">No units assigned</p>
          <p className="text-sm mt-1">Contact your manager to get unit access</p>
        </div>
      )}

      {/* ── Employee List ── */}
      {!noUnits && !selectedUnitId && !filtersLoading ? (
        <div className="text-center py-16 text-gray-500">
          <Building2 className="w-12 h-12 mx-auto mb-3 text-gray-300" />
          <p className="font-medium">Select a unit to begin</p>
          <p className="text-sm mt-1">Choose a client, then select a unit</p>
        </div>
      ) : !noUnits && loading ? (
        <div className="flex items-center justify-center py-20">
          <Loader2 className="w-6 h-6 animate-spin text-gray-400" />
        </div>
      ) : employees.length === 0 ? (
        <div className="text-center py-16 text-gray-500">
          <Users className="w-12 h-12 mx-auto mb-3 text-gray-300" />
          <p className="font-medium">No employees found for this unit</p>
          <p className="text-sm mt-1">Select a different unit or date</p>
        </div>
      ) : (
        <div className="space-y-1">
          <div className="text-xs text-gray-500 px-1 flex justify-between">
            <span>{formatDateDisplay(date)}</span>
            <span>{unitName} · {employees.length} employees</span>
          </div>
          <div className="divide-y divide-gray-100 bg-white rounded-xl border overflow-hidden">
            {employees.map(emp => (
              <EmployeeRow
                key={emp.employee_id}
                emp={emp}
                status={localStatuses[emp.employee_id] ?? (emp.status as DailyAttendanceStatus | '')}
                onStatusChange={setEmployeeStatus}
              />
            ))}
          </div>
        </div>
      )}

      {/* ── Floating Save (mobile) ── */}
      {selectedUnitId && employees.length > 0 && (
        <div className="fixed bottom-4 left-4 right-4 z-30 md:hidden">
          <button
            onClick={handleSave}
            disabled={saving}
            className="w-full flex items-center justify-center gap-2 py-3.5 rounded-2xl bg-emerald-600 text-white font-semibold shadow-lg disabled:opacity-50 transition"
          >
            {saving ? <Loader2 className="w-5 h-5 animate-spin" /> : <Save className="w-5 h-5" />}
            Save Attendance
          </button>
        </div>
      )}
    </div>
  );
}

// ── Sub-components ──────────────────────────────────────────────────

function EmployeeRow({
  emp, status, onStatusChange
}: {
  emp: DailyAttendanceEmployee;
  status: DailyAttendanceStatus | '';
  onStatusChange: (empId: number, status: DailyAttendanceStatus | '') => void;
}) {
  return (
    <div className="flex items-center gap-3 px-3 py-2.5">
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2">
          <span className="font-medium text-sm text-gray-900 truncate">{emp.full_name}</span>
          {emp.employee_code && (
            <span className="text-[10px] font-mono text-gray-400">#{emp.employee_code}</span>
          )}
        </div>
        <div className="text-xs text-gray-500 truncate">
          {emp.designation || emp.worker_category || ''}
        </div>
      </div>
      <div className="flex gap-1.5 overflow-x-auto shrink-0">
        {STATUS_OPTIONS.map(opt => {
          const isActive = status === opt.value;
          return (
            <button
              key={opt.value}
              onClick={() => onStatusChange(emp.employee_id, isActive ? '' : opt.value)}
              className={`shrink-0 flex items-center gap-1 px-2 py-1 rounded-lg text-[11px] font-medium border transition-all ${
                isActive
                  ? opt.color
                  : 'bg-gray-50 text-gray-400 border-gray-200 hover:bg-gray-100'
              }`}
            >
              {opt.icon}
              <span className="hidden sm:inline">{opt.label}</span>
            </button>
          );
        })}
      </div>
    </div>
  );
}

function SummaryChip({ label, count, color }: { label: string; count: number; color: string }) {
  const colorMap: Record<string, string> = {
    emerald: 'bg-emerald-50 text-emerald-700',
    red: 'bg-red-50 text-red-700',
    amber: 'bg-amber-50 text-amber-700',
    sky: 'bg-sky-50 text-sky-700',
    gray: 'bg-gray-100 text-gray-500',
  };
  return (
    <span className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium ${colorMap[color] ?? colorMap.gray}`}>
      {count} {label}
    </span>
  );
}