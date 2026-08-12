'use client';

import { type ReactNode } from 'react';
import { getRoleBadge, getInitials, formatDate } from './helpers';
import { getFileUrl } from '@/lib/api/config';
import { getFieldsBySection, type FieldEditRule, type FieldRule } from '@/lib/field-rules';
import DocumentsViewer from './DocumentsViewer';
import ChangeRequestItem from './ChangeRequestItem';
import type { Employee, EmployeeRole, ChangeRequest } from '@/lib/ess-types';

import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import {
  Shield,
  UserCircle,
  Building2,
  Mail,
  Phone,
  MapPin,
  CalendarDays,
  Settings,
  Leaf,
  Pencil,
  CreditCard,
  Lock,
  CircleDot,
  User,
  Hash,
  Users,
  Briefcase,
  Heart,
  UserCheck,
  Clock,
  BadgeCheck,
} from 'lucide-react';

// ══════════════════════════════════════════════════════════════
// ProfileView Component — Full employee profile with sections
// ══════════════════════════════════════════════════════════════

interface ProfileViewProps {
  employee: Employee;
  role: EmployeeRole;
  onNavigate: (page: string) => void;
  pendingChangeRequests?: ChangeRequest[];
}

// ── Icon mapping for field keys ──────────────────────────────
const FIELD_ICONS: Record<string, React.ElementType> = {
  employee_code: Hash,
  full_name: User,
  father_name: UserCircle,
  date_of_birth: CalendarDays,
  gender: UserCircle,
  blood_group: Heart,
  mobile_number: Phone,
  email: Mail,
  marital_status: Heart,
  address: MapPin,
  pin_code: MapPin,
  district: MapPin,
  state: MapPin,
  bank_name: CreditCard,
  account_number: CreditCard,
  ifsc_code: CreditCard,
  account_holder_name: UserCircle,
  client_name: Building2,
  unit_name: Building2,
  designation: Briefcase,
  department: Building2,
  date_of_joining: CalendarDays,
  date_of_leaving: CalendarDays,
  uan_number: Shield,
  esic_number: Shield,
  pf_number: Shield,
  emergency_contact_name: Phone,
  emergency_contact_relation: Users,
  nominee_name: UserCheck,
  nominee_relationship: Users,
  nominee_dob: CalendarDays,
  nominee_contact: Phone,
};

function getFieldIcon(key: string): React.ElementType {
  return FIELD_ICONS[key] || CircleDot;
}

// ── EditRuleBadge (inline) ───────────────────────────────────
function EditRuleBadge({ rule }: { rule: FieldEditRule }) {
  if (rule === 'free') {
    return (
      <TooltipProvider delayDuration={300}>
        <Tooltip>
          <TooltipTrigger asChild>
            <div className="flex items-center gap-1 shrink-0">
              <CircleDot className="w-3.5 h-3.5 text-emerald-500" />
            </div>
          </TooltipTrigger>
          <TooltipContent side="left" className="text-xs">Editable</TooltipContent>
        </Tooltip>
      </TooltipProvider>
    );
  }
  if (rule === 'admin_approval') {
    return (
      <TooltipProvider delayDuration={300}>
        <Tooltip>
          <TooltipTrigger asChild>
            <div className="flex items-center gap-1 shrink-0">
              <CircleDot className="w-3.5 h-3.5 text-amber-400" />
            </div>
          </TooltipTrigger>
          <TooltipContent side="left" className="text-xs">Needs Approval</TooltipContent>
        </Tooltip>
      </TooltipProvider>
    );
  }
  // readonly
  return (
    <TooltipProvider delayDuration={300}>
      <Tooltip>
        <TooltipTrigger asChild>
          <div className="flex items-center gap-1 shrink-0">
            <Lock className="w-3.5 h-3.5 text-gray-400" />
          </div>
        </TooltipTrigger>
        <TooltipContent side="left" className="text-xs">Read-only</TooltipContent>
      </Tooltip>
    </TooltipProvider>
  );
}

