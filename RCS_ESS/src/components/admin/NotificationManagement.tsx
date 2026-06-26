'use client';

import { useState, useEffect, useCallback, useRef } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Progress } from '@/components/ui/progress';
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  Send,
  Users,
  Building2,
  MapPin,
  Globe,
  UserSearch,
  Bell,
  Check,
  Loader2,
  Search,
  ChevronLeft,
  ChevronRight,
  ChevronsLeft,
  ChevronsRight,
  X,
  RefreshCw,
} from 'lucide-react';
import { toast } from 'sonner';
import { apiRequest } from '@/lib/api/config';

// ── Types ──────────────────────────────────────────────────────────────

type TargetType =
  | 'all_employees'
  | 'all_managers'
  | 'by_unit'
  | 'by_client'
  | 'by_city'
  | 'by_state'
  | 'individual';

interface FilterOption {
  id: number | string;
  name: string;
}

interface EmployeeResult {
  id: number;
  full_name: string;
  mobile_number: string;
  role: string;
}

interface NotificationItem {
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

interface PaginationInfo {
  page: number;
  limit: number;
  total: number;
  total_pages: number;
}

// ── Target type config ────────────────────────────────────────────────

const TARGET_TYPES: { value: TargetType; label: string; icon: React.ElementType }[] = [
  { value: 'all_employees', label: 'All Employees', icon: Users },
  { value: 'all_managers', label: 'All Managers', icon: Users },
  { value: 'by_unit', label: 'By Unit', icon: Building2 },
  { value: 'by_client', label: 'By Client', icon: Building2 },
  { value: 'by_city', label: 'By City', icon: MapPin },
  { value: 'by_state', label: 'By State', icon: Globe },
  { value: 'individual', label: 'Individual', icon: UserSearch },
];

const TARGET_TYPE_MAP: Record<string, string> = {
  all_employees: 'All Employees',
  all_managers: 'All Managers',
  by_unit: 'By Unit',
  by_client: 'By Client',
  by_city: 'By City',
  by_state: 'By State',
  individual: 'Individual',
};

// ── Component ──────────────────────────────────────────────────────────

export function NotificationManagement() {
  // ── Form state ──
  const [title, setTitle] = useState('');
  const [message, setMessage] = useState('');
  const [targetType, setTargetType] = useState<TargetType>('all_employees');
  const [targetIds, setTargetIds] = useState<(number | string)[]>([]);

  // ── Filters ──
  const [units, setUnits] = useState<FilterOption[]>([]);
  const [clients, setClients] = useState<FilterOption[]>([]);
  const [cities, setCities] = useState<string[]>([]);
  const [states, setStates] = useState<string[]>([]);
  const [filtersLoaded, setFiltersLoaded] = useState(false);

  // ── Employee search ──
  const [empSearchQuery, setEmpSearchQuery] = useState('');
  const [empSearchResults, setEmpSearchResults] = useState<EmployeeResult[]>([]);
  const [selectedEmployees, setSelectedEmployees] = useState<EmployeeResult[]>([]);
  const [empSearchLoading, setEmpSearchLoading] = useState(false);
  const [empSearchOpen, setEmpSearchOpen] = useState(false);
  const empDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // ── Multi-select popover state ──
  const [multiSelectOpen, setMultiSelectOpen] = useState(false);

  // ── Sending ──
  const [sending, setSending] = useState(false);

  // ── History ──
  const [notifications, setNotifications] = useState<NotificationItem[]>([]);
  const [pagination, setPagination] = useState<PaginationInfo>({
    page: 1,
    limit: 10,
    total: 0,
    total_pages: 0,
  });
  const [historyLoading, setHistoryLoading] = useState(true);
  const [historyRefreshing, setHistoryRefreshing] = useState(false);

  // ── Helpers: unwrap PHP envelope ──
  interface PhpEnvelope<T> {
    success: boolean;
    data: T;
  }

  function unwrap<T>(res: { data: PhpEnvelope<T> | null; error: string | null }): T | null {
    if (res.error) {
      toast.error(res.error);
      return null;
    }
    if (res.data?.success) return res.data.data;
    return null;
  }

  // ── Fetch filters ──
  const fetchFilters = useCallback(async () => {
    const res = await apiRequest<PhpEnvelope<{
      units: FilterOption[];
      clients: FilterOption[];
      cities: string[];
      states: string[];
    }>>('/ess/admin-notifications?view=filters');

    const payload = unwrap(res);
    if (payload) {
      setUnits(payload.units || []);
      setClients(payload.clients || []);
      setCities(payload.cities || []);
      setStates(payload.states || []);
      setFiltersLoaded(true);
    }
  }, []);

  // ── Fetch notifications history ──
  const fetchNotifications = useCallback(async (page = 1, refreshing = false) => {
    if (refreshing) setHistoryRefreshing(true);
    else setHistoryLoading(true);

    const res = await apiRequest<PhpEnvelope<{
      items: NotificationItem[];
      pagination: PaginationInfo;
    }>>(`/ess/admin-notifications?page=${page}&limit=10`);

    const payload = unwrap(res);
    if (payload) {
      setNotifications(payload.items || []);
      setPagination(payload.pagination || { page: 1, limit: 10, total: 0, total_pages: 0 });
    }

    setHistoryLoading(false);
    setHistoryRefreshing(false);
  }, []);

  // ── Mount ──
  useEffect(() => {
    fetchFilters();
    fetchNotifications(1);
  }, [fetchFilters, fetchNotifications]);

  // ── Employee search with debounce ──
  useEffect(() => {
    if (empDebounceRef.current) clearTimeout(empDebounceRef.current);

    if (!empSearchQuery.trim()) {
      setEmpSearchResults([]);
      return;
    }

    empDebounceRef.current = setTimeout(async () => {
      setEmpSearchLoading(true);
      const res = await apiRequest<PhpEnvelope<EmployeeResult[]>>(
        `/ess/admin-notifications?view=search-employees&q=${encodeURIComponent(empSearchQuery.trim())}`
      );
      const payload = unwrap(res);
      setEmpSearchResults(payload || []);
      setEmpSearchLoading(false);
    }, 350);

    return () => {
      if (empDebounceRef.current) clearTimeout(empDebounceRef.current);
    };
  }, [empSearchQuery]);

  // ── Target helpers ──
  const needsMultiSelect = ['by_unit', 'by_client', 'by_city', 'by_state'].includes(targetType);
  const needsEmployeeSearch = targetType === 'individual';

  // Reset selected targets when type changes
  useEffect(() => {
    setTargetIds([]);
    setSelectedEmployees([]);
    setEmpSearchQuery('');
    setEmpSearchResults([]);
    setMultiSelectOpen(false);
  }, [targetType]);

  // ── Multi-select toggle ──
  function toggleMultiSelect(id: number | string) {
    setTargetIds((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]
    );
  }

  // ── Employee select toggle ──
  function toggleEmployee(emp: EmployeeResult) {
    setSelectedEmployees((prev) => {
      const exists = prev.find((e) => e.id === emp.id);
      if (exists) return prev.filter((e) => e.id !== emp.id);
      return [...prev, emp];
    });
    // Also keep targetIds in sync (for the POST body)
    setTargetIds((prev) => {
      const exists = prev.includes(emp.id);
      if (exists) return prev.filter((x) => x !== emp.id);
      return [...prev, emp.id];
    });
  }

  function removeEmployee(id: number) {
    setSelectedEmployees((prev) => prev.filter((e) => e.id !== id));
    setTargetIds((prev) => prev.filter((x) => x !== id));
  }

  // ── Get label for a selected multi-select id ──
  function getMultiSelectLabel(id: number | string): string {
    switch (targetType) {
      case 'by_unit':
        return units.find((u) => u.id === id)?.name || String(id);
      case 'by_client':
        return clients.find((c) => c.id === id)?.name || String(id);
      case 'by_city':
        return String(id);
      case 'by_state':
        return String(id);
      default:
        return String(id);
    }
  }

  // ── Multi-select options ──
  function getMultiSelectOptions(): FilterOption[] {
    switch (targetType) {
      case 'by_unit':
        return units;
      case 'by_client':
        return clients;
      case 'by_city':
        return cities.map((c) => ({ id: c, name: c }));
      case 'by_state':
        return states.map((s) => ({ id: s, name: s }));
      default:
        return [];
    }
  }

  // ── Send notification ──
  async function handleSend() {
    if (!title.trim()) {
      toast.error('Please enter a notification title');
      return;
    }
    if (!message.trim()) {
      toast.error('Please enter a notification message');
      return;
    }
    if (needsMultiSelect && targetIds.length === 0) {
      toast.error('Please select at least one target');
      return;
    }
    if (needsEmployeeSearch && selectedEmployees.length === 0) {
      toast.error('Please select at least one employee');
      return;
    }

    setSending(true);

    // Map frontend target types to backend expected values
    const TARGET_TYPE_BACKEND_MAP: Record<string, string> = {
      all_employees: 'all',
      all_managers: 'managers',
      by_unit: 'unit',
      by_client: 'client',
      by_city: 'city',
      by_state: 'state',
      individual: 'individual',
    };
    const backendTargetType = TARGET_TYPE_BACKEND_MAP[targetType] || targetType;

    const res = await apiRequest<PhpEnvelope<Record<string, unknown>>>(
      '/ess/admin-notifications',
      {
        method: 'POST',
        body: JSON.stringify({
          title: title.trim(),
          message: message.trim(),
          target_type: backendTargetType,
          target_ids: targetIds,
        }),
      }
    );

    if (res.error) {
      toast.error(res.error);
    } else if (res.data?.success) {
      toast.success('Notification sent successfully');
      setTitle('');
      setMessage('');
      setTargetType('all_employees');
      setTargetIds([]);
      setSelectedEmployees([]);
      fetchNotifications(1);
    } else {
      toast.error('Failed to send notification');
    }

    setSending(false);
  }

  // ── Format date ──
  function formatDate(dateStr: string): string {
    try {
      const d = new Date(dateStr);
      return d.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      });
    } catch {
      return dateStr;
    }
  }

  // ── Render ──
  return (
    <div className="space-y-6">
      {/* ─── Section 1: Send New Notification ─── */}
      <Card>
        <CardHeader className="pb-4">
          <CardTitle className="flex items-center gap-2 text-lg">
            <Bell className="h-5 w-5 text-primary" />
            Send New Notification
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-5">
          {/* Title */}
          <div className="space-y-2">
            <Label htmlFor="notif-title">Title</Label>
            <Input
              id="notif-title"
              placeholder="Enter notification title..."
              value={title}
              onChange={(e) => setTitle(e.target.value)}
            />
          </div>

          {/* Message */}
          <div className="space-y-2">
            <Label htmlFor="notif-message">Message</Label>
            <Textarea
              id="notif-message"
              placeholder="Enter notification message..."
              rows={3}
              value={message}
              onChange={(e) => setMessage(e.target.value)}
            />
          </div>

          {/* Target Type Selector */}
          <div className="space-y-2">
            <Label>Target Audience</Label>
            <div className="flex flex-wrap gap-2">
              {TARGET_TYPES.map(({ value, label, icon: Icon }) => {
                const active = targetType === value;
                return (
                  <button
                    key={value}
                    type="button"
                    onClick={() => setTargetType(value)}
                    className={`
                      inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium
                      border transition-colors cursor-pointer
                      ${
                        active
                          ? 'bg-primary text-primary-foreground border-primary'
                          : 'bg-background text-foreground border-muted-foreground/30 hover:border-primary/50 hover:bg-primary/5'
                      }
                    `}
                  >
                    <Icon className="h-3.5 w-3.5" />
                    {label}
                  </button>
                );
              })}
            </div>
          </div>

          {/* Multi-select (for by_unit, by_client, by_city, by_state) */}
          {needsMultiSelect && (
            <div className="space-y-2">
              <Label>
                Select {targetType === 'by_unit' ? 'Units' : targetType === 'by_client' ? 'Clients' : targetType === 'by_city' ? 'Cities' : 'States'}
              </Label>

              {/* Selected chips */}
              {targetIds.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                  {targetIds.map((id) => (
                    <Badge
                      key={String(id)}
                      variant="secondary"
                      className="gap-1 pr-1"
                    >
                      {getMultiSelectLabel(id)}
                      <button
                        type="button"
                        onClick={() => toggleMultiSelect(id)}
                        className="ml-0.5 rounded-full p-0.5 hover:bg-muted-foreground/20 cursor-pointer"
                      >
                        <X className="h-3 w-3" />
                      </button>
                    </Badge>
                  ))}
                </div>
              )}

              {/* Popover dropdown */}
              <Popover open={multiSelectOpen} onOpenChange={setMultiSelectOpen}>
                <PopoverTrigger asChild>
                  <Button
                    type="button"
                    variant="outline"
                    className="w-full justify-start text-muted-foreground"
                  >
                    <Search className="mr-2 h-4 w-4" />
                    {targetIds.length === 0
                      ? `Select ${targetType === 'by_unit' ? 'units' : targetType === 'by_client' ? 'clients' : targetType === 'by_city' ? 'cities' : 'states'}...`
                      : `${targetIds.length} selected`}
                  </Button>
                </PopoverTrigger>
                <PopoverContent className="w-[--radix-popover-trigger-width] p-2" align="start">
                  <div className="max-h-60 overflow-y-auto space-y-1">
                    {getMultiSelectOptions().length === 0 && (
                      <p className="text-sm text-muted-foreground text-center py-4">
                        {filtersLoaded ? 'No options available' : 'Loading...'}
                      </p>
                    )}
                    {getMultiSelectOptions().map((opt) => (
                      <label
                        key={String(opt.id)}
                        className="flex items-center gap-2 rounded-sm px-2 py-1.5 text-sm cursor-pointer hover:bg-accent transition-colors"
                      >
                        <Checkbox
                          checked={targetIds.includes(opt.id)}
                          onCheckedChange={() => toggleMultiSelect(opt.id)}
                        />
                        <span className="truncate">{opt.name}</span>
                      </label>
                    ))}
                  </div>
                </PopoverContent>
              </Popover>
            </div>
          )}

          {/* Employee search (for individual) */}
          {needsEmployeeSearch && (
            <div className="space-y-2">
              <Label>Search Employees</Label>

              {/* Selected employee chips */}
              {selectedEmployees.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                  {selectedEmployees.map((emp) => (
                    <Badge
                      key={emp.id}
                      variant="secondary"
                      className="gap-1 pr-1"
                    >
                      {emp.full_name}
                      <button
                        type="button"
                        onClick={() => removeEmployee(emp.id)}
                        className="ml-0.5 rounded-full p-0.5 hover:bg-muted-foreground/20 cursor-pointer"
                      >
                        <X className="h-3 w-3" />
                      </button>
                    </Badge>
                  ))}
                </div>
              )}

              {/* Search popover */}
              <Popover open={empSearchOpen} onOpenChange={setEmpSearchOpen}>
                <PopoverTrigger asChild>
                  <Button
                    type="button"
                    variant="outline"
                    className="w-full justify-start text-muted-foreground"
                  >
                    <UserSearch className="mr-2 h-4 w-4" />
                    {selectedEmployees.length === 0
                      ? 'Search employees by name or phone...'
                      : `${selectedEmployees.length} employee${selectedEmployees.length > 1 ? 's' : ''} selected`}
                  </Button>
                </PopoverTrigger>
                <PopoverContent className="w-[--radix-popover-trigger-width] p-2" align="start">
                  {/* Search input inside popover */}
                  <div className="relative mb-2">
                    <Search className="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                      placeholder="Type to search..."
                      value={empSearchQuery}
                      onChange={(e) => setEmpSearchQuery(e.target.value)}
                      className="pl-8 h-9"
                    />
                  </div>

                  <div className="max-h-52 overflow-y-auto space-y-1">
                    {empSearchLoading && (
                      <div className="flex items-center justify-center py-4">
                        <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
                      </div>
                    )}
                    {!empSearchLoading && empSearchQuery.trim() && empSearchResults.length === 0 && (
                      <p className="text-sm text-muted-foreground text-center py-4">
                        No employees found
                      </p>
                    )}
                    {!empSearchLoading && empSearchResults.map((emp) => {
                      const isSelected = selectedEmployees.some((e) => e.id === emp.id);
                      return (
                        <label
                          key={emp.id}
                          className="flex items-start gap-2 rounded-sm px-2 py-1.5 text-sm cursor-pointer hover:bg-accent transition-colors"
                        >
                          <Checkbox
                            checked={isSelected}
                            onCheckedChange={() => toggleEmployee(emp)}
                            className="mt-0.5"
                          />
                          <div className="flex-1 min-w-0">
                            <p className="font-medium truncate">{emp.full_name}</p>
                            <p className="text-xs text-muted-foreground">
                              {emp.role} &middot; {emp.mobile_number}
                            </p>
                          </div>
                        </label>
                      );
                    })}
                  </div>
                </PopoverContent>
              </Popover>
            </div>
          )}

          {/* Send button */}
          <div className="flex justify-end pt-2">
            <Button
              onClick={handleSend}
              disabled={sending}
              className="bg-emerald-600 hover:bg-emerald-700 text-white min-w-[140px]"
            >
              {sending ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  Sending...
                </>
              ) : (
                <>
                  <Send className="mr-2 h-4 w-4" />
                  Send Notification
                </>
              )}
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* ─── Section 2: Sent Notifications History ─── */}
      <Card>
        <CardHeader className="pb-4">
          <div className="flex items-center justify-between">
            <CardTitle className="flex items-center gap-2 text-lg">
              <Bell className="h-5 w-5 text-primary" />
              Sent Notifications
            </CardTitle>
            <Button
              variant="outline"
              size="sm"
              onClick={() => fetchNotifications(pagination.page, true)}
              disabled={historyRefreshing}
            >
              <RefreshCw className={`mr-2 h-3.5 w-3.5 ${historyRefreshing ? 'animate-spin' : ''}`} />
              Refresh
            </Button>
          </div>
        </CardHeader>
        <CardContent>
          {historyLoading ? (
            <div className="flex items-center justify-center py-12">
              <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
            </div>
          ) : notifications.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-12 text-muted-foreground">
              <Bell className="h-10 w-10 mb-3 opacity-40" />
              <p className="text-sm">No notifications sent yet</p>
            </div>
          ) : (
            <>
              {/* Table */}
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead className="min-w-[160px]">Title</TableHead>
                      <TableHead className="min-w-[120px]">Target</TableHead>
                      <TableHead className="text-center min-w-[80px]">Sent To</TableHead>
                      <TableHead className="min-w-[180px]">Read</TableHead>
                      <TableHead className="min-w-[160px]">Date</TableHead>
                      <TableHead className="min-w-[100px]">Status</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {notifications.map((item) => (
                      <TableRow key={item.broadcast_id}>
                        {/* Title */}
                        <TableCell>
                          <div>
                            <p className="font-medium truncate max-w-[220px]">{item.title}</p>
                            <p className="text-xs text-muted-foreground truncate max-w-[220px]">
                              {item.message}
                            </p>
                          </div>
                        </TableCell>

                        {/* Target */}
                        <TableCell>
                          <Badge variant="outline" className="text-xs">
                            {item.target_label || TARGET_TYPE_MAP[item.target_type] || item.target_type}
                          </Badge>
                        </TableCell>

                        {/* Sent To */}
                        <TableCell className="text-center">
                          <span className="font-medium">{item.total_recipients ?? 0}</span>
                        </TableCell>

                        {/* Read */}
                        <TableCell>
                          <div className="space-y-1">
                            <div className="flex items-center justify-between text-xs">
                              <span>
                                {item.read_count ?? 0}/{item.total_recipients ?? 0}
                              </span>
                              <span className="font-medium">{item.read_percent ?? 0}%</span>
                            </div>
                            <Progress
                              value={item.read_percent ?? 0}
                              className="h-1.5"
                            />
                          </div>
                        </TableCell>

                        {/* Date */}
                        <TableCell className="text-sm text-muted-foreground whitespace-nowrap">
                          {formatDate(item.created_at)}
                        </TableCell>

                        {/* Status */}
                        <TableCell>
                          <Badge
                            variant="secondary"
                            className={
                              (item.read_percent ?? 0) >= 75
                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                                : (item.read_percent ?? 0) >= 30
                                  ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'
                                  : 'bg-muted text-muted-foreground'
                            }
                          >
                            {(item.read_percent ?? 0) >= 75
                              ? 'Well Read'
                              : (item.read_percent ?? 0) >= 30
                                ? 'Partially Read'
                                : 'Low Read'}
                          </Badge>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>

              {/* Pagination */}
              {pagination.total_pages > 1 && (
                <div className="flex items-center justify-between pt-4 border-t mt-4">
                  <p className="text-sm text-muted-foreground">
                    Page {pagination.page} of {pagination.total_pages} &middot; {pagination.total} total
                  </p>
                  <div className="flex items-center gap-1">
                    <Button
                      variant="outline"
                      size="icon"
                      className="h-8 w-8"
                      disabled={pagination.page <= 1}
                      onClick={() => fetchNotifications(1)}
                    >
                      <ChevronsLeft className="h-4 w-4" />
                    </Button>
                    <Button
                      variant="outline"
                      size="icon"
                      className="h-8 w-8"
                      disabled={pagination.page <= 1}
                      onClick={() => fetchNotifications(pagination.page - 1)}
                    >
                      <ChevronLeft className="h-4 w-4" />
                    </Button>

                    {/* Page numbers */}
                    {Array.from({ length: pagination.total_pages }, (_, i) => i + 1)
                      .filter((p) => {
                        // Show first, last, current, and neighbors
                        if (p === 1 || p === pagination.total_pages) return true;
                        if (Math.abs(p - pagination.page) <= 1) return true;
                        return false;
                      })
                      .reduce<(number | 'ellipsis')[]>((acc, page, idx, arr) => {
                        if (idx > 0 && page - (arr[idx - 1] as number) > 1) {
                          acc.push('ellipsis');
                        }
                        acc.push(page);
                        return acc;
                      }, [])
                      .map((item, idx) =>
                        item === 'ellipsis' ? (
                          <span
                            key={`ellipsis-${idx}`}
                            className="px-2 text-sm text-muted-foreground"
                          >
                            ...
                          </span>
                        ) : (
                          <Button
                            key={item}
                            variant={pagination.page === item ? 'default' : 'outline'}
                            size="icon"
                            className="h-8 w-8"
                            onClick={() => fetchNotifications(item)}
                          >
                            {item}
                          </Button>
                        )
                      )}

                    <Button
                      variant="outline"
                      size="icon"
                      className="h-8 w-8"
                      disabled={pagination.page >= pagination.total_pages}
                      onClick={() => fetchNotifications(pagination.page + 1)}
                    >
                      <ChevronRight className="h-4 w-4" />
                    </Button>
                    <Button
                      variant="outline"
                      size="icon"
                      className="h-8 w-8"
                      disabled={pagination.page >= pagination.total_pages}
                      onClick={() => fetchNotifications(pagination.total_pages)}
                    >
                      <ChevronsRight className="h-4 w-4" />
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>
    </div>
  );
}