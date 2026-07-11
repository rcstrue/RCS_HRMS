import QRCode from 'qrcode';
import type { CertificateData } from '@/lib/ess-api';

// ══════════════════════════════════════════════════════════════
// Certificate PDF Generator — A4 Portrait, browser print
// Uses full A4 letterhead background image
// ══════════════════════════════════════════════════════════════

const LETTERHEAD_URL = 'https://join.rcsfacility.com/letterhead-a4.jpg';
const VERIFY_BASE = 'https://join.rcsfacility.com/#verify?cert=';

// A4 dimensions in mm
const A4_W = 210;
const A4_H = 297;

// Letterhead safe zones (from VLM analysis of 2481x3508 @300dpi image)
// Header ends ~11mm from top, footer starts ~9mm from bottom
const HEADER_SAFE_MM = 45;  // content starts below letterhead header
const FOOTER_SAFE_MM = 14;  // content ends above this

function fmtINR(n: number): string {
  return '\u20B9' + Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDateDMY(dateStr: string): string {
  if (!dateStr) return 'N/A';
  const d = new Date(dateStr + 'T00:00:00');
  return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
}

function getPronoun(gender: string): { he: string; He: string; his: string; His: string; him: string } {
  if (gender?.toLowerCase() === 'female') {
    return { he: 'she', He: 'She', his: 'her', His: 'Her', him: 'her' };
  }
  return { he: 'he', He: 'He', his: 'his', His: 'His', him: 'him' };
}

// ── Shared page wrapper with letterhead background ──────────

function pageWrap(title: string, bodyContent: string): string {
  return `<!DOCTYPE html><html><head><meta charset="UTF-8">
  <title>${title}</title>
  <style>
    @page {
      size: ${A4_W}mm ${A4_H}mm;
      margin: 0;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
      width: ${A4_W}mm;
      height: ${A4_H}mm;
      overflow: hidden;
    }
    body {
      position: relative;
      font-family: 'Times New Roman', serif;
      color: #222;
      -webkit-print-color-adjust: exact !important;
      print-color-adjust: exact !important;
    }
    /* Letterhead full-page background */
    .letterhead-bg {
      position: absolute;
      top: 0; left: 0;
      width: ${A4_W}mm;
      height: ${A4_H}mm;
      z-index: 0;
    }
    .letterhead-bg img {
      width: 100%;
      height: 100%;
      object-fit: fill;
      display: block;
    }
    /* Content area — sits above letterhead */
    .content {
      position: relative;
      z-index: 1;
      padding: ${HEADER_SAFE_MM}mm 18mm ${FOOTER_SAFE_MM}mm 18mm;
      height: ${A4_H}mm;
      display: flex;
      flex-direction: column;
    }
    /* Typography */
    .compact p { margin: 0 0 4px; line-height: 1.45; font-size: 11px; }
    .compact p.gap { margin-bottom: 7px; }
    .compact p.strong-gap { margin-bottom: 9px; }
    table { border-collapse: collapse; }
    td, th { border: 1px solid #999; padding: 3px 8px; font-size: 11px; }
    th { background: #f5f5f5; font-weight: 600; }
    .text-end { text-align: right; }
    .label-cell { font-weight: 600; white-space: nowrap; }

    /* Signature block */
    .sig-block {
      margin-top: auto;  /* push to bottom of content area */
      text-align: right;
      padding-right: 4mm;
    }
    .sig-block .sig-line {
      display: inline-block;
      min-width: 140px;
      border-top: 1px solid #000;
      text-align: center;
      padding-top: 2px;
      font-weight: 700;
      font-size: 11px;
    }
    /* QR footer */
    .qr-footer {
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
      font-size: 8px;
      color: #888;
      margin-top: 6px;
      padding-top: 4px;
      border-top: 1px solid #ccc;
    }
    .qr-footer img { width: 52px; height: 52px; }
    @media print {
      html, body { overflow: visible; }
      .content { height: auto; min-height: ${A4_H - HEADER_SAFE_MM - FOOTER_SAFE_MM}mm; }
    }
  </style>
</head><body>
  <div class="letterhead-bg"><img src="${LETTERHEAD_URL}" /></div>
  <div class="content compact">
    ${bodyContent}
  </div>
</body></html>`;
}

// ── Shared ref/date line ────────────────────────────────────

function refAndDate(certNumber: string, date: string): string {
  return `<div style="display:flex; justify-content:space-between; margin-bottom:8px; font-size:11px;">
    <div><strong>Ref:</strong> ${certNumber}</div>
    <div><strong>Date:</strong> ${date}</div>
  </div>`;
}

// ── QR section ──────────────────────────────────────────────

function qrSection(verifyUrl: string, certNumber: string, qrDataUrl: string): string {
  return `<div class="qr-footer">
    <div>
      <strong style="color:#555;">Cert No:</strong> ${certNumber}<br>
      Computer-generated document.
    </div>
    <div style="text-align:center;">
      <img src="${qrDataUrl}" alt="Verify" />
      <div style="margin-top:1px;">Scan to verify</div>
    </div>
  </div>`;
}

// ══════════════════════════════════════════════════════════════
// APPOINTMENT LETTER — compact 1-page
// ══════════════════════════════════════════════════════════════

function buildAppointmentHTML(d: CertificateData, qrDataUrl: string): string {
  const e = d.employee, c = d.company;
  const firstName = (e.full_name || '').split(' ')[0] || 'Employee';
  const doj = formatDateDMY(e.date_of_joining);
  const basicDa = d.salary?.basic_da ?? 0;
  const hra = d.salary?.hra ?? 0;
  const gross = d.salary?.gross_salary ?? 0;

  const body = `
    ${refAndDate(d.certificate_number, d.date_of_issue)}

    <p><strong>To,</strong></p>
    <p>${e.full_name}</p>
    ${e.address ? `<p>${e.address}</p>` : ''}
    <p>${[e.district, e.state, e.pin_code ? ` - ${e.pin_code}` : ''].filter(Boolean).join(', ')}</p>

    <p class="gap"><strong>Subject: Letter of Appointment</strong></p>
    <p class="gap">Dear ${firstName},</p>

    <p class="strong-gap">With reference to your application and subsequent interview, we are pleased to inform you that you have been selected for the post of <strong>${e.designation || 'Worker'}</strong>${e.department ? ` in the <strong>${e.department}</strong> department` : ''}. You are hereby appointed on the following terms and conditions:</p>

    <p><strong>1. DATE OF JOINING:</strong> ${doj}</p>
    <p class="gap"><strong>2. PROBATION:</strong> ${e.probation_period || 3} months from date of joining, subject to satisfactory performance.</p>
    <p><strong>3. REMUNERATION (Per Month):</strong></p>
    <table style="width:55%; margin: 3px 0 6px;">
      <tr><td>Basic + DA</td><td class="text-end">${fmtINR(basicDa)}</td></tr>
      <tr><td>HRA</td><td class="text-end">${fmtINR(hra)}</td></tr>
      <tr><td><strong>Gross Salary</strong></td><td class="text-end"><strong>${fmtINR(gross)}</strong></td></tr>
    </table>
    <p class="gap"><strong>4. STATUTORY BENEFITS:</strong>
      ${d.salary?.pf_applicable ? 'PF Act, 1952 applicable. ' : ''}
      ${d.salary?.esi_applicable ? 'ESI Act, 1948 applicable. ' : ''}
      Other statutory benefits as per applicable laws.</p>
    <p class="gap"><strong>5. WORKING HOURS:</strong> 8 hours/day with weekly off. Overtime as per applicable laws.</p>
    <p class="gap"><strong>6. LEAVE:</strong> As per company policy (CL, SL, EL etc.) and applicable laws.</p>
    <p class="gap"><strong>7. TERMINATION:</strong> One month's notice or salary in lieu from either side.</p>
    <p class="gap"><strong>8. GENERAL:</strong> Governed by company rules and applicable laws of India.</p>

    <p class="strong-gap">We welcome you to the ${c.name} family and hope for a long and fruitful association. Please sign and return the duplicate copy as acceptance.</p>

    <p>Yours faithfully,</p>

    <div class="sig-block">
      <div><strong>For ${c.name}</strong></div>
      <br>
      <div class="sig-line">Authorized Signatory</div>
    </div>

    ${qrSection(d.verify_url, d.certificate_number, qrDataUrl)}
  `;

  return pageWrap(`${e.full_name} - Appointment Letter`, body);
}

// ══════════════════════════════════════════════════════════════
// SALARY CERTIFICATE — compact 1-page
// ══════════════════════════════════════════════════════════════

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

  const body = `
    ${refAndDate(d.certificate_number, d.date_of_issue)}

    <h2 style="text-align:center; text-decoration:underline; margin:0 0 10px; font-size:15px;">SALARY CERTIFICATE</h2>

    <p class="strong-gap">To Whom It May Concern,</p>
    <p class="strong-gap">This is to certify that <strong>${e.full_name}</strong>${e.father_name ? `, S/o <strong>${e.father_name}</strong>` : ''}, is employed with <strong>${c.name}</strong> as <strong>${e.designation || 'Worker'}</strong>.</p>

    <table style="width:100%; margin-bottom:8px;">
      <tr>
        <td class="label-cell" style="width:22%;">Emp Code</td><td style="width:28%;">${e.employee_code}</td>
        <td class="label-cell" style="width:22%;">Date of Joining</td><td style="width:28%;">${doj}</td>
      </tr>
      <tr>
        <td class="label-cell">Emp Name</td><td>${e.full_name}</td>
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

    <p style="margin-bottom:5px; font-weight:600; font-size:11px;">Salary Components (Per Month):</p>

    <table style="width:48%; display:inline-table; vertical-align:top; margin-bottom:8px;">
      <tr><th colspan="2" style="background:#e8f5e9; font-size:10px;">A. Earnings</th></tr>
      <tr><td>Basic + DA</td><td class="text-end">${fmtINR(basicDa)}</td></tr>
      <tr><td>HRA</td><td class="text-end">${fmtINR(hra)}</td></tr>
      ${washing > 0 ? `<tr><td style="font-size:10px;">Washing Allow.</td><td class="text-end">${fmtINR(washing)}</td></tr>` : ''}
      <tr style="background:#e8f5e9;"><td><strong>Gross</strong></td><td class="text-end"><strong>${fmtINR(gross)}</strong></td></tr>
    </table>

    <table style="width:48%; display:inline-table; vertical-align:top; margin-left:4%; margin-bottom:8px;">
      <tr><th colspan="2" style="background:#ffebee; font-size:10px;">B. Deductions</th></tr>
      <tr><td>PF</td><td class="text-end">${fmtINR(pf)}</td></tr>
      <tr><td>ESI</td><td class="text-end">${fmtINR(esi)}</td></tr>
      <tr><td>Prof. Tax</td><td class="text-end">${fmtINR(pt)}</td></tr>
      <tr style="background:#ffebee;"><td><strong>Total Ded.</strong></td><td class="text-end"><strong>${fmtINR(totalDed)}</strong></td></tr>
    </table>

    <table style="margin-bottom:8px;">
      <tr style="background:#e3f2fd;">
        <td style="width:50%; font-weight:600;">Net Salary (Take Home)/Month</td>
        <td class="text-end" style="font-size:14px; font-weight:700;">${fmtINR(netPay)}</td>
      </tr>
      <tr>
        <td style="font-weight:600;">CTC (Per Annum)</td>
        <td class="text-end" style="font-weight:700;">${fmtINR(ctc)}</td>
      </tr>
    </table>

    ${p ? `<p style="font-size:9px; color:#666; margin-bottom:6px;">(Based on payroll of ${p.month_name} ${p.year}. Paid Days: ${p.paid_days} / ${p.total_days})</p>` : ''}

    <p class="gap">This certificate is issued for the purpose as required.</p>

    <div class="sig-block">
      <div style="font-size:11px;"><strong>Date:</strong> ${d.date_of_issue}</div>
      <div style="margin-top:4px;"><strong>Authorized Signatory</strong></div>
      <div style="font-size:9px; color:#666;">Company Seal</div>
    </div>

    ${qrSection(d.verify_url, d.certificate_number, qrDataUrl)}
  `;

  return pageWrap(`${e.full_name} - Salary Certificate`, body);
}

