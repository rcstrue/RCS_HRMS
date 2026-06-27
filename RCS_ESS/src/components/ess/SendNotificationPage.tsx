'use client';

import { useState, useEffect, useCallback, useRef } from 'react';
import { toast } from 'sonner';
import {
  Send,
  Users,
  Building2,
  MapPin,
  Globe,
  User,
  Check,
  X,
  Search,
  Loader2,
  Megaphone,
  Eye,
  Clock,
  ChevronRight,
  Inbox,
} from 'lucide-react';

import { cn } from '@/lib/utils';
import { apiRequest } from '@/lib/api/config';
import { usePullToRefresh } from './hooks/usePullToRefresh';

import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

import PageHeader from './PageHeader';

// ══════════════════════════════════════════════════════════════
// Types
// ══════════════════════════════════════════════════════════════

interface FilterData {
  units: { id: number; name: string }[];
  clients: { id: number; name: string }[];
  cities: string[];
  states: string[];
}

interface SearchedEmployee {
  id: string;
  full_name: string;
  mobile_number: string;
  role: string;
  unit_name: string;
  client_name: string;
}

interface BroadcastItem {
  broadcast_id: number;
  title: string;
  message: string;
  created_at: string;
  target_type: string;
  target_label: string;
  total_recipients: number;
  read_count: number;
  read_percent: number;
}

interface BroadcastPagination {
  page: number;
  limit: number;
  total: number;
  total_pages: number;
}

type TargetType = 'all' | 'managers' | 'unit' | 'client' | 'city' | 'state' | 'individual';

// ══════════════════════════════════════════════════════════════
// unwrap helper — PHP wraps in { success, data } envelope
// ══════════════════════════════════════════════════════════════

function unwrap<T>(result: Promise<{ data: T | null; error: string | null }>): Promise<{ data: T | null; error: string | null }> {
  return result.then((res) => {
    if (res.error) return res;
    const d = res.data as Record<string, unknown> | null;
    if (d && typeof d === 'object' && 'success' in d) {
      if (d.success === false) {
        const msg = (d.message as string) || (d.error as string) || 'Request failed';
        return { data: null, error: msg };
      }
      if ('data' in d && d.data != null) {
        return { data: d.data as T, error: null };
      }
      if (d.success === true) {
        return { data: null, error: null };
      }
    }
    return res;
  });
}

// ══════════════════════════════════════════════════════════════
// Constants
// ══════════════════════════════════════════════════════════════

const TARGET_TYPES: { value: TargetType; label: string; icon: typeof Users }[] = [
  { value: 'all', label: 'All Employees', icon: Users },
  { value: 'managers', label: 'Managers', icon: Users },
  { value: 'unit', label: 'By Unit', icon: Building2 },
  { value: 'client', label: 'By Client', icon: Building2 },
  { value: 'city', label: 'By City', icon: MapPin },
  { value: 'state', label: 'By State', icon: Globe },
  { value: 'individual', label: 'Individual', icon: User },
];

// ══════════════════════════════════════════════════════════════
// Props
// ══════════════════════════════════════════════════════════════

interface SendNotificationPageProps {
  onBack?: () => void;
}

// ══════════════════════════════════════════════════════════════
// Component
// ══════════════════════════════════════════════════════════════

export default function SendNotificationPage({ onBack }: SendNotificationPageProps) {
  const [activeTab, setActiveTab] = useState<'compose' | 'sent'>('compose');

  return (
    <div className="flex flex-col gap-3 pb-6">
      <PageHeader
        title="Send Notification"
        subtitle="Broadcast messages to employees"
        onBack={onBack}
      />

      {/* Tab Switcher */}
      <div className="flex rounded-xl bg-muted/70 p-1">
        {(['compose', 'sent'] as const).map((tab) => (
          <button
            key={tab}
            onClick={() => setActiveTab(tab)}
            className={cn(
              'flex-1 py-2 text-[13px] font-semibold rounded-lg transition-all',
              activeTab === tab
                ? 'bg-white text-violet-700 shadow-sm'
                : 'text-muted-foreground hover:text-foreground'
            )}
          >
            {tab === 'compose' ? (
              <span className="flex items-center justify-center gap-1.5">
                <Send className="w-3.5 h-3.5" />
                Compose
              </span>
            ) : (
              <span className="flex items-center justify-center gap-1.5">
                <Megaphone className="w-3.5 h-3.5" />
                Sent
              </span>
            )}
          </button>
        ))}
      </div>

      {activeTab === 'compose' ? <ComposeTab onSent={() => setActiveTab('sent')} /> : <SentTab />}
    </div>
  );
}

