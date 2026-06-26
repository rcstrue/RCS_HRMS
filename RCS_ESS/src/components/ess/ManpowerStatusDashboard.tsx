'use client';

import { useState, useEffect, useCallback } from 'react';
import { toast } from 'sonner';
import {
  BarChart3,
  Users,
  TrendingDown,
  Loader2,
  AlertTriangle,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
} from 'lucide-react';

import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

import type { ManpowerDashboardData, ClientOption } from '@/lib/ess-types';
import { fetchManpowerDashboard, fetchClients } from '@/lib/ess-api';

// ══════════════════════════════════════════════════════════════
// Types
// ══════════════════════════════════════════════════════════════

type Period = 'daily' | 'weekly' | 'monthly' | 'yearly';

interface Props {
  unitIds: number[];
}

const PERIOD_OPTIONS: { value: Period; label: string }[] = [
  { value: 'daily', label: 'Daily' },
  { value: 'weekly', label: 'Weekly' },
  { value: 'monthly', label: 'Monthly' },
  { value: 'yearly', label: 'Yearly' },
];

// ══════════════════════════════════════════════════════════════
// Component
// ══════════════════════════════════════════════════════════════

export default function ManpowerStatusDashboard({ unitIds }: Props) {
  const [period, setPeriod] = useState<Period>('daily');
  const [offset, setOffset] = useState(0);
  const [selectedClientId, setSelectedClientId] = useState<number | null>(null);
  const [clients, setClients] = useState<ClientOption[]>([]);
  const [dashboard, setDashboard] = useState<ManpowerDashboardData | null>(null);
  const [loading, setLoading] = useState(true);

  // Load clients
  useEffect(() => {
    let cancelled = false;
    fetchClients(undefined, undefined, unitIds).then(({ data, error }) => {
      if (cancelled) return;
      if (error) { toast.error(error); return; }
      setClients(data || []);
    });
    return () => { cancelled = true; };
  }, [unitIds]);

  // Load dashboard data
  const loadDashboard = useCallback(async () => {
    setLoading(true);
    const { data, error } = await fetchManpowerDashboard({
      period,
      client_id: selectedClientId ?? undefined,
      unit_ids: unitIds,
      offset: offset === 0 ? undefined : offset,
    });
    if (error) { toast.error(error); setLoading(false); return; }
    setDashboard(data || null);
    setLoading(false);
  }, [period, offset, selectedClientId, unitIds]);

  useEffect(() => {
    loadDashboard();
  }, [loadDashboard]);

  // Reset offset when period changes
  const handlePeriodChange = (newPeriod: string) => {
    setPeriod(newPeriod as Period);
    setOffset(0);
  };

  const goPrev = () => setOffset((o) => o - 1);
  const goNext = () => setOffset((o) => (o >= 0 ? 0 : o + 1));
  const canGoNext = offset < 0;

  const gt = dashboard?.grand_total;
  const shortagePct = gt && gt.total_budget > 0
    ? Math.round((gt.shortage / gt.total_budget) * 100)
    : 0;
  const fulfillmentPct = gt && gt.total_budget > 0
    ? Math.round((gt.total_actual / gt.total_budget) * 100)
    : 0;

  return (
    <div className="space-y-3">
      {/* ── Header ── */}
      <div className="bg-gradient-to-r from-violet-600 to-violet-500 rounded-2xl px-4 py-3 text-white shadow-lg">
        <div className="flex items-center gap-2 mb-0.5">
          <BarChart3 className="w-4 h-4 text-white/80" />
          <h1 className="text-[15px] font-bold">Manpower Reports</h1>
        </div>
        {dashboard && (
          <p className="text-[11px] text-white/80">{dashboard.label} &middot; {dashboard.total_units} unit{dashboard.total_units !== 1 ? 's' : ''}</p>
        )}
      </div>

      {/* ── Period selector + Client filter ── */}
      <div className="flex gap-2 items-center">
        <div className="flex-1">
          <div className="flex bg-gray-100 rounded-xl p-1 gap-0.5">
            {PERIOD_OPTIONS.map((opt) => (
              <button
                key={opt.value}
                onClick={() => handlePeriodChange(opt.value)}
                className={`flex-1 py-1.5 text-[11px] font-semibold rounded-lg transition-all ${
                  period === opt.value
                    ? 'bg-white text-violet-700 shadow-sm'
                    : 'text-gray-500 hover:text-gray-700'
                }`}
              >
                {opt.label}
              </button>
            ))}
          </div>
        </div>
        {clients.length > 1 && (
          <Select
            value={selectedClientId ? String(selectedClientId) : 'all'}
            onValueChange={(v) => setSelectedClientId(v === 'all' ? null : parseInt(v))}
          >
            <SelectTrigger className="w-28 h-9 text-[11px]">
              <SelectValue placeholder="All Clients" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Clients</SelectItem>
              {clients.map((c) => (
                <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}
      </div>

      {/* ── Period navigation with < > ── */}
      <div className="flex items-center justify-between bg-gray-50 rounded-xl px-2 py-1.5">
        <button
          onClick={goPrev}
          className="p-1.5 rounded-lg hover:bg-gray-200 transition-colors active:scale-90"
        >
          <ChevronLeft className="w-5 h-5 text-gray-600" />
        </button>
        <span className="text-[13px] font-bold text-gray-900 tabular-nums">
          {dashboard?.label ?? '—'}
        </span>
        <button
          onClick={goNext}
          disabled={!canGoNext}
          className={`p-1.5 rounded-lg transition-colors active:scale-90 ${canGoNext ? 'hover:bg-gray-200' : 'opacity-30 cursor-not-allowed'}`}
        >
          <ChevronRight className="w-5 h-5 text-gray-600" />
        </button>
      </div>

      {loading ? (
        <div className="space-y-3">
          <Skeleton className="h-28 w-full rounded-2xl" />
          <Skeleton className="h-48 w-full rounded-2xl" />
        </div>
      ) : !dashboard || dashboard.total_units === 0 ? (
        <Card className="border-0 shadow-sm">
          <CardContent className="p-8 text-center">
            <AlertTriangle className="w-10 h-10 text-amber-400 mx-auto mb-3" />
            <p className="text-sm font-medium text-gray-600">No manpower data for this period</p>
            <p className="text-xs text-gray-400 mt-1">Submit daily manpower status from the Entry tab</p>
          </CardContent>
        </Card>
      ) : (
        <>
          {/* ── Grand Summary Cards ── */}
          <div className="grid grid-cols-2 gap-2.5">
            <Card className="border-0 shadow-sm">
              <CardContent className="p-3">
                <div className="flex items-center gap-1.5 mb-0.5">
                  <Users className="w-3 h-3 text-violet-500" />
                  <span className="text-[10px] font-medium text-gray-500 uppercase">Total Budget</span>
                </div>
                <p className="text-xl font-bold text-gray-900 tabular-nums">{gt?.total_budget ?? 0}</p>
                <p className="text-[10px] text-gray-400 mt-0.5">{dashboard.days_reported} of {dashboard.days_in_period} days reported</p>
              </CardContent>
            </Card>
            <Card className="border-0 shadow-sm">
              <CardContent className="p-3">
                <div className="flex items-center gap-1.5 mb-0.5">
                  <CheckCircle2 className="w-3 h-3 text-emerald-500" />
                  <span className="text-[10px] font-medium text-gray-500 uppercase">Total Actual</span>
                </div>
                <p className="text-xl font-bold text-emerald-600 tabular-nums">{gt?.total_actual ?? 0}</p>
                <p className="text-[10px] text-gray-400 mt-0.5">{fulfillmentPct}% fulfillment</p>
              </CardContent>
            </Card>
          </div>

          {/* Shortage bar */}
          <Card className="border-0 shadow-sm overflow-hidden">
            <CardContent className="p-3">
              <div className="flex items-center justify-between mb-1.5">
                <div className="flex items-center gap-1.5">
                  <TrendingDown className="w-3.5 h-3.5 text-rose-500" />
                  <span className="text-[11px] font-medium text-gray-700">Overall Shortage</span>
                </div>
                <div className="flex items-center gap-2">
                  <span className={`text-base font-bold tabular-nums ${(gt?.shortage ?? 0) > 0 ? 'text-rose-600' : 'text-emerald-600'}`}>
                    {gt?.shortage ?? 0}
                  </span>
                  {(gt?.shortage ?? 0) > 0 && (
                    <Badge variant="outline" className="text-[10px] text-rose-600 border-rose-200 bg-rose-50">
                      {shortagePct}%
                    </Badge>
                  )}
                </div>
              </div>
              {/* Progress bar */}
              <div className="w-full h-2.5 bg-gray-100 rounded-full overflow-hidden">
                {gt && gt.total_budget > 0 && (
                  <div
                    className="h-full rounded-full transition-all duration-500"
                    style={{
                      width: `${Math.min(100, fulfillmentPct)}%`,
                      backgroundColor: fulfillmentPct >= 90 ? '#10b981' : fulfillmentPct >= 70 ? '#f59e0b' : '#ef4444',
                    }}
                  />
                )}
              </div>
              <div className="flex justify-between mt-0.5">
                <span className="text-[10px] text-gray-400">0%</span>
                <span className="text-[10px] text-gray-400">100%</span>
              </div>
            </CardContent>
          </Card>

          {/* ── Unit-wise breakdown ── */}
          <div>
            <h3 className="text-[11px] font-semibold text-gray-500 uppercase tracking-wider mb-2 px-1">
              Unit-wise Status
            </h3>
            <div className="space-y-2 max-h-80 overflow-y-auto">
              {dashboard.units.map((unit) => {
                const unitFulfillment = unit.total_budget > 0
                  ? Math.round((unit.total_actual / unit.total_budget) * 100)
                  : 0;
                const hasShortage = unit.shortage > 0;
                return (
                  <Card key={unit.unit_id} className="border-0 shadow-sm overflow-hidden">
                    <CardContent className="p-0">
                      <div className="px-3 py-2.5">
                        <div className="flex items-center justify-between mb-1.5">
                          <div className="min-w-0 flex-1">
                            <p className="text-[12px] font-semibold text-gray-900 truncate">{unit.unit_name}</p>
                            <p className="text-[10px] text-gray-400">{unit.client_name}</p>
                          </div>
                          {hasShortage && (
                            <Badge variant="outline" className="text-[10px] text-rose-600 border-rose-200 bg-rose-50 shrink-0 ml-2">
                              -{unit.shortage}
                            </Badge>
                          )}
                        </div>
                        {/* Mini progress bar */}
                        <div className="w-full h-1.5 bg-gray-100 rounded-full overflow-hidden mb-1.5">
                          <div
                            className="h-full rounded-full transition-all duration-300"
                            style={{
                              width: `${Math.min(100, unitFulfillment)}%`,
                              backgroundColor: unitFulfillment >= 90 ? '#10b981' : unitFulfillment >= 70 ? '#f59e0b' : '#ef4444',
                            }}
                          />
                        </div>
                        <div className="grid grid-cols-3 gap-2 text-center">
                          <div>
                            <p className="text-[9px] text-gray-400 uppercase">Budget</p>
                            <p className="text-[11px] font-bold text-gray-700 tabular-nums">{unit.total_budget}</p>
                          </div>
                          <div>
                            <p className="text-[9px] text-gray-400 uppercase">Actual</p>
                            <p className="text-[11px] font-bold text-emerald-600 tabular-nums">{unit.total_actual}</p>
                          </div>
                          <div>
                            <p className="text-[9px] text-gray-400 uppercase">Fulfill</p>
                            <p className="text-[11px] font-bold tabular-nums" style={{ color: unitFulfillment >= 90 ? '#10b981' : unitFulfillment >= 70 ? '#f59e0b' : '#ef4444' }}>
                              {unitFulfillment}%
                            </p>
                          </div>
                        </div>
                      </div>
                      {/* Morning/Evening detail */}
                      <div className="grid grid-cols-2 border-t border-gray-100">
                        <div className="px-3 py-1.5 bg-blue-50/50">
                          <p className="text-[9px] font-semibold text-blue-500 uppercase">Morning</p>
                          <p className="text-[11px] text-gray-600 tabular-nums">
                            {unit.morning.total_actual}/{unit.morning.total_budget}
                          </p>
                        </div>
                        <div className="px-3 py-1.5 bg-emerald-50/50">
                          <p className="text-[9px] font-semibold text-emerald-500 uppercase">Evening</p>
                          <p className="text-[11px] text-gray-600 tabular-nums">
                            {unit.evening.total_actual}/{unit.evening.total_budget}
                          </p>
                        </div>
                      </div>
                    </CardContent>
                  </Card>
                );
              })}
            </div>
          </div>

          {/* ── Daily Trend (for weekly/monthly/yearly) ── */}
          {dashboard.daily_breakdown.length > 0 && (
            <div>
              <h3 className="text-[11px] font-semibold text-gray-500 uppercase tracking-wider mb-2 px-1">
                Daily Trend
              </h3>
              <Card className="border-0 shadow-sm overflow-hidden">
                <CardContent className="p-3">
                  <div className="space-y-1.5 max-h-64 overflow-y-auto">
                    {dashboard.daily_breakdown.map((day) => {
                      const dayFulfillment = day.total_budget > 0
                        ? Math.round((day.total_actual / day.total_budget) * 100)
                        : 0;
                      const d = new Date(day.date + 'T00:00:00');
                      const dayLabel = d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', weekday: 'short' });
                      return (
                        <div key={day.date} className="flex items-center gap-2">
                          <span className="text-[11px] text-gray-500 w-[4.5rem] shrink-0">{dayLabel}</span>
                          <div className="flex-1 h-4 bg-gray-100 rounded-full overflow-hidden relative">
                            <div
                              className="h-full rounded-full transition-all duration-300"
                              style={{
                                width: `${Math.min(100, dayFulfillment)}%`,
                                backgroundColor: dayFulfillment >= 90 ? '#10b981' : dayFulfillment >= 70 ? '#f59e0b' : '#ef4444',
                              }}
                            />
                          </div>
                          <span className="text-[11px] font-medium text-gray-600 w-10 text-right tabular-nums">
                            {day.total_actual}/{day.total_budget}
                          </span>
                          {day.shortage > 0 && (
                            <span className="text-[10px] text-rose-500 w-6 text-right tabular-nums">-{day.shortage}</span>
                          )}
                        </div>
                      );
                    })}
                  </div>
                </CardContent>
              </Card>
            </div>
          )}
        </>
      )}
    </div>
  );
}