// ══════════════════════════════════════════════════════════════
// EXPERIENCE CERTIFICATE — centered, elegant
// ══════════════════════════════════════════════════════════════

function buildExperienceHTML(d: CertificateData, qrDataUrl: string): string {
  const e = d.employee, c = d.company;
  const doj = formatDateDMY(e.date_of_joining);
  const today = new Date().toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
  const p = getPronoun(e.gender);
  const gross = d.salary?.gross_salary ?? 0;

  const body = `
    ${refAndDate(d.certificate_number, d.date_of_issue)}

    <h2 style="text-align:center; text-decoration:underline; margin:10px 0 18px; font-size:16px;">EXPERIENCE CERTIFICATE</h2>

    <p style="text-align:center; margin-bottom:16px;"><strong>To Whom It May Concern</strong></p>

    <p style="text-align:justify; margin:0 0 12px; font-size:12px; line-height:1.65;">
      This is to certify that <strong>${e.full_name}</strong>${e.father_name ? `, S/o/D/o <strong>${e.father_name}</strong>` : ''},
      has been employed with <strong>${c.name}</strong>
      as <strong>${e.designation || 'Worker'}</strong>
      ${e.department ? `in the <strong>${e.department}</strong> department` : ''}
      from <strong>${doj}</strong> to <strong>${today}</strong> (Till Date).
    </p>

    <p style="text-align:justify; margin:0 0 12px; font-size:12px; line-height:1.65;">
      During the tenure of <strong>${d.tenure}</strong>,
      ${p.he} has been sincere, hardworking, and diligent in ${p.his} work.
      ${p.He} has displayed excellent professional conduct and interpersonal skills throughout ${p.his} employment.
    </p>

    ${gross > 0 ? `<p style="text-align:justify; margin:0 0 12px; font-size:12px; line-height:1.65;">
      ${p.His} current gross salary is <strong>${fmtINR(gross)}</strong> per month.
    </p>` : ''}

    <p style="text-align:justify; margin:0 0 12px; font-size:12px; line-height:1.65;">
      We wish ${p.him} all the best for ${p.his} future endeavors.
    </p>

    <div class="sig-block">
      <div><strong>For ${c.name}</strong></div>
      <br>
      <div class="sig-line">Authorized Signatory</div>
    </div>

    ${qrSection(d.verify_url, d.certificate_number, qrDataUrl)}
  `;

  return pageWrap(`${e.full_name} - Experience Certificate`, body);
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
      break;
  }

  // 3. Open print window
  const printWindow = window.open('', '_blank', 'width=800,height=1100');
  if (!printWindow) {
    throw new Error('Pop-up blocked. Please allow pop-ups for this site.');
  }
  printWindow.document.write(htmlDoc);
  printWindow.document.close();

  // 4. Trigger print after content + letterhead image loads
  printWindow.onload = () => {
    // Wait for letterhead background image to load
    const bgImg = printWindow.document.querySelector('.letterhead-bg img') as HTMLImageElement;
    if (bgImg && !bgImg.complete) {
      bgImg.onload = () => setTimeout(() => printWindow.print(), 300);
      bgImg.onerror = () => setTimeout(() => printWindow.print(), 300);
    } else {
      setTimeout(() => printWindow.print(), 500);
    }
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