// ══════════════════════════════════════════════════════════════
// Compose Tab
// ══════════════════════════════════════════════════════════════

function ComposeTab({ onSent }: { onSent: () => void }) {
  // ── Target type ──
  const [targetType, setTargetType] = useState<TargetType>('all');

  // ── Filters data ──
  const [filters, setFilters] = useState<FilterData | null>(null);
  const [filtersLoading, setFiltersLoading] = useState(true);

  // ── Multi-select selections ──
  const [selectedUnits, setSelectedUnits] = useState<number[]>([]);
  const [selectedClients, setSelectedClients] = useState<number[]>([]);
  const [selectedCities, setSelectedCities] = useState<string[]>([]);
  const [selectedStates, setSelectedStates] = useState<string[]>([]);

  // ── Individual search ──
  const [searchQuery, setSearchQuery] = useState('');
  const [searchResults, setSearchResults] = useState<SearchedEmployee[]>([]);
  const [searchLoading, setSearchLoading] = useState(false);
  const [selectedEmployees, setSelectedEmployees] = useState<SearchedEmployee[]>([]);
  const searchTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // ── Form ──
  const [title, setTitle] = useState('');
  const [message, setMessage] = useState('');
  const [sending, setSending] = useState(false);

  // ── Load filters on mount ──
  useEffect(() => {
    let cancelled = false;
    setFiltersLoading(true);
    unwrap(apiRequest<{ units: FilterData['units']; clients: FilterData['clients']; cities: FilterData['cities']; states: FilterData['states'] }>(
      '/ess/admin-notifications?view=filters'
    )).then(({ data, error }) => {
      if (cancelled) return;
      if (error) {
        toast.error(error);
        setFiltersLoading(false);
        return;
      }
      if (data) {
        setFilters({
          units: Array.isArray(data.units) ? data.units : [],
          clients: Array.isArray(data.clients) ? data.clients : [],
          cities: Array.isArray(data.cities) ? data.cities : [],
          states: Array.isArray(data.states) ? data.states : [],
        });
      }
      setFiltersLoading(false);
    });
    return () => { cancelled = true; };
  }, []);

  // ── Individual search with debounce ──
  useEffect(() => {
    if (searchTimerRef.current) clearTimeout(searchTimerRef.current);
    if (!searchQuery.trim()) {
      setSearchResults([]);
      return;
    }
    searchTimerRef.current = setTimeout(async () => {
      setSearchLoading(true);
      const { data, error } = await unwrap(apiRequest<SearchedEmployee[]>(
        `/ess/admin-notifications?view=search-employees&q=${encodeURIComponent(searchQuery.trim())}`
      ));
      setSearchLoading(false);
      if (error) { toast.error(error); return; }
      setSearchResults(data ? data.slice(0, 5) : []);
    }, 300);
    return () => { if (searchTimerRef.current) clearTimeout(searchTimerRef.current); };
  }, [searchQuery]);

  // ── Selection helpers ──
  const toggleUnit = useCallback((id: number) => {
    setSelectedUnits((prev) => prev.includes(id) ? prev.filter((u) => u !== id) : [...prev, id]);
  }, []);

  const toggleClient = useCallback((id: number) => {
    setSelectedClients((prev) => prev.includes(id) ? prev.filter((c) => c !== id) : [...prev, id]);
  }, []);

  const toggleCity = useCallback((city: string) => {
    setSelectedCities((prev) => prev.includes(city) ? prev.filter((c) => c !== city) : [...prev, city]);
  }, []);

  const toggleState = useCallback((state: string) => {
    setSelectedStates((prev) => prev.includes(state) ? prev.filter((s) => s !== state) : [...prev, state]);
  }, []);

  const addEmployee = useCallback((emp: SearchedEmployee) => {
    if (selectedEmployees.some((e) => e.id === emp.id)) return;
    setSelectedEmployees((prev) => [...prev, emp]);
    setSearchQuery('');
    setSearchResults([]);
  }, [selectedEmployees]);

  const removeEmployee = useCallback((id: string) => {
    setSelectedEmployees((prev) => prev.filter((e) => e.id !== id));
  }, []);

  // ── Validation ──
  const isFormValid = (() => {
    if (!title.trim() || !message.trim()) return false;
    switch (targetType) {
      case 'all':
      case 'managers':
        return true;
      case 'unit':
        return selectedUnits.length > 0;
      case 'client':
        return selectedClients.length > 0;
      case 'city':
        return selectedCities.length > 0;
      case 'state':
        return selectedStates.length > 0;
      case 'individual':
        return selectedEmployees.length > 0;
      default:
        return false;
    }
  })();

  // ── Build target_ids for submission ──
  const getTargetIds = useCallback((): (number | string)[] => {
    switch (targetType) {
      case 'all':
      case 'managers':
        return [];
      case 'unit':
        return selectedUnits;
      case 'client':
        return selectedClients;
      case 'city':
        return selectedCities;
      case 'state':
        return selectedStates;
      case 'individual':
        return selectedEmployees.map((e) => e.id);
      default:
        return [];
    }
  }, [targetType, selectedUnits, selectedClients, selectedCities, selectedStates, selectedEmployees]);

  // ── Confirm & send ──
  const handleSend = useCallback(async () => {
    if (!isFormValid || sending) return;

    const targetIds = getTargetIds();
    const targetLabel = TARGET_TYPES.find((t) => t.value === targetType)?.label ?? targetType;
    let recipientDesc = '';
    if (targetType === 'all') recipientDesc = 'all employees';
    else if (targetType === 'managers') recipientDesc = 'all managers';
    else if (targetType === 'individual') recipientDesc = `${targetIds.length} employee(s)`;
    else recipientDesc = `${targetIds.length} ${targetLabel.toLowerCase()}(s)`;

    if (!window.confirm(`Send this notification to ${recipientDesc}?`)) return;

    setSending(true);
    try {
      const { data, error } = await unwrap(apiRequest<{ broadcast_id: number; recipient_count: number; message: string }>(
        '/ess/admin-notifications',
        {
          method: 'POST',
          body: JSON.stringify({
            title: title.trim(),
            message: message.trim(),
            target_type: targetType,
            target_ids: targetIds,
          }),
        }
      ));

      if (error) {
        toast.error(error);
        return;
      }

      toast.success(`Notification sent to ${data?.recipient_count ?? 0} recipient(s)`);

      // Reset form
      setTitle('');
      setMessage('');
      setTargetType('all');
      setSelectedUnits([]);
      setSelectedClients([]);
      setSelectedCities([]);
      setSelectedStates([]);
      setSelectedEmployees([]);
      setSearchQuery('');
      setSearchResults([]);

      // Switch to sent tab
      onSent();
    } catch (err) {
      console.error('Failed to send notification:', err);
      toast.error('Failed to send notification');
    } finally {
      setSending(false);
    }
  }, [isFormValid, sending, getTargetIds, targetType, title, message, onSent]);

  // ── Reset target selections when type changes ──
  const handleTargetTypeChange = useCallback((newType: TargetType) => {
    setTargetType(newType);
    setSelectedUnits([]);
    setSelectedClients([]);
    setSelectedCities([]);
    setSelectedStates([]);
    setSelectedEmployees([]);
    setSearchQuery('');
    setSearchResults([]);
  }, []);

  return (
    <div className="flex flex-col gap-4">
      {/* Purple gradient header */}
      <div className="bg-gradient-to-r from-violet-600 to-purple-500 rounded-2xl px-4 py-3 text-white flex items-center gap-2 shadow-lg">
        <Send className="w-5 h-5 text-white/80" />
        <h1 className="text-[15px] font-bold">Send Notification</h1>
      </div>

      {/* Target Type Selector — horizontal scroll */}
      <div className="flex gap-2 overflow-x-auto pb-1 -mx-1 px-1 scrollbar-none">
        {TARGET_TYPES.map((t) => {
          const Icon = t.icon;
          const isActive = targetType === t.value;
          return (
            <button
              key={t.value}
              onClick={() => handleTargetTypeChange(t.value)}
              className={cn(
                'flex items-center gap-1.5 px-3 py-1.5 rounded-full text-[12px] font-semibold whitespace-nowrap shrink-0 transition-all border',
                isActive
                  ? 'bg-violet-600 text-white border-violet-600 shadow-sm'
                  : 'bg-white text-muted-foreground border-gray-200 hover:border-violet-300 hover:text-violet-700'
              )}
            >
              <Icon className="w-3.5 h-3.5" />
              {t.label}
            </button>
          );
        })}
      </div>

      {/* ── Target-specific content ── */}

      {/* All employees info */}
      {targetType === 'all' && (
        <div className="flex items-start gap-2.5 bg-emerald-50 border border-emerald-200 rounded-xl px-3.5 py-3">
          <Users className="w-4 h-4 text-emerald-600 shrink-0 mt-0.5" />
          <div className="min-w-0">
            <p className="text-[12px] font-semibold text-emerald-800">Broadcast to all employees</p>
            <p className="text-[11px] text-emerald-600 mt-0.5">
              This notification will be sent to all active employees across the organization.
            </p>
          </div>
        </div>
      )}

      {/* Managers info */}
      {targetType === 'managers' && (
        <div className="flex items-start gap-2.5 bg-emerald-50 border border-emerald-200 rounded-xl px-3.5 py-3">
          <Users className="w-4 h-4 text-emerald-600 shrink-0 mt-0.5" />
          <div className="min-w-0">
            <p className="text-[12px] font-semibold text-emerald-800">Broadcast to all managers</p>
            <p className="text-[11px] text-emerald-600 mt-0.5">
              This notification will be sent to all active managers across the organization.
            </p>
          </div>
        </div>
      )}

      {/* By Unit — multi-select */}
      {targetType === 'unit' && (
        <Card className="border-0 shadow-sm">
          <CardContent className="p-3 space-y-2">
            <p className="text-[12px] font-semibold text-gray-700 flex items-center gap-1.5">
              <Building2 className="w-3.5 h-3.5 text-violet-500" />
              Select Units
              {selectedUnits.length > 0 && (
                <Badge variant="secondary" className="ml-auto text-[11px] bg-violet-100 text-violet-700 border-violet-200">
                  {selectedUnits.length} selected
                </Badge>
              )}
            </p>
            {filtersLoading ? (
              <div className="space-y-2">
                {Array.from({ length: 4 }).map((_, i) => (
                  <Skeleton key={i} className="h-9 w-full rounded-lg" />
                ))}
              </div>
            ) : !filters || filters.units.length === 0 ? (
              <p className="text-[12px] text-muted-foreground py-2 text-center">No units available</p>
            ) : (
              <div className="max-h-48 overflow-y-auto space-y-1">
                {filters.units.map((unit) => {
                  const isSelected = selectedUnits.includes(unit.id);
                  return (
                    <button
                      key={unit.id}
                      onClick={() => toggleUnit(unit.id)}
                      className={cn(
                        'w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-[13px] transition-colors text-left',
                        isSelected
                          ? 'bg-violet-50 border border-violet-200'
                          : 'hover:bg-gray-50 border border-transparent'
                      )}
                    >
                      <div className={cn(
                        'w-5 h-5 rounded border-2 flex items-center justify-center shrink-0 transition-colors',
                        isSelected
                          ? 'bg-violet-600 border-violet-600'
                          : 'border-gray-300 bg-white'
                      )}>
                        {isSelected && <Check className="w-3 h-3 text-white" />}
                      </div>
                      <span className={cn('truncate', isSelected ? 'text-violet-900 font-medium' : 'text-gray-700')}>
                        {unit.name}
                      </span>
                    </button>
                  );
                })}
              </div>
            )}
            {/* Selected chips */}
            {selectedUnits.length > 0 && (
              <div className="flex flex-wrap gap-1.5 pt-1">
                {selectedUnits.map((id) => {
                  const unit = filters?.units.find((u) => u.id === id);
                  if (!unit) return null;
                  return (
                    <span
                      key={id}
                      className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-violet-100 text-violet-700 text-[11px] font-medium border border-violet-200"
                    >
                      {unit.name}
                      <button onClick={() => toggleUnit(id)} className="hover:text-violet-900">
                        <X className="w-3 h-3" />
                      </button>
                    </span>
                  );
                })}
              </div>
            )}
          </CardContent>
        </Card>
      )}

      {/* By Client — multi-select */}
      {targetType === 'client' && (
        <Card className="border-0 shadow-sm">
          <CardContent className="p-3 space-y-2">
            <p className="text-[12px] font-semibold text-gray-700 flex items-center gap-1.5">
              <Building2 className="w-3.5 h-3.5 text-violet-500" />
              Select Clients
              {selectedClients.length > 0 && (
                <Badge variant="secondary" className="ml-auto text-[11px] bg-violet-100 text-violet-700 border-violet-200">
                  {selectedClients.length} selected
                </Badge>
              )}
            </p>
            {filtersLoading ? (
              <div className="space-y-2">
                {Array.from({ length: 4 }).map((_, i) => (
                  <Skeleton key={i} className="h-9 w-full rounded-lg" />
                ))}
              </div>
            ) : !filters || filters.clients.length === 0 ? (
              <p className="text-[12px] text-muted-foreground py-2 text-center">No clients available</p>
            ) : (
              <div className="max-h-48 overflow-y-auto space-y-1">
                {filters.clients.map((client) => {
                  const isSelected = selectedClients.includes(client.id);
                  return (
                    <button
                      key={client.id}
                      onClick={() => toggleClient(client.id)}
                      className={cn(
                        'w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-[13px] transition-colors text-left',
                        isSelected
                          ? 'bg-violet-50 border border-violet-200'
                          : 'hover:bg-gray-50 border border-transparent'
                      )}
                    >
                      <div className={cn(
                        'w-5 h-5 rounded border-2 flex items-center justify-center shrink-0 transition-colors',
                        isSelected
                          ? 'bg-violet-600 border-violet-600'
                          : 'border-gray-300 bg-white'
                      )}>
                        {isSelected && <Check className="w-3 h-3 text-white" />}
                      </div>
                      <span className={cn('truncate', isSelected ? 'text-violet-900 font-medium' : 'text-gray-700')}>
                        {client.name}
                      </span>
                    </button>
                  );
                })}
              </div>
            )}
            {/* Selected chips */}
            {selectedClients.length > 0 && (
              <div className="flex flex-wrap gap-1.5 pt-1">
                {selectedClients.map((id) => {
                  const client = filters?.clients.find((c) => c.id === id);
                  if (!client) return null;
                  return (
                    <span
                      key={id}
                      className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-violet-100 text-violet-700 text-[11px] font-medium border border-violet-200"
                    >
                      {client.name}
                      <button onClick={() => toggleClient(id)} className="hover:text-violet-900">
                        <X className="w-3 h-3" />
                      </button>
                    </span>
                  );
                })}
              </div>
            )}
          </CardContent>
        </Card>
      )}

      {/* By City — multi-select */}
      {targetType === 'city' && (
        <Card className="border-0 shadow-sm">
          <CardContent className="p-3 space-y-2">
            <p className="text-[12px] font-semibold text-gray-700 flex items-center gap-1.5">
              <MapPin className="w-3.5 h-3.5 text-violet-500" />
              Select Cities
              {selectedCities.length > 0 && (
                <Badge variant="secondary" className="ml-auto text-[11px] bg-violet-100 text-violet-700 border-violet-200">
                  {selectedCities.length} selected
                </Badge>
              )}
            </p>
            {filtersLoading ? (
              <div className="space-y-2">
                {Array.from({ length: 4 }).map((_, i) => (
                  <Skeleton key={i} className="h-9 w-full rounded-lg" />
                ))}
              </div>
            ) : !filters || filters.cities.length === 0 ? (
              <p className="text-[12px] text-muted-foreground py-2 text-center">No cities available</p>
            ) : (
              <div className="max-h-48 overflow-y-auto space-y-1">
                {filters.cities.map((city) => {
                  const isSelected = selectedCities.includes(city);
                  return (
                    <button
                      key={city}
                      onClick={() => toggleCity(city)}
                      className={cn(
                        'w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-[13px] transition-colors text-left',
                        isSelected
                          ? 'bg-violet-50 border border-violet-200'
                          : 'hover:bg-gray-50 border border-transparent'
                      )}
                    >
                      <div className={cn(
                        'w-5 h-5 rounded border-2 flex items-center justify-center shrink-0 transition-colors',
                        isSelected
                          ? 'bg-violet-600 border-violet-600'
                          : 'border-gray-300 bg-white'
                      )}>
                        {isSelected && <Check className="w-3 h-3 text-white" />}
                      </div>
                      <span className={cn('truncate', isSelected ? 'text-violet-900 font-medium' : 'text-gray-700')}>
                        {city}
                      </span>
                    </button>
                  );
                })}
              </div>
            )}
            {selectedCities.length > 0 && (
              <div className="flex flex-wrap gap-1.5 pt-1">
                {selectedCities.map((city) => (
                  <span
                    key={city}
                    className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-violet-100 text-violet-700 text-[11px] font-medium border border-violet-200"
                  >
                    {city}
                    <button onClick={() => toggleCity(city)} className="hover:text-violet-900">
                      <X className="w-3 h-3" />
                    </button>
                  </span>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      )}

      {/* By State — multi-select */}
      {targetType === 'state' && (
        <Card className="border-0 shadow-sm">
          <CardContent className="p-3 space-y-2">
            <p className="text-[12px] font-semibold text-gray-700 flex items-center gap-1.5">
              <Globe className="w-3.5 h-3.5 text-violet-500" />
              Select States
              {selectedStates.length > 0 && (
                <Badge variant="secondary" className="ml-auto text-[11px] bg-violet-100 text-violet-700 border-violet-200">
                  {selectedStates.length} selected
                </Badge>
              )}
            </p>
            {filtersLoading ? (
              <div className="space-y-2">
                {Array.from({ length: 4 }).map((_, i) => (
                  <Skeleton key={i} className="h-9 w-full rounded-lg" />
                ))}
              </div>
            ) : !filters || filters.states.length === 0 ? (
              <p className="text-[12px] text-muted-foreground py-2 text-center">No states available</p>
            ) : (
              <div className="max-h-48 overflow-y-auto space-y-1">
                {filters.states.map((state) => {
                  const isSelected = selectedStates.includes(state);
                  return (
                    <button
                      key={state}
                      onClick={() => toggleState(state)}
                      className={cn(
                        'w-full flex items-center gap-2.5 px-3 py-2 rounded-lg text-[13px] transition-colors text-left',
                        isSelected
                          ? 'bg-violet-50 border border-violet-200'
                          : 'hover:bg-gray-50 border border-transparent'
                      )}
                    >
                      <div className={cn(
                        'w-5 h-5 rounded border-2 flex items-center justify-center shrink-0 transition-colors',
                        isSelected
                          ? 'bg-violet-600 border-violet-600'
                          : 'border-gray-300 bg-white'
                      )}>
                        {isSelected && <Check className="w-3 h-3 text-white" />}
                      </div>
                      <span className={cn('truncate', isSelected ? 'text-violet-900 font-medium' : 'text-gray-700')}>
                        {state}
                      </span>
                    </button>
                  );
                })}
              </div>
            )}
            {selectedStates.length > 0 && (
              <div className="flex flex-wrap gap-1.5 pt-1">
                {selectedStates.map((state) => (
                  <span
                    key={state}
                    className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-violet-100 text-violet-700 text-[11px] font-medium border border-violet-200"
                  >
                    {state}
                    <button onClick={() => toggleState(state)} className="hover:text-violet-900">
                      <X className="w-3 h-3" />
                    </button>
                  </span>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      )}

      {/* Individual — search */}
      {targetType === 'individual' && (
        <Card className="border-0 shadow-sm">
          <CardContent className="p-3 space-y-2.5">
            <p className="text-[12px] font-semibold text-gray-700 flex items-center gap-1.5">
              <User className="w-3.5 h-3.5 text-violet-500" />
              Search Employees
              {selectedEmployees.length > 0 && (
                <Badge variant="secondary" className="ml-auto text-[11px] bg-violet-100 text-violet-700 border-violet-200">
                  {selectedEmployees.length} selected
                </Badge>
              )}
            </p>

            {/* Selected employee chips above input */}
            {selectedEmployees.length > 0 && (
              <div className="flex flex-wrap gap-1.5">
                {selectedEmployees.map((emp) => (
                  <span
                    key={emp.id}
                    className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-violet-100 text-violet-700 text-[11px] font-medium border border-violet-200"
                  >
                    {emp.full_name}
                    <button onClick={() => removeEmployee(emp.id)} className="hover:text-violet-900">
                      <X className="w-3 h-3" />
                    </button>
                  </span>
                ))}
              </div>
            )}

            {/* Search input */}
            <div className="relative">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
              <Input
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Search by name..."
                className="pl-9 h-9 text-[13px]"
              />
              {searchLoading && (
                <Loader2 className="absolute right-2.5 top-1/2 -translate-y-1/2 w-4 h-4 animate-spin text-violet-500" />
              )}
            </div>

            {/* Search results */}
            {searchResults.length > 0 && (
              <div className="border rounded-lg overflow-hidden divide-y max-h-52 overflow-y-auto">
                {searchResults.map((emp) => {
                  const alreadySelected = selectedEmployees.some((e) => e.id === emp.id);
                  return (
                    <button
                      key={emp.id}
                      onClick={() => !alreadySelected && addEmployee(emp)}
                      disabled={alreadySelected}
                      className={cn(
                        'w-full flex items-center gap-2.5 px-3 py-2 text-left transition-colors',
                        alreadySelected
                          ? 'bg-gray-50 opacity-60 cursor-not-allowed'
                          : 'hover:bg-violet-50'
                      )}
                    >
                      <div className="w-8 h-8 rounded-full bg-violet-100 flex items-center justify-center shrink-0">
                        <User className="w-4 h-4 text-violet-600" />
                      </div>
                      <div className="min-w-0 flex-1">
                        <p className="text-[13px] font-medium text-gray-900 truncate">{emp.full_name}</p>
                        <p className="text-[11px] text-muted-foreground truncate">
                          {emp.role && `${emp.role} · `}{emp.unit_name}{emp.client_name ? ` · ${emp.client_name}` : ''}
                        </p>
                      </div>
                      {alreadySelected ? (
                        <Check className="w-4 h-4 text-violet-500 shrink-0" />
                      ) : (
                        <ChevronRight className="w-4 h-4 text-gray-400 shrink-0" />
                      )}
                    </button>
                  );
                })}
              </div>
            )}

            {!searchLoading && searchQuery.trim().length > 0 && searchResults.length === 0 && (
              <p className="text-[12px] text-muted-foreground text-center py-2">No employees found</p>
            )}
          </CardContent>
        </Card>
      )}

      {/* ── Title input ── */}
      <Card className="border-0 shadow-sm">
        <CardContent className="p-3 space-y-1.5">
          <label htmlFor="notif-title" className="text-[12px] font-semibold text-gray-700">
            Title
          </label>
          <Input
            id="notif-title"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            placeholder="Notification title"
            className="text-[13px] h-10"
            maxLength={200}
          />
        </CardContent>
      </Card>

      {/* ── Message textarea ── */}
      <Card className="border-0 shadow-sm">
        <CardContent className="p-3 space-y-1.5">
          <label htmlFor="notif-message" className="text-[12px] font-semibold text-gray-700">
            Message
          </label>
          <Textarea
            id="notif-message"
            value={message}
            onChange={(e) => setMessage(e.target.value)}
            placeholder="Write your notification message..."
            className="text-[13px] min-h-[120px] resize-none"
            rows={5}
            maxLength={5000}
          />
        </CardContent>
      </Card>

      {/* ── Send button ── */}
      <Button
        className="w-full h-12 text-[15px] font-bold bg-violet-600 hover:bg-violet-700 text-white gap-2 shadow-lg rounded-xl"
        onClick={handleSend}
        disabled={!isFormValid || sending}
      >
        {sending ? (
          <Loader2 className="w-5 h-5 animate-spin" />
        ) : (
          <Send className="w-5 h-5" />
        )}
        {sending ? 'Sending...' : 'Send Notification'}
      </Button>
    </div>
  );
}

// ══════════════════════════════════════════════════════════════
// Sent Tab
// ══════════════════════════════════════════════════════════════

function SentTab() {
  const [broadcasts, setBroadcasts] = useState<BroadcastItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const loadBroadcasts = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const { data, error: fetchError } = await unwrap(apiRequest<{ items: BroadcastItem[]; pagination: BroadcastPagination }>(
        '/ess/admin-notifications?view=broadcasts&page=1&limit=20'
      ));
      if (fetchError) {
        toast.error(fetchError);
        setError(fetchError);
        return;
      }
      setBroadcasts(data?.items ? (Array.isArray(data.items) ? data.items : []) : []);
    } catch (err) {
      console.error('Failed to fetch broadcasts:', err);
      setError('Failed to load sent notifications');
      toast.error('Failed to load sent notifications');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadBroadcasts();
  }, [loadBroadcasts]);

  const pullRefresh = usePullToRefresh<HTMLDivElement>({
    onRefresh: loadBroadcasts,
  });

  const pullRefreshProps = {
    ref: pullRefresh.containerRef,
    onTouchStart: pullRefresh.handleTouchStart,
    onTouchMove: pullRefresh.handleTouchMove,
    onTouchEnd: pullRefresh.handleTouchEnd,
  };

  return (
    <div {...pullRefreshProps} className="flex flex-col gap-3" style={{ touchAction: 'pan-y' }}>
      {/* Pull-to-refresh indicator */}
      <div style={pullRefresh.pullIndicatorStyle} className="flex items-center justify-center">
        <Loader2 className={cn(
          'h-5 w-5 text-violet-500',
          (pullRefresh.isRefreshing || pullRefresh.pullDistance > 20) && 'animate-spin'
        )} />
      </div>

      {loading ? (
        <div className="flex flex-col gap-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <div key={i} className="rounded-xl border p-4 space-y-3">
              <div className="flex items-center justify-between">
                <Skeleton className="h-5 w-24" />
                <Skeleton className="h-5 w-16" />
              </div>
              <Skeleton className="h-5 w-3/4" />
              <Skeleton className="h-4 w-full" />
              <Skeleton className="h-4 w-2/3" />
              <div className="flex items-center justify-between pt-1">
                <Skeleton className="h-2.5 flex-1 max-w-[120px]" />
                <Skeleton className="h-4 w-20" />
              </div>
            </div>
          ))}
        </div>
      ) : error ? (
        <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-destructive/30 bg-destructive/5 p-8 text-center">
          <X className="h-10 w-10 text-destructive" />
          <p className="text-sm text-destructive">{error}</p>
          <Button variant="outline" size="sm" onClick={loadBroadcasts}>
            Retry
          </Button>
        </div>
      ) : broadcasts.length === 0 ? (
        <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed p-10 text-center">
          <Inbox className="h-10 w-10 text-muted-foreground/50" />
          <div>
            <p className="font-medium text-muted-foreground">No notifications sent</p>
            <p className="text-sm text-muted-foreground/70">
              Notifications you send will appear here
            </p>
          </div>
        </div>
      ) : (
        <div className="flex flex-col gap-3">
          {broadcasts.map((item) => (
            <BroadcastCard key={item.broadcast_id} item={item} />
          ))}
        </div>
      )}
    </div>
  );
}

// ══════════════════════════════════════════════════════════════
// Broadcast Card
// ══════════════════════════════════════════════════════════════

function BroadcastCard({ item }: { item: BroadcastItem }) {
  const readPercent = Math.round(item.read_percent) || 0;

  const timeAgo = (dateStr: string): string => {
    const now = new Date();
    const date = new Date(dateStr);
    const diffMs = now.getTime() - date.getTime();
    const diffSec = Math.floor(diffMs / 1000);
    const diffMin = Math.floor(diffSec / 60);
    const diffHr = Math.floor(diffMin / 60);
    const diffDay = Math.floor(diffHr / 24);

    if (diffSec < 60) return 'Just now';
    if (diffMin < 60) return `${diffMin}m ago`;
    if (diffHr < 24) return `${diffHr}h ago`;
    if (diffDay < 7) return `${diffDay}d ago`;
    return date.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
  };

  const progressColor = readPercent >= 80
    ? 'bg-emerald-500'
    : readPercent >= 50
      ? 'bg-amber-500'
      : readPercent > 0
        ? 'bg-violet-500'
        : 'bg-gray-300';

  return (
    <div className="rounded-xl border bg-card p-4 transition-colors hover:bg-accent/30">
      {/* Title + target badge */}
      <div className="flex items-start justify-between gap-2 mb-1.5">
        <h3 className="font-semibold text-[13px] text-gray-900 leading-snug line-clamp-1">
          {item.title}
        </h3>
        <Badge
          variant="outline"
          className="shrink-0 text-[10px] bg-violet-50 text-violet-700 border-violet-200"
        >
          {item.target_label}
        </Badge>
      </div>

      {/* Message (truncated) */}
      <p className="text-[12px] text-muted-foreground leading-relaxed line-clamp-2 mb-3">
        {item.message}
      </p>

      {/* Read progress bar */}
      <div className="flex items-center gap-2.5 mb-2.5">
        <div className="flex-1 h-2 rounded-full bg-gray-100 overflow-hidden">
          <div
            className={cn('h-full rounded-full transition-all', progressColor)}
            style={{ width: `${Math.min(readPercent, 100)}%` }}
          />
        </div>
        <span className="text-[11px] font-semibold text-gray-500 tabular-nums shrink-0">
          {readPercent}%
        </span>
      </div>

      {/* Meta: recipients, read count, time */}
      <div className="flex items-center justify-between text-[11px] text-muted-foreground">
        <div className="flex items-center gap-3">
          <span className="flex items-center gap-1">
            <Users className="w-3 h-3" />
            {item.total_recipients}
          </span>
          <span className="flex items-center gap-1">
            <Eye className="w-3 h-3" />
            {item.read_count}
          </span>
        </div>
        <span className="flex items-center gap-1">
          <Clock className="w-3 h-3" />
          {timeAgo(item.created_at)}
        </span>
      </div>
    </div>
  );
}