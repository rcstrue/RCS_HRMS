'use client';

import { useState, useEffect, useCallback, useRef } from 'react';
import { toast } from 'sonner';
import {
  ClipboardList,
  Loader2,
  Save,
  ChevronLeft,
  ChevronRight,
  Download,
  Plus,
  Trash2,
  UserMinus,
  AlertTriangle,
  GripVertical,
} from 'lucide-react';

import { cn } from '@/lib/utils';
import {
  fetchClients,
  fetchUnits,
  fetchTeamSummary,
  saveTeamAdvance,
  addTempEmployee,
  deleteTempEmployee,
  removeEmployee,
} from '@/lib/ess-api';
import type { ClientOption, UnitOption, TeamSummaryRow, TeamSummaryTotals } from '@/lib/ess-types';

import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';

// ── Props ──────────────────────────────────────────
interface TeamMonthlyPageProps {
  employeeId: number;
  scope: string;
  unitIds: number[];
}

// ── Month names ────────────────────────────────────
const MONTHS = [
  'January','February','March','April','May','June',
  'July','August','September','October','November','December',
];

// ── Column definitions ─────────────────────────────
interface ColDef {
  key: string;            // field key in data row
  label: string;          // header text
  minWidth: number;       // minimum width in px
  defaultWidth: number;   // starting width in px
  editable: boolean;
  type: 'attendance' | 'advance' | 'text' | 'action';
}

const COLUMNS: ColDef[] = [
  { key: 'employee_code', label: 'Code',    minWidth: 60,  defaultWidth: 70,  editable: false, type: 'text' },
  { key: 'full_name',     label: 'Name',    minWidth: 120, defaultWidth: 160, editable: false, type: 'text' },
  { key: 'present',       label: 'Present', minWidth: 70,  defaultWidth: 80,  editable: true,  type: 'attendance' },
  { key: 'wo',            label: 'WO',      minWidth: 60,  defaultWidth: 70,  editable: true,  type: 'attendance' },
  { key: 'adv1',          label: 'Adv 1',   minWidth: 80,  defaultWidth: 90,  editable: true,  type: 'advance' },
  { key: 'office_advance',label: 'Off Adv', minWidth: 80,  defaultWidth: 90,  editable: true,  type: 'advance' },
  { key: 'dress_advance', label: 'Dress Adv',minWidth: 80, defaultWidth: 90, editable: true,  type: 'advance' },
  { key: '_action',       label: '',        minWidth: 40,  defaultWidth: 44,  editable: false, type: 'action' },
];

// ── Helpers ────────────────────────────────────────
function getPreviousMonth(): [number, number] {
  const now = new Date();
  const m = now.getMonth();
  if (m === 0) return [12, now.getFullYear() - 1];
  return [m, now.getFullYear()];
}

// ── Column Resize Hook ─────────────────────────────
function useColumnResize(defaultWidths: number[], minWidths: number[]) {
  const [widths, setWidths] = useState(defaultWidths);
  const resizingIdx = useRef<number | null>(null);
  const startX = useRef(0);
  const startWidth = useRef(0);
  const tableRef = useRef<HTMLDivElement>(null);

  const onPointerDown = useCallback((idx: number, e: React.PointerEvent) => {
    e.preventDefault();
    resizingIdx.current = idx;
    startX.current = e.clientX;
    startWidth.current = widths[idx];
    (e.target as HTMLElement).setPointerCapture(e.pointerId);
  }, [widths]);

  const onPointerMove = useCallback((e: React.PointerEvent) => {
    if (resizingIdx.current === null) return;
    const diff = e.clientX - startX.current;
    const idx = resizingIdx.current;
    const newWidth = Math.max(minWidths[idx], startWidth.current + diff);
    setWidths(prev => {
      const next = [...prev];
      next[idx] = newWidth;
      return next;
    });
  }, [minWidths]);

  const onPointerUp = useCallback(() => {
    resizingIdx.current = null;
  }, []);

  return { widths, tableRef, onPointerDown, onPointerMove, onPointerUp };
}

