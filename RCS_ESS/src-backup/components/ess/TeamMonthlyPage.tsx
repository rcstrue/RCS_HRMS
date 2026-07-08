'use client';

import { useState, useEffect, useCallback } from 'react';
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

// ── Helpers ────────────────────────────────────────
function getPreviousMonth(): [number, number] {
  const now = new Date();
  const m = now.getMonth(); // 0-indexed
  if (m === 0) return [12, now.getFullYear() - 1];
  return [m, now.getFullYear()];
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

  // ── Handle field change (present, wo, or advance) ──
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

  // ── Render ──
  const hasDirty = Object.keys(dirty).length > 0;

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <h2 className="text-lg font-bold text-gray-900">Team Attendance</h2>
        <div className="flex gap-2">
          {selectedUnit && (
            <Button variant="outline" size="sm" onClick={() => setShowAddTemp(true)} className="gap-1.5 text-xs">
              <Plus className="w-3.5 h-3.5" /> Temp Employee
            </Button>
          )}
          {rows.length > 0 && (
            <Button variant="outline" size="sm" onClick={handleExport} className="gap-1.5 text-xs">
              <Download className="w-3.5 h-3.5" /> Export
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
            <Skeleton key={i} className="h-12 w-full" />
          ))}
        </div>
      )}

      {/* Table */}
      {!loading && rows.length > 0 && (
        <div className="bg-white rounded-xl border overflow-hidden">
          {/* Save button */}
          {hasDirty && (
            <div className="bg-amber-50 px-3 py-2 border-b flex items-center justify-between">
              <span className="text-xs text-amber-700 font-medium">
                {Object.keys(dirty).length} unsaved change{Object.keys(dirty).length > 1 ? 's' : ''}
              </span>
              <Button size="sm" onClick={handleSave} disabled={saving} className="gap-1.5 h-7 text-xs bg-emerald-600 hover:bg-emerald-700">
                {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />}
                Save
              </Button>
            </div>
          )}

          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="bg-gray-50 border-b">
                  <th className="text-left px-2 py-2.5 font-semibold text-gray-600 whitespace-nowrap">Code</th>
                  <th className="text-left px-2 py-2.5 font-semibold text-gray-600 whitespace-nowrap">Name</th>
                  <th className="text-center px-1 py-2.5 font-semibold text-emerald-600 w-14">Present</th>
                  <th className="text-center px-1 py-2.5 font-semibold text-emerald-600 w-12">WO</th>
                  <th className="text-center px-1 py-2.5 font-semibold text-blue-600 w-20">Adv 1</th>
                  <th className="text-center px-1 py-2.5 font-semibold text-blue-600 w-20">Off Adv</th>
                  <th className="text-center px-1 py-2.5 font-semibold text-blue-600 w-20">Dress Adv</th>
                  <th className="w-9"></th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row, idx) => (
                  <tr
                    key={row.employee_id}
                    className={cn(
                      'border-b last:border-b-0 transition-colors',
                      dirty[row.employee_id] ? 'bg-blue-50/50' : idx % 2 === 0 ? 'bg-white' : 'bg-gray-50/50',
                      row.is_temp && 'bg-orange-50/30'
                    )}
                  >
                    <td className="px-2 py-1.5 font-mono text-gray-700 text-[11px]">{row.employee_code || '—'}</td>
                    <td className="px-2 py-1.5 font-medium text-gray-900 truncate max-w-[100px]">
                      {row.full_name}
                      {row.is_temp && (
                        <Badge variant="outline" className="ml-1 text-[9px] px-1 py-0 text-orange-600 border-orange-300 bg-orange-50">
                          TEMP
                        </Badge>
                      )}
                    </td>
                    <td className="px-0.5 py-0.5">
                      <Input
                        type="number"
                        min="0"
                        step="1"
                        value={row.present || ''}
                        onChange={e => handleFieldChange(row.employee_id, 'present', e.target.value)}
                        className="h-7 text-[11px] text-center bg-emerald-50/50 border-emerald-200 focus:border-emerald-400"
                        placeholder="0"
                      />
                    </td>
                    <td className="px-0.5 py-0.5">
                      <Input
                        type="number"
                        min="0"
                        step="1"
                        value={row.wo || ''}
                        onChange={e => handleFieldChange(row.employee_id, 'wo', e.target.value)}
                        className="h-7 text-[11px] text-center bg-emerald-50/50 border-emerald-200 focus:border-emerald-400"
                        placeholder="0"
                      />
                    </td>
                    <td className="px-0.5 py-0.5">
                      <Input
                        type="number"
                        min="0"
                        step="1"
                        value={row.adv1 || ''}
                        onChange={e => handleFieldChange(row.employee_id, 'adv1', e.target.value)}
                        className="h-7 text-[11px] text-center bg-blue-50/50 border-blue-200 focus:border-blue-400"
                        placeholder="0"
                      />
                    </td>
                    <td className="px-0.5 py-0.5">
                      <Input
                        type="number"
                        min="0"
                        step="1"
                        value={row.office_advance || ''}
                        onChange={e => handleFieldChange(row.employee_id, 'office_advance', e.target.value)}
                        className="h-7 text-[11px] text-center bg-blue-50/50 border-blue-200 focus:border-blue-400"
                        placeholder="0"
                      />
                    </td>
                    <td className="px-0.5 py-0.5">
                      <Input
                        type="number"
                        min="0"
                        step="1"
                        value={row.dress_advance || ''}
                        onChange={e => handleFieldChange(row.employee_id, 'dress_advance', e.target.value)}
                        className="h-7 text-[11px] text-center bg-blue-50/50 border-blue-200 focus:border-blue-400"
                        placeholder="0"
                      />
                    </td>
                    <td className="px-0.5 py-0.5 text-center">
                      {row.is_temp ? (
                        <button
                          onClick={() => handleDeleteTemp(row)}
                          disabled={deletingTemp === row.employee_id}
                          className="p-1 rounded hover:bg-red-100 text-red-400 hover:text-red-600 transition-colors disabled:opacity-50"
                          title="Remove temp employee"
                        >
                          {deletingTemp === row.employee_id
                            ? <Loader2 className="w-3.5 h-3.5 animate-spin" />
                            : <Trash2 className="w-3.5 h-3.5" />
                          }
                        </button>
                      ) : (
                        <button
                          onClick={() => setRemoveTarget(row)}
                          className="p-1 rounded hover:bg-red-100 text-red-400 hover:text-red-600 transition-colors"
                          title="Remove employee (mark as left)"
                        >
                          <UserMinus className="w-3.5 h-3.5" />
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr className="bg-gray-100 border-t-2 border-gray-300 font-bold">
                  <td colSpan={2} className="px-2 py-2 text-gray-800 text-[11px]">TOTAL ({rows.length})</td>
                  <td className="px-1 py-2 text-center text-emerald-700">{totals.present}</td>
                  <td className="px-1 py-2 text-center text-emerald-700">{totals.wo}</td>
                  <td className="px-1 py-2 text-center text-blue-700">{totals.adv1}</td>
                  <td className="px-1 py-2 text-center text-blue-700">{totals.office_advance}</td>
                  <td className="px-1 py-2 text-center text-blue-700">{totals.dress_advance}</td>
                  <td></td>
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