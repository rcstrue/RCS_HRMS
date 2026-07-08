'use client';

import { useState, useEffect } from 'react';
import { toast } from 'sonner';
import { fetchUnitVisitDetail, deleteUnitVisit } from '@/lib/ess-api';
import { getFileUrl } from '@/lib/api/config';
import type { UnitVisit } from '@/lib/ess-types';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import {
  ArrowLeft, FileText, Trash2, Loader2,
  MapPin, Calendar, User, Building2, AlertCircle,
} from 'lucide-react';

const MONTHS = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

interface UnitVisitReportProps {
  visitId: number;
  employeeId: number;
  employeeName: string;
  onBack: () => void;
  onDeleted?: () => void;
}

export default function UnitVisitReport({ visitId, employeeId, employeeName, onBack, onDeleted }: UnitVisitReportProps) {
  const [visit, setVisit] = useState<UnitVisit | null>(null);
  const [loading, setLoading] = useState(true);
  const [deleting, setDeleting] = useState(false);
  const [showDeleteDialog, setShowDeleteDialog] = useState(false);
  const [showImageDialog, setShowImageDialog] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    fetchUnitVisitDetail(visitId).then(({ data, error }) => {
      if (cancelled) return;
      if (error) { toast.error(error); return; }
      if (data) setVisit(data);
    }).finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [visitId]);

  const handleDelete = async () => {
    setDeleting(true);
    const { error } = await deleteUnitVisit(visitId);
    setDeleting(false);
    setShowDeleteDialog(false);
    if (error) { toast.error(error); return; }
    toast.success('Visit deleted');
    onDeleted?.();
    onBack();
  };

  if (loading) return <div className="flex items-center justify-center py-20"><Loader2 className="w-6 h-6 animate-spin text-emerald-600" /><span className="ml-2 text-sm text-gray-500">Loading...</span></div>;
  if (!visit) return <div className="flex items-center justify-center py-20"><AlertCircle className="w-6 h-6 text-gray-400" /><span className="ml-2 text-sm text-gray-500">Visit not found</span></div>;

  const statusColors: Record<string, string> = {
    submitted: 'bg-blue-100 text-blue-700 border-blue-200',
    approved: 'bg-emerald-100 text-emerald-700 border-emerald-200',
    rejected: 'bg-red-100 text-red-700 border-red-200',
  };

  return (
    <div className="space-y-4">
      {/* Back + Delete */}
      <div className="flex items-center justify-between">
        <button onClick={onBack} className="flex items-center gap-1.5 text-sm font-medium text-gray-600 hover:text-gray-900"><ArrowLeft className="w-4 h-4" /> Back</button>
        {visit.status === 'submitted' && visit.employee_id === employeeId && (
          <Button size="sm" variant="outline" className="h-8 text-xs gap-1 text-red-600 hover:text-red-700" onClick={() => setShowDeleteDialog(true)}><Trash2 className="w-3.5 h-3.5" /> Delete</Button>
        )}
      </div>

      {/* Visit Info Card */}
      <Card className="border-2 border-emerald-200 overflow-hidden">
        <div className="bg-emerald-500 px-5 py-4 flex items-center justify-between">
          <div className="text-white">
            <p className="text-xs font-medium text-white/80 uppercase tracking-wider">Unit Visit Checklist</p>
            <p className="text-lg font-bold mt-0.5">{visit.unit_name || ''}</p>
          </div>
          <div className="text-right text-white">
            <p className="text-xs text-white/80">{visit.visit_number === 1 ? 'First' : 'Second'} Visit</p>
            <p className="text-sm font-semibold">{MONTHS[visit.visit_month]} {visit.visit_year}</p>
          </div>
        </div>
        <CardContent className="p-4 space-y-3">
          <div className="grid grid-cols-2 gap-3 text-sm">
            <div className="flex items-center gap-2"><User className="w-3.5 h-3.5 text-gray-400" /><div><p className="text-[10px] text-gray-400">Employee</p><p className="font-medium">{visit.employee_name || employeeName}</p></div></div>
            <div className="flex items-center gap-2"><Building2 className="w-3.5 h-3.5 text-gray-400" /><div><p className="text-[10px] text-gray-400">Client</p><p className="font-medium truncate">{visit.client_name || ''}</p></div></div>
            <div className="flex items-center gap-2"><Calendar className="w-3.5 h-3.5 text-gray-400" /><div><p className="text-[10px] text-gray-400">Submitted</p><p className="font-medium">{visit.created_at ? new Date(visit.created_at).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : ''}</p></div></div>
            <div className="flex items-center gap-2"><MapPin className="w-3.5 h-3.5 text-gray-400" /><div><p className="text-[10px] text-gray-400">Status</p><Badge variant="outline" className={`text-xs ${statusColors[visit.status] || ''}`}>{visit.status.charAt(0).toUpperCase() + visit.status.slice(1)}</Badge></div></div>
          </div>
          {visit.notes && <div className="bg-gray-50 rounded-lg p-3"><p className="text-xs text-gray-400 font-medium mb-1">Notes</p><p className="text-sm text-gray-700">{visit.notes}</p></div>}
          {visit.rejection_reason && <div className="bg-red-50 rounded-lg p-3 border border-red-100"><p className="text-xs text-red-400 font-medium mb-1">Rejection Reason</p><p className="text-sm text-red-700">{visit.rejection_reason}</p></div>}
        </CardContent>
      </Card>

      {/* Document Preview */}
      {visit.document_url && (
        <Card>
          <CardHeader className="pb-2"><CardTitle className="text-sm">Visit Document</CardTitle></CardHeader>
          <CardContent>
            {visit.document_type === 'image' ? (
              <img src={getFileUrl(visit.document_url) || ''} alt="Visit document" className="max-h-60 rounded-lg border mx-auto cursor-pointer" onClick={() => setShowImageDialog(getFileUrl(visit.document_url))} />
            ) : (
              <a href={getFileUrl(visit.document_url) || ''} target="_blank" rel="noreferrer" className="flex items-center gap-2 p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors"><FileText className="w-5 h-5 text-gray-500" /><span className="text-sm text-gray-700">View PDF Document</span></a>
            )}
          </CardContent>
        </Card>
      )}

      {/* Delete Dialog */}
      <Dialog open={showDeleteDialog} onOpenChange={setShowDeleteDialog}>
        <DialogContent><DialogHeader><DialogTitle>Delete Visit</DialogTitle></DialogHeader><p className="text-sm text-gray-600">Are you sure? This will permanently delete this visit submission. Only submitted visits can be deleted.</p><DialogFooter>
          <Button variant="outline" onClick={() => setShowDeleteDialog(false)}>Cancel</Button>
          <Button variant="destructive" onClick={handleDelete} disabled={deleting}>{deleting ? <Loader2 className="w-4 h-4 animate-spin" /> : 'Delete'}</Button>
        </DialogFooter></DialogContent>
      </Dialog>

      {/* Image Preview Dialog */}
      <Dialog open={!!showImageDialog} onOpenChange={() => setShowImageDialog(null)}>
        <DialogContent className="max-w-lg p-0 overflow-hidden"><img src={showImageDialog || ''} alt="" className="w-full" /></DialogContent>
      </Dialog>
    </div>
  );
}