import QRCode from 'qrcode';
import type { CertificateData } from '@/lib/ess-api';

// ══════════════════════════════════════════════════════════════
// Certificate PDF Generator — A4 Portrait, browser print
// Follows same pattern as generatePayslipPDF.ts
// ══════════════════════════════════════════════════════════════

const LOGO_URL = 'https://join.rcsfacility.com/assets/images/logo.png';
const VERIFY_BASE = 'https://join.rcsfacility.com/#verify?cert=';

function fmtINR(n: number): string {
  return '₹' + Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDateDMY(dateStr: string): string {
  if (!dateStr) return 'N/A';
  const d = new Date(dateStr + 'T00:00:00');
  return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
}

function getPronoun(gender: string): { he: string; He: string; his: string; His: string } {
  if (gender?.toLowerCase() === 'female') {
    return { he: 'she', He: 'She', his: 'her', His: 'Her' };
  }
  return { he: 'he', He: 'He', his: 'his', His: 'His' };
}

// ── Shared letterhead HTML ────────────────────────────────────

function letterhead(c: CertificateData['company']): string {
  const gstLine = c.gst ? `GST: ${c.gst} | PAN: ${c.pan}` : '';
  const contactLine = [c.email, c.phone].filter(Boolean).join(' | ');
  return `
    <div style="text-align:center; border-bottom:3px double #000; padding-bottom:18px; margin-bottom:12px;">
      <img src="${LOGO_URL}" alt="Logo" style="height:60px; margin-bottom:6px;" onerror="this.style.display='none'">
      <h1 style="margin:0; font-size:22px; font-weight:700; letter-spacing:0.5px;">${c.name}</h1>
      <p style="margin:2px 0 0; font-size:11px; color:#444;">${c.address}, ${c.city} - ${c.pincode}, ${c.state}</p>
      ${gstLine ? `<p style="margin:2px 0 0; font-size:10px; color:#666;">${gstLine}</p>` : ''}
      ${contactLine ? `<p style="margin:2px 0 0; font-size:10px; color:#666;">${contactLine}</p>` : ''}
    </div>`;
}

function refAndDate(certNumber: string, date: string): string {
  return `
    <div style="display:flex; justify-content:space-between; margin-bottom:12px; font-size:13px;">
      <div><strong>Ref No:</strong> ${certNumber}</div>
      <div><strong>Date:</strong> ${date}</div>
    </div>`;
}

function signatureBlock(companyName: string): string {
  return `
    <div style="margin-top:50px; text-align:right; padding-right:20px;">
      <p style="margin:0;"><strong>For ${companyName}</strong></p>
      <br><br>
      <p style="margin:0; border-top:1px solid #000; display:inline-block; padding-top:2px; min-width:200px; text-align:center;">
        <strong>Authorized Signatory</strong>
      </p>
    </div>`;
}

function qrSection(verifyUrl: string, certNumber: string): string {
  return `
    <div style="margin-top:30px; border-top:1px solid #ddd; padding-top:12px; display:flex; justify-content:space-between; align-items:flex-end; font-size:9px; color:#888;">
      <div>
        <strong style="color:#555;">Certificate No:</strong> ${certNumber}<br>
        <span>This document is computer-generated and does not require a physical signature.</span>
      </div>
      <div style="text-align:center;">
        <img src="{{QR_CODE}}" alt="Verify" style="width:72px; height:72px;" />
        <p style="margin:2px 0 0;">Scan QR to verify online</p>
      </div>
    </div>`;
}

// ── Appointment Letter HTML ───────────────────────────────────

function buildAppointmentHTML(d: CertificateData, qrDataUrl: string): string {
  const e = d.employee, c = d.company;
  const firstName = (e.full_name || '').split(' ')[0] || 'Employee';
  const doj = formatDateDMY(e.date_of_joining);
  const basicDa = d.salary?.basic_da ?? 0;
  const hra = d.salary?.hra ?? 0;
  const gross = d.salary?.gross_salary ?? 0;

  return `<!DOCTYPE html><html><head><meta charset="UTF-8">
    <title>${e.full_name} - Appointment Letter</title>
    <style>
      @page { size:A4 portrait; margin:15mm; }
      body { font-family:'Times New Roman',serif; font-size:13px; line-height:1.7; color:#222; }
      table { border-collapse:collapse; width:55%; }
      td,th { border:1px solid #999; padding:4px 10px; }
      .text-end { text-align:right; }
      @media print { body { margin:0; } }
    </style>
  </head><body>
    ${letterhead(c)}
    ${refAndDate(d.certificate_number, d.date_of_issue)}

    <p style="margin:0 0 4px;"><strong>To,</strong></p>
    <p style="margin:0;">${e.full_name}</p>
    ${e.address ? `<p style="margin:0;">${e.address}</p>` : ''}
    <p style="margin:0;">${[e.district, e.state, e.pin_code ? ` - ${e.pin_code}` : ''].filter(Boolean).join(', ')}</p>

    <p style="margin:14px 0 4px;"><strong>Subject: Letter of Appointment</strong></p>
    <p style="margin:4px 0 10px;">Dear ${firstName},</p>

    <p style="margin:0 0 8px;">With reference to your application and subsequent interview, we are pleased to inform you that you have been selected for the post of <strong>${e.designation || 'Worker'}</strong>${e.department ? ` in the <strong>${e.department}</strong> department` : ''} in our organization. You are hereby appointed on the following terms and conditions:</p>

    <p style="margin:10px 0 2px;"><strong>1. DATE OF JOINING:</strong></p>
    <p style="margin:0 0 8px;">You will join your duties on <strong>${doj}</strong>.</p>

    <p style="margin:10px 0 2px;"><strong>2. PROBATION PERIOD:</strong></p>
    <p style="margin:0 0 8px;">You will be on probation for a period of <strong>${e.probation_period || 3} months</strong> from the date of joining. Your confirmation will be subject to satisfactory performance during the probation period.</p>

    <p style="margin:10px 0 2px;"><strong>3. REMUNERATION:</strong></p>
    <p style="margin:0 0 6px;">Your monthly remuneration will be as follows:</p>
    <table style="margin-bottom:10px;">
      <tr><td>Basic + DA</td><td class="text-end">${fmtINR(basicDa)}</td></tr>
      <tr><td>HRA</td><td class="text-end">${fmtINR(hra)}</td></tr>
      <tr><td><strong>Gross Salary</strong></td><td class="text-end"><strong>${fmtINR(gross)}</strong></td></tr>
    </table>

    <p style="margin:10px 0 2px;"><strong>4. STATUTORY BENEFITS:</strong></p>
    <p style="margin:0 0 8px;">
      ${d.salary?.pf_applicable ? 'You will be covered under Employees\' Provident Fund and Misc. Provisions Act, 1952. ' : ''}
      ${d.salary?.esi_applicable ? 'You will be covered under Employees\' State Insurance Act, 1948. ' : ''}
      You will be entitled to other statutory benefits as per applicable laws.
    </p>

    <p style="margin:10px 0 2px;"><strong>5. WORKING HOURS:</strong></p>
    <p style="margin:0 0 8px;">Your working hours will be 8 hours per day with a weekly off. Overtime will be paid as per applicable laws.</p>

    <p style="margin:10px 0 2px;"><strong>6. LEAVE:</strong></p>
    <p style="margin:0 0 8px;">You will be entitled to leaves as per the company policy and applicable laws (Casual Leave, Sick Leave, Earned Leave, etc.).</p>

    <p style="margin:10px 0 2px;"><strong>7. TERMINATION:</strong></p>
    <p style="margin:0 0 8px;">Your services can be terminated by giving one month's notice or salary in lieu thereof from either side.</p>

    <p style="margin:10px 0 2px;"><strong>8. GENERAL:</strong></p>
    <p style="margin:0 0 10px;">You will be governed by the rules and regulations of the company and applicable laws of India.</p>

    <p style="margin:0 0 6px;">We welcome you to ${c.name} family and hope for a long and fruitful association.</p>
    <p style="margin:0;">Please sign and return the duplicate copy of this letter as a token of your acceptance of the above terms and conditions.</p>

    <p style="margin:10px 0 0;">Yours faithfully,</p>
    ${signatureBlock(c.name)}

    <br><br>
    <p style="margin:0;"><strong>I accept the above terms and conditions:</strong></p>
    <br><br>
    <p style="margin:0;">Signature: ________________________</p>
    <p style="margin:0;">Name: ${e.full_name}</p>
    <p style="margin:0;">Date: ________________________</p>

    ${qrSection(d.verify_url, d.certificate_number).replace('{{QR_CODE}}', qrDataUrl)}
  </body></html>`;
}

// ── Salary Certificate HTML ───────────────────────────────────

function buildSalaryHTML(d: CertificateData, qrDataUrl: string): string {
  const e = d.employee, c = d.company, s = d.salary, p = d.payroll;
  const doj = formatDateDMY(e.date_of_joining);
  const basicDa = s?.basic_da ?? 0;
  const hra = s?.hra ?? 0;
  const washing = s?.washing_allowance ?? 0;
  const gross = s?.gross_salary ?? p?.gross_earnings ?? 0;
  const pf = p?.pf_employee ?? 0;
  const esi = p?.esi_employee ?? 0;
  const pt = p?.professional_tax ?? 0;
  const totalDed = p?.total_deductions ?? 0;
  const netPay = p?.net_pay ?? 0;
  const ctc = p?.ctc ?? gross * 12;
  const ctcMonthly = p?.ctc ?? gross;

  return `<!DOCTYPE html><html><head><meta charset="UTF-8">
    <title>${e.full_name} - Salary Certificate</title>
    <style>
      @page { size:A4 portrait; margin:15mm; }
      body { font-family:'Times New Roman',serif; font-size:13px; line-height:1.7; color:#222; }
      table { border-collapse:collapse; width:100%; }
      td,th { border:1px solid #999; padding:5px 10px; }
      th { background:#f5f5f5; font-weight:600; }
      .text-end { text-align:right; }
      .label-cell { width:35%; font-weight:600; }
      @media print { body { margin:0; } }
    </style>
  </head><body>
    ${letterhead(c)}
    ${refAndDate(d.certificate_number, d.date_of_issue)}

    <h2 style="text-align:center; text-decoration:underline; margin:8px 0 16px; font-size:18px;">SALARY CERTIFICATE</h2>

    <p style="margin:0 0 10px;">To Whom It May Concern,</p>
    <p style="margin:0 0 12px;">This is to certify that <strong>${e.full_name}</strong>${e.father_name ? `, S/o <strong>${e.father_name}</strong>` : ''}, is employed with <strong>${c.name}</strong> as <strong>${e.designation || 'Worker'}</strong>.</p>

    <table style="margin-bottom:14px;">
      <tr>
        <td class="label-cell">Employee Code</td><td>${e.employee_code}</td>
        <td class="label-cell">Date of Joining</td><td>${doj}</td>
      </tr>
      <tr>
        <td class="label-cell">Employee Name</td><td>${e.full_name}</td>
        <td class="label-cell">Father Name</td><td>${e.father_name || '-'}</td>
      </tr>
      <tr>
        <td class="label-cell">Designation</td><td>${e.designation || '-'}</td>
        <td class="label-cell">Department</td><td>${e.department || '-'}</td>
      </tr>
      <tr>
        <td class="label-cell">UAN</td><td>${e.uan_number || 'N/A'}</td>
        <td class="label-cell">ESIC</td><td>${e.esic_number || 'N/A'}</td>
      </tr>
    </table>

    <p style="margin:0 0 6px; font-weight:600;">The salary components are as follows:</p>

    <table style="width:48%; display:inline-table; vertical-align:top; margin-bottom:12px;">
      <tr><th colspan="2" style="background:#e8f5e9;">A. Earnings (Per Month)</th></tr>
      <tr><td>Basic + DA</td><td class="text-end">${fmtINR(basicDa)}</td></tr>
      <tr><td>HRA</td><td class="text-end">${fmtINR(hra)}</td></tr>
      ${washing > 0 ? `<tr><td>Washing / Conveyance Allowance</td><td class="text-end">${fmtINR(washing)}</td></tr>` : ''}
      <tr style="background:#e8f5e9;"><td><strong>Gross Salary</strong></td><td class="text-end"><strong>${fmtINR(gross)}</strong></td></tr>
    </table>

    <table style="width:48%; display:inline-table; vertical-align:top; margin-left:4%; margin-bottom:12px;">
      <tr><th colspan="2" style="background:#ffebee;">B. Deductions (Per Month)</th></tr>
      <tr><td>Provident Fund (PF)</td><td class="text-end">${fmtINR(pf)}</td></tr>
      <tr><td>ESI (Employee Share)</td><td class="text-end">${fmtINR(esi)}</td></tr>
      <tr><td>Professional Tax</td><td class="text-end">${fmtINR(pt)}</td></tr>
      <tr style="background:#ffebee;"><td><strong>Total Deductions</strong></td><td class="text-end"><strong>${fmtINR(totalDed)}</strong></td></tr>
    </table>

    <table style="margin-bottom:12px;">
      <tr style="background:#e3f2fd;"><td style="width:50%; font-weight:600;">Net Salary (Take Home) Per Month</td><td class="text-end" style="font-size:16px; font-weight:700;">${fmtINR(netPay)}</td></tr>
      <tr><td style="font-weight:600;">CTC (Cost to Company) Per Annum</td><td class="text-end" style="font-weight:700;">${fmtINR(ctc)}</td></tr>
    </table>

    ${p ? `<p style="font-size:10px; color:#666; margin:0 0 10px;">(Above salary details are based on the payroll of ${p.month_name} ${p.year}. Paid Days: ${p.paid_days} / ${p.total_days})</p>` : ''}

    <p style="margin:10px 0 0;">This certificate is issued for the purpose as required.</p>

    <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-top:40px;">
      <div style="font-size:12px;"><strong>Date:</strong> ${d.date_of_issue}</div>
      <div style="text-align:right;">
        <p style="margin:0;"><strong>Authorized Signatory</strong></p>
        <p style="margin:2px 0 0; font-size:10px; color:#666;">Company Seal</p>
      </div>
    </div>

    ${qrSection(d.verify_url, d.certificate_number).replace('{{QR_CODE}}', qrDataUrl)}
  </body></html>`;
}

// ── Experience Certificate HTML ───────────────────────────────

function buildExperienceHTML(d: CertificateData, qrDataUrl: string): string {
  const e = d.employee, c = d.company;
  const doj = formatDateDMY(e.date_of_joining);
  const today = new Date().toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
  const p = getPronoun(e.gender);
  const gross = d.salary?.gross_salary ?? 0;

  return `<!DOCTYPE html><html><head><meta charset="UTF-8">
    <title>${e.full_name} - Experience Certificate</title>
    <style>
      @page { size:A4 portrait; margin:15mm; }
      body { font-family:'Times New Roman',serif; font-size:13px; line-height:1.8; color:#222; }
      @media print { body { margin:0; } }
    </style>
  </head><body>
    ${letterhead(c)}
    ${refAndDate(d.certificate_number, d.date_of_issue)}

    <h2 style="text-align:center; text-decoration:underline; margin:8px 0 16px; font-size:18px;">EXPERIENCE CERTIFICATE</h2>

    <p style="margin:0 0 12px;"><strong>To Whom It May Concern</strong></p>

    <p style="text-align:justify; margin:0 0 10px;">
      This is to certify that <strong>${e.full_name}</strong>${e.father_name ? `, S/o/D/o <strong>${e.father_name}</strong>` : ''},
      has been employed with <strong>${c.name}</strong>
      as <strong>${e.designation || 'Worker'}</strong>
      ${e.department ? `in the <strong>${e.department}</strong> department` : ''}
      from <strong>${doj}</strong> to <strong>${today}</strong> (Till Date).
    </p>

    <p style="text-align:justify; margin:0 0 10px;">
      During the tenure of <strong>${d.tenure}</strong>,
      ${p.he} has been sincere, hardworking, and diligent in ${p.his} work.
      ${p.He} has displayed excellent professional conduct and interpersonal skills.
    </p>

    ${gross > 0 ? `<p style="text-align:justify; margin:0 0 10px;">
      ${p.His} current gross salary is <strong>${fmtINR(gross)}</strong> per month.
    </p>` : ''}

    <p style="text-align:justify; margin:0 0 10px;">
      We wish ${p.him} all the best for ${p.his} future endeavors.
    </p>

    ${signatureBlock(c.name)}

    ${qrSection(d.verify_url, d.certificate_number).replace('{{QR_CODE}}', qrDataUrl)}
  </body></html>`;
}

// ── Public API ────────────────────────────────────────────────

export async function generateCertificatePDF(data: CertificateData): Promise<void> {
  // 1. Generate QR code
  const qrDataUrl = await QRCode.toDataURL(data.verify_url, { width: 160, margin: 1 });

  // 2. Build HTML based on type
  let htmlDoc: string;
  switch (data.certificate_type) {
    case 'salary':
      htmlDoc = buildSalaryHTML(data, qrDataUrl);
      break;
    case 'experience':
      htmlDoc = buildExperienceHTML(data, qrDataUrl);
      break;
    case 'appointment':
    default:
      htmlDoc = buildAppointmentHTML(data, qrDataUrl);
  }

  // 3. Open print window
  const printWindow = window.open('', '_blank', 'width=800,height=1100');
  if (!printWindow) {
    throw new Error('Pop-up blocked. Please allow pop-ups for this site.');
  }
  printWindow.document.write(htmlDoc);
  printWindow.document.close();

  // 4. Trigger print after content loads
  printWindow.onload = () => {
    setTimeout(() => {
      printWindow.print();
    }, 400);
  };
}

export function getCertificateFileName(data: CertificateData): string {
  const code = String(data.employee.employee_code || 'EMP').padStart(4, '0');
  const map: Record<string, string> = {
    appointment: 'Appointment_Letter',
    salary: 'Salary_Certificate',
    experience: 'Experience_Certificate',
  };
  return `${map[data.certificate_type] || 'Certificate'}_EMP${code}.pdf`;
}