// ── Component ──────────────────────────────────────
export default function TeamMonthlyPage({ employeeId, scope, unitIds }: TeamMonthlyPageProps) {
  // Filters — default to previous month
  const [prevMonth, prevYear] = getPreviousMonth();
  const [clients, setClients] = useState<ClientOption[]>([]);
  const [units, setUnits] = useState<UnitOption[]>([]);
  const [filtersLoading, setFiltersLoading] = useState(true);

  const [selectedClient, setSelectedClient] = useState('');
  const [selectedUnit, setSelectedUnit] = useState('');
  const [selectedMonth, setSelectedMonth] = useState(prevMonth);
  const [selectedYear, setSelectedYear] = useState(prevYear);

  // Data
  const [rows, setRows] = useState<TeamSummaryRow[]>([]);
  const [totals, setTotals] = useState<TeamSummaryTotals>({ present: 0, wo: 0, adv1: 0, office_advance: 0, dress_advance: 0 });
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [loaded, setLoaded] = useState(false);

  // Track which rows have unsaved changes
  const [dirty, setDirty] = useState<Record<string, boolean>>({});

  // Temp employee dialog
  const [showAddTemp, setShowAddTemp] = useState(false);
  const [tempName, setTempName] = useState('');
  const [addingTemp, setAddingTemp] = useState(false);
  const [deletingTemp, setDeletingTemp] = useState<string | null>(null);

  // Remove employee confirmation dialog
  const [removeTarget, setRemoveTarget] = useState<TeamSummaryRow | null>(null);
  const [removing, setRemoving] = useState(false);

  // Column resize
  const { widths, tableRef, onPointerDown, onPointerMove, onPointerUp } = useColumnResize(
    COLUMNS.map(c => c.defaultWidth),
    COLUMNS.map(c => c.minWidth),
  );

  // ── Load filters ──
  const loadFilters = useCallback(async () => {
    setFiltersLoading(true);
    try {
      const [clientsRes, unitsRes] = await Promise.all([
        fetchClients(scope, employeeId, unitIds.length > 0 ? unitIds : undefined),
        fetchUnits(scope, employeeId, undefined, unitIds.length > 0 ? unitIds : undefined),
      ]);
      setClients(clientsRes.data ?? []);
      setUnits(unitsRes.data ?? []);
    } catch (err) {
      console.error('Failed to load filters:', err);
      toast.error('Failed to load filters');
    } finally {
      setFiltersLoading(false);
    }
  }, [scope, employeeId, unitIds]);

  useEffect(() => { loadFilters(); }, [loadFilters]);

  // Filter units by selected client
  const filteredUnits = selectedClient
    ? units.filter(u => u.client_id === Number(selectedClient))
    : units;

  // ── Load data ──
  const loadSummary = useCallback(async () => {
    const unitId = Number(selectedUnit);
    if (!unitId) return;
    setLoading(true);
    setLoaded(true);
    setDirty({});
    try {
      const res = await fetchTeamSummary(unitId, selectedMonth, selectedYear);
      if (res.data) {
        setRows(res.data.items ?? []);
        setTotals(res.data.totals ?? { present: 0, wo: 0, adv1: 0, office_advance: 0, dress_advance: 0 });
      } else {
        setRows([]);
        setTotals({ present: 0, wo: 0, adv1: 0, office_advance: 0, dress_advance: 0 });
      }
    } catch (err) {
      console.error('Failed to load summary:', err);
      toast.error('Failed to load team data');
    } finally {
      setLoading(false);
    }
  }, [selectedUnit, selectedMonth, selectedYear]);

  // Auto-load when unit changes
  useEffect(() => {
    if (selectedUnit) loadSummary();
  }, [selectedUnit, loadSummary]);

  // ── Handle field change ──
  const handleFieldChange = (empId: string, field: keyof TeamSummaryRow, value: string) => {
    const numVal = value === '' ? 0 : parseFloat(value);
    if (isNaN(numVal) || numVal < 0) return;

    setRows(prev => prev.map(r => {
      if (r.employee_id !== empId) return r;
      return { ...r, [field]: numVal };
    }));

    setDirty(prev => ({ ...prev, [empId]: true }));

    // Recalculate totals
    setRows(currentRows => {
      const newTotals = { present: 0, wo: 0, adv1: 0, office_advance: 0, dress_advance: 0 };
      currentRows.forEach(r => {
        newTotals.present += r.present;
        newTotals.wo += r.wo;
        newTotals.adv1 += r.adv1;
        newTotals.office_advance += r.office_advance;
        newTotals.dress_advance += r.dress_advance;
      });
      setTotals(newTotals);
      return currentRows;
    });
  };

  // ── Save all dirty rows ──
  const handleSave = async () => {
    const dirtyRows = rows.filter(r => dirty[r.employee_id]);
    if (dirtyRows.length === 0) {
      toast.info('No changes to save');
      return;
    }

    setSaving(true);
    let saved = 0;
    let failed = 0;

    for (const row of dirtyRows) {
      try {
        const res = await saveTeamAdvance({
          employee_id: row.employee_id,
          unit_id: Number(selectedUnit),
          month: selectedMonth,
          year: selectedYear,
          present: row.present,
          wo: row.wo,
          adv1: row.adv1,
          office_advance: row.office_advance,
          dress_advance: row.dress_advance,
        });
        if (!res.error) saved++;
        else { failed++; console.error(`Save failed for ${row.employee_id}:`, res.error); }
      } catch (err) {
        failed++;
        console.error(`Save failed for ${row.employee_id}:`, err);
      }
    }

    setSaving(false);
    setDirty({});

    if (failed === 0) {
      toast.success(`Saved for ${saved} employee${saved > 1 ? 's' : ''}`);
    } else {
      toast.error(`Saved ${saved}, failed ${failed}`);
    }
  };

  // ── Add temp employee ──
  const handleAddTemp = async () => {
    const name = tempName.trim();
    if (!name) {
      toast.error('Please enter a name');
      return;
    }
    const unitId = Number(selectedUnit);
    if (!unitId) return;

    setAddingTemp(true);
    try {
      const res = await addTempEmployee({
        name,
        unit_id: unitId,
        month: selectedMonth,
        year: selectedYear,
      });
      if (!res.error) {
        toast.success('Temp employee added');
        setTempName('');
        setShowAddTemp(false);
        loadSummary();
      } else {
        toast.error(res.error || 'Failed to add temp employee');
      }
    } catch (err) {
      console.error('Failed to add temp employee:', err);
      toast.error('Failed to add temp employee');
    } finally {
      setAddingTemp(false);
    }
  };

  // ── Delete temp employee ──
  const handleDeleteTemp = async (row: TeamSummaryRow) => {
    const tempId = parseInt(row.employee_id.replace('TEMP-', ''), 10);
    if (isNaN(tempId)) return;

    setDeletingTemp(row.employee_id);
    try {
      const res = await deleteTempEmployee({
        temp_id: tempId,
        unit_id: Number(selectedUnit),
      });
      if (!res.error) {
        toast.success('Temp employee removed');
        loadSummary();
      } else {
        toast.error(res.error || 'Failed to remove');
      }
    } catch (err) {
      console.error('Failed to remove temp employee:', err);
      toast.error('Failed to remove temp employee');
    } finally {
      setDeletingTemp(null);
    }
  };

  // ── Remove regular employee ──
  const handleRemoveEmployee = async () => {
    if (!removeTarget) return;
    setRemoving(true);
    try {
      const res = await removeEmployee({
        employee_id: Number(removeTarget.employee_id),
        unit_id: Number(selectedUnit),
      });
      if (!res.error) {
        toast.success(`${removeTarget.full_name} marked as left`);
        setRemoveTarget(null);
        loadSummary();
      } else {
        toast.error(res.error || 'Failed to remove');
      }
    } catch (err) {
      console.error('Failed to remove employee:', err);
      toast.error('Failed to remove employee');
    } finally {
      setRemoving(false);
    }
  };

  // ── Export CSV ──
  const handleExport = () => {
    const headers = ['Employee Code', 'Name', 'Present', 'WO', 'Adv 1', 'Office Adv', 'Dress Adv'];
    const csvRows = rows.map(r =>
      [r.employee_code, r.full_name, r.present, r.wo, r.adv1, r.office_advance, r.dress_advance].join(',')
    );
    csvRows.push(['', 'TOTAL', totals.present, totals.wo, totals.adv1, totals.office_advance, totals.dress_advance].join(','));

    const csv = [headers.join(','), ...csvRows].join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `team-attendance-${selectedUnit}-${selectedMonth}-${selectedYear}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  };

  // ── Render helpers ──
  const hasDirty = Object.keys(dirty).length > 0;

  const getCellValue = (row: TeamSummaryRow, key: string): string | number => {
    if (key === 'employee_code') return row.employee_code || '—';
    if (key === 'full_name') return row.full_name;
    return (row as Record<string, unknown>)[key] as number ?? 0;
  };

  // ── Render ──
  return (
    <div className="space-y-4 pb-24">
      {/* Header */}
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-bold text-gray-900">Team Attendance</h2>
        <div className="flex gap-2">
          {selectedUnit && (
            <Button variant="outline" size="sm" onClick={() => setShowAddTemp(true)} className="gap-1.5 text-xs">
              <Plus className="w-3.5 h-3.5" /> Temp
            </Button>
          )}
          {rows.length > 0 && (
            <Button variant="outline" size="sm" onClick={handleExport} className="gap-1.5 text-xs">
              <Download className="w-3.5 h-3.5" /> CSV
            </Button>
          )}
        </div>
      </div>

      {/* Filters */}
      <div className="bg-white rounded-xl border p-3 space-y-3">
        {filtersLoading ? (
          <div className="flex gap-2">
            <Skeleton className="h-10 flex-1" />
            <Skeleton className="h-10 flex-1" />
          </div>
        ) : (
          <div className="grid grid-cols-2 gap-2">
            <div className="space-y-1">
              <Label className="text-xs text-gray-500">Client</Label>
              <Select value={selectedClient} onValueChange={(v) => { setSelectedClient(v); setSelectedUnit(''); }}>
                <SelectTrigger className="h-10 text-sm">
                  <SelectValue placeholder="Select client" />
                </SelectTrigger>
                <SelectContent>
                  {clients.map(c => (
                    <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1">
              <Label className="text-xs text-gray-500">Unit</Label>
              <Select value={selectedUnit} onValueChange={setSelectedUnit}>
                <SelectTrigger className="h-10 text-sm">
                  <SelectValue placeholder="Select unit" />
                </SelectTrigger>
                <SelectContent>
                  {filteredUnits.map(u => (
                    <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>
        )}

        {/* Month/Year picker */}
        <div className="flex items-center gap-2">
          <button
            onClick={() => { if (selectedMonth === 1) { setSelectedYear(y => y - 1); setSelectedMonth(12); } else setSelectedMonth(m => m - 1); }}
            className="p-1.5 rounded-lg hover:bg-gray-100 transition-colors"
          >
            <ChevronLeft className="w-4 h-4 text-gray-600" />
          </button>
          <div className="flex-1 text-center">
            <span className="text-sm font-semibold text-gray-900">
              {MONTHS[selectedMonth - 1]} {selectedYear}
            </span>
          </div>
          <button
            onClick={() => { if (selectedMonth === 12) { setSelectedYear(y => y + 1); setSelectedMonth(1); } else setSelectedMonth(m => m + 1); }}
            className="p-1.5 rounded-lg hover:bg-gray-100 transition-colors"
          >
            <ChevronRight className="w-4 h-4 text-gray-600" />
          </button>
        </div>
      </div>

      {/* No unit selected */}
      {!selectedUnit && !loading && (
        <div className="flex flex-col items-center justify-center py-16 text-center">
          <ClipboardList className="w-12 h-12 text-gray-300 mb-3" />
          <p className="text-sm text-gray-500">Select a client and unit to view team data</p>
        </div>
      )}

      {/* Loading */}
      {loading && (
        <div className="space-y-2">
          {Array.from({ length: 5 }).map((_, i) => (
            <Skeleton key={i} className="h-10 w-full" />
          ))}
        </div>
      )}

      {/* Save bar — sticky bottom */}
      {hasDirty && !loading && (
        <div className="fixed bottom-0 left-0 right-0 z-50 bg-white border-t shadow-lg px-4 py-3 flex items-center justify-between safe-area-bottom">
          <span className="text-sm text-amber-700 font-medium">
            {Object.keys(dirty).length} unsaved change{Object.keys(dirty).length > 1 ? 's' : ''}
          </span>
          <Button onClick={handleSave} disabled={saving} className="gap-2 h-10 px-5 bg-emerald-600 hover:bg-emerald-700 text-sm font-semibold">
            {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
            Save
          </Button>
        </div>
      )}

      {/* Excel-like Resizable Table */}
      {!loading && rows.length > 0 && (
        <div
          ref={tableRef}
          className="bg-white rounded-xl border overflow-hidden"
          onPointerMove={onPointerMove}
          onPointerUp={onPointerUp}
          onPointerLeave={onPointerUp}
          style={{ touchAction: 'none' }}
        >
          {/* Unsaved changes banner */}
          {hasDirty && (
            <div className="bg-amber-50 px-3 py-1.5 border-b">
              <span className="text-[11px] text-amber-600">
                Drag column borders to resize
              </span>
            </div>
          )}

          <div className="overflow-x-auto overflow-y-auto max-h-[65vh]">
            <table
              className="border-collapse"
              style={{ minWidth: widths.reduce((a, b) => a + b, 0) }}
            >
              <colgroup>
                {COLUMNS.map((col, i) => (
                  <col key={col.key} style={{ width: widths[i] }} />
                ))}
              </colgroup>

              {/* ── Header ── */}
              <thead className="sticky top-0 z-10">
                <tr className="bg-gray-100 border-b-2 border-gray-300">
                  {COLUMNS.map((col, i) => {
                    const isLast = i === COLUMNS.length - 1;
                    const isAtt = col.type === 'attendance';
                    const isAdv = col.type === 'advance';
                    return (
                      <th
                        key={col.key}
                        className={cn(
                          'relative text-[11px] font-bold uppercase tracking-wider whitespace-nowrap select-none',
                          isAtt && 'text-emerald-700 bg-emerald-50',
                          isAdv && 'text-blue-700 bg-blue-50',
                          !isAtt && !isAdv && 'text-gray-600',
                        )}
                        style={{ height: 36, width: widths[i] }}
                      >
                        <span className="px-1.5 block truncate">
                          {col.label}
                        </span>
                        {/* Resize handle */}
                        {!isLast && (
                          <div
                            className="absolute top-0 right-0 bottom-0 w-2.5 cursor-col-resize hover:bg-blue-400/30 active:bg-blue-500/40 transition-colors flex items-center justify-center z-20"
                            onPointerDown={(e) => onPointerDown(i, e)}
                          >
                            <div className="w-[3px] h-5 bg-gray-300 rounded-full" />
                          </div>
                        )}
                      </th>
                    );
                  })}
                </tr>
              </thead>

              {/* ── Body ── */}
              <tbody>
                {rows.map((row, rowIdx) => (
                  <tr
                    key={row.employee_id}
                    className={cn(
                      'border-b border-gray-100 transition-colors',
                      dirty[row.employee_id] && 'bg-blue-50/40',
                      row.is_temp && !dirty[row.employee_id] && 'bg-orange-50/20',
                      rowIdx % 2 === 1 && !dirty[row.employee_id] && !row.is_temp && 'bg-gray-50/40',
                    )}
                  >
                    {COLUMNS.map((col, colIdx) => {
                      const val = getCellValue(row, col.key);

                      // Action column
                      if (col.type === 'action') {
                        return (
                          <td key={col.key} className="p-0.5 text-center" style={{ width: widths[colIdx] }}>
                            {row.is_temp ? (
                              <button
                                onClick={() => handleDeleteTemp(row)}
                                disabled={deletingTemp === row.employee_id}
                                className="p-1.5 rounded hover:bg-red-100 text-gray-400 hover:text-red-600 transition-colors disabled:opacity-50"
                                title="Remove temp employee"
                              >
                                {deletingTemp === row.employee_id
                                  ? <Loader2 className="w-4 h-4 animate-spin" />
                                  : <Trash2 className="w-4 h-4" />
                                }
                              </button>
                            ) : (
                              <button
                                onClick={() => setRemoveTarget(row)}
                                className="p-1.5 rounded hover:bg-red-100 text-gray-400 hover:text-red-600 transition-colors"
                                title="Remove employee"
                              >
                                <UserMinus className="w-4 h-4" />
                              </button>
                            )}
                          </td>
                        );
                      }

                      // Editable number columns
                      if (col.editable) {
                        const numVal = val as number;
                        const isAtt = col.type === 'attendance';
                        return (
                          <td key={col.key} className="p-0.5" style={{ width: widths[colIdx] }}>
                            <input
                              type="number"
                              inputMode="numeric"
                              min="0"
                              step="1"
                              value={numVal || ''}
                              onChange={e => handleFieldChange(row.employee_id, col.key as keyof TeamSummaryRow, e.target.value)}
                              placeholder="0"
                              className={cn(
                                'w-full h-9 text-center text-sm font-semibold border-0 outline-none',
                                'focus:ring-2 focus:ring-inset',
                                'placeholder:text-gray-300 placeholder:font-normal',
                                isAtt
                                  ? 'bg-emerald-50/60 text-emerald-800 focus:ring-emerald-400 focus:bg-emerald-50'
                                  : 'bg-blue-50/60 text-blue-800 focus:ring-blue-400 focus:bg-blue-50',
                              )}
                            />
                          </td>
                        );
                      }

                      // Text columns (code, name)
                      if (col.key === 'employee_code') {
                        return (
                          <td
                            key={col.key}
                            className="px-1.5 py-1 text-xs font-mono text-gray-500 whitespace-nowrap truncate"
                            style={{ width: widths[colIdx] }}
                          >
                            {val as string}
                          </td>
                        );
                      }

                      // Name column
                      return (
                        <td
                          key={col.key}
                          className="px-1.5 py-1 text-sm font-medium text-gray-900 whitespace-nowrap"
                          style={{ width: widths[colIdx], maxWidth: widths[colIdx] }}
                        >
                          <div className="flex items-center gap-1 min-w-0">
                            <span className="truncate">{val as string}</span>
                            {row.is_temp && (
                              <Badge variant="outline" className="text-[9px] px-1 py-0 text-orange-600 border-orange-300 bg-orange-50 shrink-0 leading-none">
                                T
                              </Badge>
                            )}
                          </div>
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>

              {/* ── Totals Footer ── */}
              <tfoot className="sticky bottom-0 z-10">
                <tr className="bg-gray-200/90 border-t-2 border-gray-400">
                  <td
                    colSpan={2}
                    className="px-2 py-2 text-xs font-bold text-gray-700 whitespace-nowrap"
                  >
                    TOTAL ({rows.length})
                  </td>
                  {COLUMNS.slice(2).map((col, i) => {
                    if (col.type === 'action') {
                      return <td key={col.key} className="p-1" />;
                    }
                    const totalVal = totals[col.key as keyof TeamSummaryTotals] || 0;
                    const isAtt = col.type === 'attendance';
                    return (
                      <td
                        key={col.key}
                        className={cn(
                          'px-1 py-2 text-center text-sm font-bold',
                          isAtt ? 'text-emerald-800' : 'text-blue-800',
                        )}
                      >
                        {totalVal}
                      </td>
                    );
                  })}
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      )}

      {/* Empty state */}
      {!loading && loaded && rows.length === 0 && selectedUnit && (
        <div className="text-center py-12">
          <p className="text-sm text-gray-500">No employees found for this unit</p>
        </div>
      )}

      {/* Add Temp Employee Dialog */}
      <Dialog open={showAddTemp} onOpenChange={setShowAddTemp}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="text-base">Add Temp Employee</DialogTitle>
            <DialogDescription className="text-xs text-gray-500">
              Valid for {MONTHS[selectedMonth - 1]} {selectedYear} only. Not registered in the system.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 pt-2">
            <div className="space-y-1.5">
              <Label className="text-xs text-gray-600">Employee Name</Label>
              <Input
                value={tempName}
                onChange={e => setTempName(e.target.value)}
                placeholder="Enter full name"
                className="h-10 text-sm"
                onKeyDown={e => { if (e.key === 'Enter' && !addingTemp) handleAddTemp(); }}
                autoFocus
              />
            </div>
            <div className="flex gap-2 justify-end">
              <Button variant="outline" size="sm" onClick={() => { setShowAddTemp(false); setTempName(''); }} className="text-xs">
                Cancel
              </Button>
              <Button size="sm" onClick={handleAddTemp} disabled={addingTemp || !tempName.trim()} className="gap-1.5 text-xs bg-emerald-600 hover:bg-emerald-700">
                {addingTemp ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Plus className="w-3.5 h-3.5" />}
                Add
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>

      {/* Remove Employee Confirmation Dialog */}
      <Dialog open={!!removeTarget} onOpenChange={(open) => { if (!open) setRemoveTarget(null); }}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="text-base flex items-center gap-2">
              <AlertTriangle className="w-5 h-5 text-red-500" />
              Remove Employee
            </DialogTitle>
            <DialogDescription className="text-sm text-gray-600 pt-1">
              Are you sure you want to mark <span className="font-semibold">{removeTarget?.full_name}</span> as left? They will no longer appear in this unit.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="gap-2 pt-2">
            <Button variant="outline" size="sm" onClick={() => setRemoveTarget(null)} disabled={removing} className="text-xs">
              Cancel
            </Button>
            <Button size="sm" onClick={handleRemoveEmployee} disabled={removing} className="gap-1.5 text-xs bg-red-600 hover:bg-red-700">
              {removing ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <UserMinus className="w-3.5 h-3.5" />}
              Remove
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}