// ── Field Row ────────────────────────────────────────────────
function FieldRow({ field, value }: { field: FieldRule; value: string | null | undefined }) {
  const Icon = getFieldIcon(field.key);
  const displayValue = value
    ? field.masked
      ? value.length > 8
        ? value.slice(0, 4) + ' **** ' + value.slice(-4)
        : '****'
      : value
    : null;

  return (
    <div className="flex items-start gap-3 py-2.5">
      <Icon className="w-4 h-4 text-gray-400 mt-0.5 shrink-0" />
      <div className="flex-1 min-w-0">
        <p className="text-xs text-gray-400">{field.label}</p>
        <p className="text-sm font-medium text-gray-800 truncate">
          {displayValue || '—'}
        </p>
      </div>
      <EditRuleBadge rule={field.rule} />
    </div>
  );
}

// ── Section Card ─────────────────────────────────────────────
function SectionCard({
  title,
  icon: SectionIcon,
  children,
}: {
  title: string;
  icon: React.ElementType;
  children: ReactNode;
}) {
  return (
    <Card className="border-0 shadow-sm">
      <CardContent className="p-4">
        <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-1 flex items-center gap-2">
          <SectionIcon className="w-4 h-4" />
          {title}
        </h3>
        <div className="divide-y divide-gray-100">
          {children}
        </div>
      </CardContent>
    </Card>
  );
}

// ══════════════════════════════════════════════════════════════
// Main Component
// ══════════════════════════════════════════════════════════════

