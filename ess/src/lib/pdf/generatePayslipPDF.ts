import QRCode from 'qrcode';

// ══════════════════════════════════════════════════════════════
// Payslip Print Generator — A4 Portrait, HRMS Pro style
// Uses browser native print (window.print) for clean text-based PDF
// No canvas, no html2canvas, no jsPDF — lightweight & reliable
// ══════════════════════════════════════════════════════════════

// ---------- Types ----------

interface PayslipPeriod {
  id: number;
  period_name: string;
  month: number;
  year: number;
  start_date: string;
  end_date: string;
}

interface PayslipEmployee {
  id: number;
  employee_code: string;
  full_name: string;
  mobile_number: string;
  gender: string;
  designation: string;
  department: string;
  date_of_joining: string;
  uan_number: string;
  esic_number: string;
  bank_name: string;
  account_number: string;
  ifsc_code: string;
  client_name: string;
  unit_name: string;
}

interface PayslipPayroll {
  total_days: number;
  paid_days: number;
  unpaid_days: number;
  overtime_hours: number;
  basic_da: number;
  hra: number;
  washing_allowance: number;
  leave_encashment: number;
  bonus_encashment: number;
  overtime_amount: number;
  extra_days_amount: number;
  gross_earnings: number;
  pf_employee: number;
  esi_employee: number;
  professional_tax: number;
  lwf_employee: number;
  salary_advance: number;
  office_deduction: number;
  trust_deduction: number;
  total_deductions: number;
  net_pay: number;
  extra_deductions?: { key: string; label: string; value: number }[];
  gross_salary: number;
  ctc: number;
  payment_mode: string;
  payment_status: string;
  status: string;
}

export interface PayslipData {
  period: PayslipPeriod;
  employee: PayslipEmployee;
  payroll: PayslipPayroll;
}

// ---------- Helpers ----------

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

// ---------- Earnings/Deductions Rows ----------

function buildEarningsRows(pr: PayslipPayroll): { label: string; value: number; show: boolean }[] {
  return [
    { label: 'Basic + DA', value: pr.basic_da || 0, show: true },
    { label: 'HRA', value: pr.hra || 0, show: true },
    { label: 'Washing Allowance', value: pr.washing_allowance || 0, show: (pr.washing_allowance || 0) > 0 },
    { label: 'Leave Encashment', value: pr.leave_encashment || 0, show: (pr.leave_encashment || 0) > 0 },
    { label: 'Bonus Encashment', value: pr.bonus_encashment || 0, show: (pr.bonus_encashment || 0) > 0 },
    { label: `Overtime (${pr.overtime_hours || 0} hrs)`, value: pr.overtime_amount || 0, show: (pr.overtime_amount || 0) > 0 },
    { label: 'Extra Days Payment', value: pr.extra_days_amount || 0, show: (pr.extra_days_amount || 0) > 0 },
  ].filter(r => r.show);
}

function buildDeductionsRows(pr: PayslipPayroll): { label: string; value: number; show: boolean }[] {
  return [
    { label: 'Provident Fund', value: pr.pf_employee || 0, show: (pr.pf_employee || 0) > 0 },
    { label: 'ESI Contribution', value: pr.esi_employee || 0, show: (pr.esi_employee || 0) > 0 },
    { label: 'Professional Tax', value: pr.professional_tax || 0, show: (pr.professional_tax || 0) > 0 },
    { label: 'Labour Welfare Fund', value: pr.lwf_employee || 0, show: (pr.lwf_employee || 0) > 0 },
    { label: 'Salary Advance', value: pr.salary_advance || 0, show: (pr.salary_advance || 0) > 0 },
    { label: 'Office Deduction', value: pr.office_deduction || 0, show: (pr.office_deduction || 0) > 0 },
    { label: 'Trust Deduction', value: pr.trust_deduction || 0, show: (pr.trust_deduction || 0) > 0 },
    ...(pr.extra_deductions || []).map(d => ({ label: d.label, value: d.value, show: true })),
  ].filter(r => r.show);
}

// ---------- Build Full HTML Document ----------

