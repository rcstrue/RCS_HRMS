'use client';

import type { ChangeRequest } from '@/lib/ess-types';
import { FIELD_RULES } from '@/lib/field-rules';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { ArrowRight, XCircle } from 'lucide-react';
import { formatDate } from './helpers';

// ══════════════════════════════════════════════════════════════
// ChangeRequestItem — Small card showing a single change request
// ══════════════════════════════════════════════════════════════

interface ChangeRequestItemProps {
  request: ChangeRequest;
}

export default function ChangeRequestItem({ request }: ChangeRequestItemProps) {
  const fieldRule = FIELD_RULES.find(f => f.key === request.field_name);
  const label = fieldRule?.label || request.field_name.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

  const statusConfig = {
    pending: { color: 'bg-amber-100 text-amber-700 border-amber-200', label: 'Pending' },
    approved: { color: 'bg-emerald-100 text-emerald-700 border-emerald-200', label: 'Approved' },
    rejected: { color: 'bg-red-100 text-red-700 border-red-200', label: 'Rejected' },
  };

  const cfg = statusConfig[request.status];

  return (
    <Card className="border-0 shadow-sm">
      <CardContent className="p-3">
        <div className="flex items-start justify-between gap-2 mb-2">
          <p className="text-sm font-medium text-gray-800">{label}</p>
          <Badge variant="outline" className={`text-[10px] px-1.5 py-0 shrink-0 ${cfg.color}`}>
            {cfg.label}
          </Badge>
        </div>

        <div className="flex items-center gap-2 text-xs text-gray-600">
          <span className="bg-gray-100 px-2 py-0.5 rounded truncate max-w-[120px]" title={request.old_value || '—'}>
            {request.old_value || '—'}
          </span>
          <ArrowRight className="w-3 h-3 text-gray-400 shrink-0" />
          <span className="bg-emerald-50 px-2 py-0.5 rounded truncate max-w-[120px] font-medium" title={request.new_value || '—'}>
            {request.new_value || '—'}
          </span>
        </div>

        {request.reason && (
          <p className="text-[11px] text-gray-400 mt-1.5">Reason: {request.reason}</p>
        )}

        {request.status === 'rejected' && request.rejection_reason && (
          <div className="flex items-start gap-1.5 mt-2 text-xs text-red-600">
            <XCircle className="w-3.5 h-3.5 shrink-0 mt-0.5" />
            <span>{request.rejection_reason}</span>
          </div>
        )}

        <p className="text-[10px] text-gray-400 mt-1.5">
          {request.created_at ? formatDate(request.created_at) : '—'}
        </p>
      </CardContent>
    </Card>
  );
}
