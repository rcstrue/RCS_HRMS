'use client';

import { useState, useEffect, useCallback } from 'react';
import { toast } from 'sonner';
import {
  Calendar,
  ChevronLeft,
  ChevronRight,
  Building2,
  Sun,
  Moon,
  Plus,
  Minus,
  Save,
  Loader2,
  Trash2,
  Info,
} from 'lucide-react';

import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

import type { ClientOption, UnitOption, ManpowerEntry } from '@/lib/ess-types';
import { fetchClients, fetchUnits, fetchManpowerEntries, saveManpowerStatus, deleteManpowerStatus } from '@/lib/ess-api';

// ══════════════════════════════════════════════════════════════
// Types
// ══════════════════════════════════════════════════════════════

interface ShiftData {
  workerBudget: number;
  workerActual: number;
  supervisorBudget: number;
  supervisorActual: number;
}

interface Props {
  employeeId: number;
  unitIds: number[];
}

// ══════════════════════════════════════════════════════════════
// Helpers
// ══════════════════════════════════════════════════════════════

function formatDateDisplay(dateStr: string): string {
  const d = new Date(dateStr + 'T00:00:00');
  return d.toLocaleDateString('en-IN', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function toYMD(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

function todayYMD(): string {
  return toYMD(new Date());
}

function prevDayYMD(dateStr: string): string {
  const d = new Date(dateStr + 'T00:00:00');
  d.setDate(d.getDate() - 1);
  return toYMD(d);
}

const emptyShift = (): ShiftData => ({
  workerBudget: 0, workerActual: 0,
  supervisorBudget: 0, supervisorActual: 0,
});

function shiftFromEntry(entry: ManpowerEntry, shift: 'morning' | 'evening'): ShiftData {
  const s = entry[shift];
  return {
    workerBudget: s.worker_budget,
    workerActual: s.worker_actual,
    supervisorBudget: s.supervisor_budget,
    supervisorActual: s.supervisor_actual,
  };
}

// ══════════════════════════════════════════════════════════════
// Component
// ══════════════════════════════════════════════════════════════

export default function ManpowerStatusEntry({ employeeId, unitIds }: Props) {
  // ── Date navigation ──
  const [selectedDate, setSelectedDate] = useState(todayYMD);
  const today = todayYMD();

  const goToPrevDay = useCallback(() => {
    setSelectedDate((prev) => {
      const d = new Date(prev + 'T00:00:00');
      d.setDate(d.getDate() - 1);
      return toYMD(d);
    });
  }, []);

  const goToNextDay = useCallback(() => {
    setSelectedDate((prev) => {
      if (prev >= today) return prev;
      const d = new Date(prev + 'T00:00:00');
      d.setDate(d.getDate() + 1);
      return toYMD(d);
    });
  }, [today]);

  const canGoNext = selectedDate < today;

  // ── Client / Unit dropdowns ──
  const [clients, setClients] = useState<ClientOption[]>([]);
  const [units, setUnits] = useState<UnitOption[]>([]);
  const [selectedClientId, setSelectedClientId] = useState<number | null>(null);
  const [selectedUnitId, setSelectedUnitId] = useState<number | null>(null);
  const [clientsLoading, setClientsLoading] = useState(true);
  const [unitsLoading, setUnitsLoading] = useState(false);

  // Load clients (filtered by unit access)
  useEffect(() => {
    let cancelled = false;
    setClientsLoading(true);
    fetchClients(undefined, undefined, unitIds).then(({ data, error }) => {
      if (cancelled) return;
      if (error) { toast.error(error); return; }
      setClients(data || []);
      if (data && data.length > 0 && !selectedClientId) {
        setSelectedClientId(data[0].id);
      }
      setClientsLoading(false);
    });
    return () => { cancelled = true; };
  }, [unitIds, selectedClientId]);

  // Load units when client changes
  useEffect(() => {
    if (!selectedClientId) { setUnits([]); return; }
    let cancelled = false;
    setUnitsLoading(true);
    fetchUnits(undefined, undefined, selectedClientId, unitIds).then(({ data, error }) => {
      if (cancelled) return;
      if (error) { toast.error(error); return; }
      setUnits(data || []);
      if (data && data.length > 0 && !selectedUnitId) {
        setSelectedUnitId(data[0].id);
      }
      setUnitsLoading(false);
    });
    return () => { cancelled = true; };
  }, [selectedClientId, unitIds, selectedUnitId]);

  // ── Shift data ──
  const [morning, setMorning] = useState<ShiftData>(emptyShift());
  const [evening, setEvening] = useState<ShiftData>(emptyShift());
  const [remarks, setRemarks] = useState('');
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [existingEntryId, setExistingEntryId] = useState<number | null>(null);
  const [loadingExisting, setLoadingExisting] = useState(false);
  const [isCarriedForward, setIsCarriedForward] = useState(false);
  const [carriedFromDate, setCarriedFromDate] = useState('');

  // Derived summaries
  const morningTotalBudget = morning.workerBudget + morning.supervisorBudget;
  const morningTotalActual = morning.workerActual + morning.supervisorActual;
  const morningShortage = morningTotalBudget - morningTotalActual;
  const eveningTotalBudget = evening.workerBudget + evening.supervisorBudget;
  const eveningTotalActual = evening.workerActual + evening.supervisorActual;
  const eveningShortage = eveningTotalBudget - eveningTotalActual;
  const overallBudget = morningTotalBudget + eveningTotalBudget;
  const overallActual = morningTotalActual + eveningTotalActual;
  const overallShortage = overallBudget - overallActual;

  // Load existing entry for date/unit, or carry forward from previous day
  const loadExisting = useCallback(async () => {
    if (!selectedUnitId || !selectedDate) {
      setExistingEntryId(null);
      return;
    }
    setLoadingExisting(true);
    setIsCarriedForward(false);
    setCarriedFromDate('');

    // Try to find an entry for this date + unit
    const { data, error } = await fetchManpowerEntries({
      date: selectedDate,
      unit_id: selectedUnitId,
      unit_ids: unitIds,
    });
    if (error) { toast.error(error); setLoadingExisting(false); return; }

    const entry = data?.find((e) => e.unit_id === selectedUnitId);

    if (entry) {
      // Found entry for this date — load it
      setExistingEntryId(entry.id);
      setMorning(shiftFromEntry(entry, 'morning'));
      setEvening(shiftFromEntry(entry, 'evening'));
      setRemarks(entry.remarks || '');
    } else {
      // No entry for this date — carry forward budget only from previous day
      setExistingEntryId(null);
      setRemarks('');

      const yesterday = prevDayYMD(selectedDate);
      const { data: prevData } = await fetchManpowerEntries({
        date: yesterday,
        unit_id: selectedUnitId,
        unit_ids: unitIds,
      });

      const prevEntry = prevData?.find((e) => e.unit_id === selectedUnitId);
      if (prevEntry) {
        // Carry forward BUDGET ONLY from yesterday, reset actuals to 0
        const m = shiftFromEntry(prevEntry, 'morning');
        const e = shiftFromEntry(prevEntry, 'evening');
        setMorning({ workerBudget: m.workerBudget, workerActual: 0, supervisorBudget: m.supervisorBudget, supervisorActual: 0 });
        setEvening({ workerBudget: e.workerBudget, workerActual: 0, supervisorBudget: e.supervisorBudget, supervisorActual: 0 });
        setIsCarriedForward(true);
        setCarriedFromDate(yesterday);
      } else {
        // No previous entry either — start fresh
        setMorning(emptyShift());
        setEvening(emptyShift());
      }
    }
    setLoadingExisting(false);
  }, [selectedUnitId, selectedDate, unitIds]);

  useEffect(() => {
    loadExisting();
  }, [loadExisting]);

  // ── Save handler ──
  const handleSave = async () => {
    if (!selectedUnitId || !selectedClientId) {
      toast.error('Please select a client and unit');
      return;
    }
    setSaving(true);
    const { error } = await saveManpowerStatus({
      unit_id: selectedUnitId,
      client_id: selectedClientId,
      report_date: selectedDate,
      morning_worker_budget: morning.workerBudget,
      morning_worker_actual: morning.workerActual,
      morning_supervisor_budget: morning.supervisorBudget,
      morning_supervisor_actual: morning.supervisorActual,
      evening_worker_budget: evening.workerBudget,
      evening_worker_actual: evening.workerActual,
      evening_supervisor_budget: evening.supervisorBudget,
      evening_supervisor_actual: evening.supervisorActual,
      remarks: remarks.trim() || undefined,
    });
    setSaving(false);
    if (error) { toast.error(error); return; }
    toast.success(existingEntryId ? 'Manpower status updated!' : 'Manpower status saved!');
    loadExisting();
  };

  // ── Delete handler ──
  const handleDelete = async () => {
    if (!existingEntryId) return;
    if (!confirm('Delete this manpower status entry?')) return;
    setDeleting(true);
    const { error } = await deleteManpowerStatus(existingEntryId);
    setDeleting(false);
    if (error) { toast.error(error); return; }
    toast.success('Entry deleted');
    loadExisting();
  };

  // ── Value adjusters ──
  const adjustValue = (
    shift: 'morning' | 'evening',
    field: keyof ShiftData,
    delta: number,
  ) => {
    const setter = shift === 'morning' ? setMorning : setEvening;
    setter((prev) => {
      const val = Math.max(0, (prev[field] || 0) + delta);
      return { ...prev, [field]: val };
    });
  };

  // ── Client/Unit change handlers that reset form ──
  const handleClientChange = (val: string) => {
    const cid = parseInt(val);
    setSelectedClientId(cid);
    setSelectedUnitId(null);
    setExistingEntryId(null);
    setIsCarriedForward(false);
    setCarriedFromDate('');
    setMorning(emptyShift());
    setEvening(emptyShift());
    setRemarks('');
  };

  const handleUnitChange = (val: string) => {
    setSelectedUnitId(parseInt(val));
  };

  // ════════════════════════════════════════════════════════════
  return (
    <div className="space-y-3">
      {/* ── Header ── */}
      <div className="bg-gradient-to-r from-blue-600 to-blue-500 rounded-2xl px-4 py-3 text-white flex items-center justify-between shadow-lg">
        <div className="flex items-center gap-2">
          <Sun className="w-5 h-5 text-white/80" />
          <h1 className="text-[15px] font-bold">Daily Manpower Status</h1>
        </div>
        {existingEntryId && (
          <button
            onClick={handleDelete}
            disabled={deleting}
            className="p-2 rounded-lg bg-white/20 hover:bg-white/30 transition-colors"
            title="Delete entry"
          >
            {deleting ? <Loader2 className="w-4 h-4 animate-spin" /> : <Trash2 className="w-4 h-4" />}
          </button>
        )}
      </div>

      {/* ── Date / Client / Unit — compact layout ── */}
      {loadingExisting ? (
        <div className="space-y-2">
          <Skeleton className="h-12 w-full rounded-xl" />
        </div>
      ) : (
        <Card className="border-0 shadow-sm">
          <CardContent className="p-3 space-y-2">
            {/* Date row */}
            <div className="flex items-center gap-2">
              <Calendar className="w-4 h-4 text-gray-500 shrink-0" />
              <span className="text-[13px] font-semibold text-gray-700 w-12 shrink-0">Date</span>
              <div className="flex-1 flex items-center justify-between bg-gray-50 rounded-lg px-2 py-1.5">
                <button
                  onClick={goToPrevDay}
                  className="p-1 rounded hover:bg-gray-200 transition-colors active:scale-90"
                >
                  <ChevronLeft className="w-5 h-5 text-gray-600" />
                </button>
                <span className="text-[13px] font-bold text-gray-900 tabular-nums">
                  {formatDateDisplay(selectedDate)}
                </span>
                <button
                  onClick={goToNextDay}
                  disabled={!canGoNext}
                  className={`p-1 rounded transition-colors active:scale-90 ${canGoNext ? 'hover:bg-gray-200' : 'opacity-30 cursor-not-allowed'}`}
                >
                  <ChevronRight className="w-5 h-5 text-gray-600" />
                </button>
              </div>
            </div>

            {/* Client + Unit row side by side */}
            <div className="grid grid-cols-2 gap-2">
              {/* Client */}
              <div className="flex items-center gap-1.5">
                <Building2 className="w-4 h-4 text-gray-500 shrink-0" />
                <div className="flex-1 min-w-0">
                  {clientsLoading ? (
                    <Skeleton className="h-9 w-full rounded-lg" />
                  ) : (
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
                  )}
                </div>
              </div>

              {/* Unit */}
              <div className="flex items-center gap-1.5">
                <Building2 className="w-4 h-4 text-gray-500 shrink-0" />
                <div className="flex-1 min-w-0">
                  {unitsLoading ? (
                    <Skeleton className="h-9 w-full rounded-lg" />
                  ) : (
                    <Select
                      value={selectedUnitId ? String(selectedUnitId) : ''}
                      onValueChange={handleUnitChange}
                      disabled={!selectedClientId || units.length === 0}
                    >
                      <SelectTrigger className="w-full text-[13px] h-9">
                        <SelectValue placeholder={selectedClientId ? 'Unit' : '—'} />
                      </SelectTrigger>
                      <SelectContent>
                        {units.map((u) => (
                          <SelectItem key={u.id} value={String(u.id)} className="text-[13px]">{u.name}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                </div>
              </div>
            </div>
          </CardContent>
        </Card>
      )}

      {/* ── Carry-forward notice ── */}
      {isCarriedForward && selectedUnitId && (
        <div className="flex items-start gap-2 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2.5">
          <Info className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
          <div className="min-w-0">
            <p className="text-[12px] font-semibold text-amber-800">
              Budget copied from {formatDateDisplay(carriedFromDate)}
            </p>
            <p className="text-[11px] text-amber-600 mt-0.5">
              Actuals are reset to 0. Verify & update actuals, then SAVE.
            </p>
          </div>
        </div>
      )}

      {/* ── Morning Section ── */}
      {selectedUnitId && (
        <ShiftTable
          label="MORNING"
          icon={<Sun className="w-4 h-4" />}
          colorClass="blue"
          data={morning}
          onAdjust={(field, delta) => adjustValue('morning', field, delta)}
        />
      )}

      {/* ── Evening Section ── */}
      {selectedUnitId && (
        <ShiftTable
          label="EVENING"
          icon={<Moon className="w-4 h-4" />}
          colorClass="emerald"
          data={evening}
          onAdjust={(field, delta) => adjustValue('evening', field, delta)}
        />
      )}

      {/* ── Summary Row ── */}
      {selectedUnitId && (
        <Card className="border-0 shadow-sm overflow-hidden">
          <div className="bg-amber-50 px-3 py-2.5">
            <div className="grid grid-cols-3 gap-2 text-center">
              <div>
                <p className="text-[10px] font-semibold text-gray-500 uppercase tracking-wide">Total Budget</p>
                <p className="text-lg font-bold text-gray-900 tabular-nums">{overallBudget}</p>
              </div>
              <div>
                <p className="text-[10px] font-semibold text-gray-500 uppercase tracking-wide">Total Actual</p>
                <p className="text-lg font-bold text-blue-600 tabular-nums">{overallActual}</p>
              </div>
              <div>
                <p className="text-[10px] font-semibold text-gray-500 uppercase tracking-wide">Shortage</p>
                <p className={`text-lg font-bold tabular-nums ${overallShortage > 0 ? 'text-rose-600' : 'text-emerald-600'}`}>
                  {overallShortage}
                </p>
              </div>
            </div>
          </div>
        </Card>
      )}

      {/* ── Remarks ── */}
      {selectedUnitId && (
        <Card className="border-0 shadow-sm">
          <CardContent className="px-3 py-2.5">
            <input
              type="text"
              value={remarks}
              onChange={(e) => setRemarks(e.target.value)}
              placeholder="Remarks (optional)"
              className="w-full border-b-2 border-gray-200 focus:border-blue-500 outline-none py-1.5 text-[13px] text-gray-900 placeholder:text-gray-400 bg-transparent transition-colors"
            />
          </CardContent>
        </Card>
      )}

      {/* ── Save Button ── */}
      {selectedUnitId && (
        <Button
          className="w-full h-12 text-[15px] font-bold bg-blue-600 hover:bg-blue-700 text-white gap-2 shadow-lg rounded-xl"
          onClick={handleSave}
          disabled={saving}
        >
          {saving ? <Loader2 className="w-5 h-5 animate-spin" /> : <Save className="w-5 h-5" />}
          {saving ? 'Saving...' : 'SAVE'}
        </Button>
      )}
    </div>
  );
}

// ══════════════════════════════════════════════════════════════
// Shift Table — Morning / Evening
// ══════════════════════════════════════════════════════════════

function ShiftTable({
  label,
  icon,
  colorClass,
  data,
  onAdjust,
}: {
  label: string;
  icon: React.ReactNode;
  colorClass: 'blue' | 'emerald';
  data: ShiftData;
  onAdjust: (field: keyof ShiftData, delta: number) => void;
}) {
  const bgMap = { blue: 'bg-blue-50', emerald: 'bg-emerald-50' };
  const textMap = { blue: 'text-blue-600', emerald: 'text-emerald-600' };
  const headerBgMap = { blue: 'bg-blue-100/80', emerald: 'bg-emerald-100/80' };

  const workerTotal = data.workerBudget + data.supervisorBudget;
  const actualTotal = data.workerActual + data.supervisorActual;
  const shortage = workerTotal - actualTotal;
  const shortageColor = shortage > 0 ? 'text-rose-600 font-bold' : 'text-emerald-600 font-bold';

  return (
    <Card className="border-0 shadow-sm overflow-hidden">
      {/* Shift header */}
      <div className={`${headerBgMap[colorClass]} px-3 py-2 flex items-center gap-2`}>
        {icon}
        <span className={`text-[13px] font-bold ${textMap[colorClass]}`}>{label}</span>
      </div>

      {/* Table */}
      <div className={`${bgMap[colorClass]} px-2 py-2`}>
        {/* Header row */}
        <div className="grid grid-cols-[1fr_1fr_1.4fr] gap-1 text-[10px] font-bold text-gray-500 uppercase tracking-wide px-2 mb-1.5">
          <span>Category</span>
          <span className="text-center">Budget</span>
          <span className="text-center">Actual (Present)</span>
        </div>

        {/* Worker row */}
        <ShiftRow
          label="Worker"
          budget={data.workerBudget}
          actual={data.workerActual}
          budgetColor="text-gray-500"
          onBudgetUp={() => onAdjust('workerBudget', 1)}
          onBudgetDown={() => onAdjust('workerBudget', -1)}
          onActualUp={() => onAdjust('workerActual', 1)}
          onActualDown={() => onAdjust('workerActual', -1)}
        />

        {/* Supervisor row */}
        <ShiftRow
          label="Supervisor"
          budget={data.supervisorBudget}
          actual={data.supervisorActual}
          budgetColor="text-gray-500"
          onBudgetUp={() => onAdjust('supervisorBudget', 1)}
          onBudgetDown={() => onAdjust('supervisorBudget', -1)}
          onActualUp={() => onAdjust('supervisorActual', 1)}
          onActualDown={() => onAdjust('supervisorActual', -1)}
        />

        {/* Total row */}
        <div className="grid grid-cols-[1fr_1fr_1.4fr] gap-1 items-center px-2 py-1.5 mt-1">
          <span className="text-[12px] font-semibold text-gray-900">Total</span>
          <span className={`text-sm font-bold text-center ${textMap[colorClass]} tabular-nums`}>{workerTotal}</span>
          <span className={`text-sm font-bold text-center ${textMap[colorClass]} tabular-nums`}>{actualTotal}</span>
        </div>

        {/* Shortage row */}
        <div className="grid grid-cols-[1fr_1fr_1.4fr] gap-1 items-center px-2 py-1.5 border-t border-gray-200/60">
          <span className="text-[12px] font-semibold text-gray-900">Shortage</span>
          <span className="text-sm text-center text-gray-300">—</span>
          <span className={`text-sm text-center tabular-nums ${shortageColor}`}>{shortage}</span>
        </div>
      </div>
    </Card>
  );
}

// ══════════════════════════════════════════════════════════════
// Shift Row — horizontal [−] VALUE [+] layout
// ══════════════════════════════════════════════════════════════

function ShiftRow({
  label,
  budget,
  actual,
  budgetColor,
  onBudgetUp,
  onBudgetDown,
  onActualUp,
  onActualDown,
}: {
  label: string;
  budget: number;
  actual: number;
  budgetColor: string;
  onBudgetUp: () => void;
  onBudgetDown: () => void;
  onActualUp: () => void;
  onActualDown: () => void;
}) {
  return (
    <div className="grid grid-cols-[1fr_1fr_1.4fr] gap-1 items-center px-2 py-1.5">
      <span className="text-[12px] font-medium text-gray-700">{label}</span>
      {/* Budget: [−] value [+] */}
      <div className="flex items-center justify-center gap-0.5">
        <button
          onClick={onBudgetDown}
          className="w-8 h-8 flex items-center justify-center rounded-lg bg-white shadow-sm hover:bg-gray-100 active:scale-90 transition-all"
        >
          <Minus className="w-4 h-4 text-gray-500" />
        </button>
        <span className={`text-sm tabular-nums min-w-[2rem] text-center font-medium ${budgetColor}`}>{budget}</span>
        <button
          onClick={onBudgetUp}
          className="w-8 h-8 flex items-center justify-center rounded-lg bg-white shadow-sm hover:bg-gray-100 active:scale-90 transition-all"
        >
          <Plus className="w-4 h-4 text-gray-500" />
        </button>
      </div>
      {/* Actual: [−] value [+] */}
      <div className="flex items-center justify-center gap-0.5">
        <button
          onClick={onActualDown}
          className="w-8 h-8 flex items-center justify-center rounded-lg bg-white shadow-sm hover:bg-gray-100 active:scale-90 transition-all"
        >
          <Minus className="w-4 h-4 text-gray-500" />
        </button>
        <span className="text-sm tabular-nums min-w-[2rem] text-center font-semibold text-gray-900">{actual}</span>
        <button
          onClick={onActualUp}
          className="w-8 h-8 flex items-center justify-center rounded-lg bg-white shadow-sm hover:bg-gray-100 active:scale-90 transition-all"
        >
          <Plus className="w-4 h-4 text-gray-500" />
        </button>
      </div>
    </div>
  );
}