function buildPayslipDocument(data: PayslipData, qrDataURL: string): string {
  const { period, employee: emp, payroll: pr } = data;
  const earnings = buildEarningsRows(pr);
  const deductions = buildDeductionsRows(pr);
  const maxRows = Math.max(earnings.length, deductions.length);

  const empName = (emp.full_name || 'N/A').toUpperCase();
  const empCode = String(emp.employee_code ?? 'N/A');
  const clientUnit = [emp.client_name, emp.unit_name].filter(Boolean).join(' / ') || 'N/A';

  // Build table rows
  let earningRowsHTML = '';
  let deductionRowsHTML = '';

  for (let i = 0; i < maxRows; i++) {
    const isEven = i % 2 === 0;
    const rowClass = isEven ? 'row-even' : 'row-odd';

    if (i < earnings.length) {
      earningRowsHTML += `
        <tr class="${rowClass}">
          <td>${earnings[i].label}</td>
          <td class="text-right">${formatINR(earnings[i].value)}</td>
        </tr>`;
    } else {
      earningRowsHTML += `<tr class="${rowClass}"><td>&nbsp;</td><td>&nbsp;</td></tr>`;
    }

    if (i < deductions.length) {
      deductionRowsHTML += `
        <tr class="${rowClass}">
          <td>${deductions[i].label}</td>
          <td class="text-right">${formatINR(deductions[i].value)}</td>
        </tr>`;
    } else {
      deductionRowsHTML += `<tr class="${rowClass}"><td>&nbsp;</td><td>&nbsp;</td></tr>`;
    }
  }

  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payslip - ${empName} - ${period.period_name}</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
      color: #374151;
      background: #fff;
      -webkit-print-color-adjust: exact !important;
      print-color-adjust: exact !important;
    }

    .payslip {
      width: 210mm;
      max-width: 100%;
      margin: 0 auto;
      border: 1.5px solid #374151;
      background: #fff;
    }

    /* Header */
    .header {
      background: #2563EB;
      padding: 14px 18px;
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
    }
    .header .company-name {
      font-size: 17px;
      font-weight: 700;
      color: #fff;
      letter-spacing: 0.5px;
    }
    .header .company-addr {
      font-size: 10.5px;
      color: rgba(255,255,255,0.85);
      margin-top: 2px;
    }
    .header .company-tax {
      font-size: 9.5px;
      color: rgba(255,255,255,0.7);
      margin-top: 1px;
    }
    .header .period-label {
      font-size: 9px;
      color: rgba(255,255,255,0.7);
      text-align: right;
    }
    .header .period-value {
      font-size: 14px;
      color: #fff;
      font-weight: 700;
      text-align: right;
      margin-top: 1px;
    }

    /* Employee Banner */
    .emp-banner {
      background: #F3F4F6;
      padding: 10px 18px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-bottom: 1px solid #E5E7EB;
    }
    .emp-banner .emp-name {
      font-size: 14px;
      font-weight: 700;
      color: #1F2937;
      letter-spacing: 0.5px;
    }
    .emp-banner .emp-mobile {
      font-size: 10.5px;
      color: #6B7280;
    }

    /* Info Grid */
    .info-grid {
      padding: 12px 18px;
      background: #fff;
      border-bottom: 1px solid #E5E7EB;
    }
    .info-grid table {
      width: 100%;
      border-collapse: collapse;
    }
    .info-grid td {
      width: 25%;
      padding: 3px 6px;
      vertical-align: top;
    }
    .info-grid .info-label {
      font-size: 8.5px;
      color: #9CA3AF;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 0.3px;
    }
    .info-grid .info-value {
      font-size: 11px;
      color: #374151;
      font-weight: 600;
      margin-top: 1px;
    }
    .info-grid .row2 td {
      border-top: 1px solid #F3F4F6;
      padding-top: 4px;
    }

    /* Earnings & Deductions */
    .ed-section {
      display: flex;
      border-bottom: 1px solid #E5E7EB;
    }
    .ed-panel {
      flex: 1;
    }
    .ed-panel:first-child {
      border-right: 1px solid #E5E7EB;
    }
    .ed-panel-header {
      background: #fff;
      padding: 7px 10px;
      border-bottom: 2px solid #E5E7EB;
    }
    .ed-panel-header h3 {
      font-size: 10.5px;
      font-weight: 700;
      color: #374151;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .ed-panel table {
      width: 100%;
      border-collapse: collapse;
    }
    .ed-panel col.col-label { width: 65%; }
    .ed-panel col.col-value { width: 35%; }
    .ed-panel td {
      padding: 7px 10px;
      font-size: 11px;
      color: #374151;
      border-bottom: 1px solid #E5E7EB;
    }
    .ed-panel .text-right { text-align: right; font-weight: 500; }
    .row-even { background: #FFFFFF; }
    .row-odd  { background: #F9FAFB; }
    .ed-total {
      background: #F9FAFB !important;
    }
    .ed-total td {
      font-weight: 700 !important;
      border-top: 2px solid #E5E7EB !important;
    }

    /* Net Pay */
    .net-section {
      background: #EFF6FF;
      padding: 12px 18px;
    }
    .net-section .net-inner {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .net-section .net-label {
      font-size: 9.5px;
      color: #6B7280;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 0.3px;
    }
    .net-section .net-value {
      font-size: 24px;
      font-weight: 700;
      color: #10B981;
      margin-top: 1px;
    }
    .net-section .words-label {
      font-size: 9.5px;
      color: #9CA3AF;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 0.3px;
      text-align: right;
    }
    .net-section .words-value {
      font-size: 10.5px;
      color: #6B7280;
      font-style: italic;
      margin-top: 1px;
      line-height: 1.35;
      text-align: right;
    }

    /* Bank Details */
    .bank-section {
      padding: 12px 18px;
      background: #fff;
      border-bottom: 1px solid #E5E7EB;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .bank-table td {
      font-size: 10.5px;
      padding: 2px 0;
    }
    .bank-table .bl { color: #6B7280; }
    .bank-table .bv { color: #374151; font-weight: 500; padding-left: 6px; }
    .qr-section {
      text-align: center;
    }
    .qr-section img {
      width: 64px;
      height: 64px;
      border: 1px solid #E5E7EB;
      border-radius: 4px;
    }
    .qr-section .qr-label {
      font-size: 7.5px;
      color: #9CA3AF;
      margin-top: 2px;
    }

    /* Footer */
    .payslip-footer {
      padding: 8px 18px;
      text-align: center;
    }
    .payslip-footer p {
      font-size: 9.5px;
      color: #9CA3AF;
      font-style: italic;
    }

    /* ── Print-specific styles ── */
    @media print {
      @page {
        size: A4 portrait;
        margin: 8mm;
      }
      body {
        background: #fff !important;
      }
      .payslip {
        width: 100% !important;
        border: 1.5px solid #374151 !important;
        page-break-inside: avoid;
      }
      /* No button in print */
      .no-print { display: none !important; }
    }

    /* Screen-only button */
    .print-btn-wrap {
      padding: 12px 18px;
      text-align: center;
      background: #fff;
    }
    @media print {
      .print-btn-wrap { display: none !important; }
    }
    .print-btn {
      background: #2563EB;
      color: #fff;
      border: none;
      padding: 10px 32px;
      font-size: 14px;
      font-weight: 600;
      border-radius: 8px;
      cursor: pointer;
      margin: 4px auto;
      display: inline-block;
    }
    .print-btn:hover { background: #1D4ED8; }
    .print-btn:active { background: #1E40AF; }
    .hint-text {
      margin-top: 8px;
      font-size: 11px;
      color: #9CA3AF;
    }
  </style>
</head>
<body>

  <div class="payslip">

    <!-- HEADER -->
    <div class="header">
      <div>
        <div class="company-name">RCS TRUE FACILITIES PVT LTD</div>
        <div class="company-addr">110, Someswar Square, Vesu, Surat - 395007, Gujarat</div>
        <div class="company-tax">GST: 24AAICR1390M1Z3 | PAN: AAICR1390M</div>
      </div>
      <div>
        <div class="period-label">PAYSLIP FOR</div>
        <div class="period-value">${period.period_name}</div>
      </div>
    </div>

    <!-- EMPLOYEE NAME BANNER -->
    <div class="emp-banner">
      <div class="emp-name">${empName}</div>
      ${emp.mobile_number ? `<div class="emp-mobile">${emp.mobile_number}</div>` : ''}
    </div>

    <!-- EMPLOYEE INFO GRID -->
    <div class="info-grid">
      <table>
        <tr>
          <td>
            <div class="info-label">Employee Code</div>
            <div class="info-value">${empCode}</div>
          </td>
          <td>
            <div class="info-label">Department</div>
            <div class="info-value">${emp.department || 'N/A'}</div>
          </td>
          <td>
            <div class="info-label">Designation</div>
            <div class="info-value">${emp.designation || 'N/A'}</div>
          </td>
          <td>
            <div class="info-label">Client / Unit</div>
            <div class="info-value">${clientUnit}</div>
          </td>
        </tr>
        <tr class="row2">
          <td>
            <div class="info-label">Paid Days</div>
            <div class="info-value">${pr.paid_days || 0} / ${pr.total_days || 0}</div>
          </td>
          <td>
            <div class="info-label">UAN Number</div>
            <div class="info-value">${emp.uan_number || 'N/A'}</div>
          </td>
          <td>
            <div class="info-label">ESIC Number</div>
            <div class="info-value">${emp.esic_number || 'N/A'}</div>
          </td>
          <td>
            <div class="info-label">Date of Joining</div>
            <div class="info-value">${formatDateDDMMY(emp.date_of_joining)}</div>
          </td>
        </tr>
      </table>
    </div>

    <!-- EARNINGS & DEDUCTIONS -->
    <div class="ed-section">
      <div class="ed-panel">
        <div class="ed-panel-header"><h3>Earnings</h3></div>
        <table>
          <colgroup>
            <col class="col-label">
            <col class="col-value">
          </colgroup>
          ${earningRowsHTML}
          <tr class="ed-total">
            <td>Gross Earnings</td>
            <td class="text-right">${formatINR(pr.gross_earnings || 0)}</td>
          </tr>
        </table>
      </div>
      <div class="ed-panel">
        <div class="ed-panel-header"><h3>Deductions</h3></div>
        <table>
          <colgroup>
            <col class="col-label">
            <col class="col-value">
          </colgroup>
          ${deductionRowsHTML}
          <tr class="ed-total">
            <td>Total Deductions</td>
            <td class="text-right">${formatINR(pr.total_deductions || 0)}</td>
          </tr>
        </table>
      </div>
    </div>

    <!-- NET PAY -->
    <div class="net-section">
      <div class="net-inner">
        <div>
          <div class="net-label">Net Pay</div>
          <div class="net-value">${formatINR(pr.net_pay || 0)}</div>
        </div>
        <div style="max-width:55%;">
          <div class="words-label">Amount in Words</div>
          <div class="words-value">${numberToIndianWords(pr.net_pay || 0)}</div>
        </div>
      </div>
    </div>

    <!-- BANK DETAILS -->
    <div class="bank-section">
      <div>
        <table class="bank-table">
          <tr>
            <td class="bl">Bank:</td>
            <td class="bv">${emp.bank_name || 'N/A'}</td>
          </tr>
          <tr>
            <td class="bl">A/C:</td>
            <td class="bv">${emp.account_number || 'N/A'}</td>
          </tr>
          <tr>
            <td class="bl">IFSC:</td>
            <td class="bv">${emp.ifsc_code || 'N/A'}</td>
          </tr>
        </table>
      </div>
      <div class="qr-section">
        ${qrDataURL ? `<img src="${qrDataURL}" alt="QR Code" /><div class="qr-label">Scan to verify</div>` : ''}
      </div>
    </div>

    <!-- FOOTER -->
    <div class="payslip-footer">
      <p>This is a computer generated payslip. Stamp / Sign not required.</p>
    </div>

    <!-- PRINT BUTTON (screen only) -->
    <div class="print-btn-wrap">
      <button class="print-btn" onclick="window.print()">Download / Print PDF</button>
      <p class="hint-text">Use browser's "Save as PDF" option to download</p>
    </div>

  </div>

</body>
</html>`;
}

// ---------- Main Export: Open Print Window ----------

export async function generatePayslipPDF(data: PayslipData): Promise<void> {
  // ── Step 1: Generate QR code as base64 data URL ──
  let qrDataURL = '';
  try {
    const qrPayload = JSON.stringify({
      company: 'RCS TRUE FACILITIES PVT LTD',
      emp: String(data.employee.employee_code ?? ''),
      period: data.period.period_name,
      net: data.payroll.net_pay,
    });
    qrDataURL = await QRCode.toDataURL(qrPayload, { width: 160, margin: 1 });
  } catch {
    // QR generation failed — continue without QR
  }

  // ── Step 2: Build the full HTML document ──
  const htmlDoc = buildPayslipDocument(data, qrDataURL);

  // ── Step 3: Open a new window and write the HTML ──
  const printWindow = window.open('', '_blank', 'width=800,height=1000');
  if (!printWindow) {
    throw new Error('Popup blocked. Please allow popups for this site to print payslips.');
  }

  printWindow.document.open();
  printWindow.document.write(htmlDoc);
  printWindow.document.close();

  // ── Step 4: Wait for content (especially QR image) to load, then trigger print ──
  printWindow.onload = () => {
    setTimeout(() => {
      printWindow.print();
    }, 400);
  };
}