export default function ProfileView({
  employee,
  role,
  onNavigate,
  pendingChangeRequests = [],
}: ProfileViewProps) {
  const roleBadge = getRoleBadge(role);
  const initials = getInitials(employee.full_name || 'U');

  const isApproved = employee.status === 'approved' || employee.status === 'verified';
  const pendingRequests = pendingChangeRequests.filter(r => r.status === 'pending');

  // Get employee value for a field key
  const getValue = (key: string): string | undefined => {
    return (employee as unknown as Record<string, unknown>)[key] as string | undefined;
  };

  // Status badge
  const statusColor = isApproved
    ? 'bg-emerald-100 text-emerald-700 border-emerald-200'
    : employee.status === 'pending'
      ? 'bg-amber-100 text-amber-700 border-amber-200'
      : 'bg-gray-100 text-gray-600 border-gray-200';

  return (
    <div className="space-y-4">
      {/* ── 1. Profile Header Card ────────────────────────────── */}
      <Card className="border-0 shadow-sm overflow-hidden">
        <div className="h-24 bg-gradient-to-r from-emerald-600 to-emerald-500" />
        <CardContent className="p-5 -mt-10">
          <div className="flex items-end gap-4">
            <Avatar className="w-20 h-20 border-4 border-white shadow-md">
              <AvatarImage src={getFileUrl(employee.profile_pic_url) || undefined} alt={employee.full_name} />
              <AvatarFallback className="bg-emerald-100 text-emerald-700 text-xl font-bold">
                {initials}
              </AvatarFallback>
            </Avatar>
            <div className="pb-1 min-w-0 flex-1">
              <h2 className="text-xl font-bold text-gray-900 truncate">{employee.full_name || 'Employee'}</h2>
              <p className="text-sm text-gray-500 truncate">
                {employee.designation || 'Employee'}
                {employee.employee_code ? ` · ${employee.employee_code}` : ''}
              </p>
              <div className="flex items-center gap-1.5 mt-1.5 flex-wrap">
                <Badge variant="outline" className={`text-xs ${roleBadge.className}`}>
                  {roleBadge.label}
                </Badge>
                {employee.status && (
                  <Badge variant="outline" className={`text-xs ${statusColor}`}>
                    <BadgeCheck className="w-3 h-3 mr-1" />
                    {employee.status.charAt(0).toUpperCase() + employee.status.slice(1)}
                  </Badge>
                )}
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* ── 2. Personal Details Card ──────────────────────────── */}
      <SectionCard title="Personal Details" icon={User}>
        {getFieldsBySection('personal').map(field => (
          <FieldRow
            key={field.key}
            field={field}
            value={field.key === 'date_of_birth'
              ? (employee.date_of_birth ? formatDate(employee.date_of_birth) : undefined)
              : getValue(field.key)
            }
          />
        ))}
      </SectionCard>

      {/* ── 3. Employment Details Card ────────────────────────── */}
      <SectionCard title="Employment Details" icon={Briefcase}>
        {getFieldsBySection('employment').map(field => (
          <FieldRow
            key={field.key}
            field={field}
            value={field.key === 'date_of_joining'
              ? (employee.date_of_joining ? formatDate(employee.date_of_joining) : undefined)
              : field.key === 'date_of_leaving'
                ? (employee.date_of_leaving ? formatDate(employee.date_of_leaving) : undefined)
                : getValue(field.key)
            }
          />
        ))}
      </SectionCard>

      {/* ── 4. Address Card ──────────────────────────────────── */}
      <SectionCard title="Address" icon={MapPin}>
        {getFieldsBySection('address').map(field => (
          <FieldRow
            key={field.key}
            field={field}
            value={getValue(field.key)}
          />
        ))}
      </SectionCard>

      {/* ── 5. Bank Details Card ──────────────────────────────── */}
      <SectionCard title="Bank Details" icon={CreditCard}>
        {getFieldsBySection('bank').map(field => (
          <FieldRow
            key={field.key}
            field={field}
            value={getValue(field.key)}
          />
        ))}
      </SectionCard>

      {/* ── 6. Emergency Contact Card ─────────────────────────── */}
      <SectionCard title="Emergency Contact" icon={Phone}>
        {getFieldsBySection('emergency').map(field => (
          <FieldRow
            key={field.key}
            field={field}
            value={getValue(field.key)}
          />
        ))}
      </SectionCard>

      {/* ── 7. Nominee Details Card ───────────────────────────── */}
      <SectionCard title="Nominee Details" icon={UserCheck}>
        {getFieldsBySection('nominee').map(field => (
          <FieldRow
            key={field.key}
            field={field}
            value={field.key === 'nominee_dob'
              ? (employee.nominee_dob ? formatDate(employee.nominee_dob) : undefined)
              : getValue(field.key)
            }
          />
        ))}
      </SectionCard>

      {/* ── 8. Documents Card ────────────────────────────────── */}
      <DocumentsViewer employee={employee} />

      {/* ── 9. Pending Change Requests ──────────────────────── */}
      {pendingRequests.length > 0 && (
        <Card className="border-0 shadow-sm">
          <CardContent className="p-4">
            <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3 flex items-center gap-2">
              <Clock className="w-4 h-4" />
              Pending Change Requests
              <Badge variant="outline" className="bg-amber-100 text-amber-700 border-amber-200 text-[10px] px-1.5 py-0">
                {pendingRequests.length}
              </Badge>
            </h3>
            <div className="space-y-2 max-h-72 overflow-y-auto">
              {pendingRequests.map(req => (
                <ChangeRequestItem key={req.id} request={req} />
              ))}
            </div>
          </CardContent>
        </Card>
      )}

      {/* ── 11. Action Buttons ────────────────────────────────── */}
      <div className="grid grid-cols-3 gap-3 pt-1">
        <Button variant="outline" className="w-full" onClick={() => onNavigate('edit-profile')}>
          <Pencil className="w-4 h-4" />
          <span className="text-xs">Edit Profile</span>
        </Button>
        <Button variant="outline" className="w-full" onClick={() => onNavigate('settings')}>
          <Settings className="w-4 h-4" />
          <span className="text-xs">Settings</span>
        </Button>
        <Button variant="outline" className="w-full" onClick={() => onNavigate('leaves')}>
          <Leaf className="w-4 h-4" />
          <span className="text-xs">Leave Balance</span>
        </Button>
      </div>
    </div>
  );
}
