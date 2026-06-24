'use client';

import { useState, useEffect, useCallback } from 'react';
import { toast } from 'sonner';
import { FileText, Download, Loader2 } from 'lucide-react';
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
import PageHeader from './PageHeader';
import { fetchPayslipPeriods, fetchPayslipData } from '@/lib/ess-api';
import { generatePayslipPDF } from '@/lib/pdf/generatePayslipPDF';
import type { PayslipData } from '@/lib/pdf/generatePayslipPDF';

// ══════════════════════════════════════════════════════════════
// PayslipPage — View payslip in HTML, download as PDF on demand
// ══════════════════════════════════════════════════════════════

interface PayslipPeriod {
  id: number;
  period_name: string;
  month: number;
  year: number;
  status: string;
}

interface PayslipPageProps {
  employeeId: number;
  employeeName: string;
}

// ── Helpers ──

function formatINR(amount: number): string {
  return '\u20B9' + amount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDateDDMMY(dateStr: string): string {
  if (!dateStr) return 'N/A';
  const d = new Date(dateStr);
  if (isNaN(d.getTime())) return dateStr;
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const yyyy = d.getFullYear();
  return `${dd}-${mm}-${yyyy}`;
}

function numberToIndianWords(num: number): string {
  if (num === 0) return 'Zero Rupees Only';
  const ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
  const tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
  function convertBelow1000(n: number): string {
    if (n === 0) return '';
    const h = Math.floor(n / 100);
    const rem = n % 100;
    let result = '';
    if (h > 0) result += ones[h] + ' Hundred';
    if (rem > 0) {
      if (result) result += ' and ';
      if (rem < 20) result += ones[rem];
      else {
        const t = Math.floor(rem / 10);
        const u = rem % 10;
        result += tens[t];
        if (u > 0) result += ' ' + ones[u];
      }
    }
    return result;
  }
  const crore = Math.floor(num / 10000000);
  const lakh = Math.floor((num % 10000000) / 100000);
  const thousand = Math.floor((num % 100000) / 1000);
  const rest = Math.floor(num % 1000);
  const paise = Math.round((num - Math.floor(num)) * 100);
  const parts: string[] = [];
  if (crore > 0) parts.push(convertBelow1000(crore) + ' Crore');
  if (lakh > 0) parts.push(convertBelow1000(lakh) + ' Lakh');
  if (thousand > 0) parts.push(convertBelow1000(thousand) + ' Thousand');
  if (rest > 0) parts.push(convertBelow1000(rest));
  let result = parts.join(' ');
  if (!result) result = 'Zero';
  if (paise > 0) {
    result += ' and ' + (paise < 20 ? ones[paise] : tens[Math.floor(paise / 10)] + (paise % 10 > 0 ? ' ' + ones[paise % 10] : '')) + ' Paise';
  }
  return result + ' Rupees Only';
}

// ── Component ──

export default function PayslipPage({ employeeId, employeeName }: PayslipPageProps) {
  const [periods, setPeriods] = useState<PayslipPeriod[]>([]);
  const [selectedPeriod, setSelectedPeriod] = useState<string>('');
  const [loadingPeriods, setLoadingPeriods] = useState(true);
  const [loadingData, setLoadingData] = useState(false);
  const [downloading, setDownloading] = useState(false);
  const [payslipData, setPayslipData] = useState<PayslipData | null>(null);
  const [dataError, setDataError] = useState<string | null>(null);

  // Fetch available periods
  const loadPeriods = useCallback(async () => {
    setLoadingPeriods(true);
    try {
      const res = await fetchPayslipPeriods(employeeId);
      if (res.error) {
        toast.error(res.error);
        return;
      }
      const data = res.data as PayslipPeriod[] | null;
      if (data && Array.isArray(data) && data.length > 0) {
        setPeriods(data);
        setSelectedPeriod(String(data[0].id));
      } else {
        setPeriods([]);
      }
    } catch {
      toast.error('Failed to load payslip periods');
    } finally {
      setLoadingPeriods(false);
    }
  }, [employeeId]);

  useEffect(() => {
    loadPeriods();
  }, [loadPeriods]);

  // When period changes, fetch payslip data
  useEffect(() => {
    if (!selectedPeriod) {
      setPayslipData(null);
      return;
    }
    const period = periods.find((p) => String(p.id) === selectedPeriod);
    if (!period) return;

    let cancelled = false;
    setLoadingData(true);
    setDataError(null);

    fetchPayslipData(employeeId, period.month, period.year)
      .then((res) => {
        if (cancelled) return;
        if (res.error) {
          setDataError(res.error);
          setPayslipData(null);
        } else {
          const data = res.data as PayslipData | null;
          if (data) {
            setPayslipData(data);
            setDataError(null);
          } else {
            setDataError('No payslip data found for this period');
            setPayslipData(null);
          }
        }
      })
      .catch(() => {
        if (!cancelled) {
          setDataError('Failed to load payslip data');
          setPayslipData(null);
        }
      })
      .finally(() => {
        if (!cancelled) setLoadingData(false);
      });

    return () => { cancelled = true; };
  }, [selectedPeriod, employeeId, periods]);

  // Download PDF (opens print window)
  const handleDownload = async () => {
    if (!payslipData) return;

    setDownloading(true);
    try {
      await generatePayslipPDF(payslipData);
      // Print window opened — user handles save from browser dialog
      toast.success('Print dialog opened! Use "Save as PDF" to download.');
    } catch (err) {
      console.error('PDF generation failed:', err);
      const msg = err instanceof Error ? err.message : 'Failed to open payslip for printing.';
      toast.error(msg);
    } finally {
      setDownloading(false);
    }
  };

  return (
    <div className="space-y-4 pb-6">
      <PageHeader
        title="Payslip"
        subtitle="View & download your monthly payslips"
      />

      {/* Period Selector */}
      <Card className="border-slate-200">
        <CardContent className="p-4 space-y-3">
          <div className="flex items-center gap-2">
            <div className="flex items-center justify-center w-8 h-8 rounded-lg bg-blue-50">
              <FileText className="w-4 h-4 text-blue-600" />
            </div>
            <div>
              <p className="text-sm font-semibold text-gray-900">Select Period</p>
              <p className="text-xs text-gray-500">Choose a month to view your payslip</p>
            </div>
          </div>

          {loadingPeriods ? (
            <Skeleton className="h-10 w-full" />
          ) : periods.length === 0 ? (
            <div className="flex flex-col items-center gap-2 py-8 text-center">
              <FileText className="w-10 h-10 text-gray-300" />
              <p className="text-sm text-muted-foreground">No payslips available yet</p>
              <p className="text-xs text-muted-foreground/70">Payslips will appear here once they are generated</p>
            </div>
          ) : (
            <Select value={selectedPeriod} onValueChange={setSelectedPeriod}>
              <SelectTrigger className="w-full">
                <SelectValue placeholder="Select a period" />
              </SelectTrigger>
              <SelectContent>
                {periods.map((p) => (
                  <SelectItem key={p.id} value={String(p.id)}>
                    {p.period_name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        </CardContent>
      </Card>

      {/* Payslip HTML Preview */}
      {selectedPeriod && loadingData && (
        <Card className="border-slate-200">
          <CardContent className="p-6 space-y-4">
            <Skeleton className="h-6 w-48" />
            <Skeleton className="h-20 w-full" />
            <div className="grid grid-cols-2 gap-3">
              <Skeleton className="h-12" />
              <Skeleton className="h-12" />
              <Skeleton className="h-12" />
              <Skeleton className="h-12" />
            </div>
            <Skeleton className="h-32 w-full" />
            <Skeleton className="h-16 w-full" />
          </CardContent>
        </Card>
      )}

      {dataError && !loadingData && (
        <Card className="border-red-100 bg-red-50/50">
          <CardContent className="p-6 text-center">
            <FileText className="w-10 h-10 text-red-300 mx-auto mb-2" />
            <p className="text-sm text-red-600 font-medium">{dataError}</p>
          </CardContent>
        </Card>
      )}

      {payslipData && !loadingData && (
        <PayslipHTMLPreview data={payslipData} />
      )}

      {/* Download Button */}
      {payslipData && !loadingData && (
        <Button
          onClick={handleDownload}
          disabled={downloading}
          className="w-full bg-blue-600 hover:bg-blue-700 text-white h-11 text-sm font-semibold"
        >
          {downloading ? (
            <Loader2 className="w-4 h-4 animate-spin mr-2" />
          ) : (
            <Download className="w-4 h-4 mr-2" />
          )}
          {downloading ? 'Generating PDF...' : 'Download PDF'}
        </Button>
      )}
    </div>
  );
}

// ══════════════════════════════════════════════════════════════
// PayslipHTMLPreview — Inline HTML payslip view
// ══════════════════════════════════════════════════════════════

function PayslipHTMLPreview({ data }: { data: PayslipData }) {
  const { period, employee: emp, payroll: pr } = data;

  // Earnings rows (show only if > 0)
  const earnings = [
    { label: 'Basic + DA', value: pr.basic_da },
    { label: 'HRA', value: pr.hra },
    { label: 'Washing Allowance', value: pr.washing_allowance },
    { label: 'Leave Encashment', value: pr.leave_encashment },
    { label: 'Bonus Encashment', value: pr.bonus_encashment },
    { label: `Overtime (${pr.overtime_hours || 0} hrs)`, value: pr.overtime_amount },
    { label: 'Extra Days Payment', value: pr.extra_days_amount },
  ].filter((r) => (r.value || 0) > 0);

  // Deductions rows (show only if > 0)
  const deductions = [
    { label: 'Provident Fund', value: pr.pf_employee },
    { label: 'ESI Contribution', value: pr.esi_employee },
    { label: 'Professional Tax', value: pr.professional_tax },
    { label: 'Labour Welfare Fund', value: pr.lwf_employee },
    { label: 'Salary Advance', value: pr.salary_advance },
    { label: 'Office Deduction', value: pr.office_deduction },
    { label: 'Trust Deduction', value: pr.trust_deduction },
    ...(pr.extra_deductions || []).map(d => ({ label: d.label, value: d.value })),
  ].filter((r) => (r.value || 0) > 0);

  const maxRows = Math.max(earnings.length, deductions.length);

  return (
    <div className="border-2 border-gray-700 rounded-lg overflow-hidden bg-white shadow-sm">
      {/* ── HEADER (Blue Bar) ── */}
      <div className="bg-[#2563EB] px-4 py-3 flex items-start justify-between">
        <div className="flex-1">
          <p className="text-sm font-bold text-white tracking-wide">RCS TRUE FACILITIES PVT LTD</p>
          <p className="text-[10px] text-white/85 mt-0.5">110, Someswar Square, Vesu, Surat - 395007, Gujarat</p>
          <p className="text-[9px] text-white/70 mt-0.5">GST: 24AAICR1390M1Z3 | PAN: AAICR1390M</p>
        </div>
        <div className="text-right ml-3">
          <p className="text-[9px] text-white/70 font-normal">PAYSLIP FOR</p>
          <p className="text-sm font-bold text-white mt-0.5">{period.period_name}</p>
        </div>
      </div>

      {/* ── Employee Name Banner ── */}
      <div className="bg-[#F3F4F6] px-4 py-2.5 flex items-center justify-between border-b border-gray-200">
        <p className="text-sm font-bold text-gray-900 tracking-wide uppercase">{(emp.full_name || 'N/A')}</p>
        {emp.mobile_number && (
          <p className="text-[11px] text-gray-500">{emp.mobile_number}</p>
        )}
      </div>

      {/* ── Employee Info Grid (4 cols) ── */}
      <div className="px-4 py-3 bg-white border-b border-gray-200">
        <div className="grid grid-cols-4 gap-x-2">
          <InfoCell label="Employee Code" value={emp.employee_code} />
          <InfoCell label="Department" value={emp.department} />
          <InfoCell label="Designation" value={emp.designation} />
          <InfoCell label="Client / Unit" value={[emp.client_name, emp.unit_name].filter(Boolean).join(' / ')} />
        </div>
        <div className="grid grid-cols-4 gap-x-2 mt-2 pt-2 border-t border-gray-100">
          <InfoCell label="Paid Days" value={`${pr.paid_days || 0} / ${pr.total_days || 0}`} />
          <InfoCell label="UAN Number" value={emp.uan_number} />
          <InfoCell label="ESIC Number" value={emp.esic_number} />
          <InfoCell label="Date of Joining" value={formatDateDDMMY(emp.date_of_joining)} />
        </div>
      </div>

      {/* ── Earnings & Deductions (Side by Side) ── */}
      <div className="flex border-b border-gray-200">
        {/* Earnings */}
        <div className="flex-1 border-r border-gray-200">
          <div className="px-3 py-2 bg-white border-b-2 border-gray-200">
            <p className="text-[11px] font-bold text-gray-700 uppercase tracking-wider">Earnings</p>
          </div>
          <div className="min-h-[80px]">
            {earnings.map((row, i) => (
              <div
                key={i}
                className={`flex justify-between px-3 py-2 text-xs border-b border-gray-100 ${
                  i % 2 === 0 ? 'bg-white' : 'bg-[#F9FAFB]'
                }`}
              >
                <span className="text-gray-600">{row.label}</span>
                <span className="font-medium text-gray-800">{formatINR(row.value || 0)}</span>
              </div>
            ))}
          </div>
          <div className="flex justify-between px-3 py-2.5 bg-[#F9FAFB] border-t-2 border-gray-200 text-xs font-bold">
            <span className="text-gray-800">Gross Earnings</span>
            <span className="text-gray-900">{formatINR(pr.gross_earnings || 0)}</span>
          </div>
        </div>

        {/* Deductions */}
        <div className="flex-1">
          <div className="px-3 py-2 bg-white border-b-2 border-gray-200">
            <p className="text-[11px] font-bold text-gray-700 uppercase tracking-wider">Deductions</p>
          </div>
          <div className="min-h-[80px]">
            {deductions.length === 0 && (
              <div className="flex items-center justify-center h-[80px]">
                <p className="text-xs text-gray-400">No deductions</p>
              </div>
            )}
            {deductions.map((row, i) => (
              <div
                key={i}
                className={`flex justify-between px-3 py-2 text-xs border-b border-gray-100 ${
                  i % 2 === 0 ? 'bg-white' : 'bg-[#F9FAFB]'
                }`}
              >
                <span className="text-gray-600">{row.label}</span>
                <span className="font-medium text-gray-800">{formatINR(row.value || 0)}</span>
              </div>
            ))}
          </div>
          <div className="flex justify-between px-3 py-2.5 bg-[#F9FAFB] border-t-2 border-gray-200 text-xs font-bold">
            <span className="text-gray-800">Total Deductions</span>
            <span className="text-gray-900">{formatINR(pr.total_deductions || 0)}</span>
          </div>
        </div>
      </div>

      {/* ── Net Pay Section (Light Blue) ── */}
      <div className="bg-[#EFF6FF] px-4 py-3 flex items-center justify-between">
        <div>
          <p className="text-[10px] text-gray-500 uppercase tracking-wider font-medium">Net Pay</p>
          <p className="text-2xl font-bold text-emerald-500 mt-0.5">{formatINR(pr.net_pay || 0)}</p>
        </div>
        <div className="text-right max-w-[55%]">
          <p className="text-[10px] text-gray-400 uppercase tracking-wider font-medium">Amount in Words</p>
          <p className="text-[11px] text-gray-500 italic mt-0.5 leading-relaxed">
            {numberToIndianWords(pr.net_pay || 0)}
          </p>
        </div>
      </div>

      {/* ── Bank Details ── */}
      <div className="px-4 py-3 bg-white border-b border-gray-200 flex items-center justify-between">
        <div>
          <div className="flex text-xs">
            <span className="text-gray-500 mr-1">Bank:</span>
            <span className="text-gray-700 font-medium mr-4">{emp.bank_name || 'N/A'}</span>
          </div>
          <div className="flex text-xs mt-0.5">
            <span className="text-gray-500 mr-1">A/C:</span>
            <span className="text-gray-700 font-medium mr-4">{emp.account_number || 'N/A'}</span>
          </div>
          <div className="flex text-xs mt-0.5">
            <span className="text-gray-500 mr-1">IFSC:</span>
            <span className="text-gray-700 font-medium">{emp.ifsc_code || 'N/A'}</span>
          </div>
        </div>
        <div className="text-center">
          <div className="w-12 h-12 bg-gray-100 rounded border border-gray-200 flex items-center justify-center">
            <span className="text-[8px] text-gray-400">QR</span>
          </div>
          <p className="text-[8px] text-gray-400 mt-0.5">Scan to verify</p>
        </div>
      </div>

      {/* ── Footer ── */}
      <div className="px-4 py-2.5 text-center">
        <p className="text-[10px] text-gray-400 italic">
          This is a computer generated payslip. Stamp / Sign not required.
        </p>
      </div>
    </div>
  );
}

// ── Info Cell Component ──

function InfoCell({ label, value }: { label: string; value?: string | number | null }) {
  return (
    <div>
      <p className="text-[9px] text-gray-400 uppercase tracking-wider font-medium">{label}</p>
      <p className="text-[12px] text-gray-700 font-semibold mt-0.5 truncate">{value || 'N/A'}</p>
    </div>
  );
}
