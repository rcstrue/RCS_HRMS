import { getFileUrl } from '@/lib/api/config';
import type { Employee } from '@/lib/ess-types';

/**
 * Employee Registration Form — A4 Portrait
 * Modern clean design with RCS logo, 2-page print-friendly PDF.
 * Page 1: Employee details with photo & info grid
 * Page 2: Document images (Aadhaar Front, Aadhaar Back, Bank Passbook)
 */

export function generateEmployeeRegistrationForm(emp: Employee): void {
  const photoUrl = getFileUrl(emp.profile_pic_url || emp.profile_pic_cropped_url);
  const aadhaarFrontUrl = getFileUrl(emp.aadhaar_front_url);
  const aadhaarBackUrl = getFileUrl(emp.aadhaar_back_url);
  const bankDocUrl = getFileUrl(emp.bank_document_url);
  const logoUrl = 'https://join.rcsfacility.com/logo.png';

  function fmtDate(dateStr?: string | null): string {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    const day = String(d.getDate()).padStart(2, '0');
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const year = d.getFullYear();
    return `${day}-${month}-${year}`;
  }

  const v = (val?: string | null | number) =>
    val !== undefined && val !== null && String(val).trim() !== '' ? String(val).trim() : '—';

  // Row: label + value
  const row = (label: string, value?: string | null | number) => `
    <tr>
      <td class="lbl">${label}</td>
      <td class="val">${v(value)}</td>
    </tr>`;

  // Document card for page 2
  const docCard = (title: string, url: string | null) => {
    if (!url) return `
      <div class="doc-card">
        <div class="doc-label">${title}</div>
        <div class="doc-placeholder"><span>Not Uploaded</span></div>
      </div>`;
    const isPDF = url.toLowerCase().endsWith('.pdf');
    if (isPDF) return `
      <div class="doc-card">
        <div class="doc-label">${title}</div>
        <div class="doc-placeholder" style="flex-direction:column;gap:4px;">
          <span style="font-size:20px;">📄</span>
          <span style="font-size:9px;color:#888;">${url.split('/').pop()}</span>
        </div>
      </div>`;
    return `
      <div class="doc-card">
        <div class="doc-label">${title}</div>
        <img src="${url}" class="doc-img" onerror="this.outerHTML='<div class=\\'doc-placeholder\\'><span>Image not available</span></div>'" />
      </div>`;
  };

  const htmlDoc = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Registration — ${emp.full_name || emp.employee_code}</title>
<style>
  @page {
    size: A4;
    margin: 10mm 12mm 12mm 12mm;
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: 'Segoe UI', Arial, Helvetica, sans-serif;
    font-size: 10px;
    color: #1a1a2e;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
  }
  .page-break { page-break-after: always; }

  /* ── Top bar ── */
  .topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 16px;
    background: #0f3460;
    border-radius: 6px;
    margin-bottom: 14px;
  }
  .topbar-logo {
    height: 34px;
    width: auto;
    object-fit: contain;
  }
  .topbar-text {
    text-align: right;
  }
  .topbar-title {
    font-size: 13px;
    font-weight: 700;
    color: #ffffff;
    letter-spacing: 0.8px;
  }
  .topbar-sub {
    font-size: 8.5px;
    color: #a8c0e0;
    margin-top: 1px;
  }

  /* ── Profile hero ── */
  .hero {
    display: flex;
    gap: 20px;
    align-items: flex-start;
    margin-bottom: 18px;
    padding: 14px 16px;
    background: #f8f9fc;
    border-radius: 8px;
    border: 1px solid #e8ecf2;
  }
  .hero-photo {
    width: 90px;
    height: 110px;
    border-radius: 6px;
    overflow: hidden;
    border: 2px solid #0f3460;
    flex-shrink: 0;
    background: #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .hero-photo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .hero-info {
    flex: 1;
  }
  .hero-name {
    font-size: 16px;
    font-weight: 700;
    color: #0f3460;
    margin-bottom: 2px;
  }
  .hero-code {
    font-size: 10px;
    color: #0f3460;
    font-weight: 600;
    margin-bottom: 6px;
  }
  .hero-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
  }
  .hero-tag {
    display: inline-block;
    padding: 2px 8px;
    background: #e2ecf8;
    color: #0f3460;
    border-radius: 3px;
    font-size: 8.5px;
    font-weight: 600;
  }

  /* ── Section cards ── */
  .section {
    margin-bottom: 14px;
  }
  .section-title {
    font-size: 10px;
    font-weight: 700;
    color: #ffffff;
    background: #0f3460;
    padding: 5px 12px;
    border-radius: 4px 4px 0 0;
    letter-spacing: 0.3px;
  }
  .section-body {
    border: 1px solid #e0e4ea;
    border-top: none;
    border-radius: 0 0 4px 4px;
    overflow: hidden;
  }

  /* ── Info table ── */
  .info-table {
    width: 100%;
    border-collapse: collapse;
  }
  .info-table td {
    padding: 5px 10px;
    border-bottom: 1px solid #f0f1f3;
    vertical-align: middle;
  }
  .info-table tr:last-child td {
    border-bottom: none;
  }
  .info-table .lbl {
    width: 38%;
    font-size: 9px;
    font-weight: 600;
    color: #555;
    white-space: nowrap;
  }
  .info-table .val {
    font-size: 10px;
    color: #1a1a2e;
    font-weight: 500;
  }

  /* ── Two-column layout ── */
  .two-col {
    display: flex;
    gap: 14px;
  }
  .two-col .col {
    flex: 1;
  }

  /* ── Declaration ── */
  .decl-box {
    margin-top: 14px;
    padding: 10px 14px;
    border-left: 3px solid #e94560;
    background: #fef2f2;
    border-radius: 0 4px 4px 0;
  }
  .decl-title {
    font-size: 9.5px;
    font-weight: 700;
    color: #e94560;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
  }
  .decl-text {
    font-size: 9px;
    color: #444;
    text-align: justify;
    line-height: 1.6;
  }
  .sig-row {
    display: flex;
    justify-content: flex-end;
    margin-top: 22px;
    gap: 40px;
  }
  .sig-block {
    text-align: center;
  }
  .sig-line {
    width: 120px;
    border-top: 1px solid #333;
    margin-bottom: 3px;
  }
  .sig-label {
    font-size: 8.5px;
    color: #555;
  }

  /* ── Page 2: Documents ── */
  .docs-page {
    padding-top: 4px;
  }
  .docs-header {
    text-align: center;
    margin-bottom: 16px;
  }
  .docs-title {
    font-size: 14px;
    font-weight: 700;
    color: #0f3460;
  }
  .docs-sub {
    font-size: 9px;
    color: #666;
    margin-top: 2px;
  }
  .docs-grid {
    display: flex;
    flex-direction: column;
    gap: 18px;
  }
  .doc-card {
    border: 1px solid #e0e4ea;
    border-radius: 6px;
    overflow: hidden;
  }
  .doc-label {
    font-size: 9px;
    font-weight: 700;
    color: #0f3460;
    background: #f0f3f8;
    padding: 5px 10px;
    border-bottom: 1px solid #e0e4ea;
  }
  .doc-img {
    width: 100%;
    height: auto;
    display: block;
    page-break-inside: avoid;
  }
  .doc-placeholder {
    min-height: 80px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #aaa;
    font-size: 10px;
    background: #fafbfc;
    padding: 16px;
  }
  .doc-passbook .doc-img {
    max-height: 380px;
    object-fit: contain;
    background: #fff;
  }

  /* ── Print button ── */
  .print-btn {
    display: block;
    margin: 10px auto;
    padding: 8px 28px;
    background: #0f3460;
    color: #fff;
    border: none;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    letter-spacing: 0.3px;
  }
  .print-btn:hover { background: #16213e; }

  @media print {
    .print-btn { display: none !important; }
    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head>
<body>

<button class="print-btn" onclick="window.print()">⬇ Download / Print PDF</button>

<!-- ══════ PAGE 1 ══════ -->

<!-- Top bar -->
<div class="topbar">
  <img src="${logoUrl}" class="topbar-logo" onerror="this.outerHTML='<div style=\\'color:#fff;font-size:18px;font-weight:800;letter-spacing:1px;\\'>RCS</div>'" />
  <div class="topbar-text">
    <div class="topbar-title">Employee Registration Form</div>
    <div class="topbar-sub">RCS True Facilities Pvt. Ltd.</div>
  </div>
</div>

<!-- Hero: Photo + Name + Tags -->
<div class="hero">
  <div class="hero-photo">
    ${photoUrl
      ? `<img src="${photoUrl}" onerror="this.outerHTML='<div style=\\'font-size:9px;color:#888;\\'>No Photo</div>'" />`
      : '<div style="font-size:9px;color:#888;">No Photo</div>'}
  </div>
  <div class="hero-info">
    <div class="hero-name">${v(emp.full_name)}</div>
    <div class="hero-code">Employee Code: ${v(emp.employee_code)}</div>
    <div class="hero-tags">
      ${emp.designation ? `<span class="hero-tag">${emp.designation}</span>` : ''}
      ${emp.unit_name ? `<span class="hero-tag">${emp.unit_name.toUpperCase()}</span>` : ''}
      ${emp.state ? `<span class="hero-tag">${emp.state.toUpperCase()}</span>` : ''}
      ${emp.gender ? `<span class="hero-tag">${emp.gender}</span>` : ''}
      ${emp.blood_group ? `<span class="hero-tag">🩸 ${emp.blood_group}</span>` : ''}
      ${emp.marital_status ? `<span class="hero-tag">${emp.marital_status}</span>` : ''}
    </div>
  </div>
</div>

<!-- Personal Details — Two Column -->
<div class="section">
  <div class="section-title">Personal Information</div>
  <div class="section-body">
    <div class="two-col">
      <div class="col">
        <table class="info-table">
          ${row('Full Name', emp.full_name)}
          ${row('Father / Husband Name', emp.father_name)}
          ${row('Date of Birth', fmtDate(emp.date_of_birth))}
          ${row('Gender', emp.gender)}
          ${row('Marital Status', emp.marital_status)}
          ${row('Blood Group', emp.blood_group)}
          ${row('Mobile Number', emp.mobile_number)}
          ${row('Email', emp.email)}
        </table>
      </div>
      <div class="col">
        <table class="info-table">
          ${row('Aadhaar Number', emp.aadhaar_number)}
          ${row('Name (As in Aadhaar)', emp.full_name)}
          ${row('UAN (PF)', emp.uan_number)}
          ${row('ESIC Number', emp.esic_number)}
          ${row('State', emp.state)}
          ${row('Zone / Unit', emp.unit_name)}
          ${row('Residence Address', emp.address)}
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Employment & Bank — Two Column -->
<div class="section">
  <div class="section-title">Employment & Bank Details</div>
  <div class="section-body">
    <div class="two-col">
      <div class="col">
        <table class="info-table">
          ${row('Designation', emp.designation)}
          ${row('Department', emp.unit_name)}
          ${row('Date of Joining', fmtDate(emp.date_of_joining))}
          ${row('Client', emp.client_name)}
        </table>
      </div>
      <div class="col">
        <table class="info-table">
          ${row('Bank Name', emp.bank_name)}
          ${row('Account Number', emp.account_number)}
          ${row('IFSC Code', emp.ifsc_code)}
          ${row('Account Holder', emp.account_holder_name)}
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Nominee & Emergency — Two Column -->
<div class="section">
  <div class="section-title">Nominee & Emergency Contact</div>
  <div class="section-body">
    <div class="two-col">
      <div class="col">
        <table class="info-table">
          ${row('Nominee Name', emp.nominee_name)}
          ${row('Relationship', emp.nominee_relationship)}
          ${row('Nominee DOB', fmtDate(emp.nominee_dob))}
          ${row('Nominee Contact', emp.nominee_contact)}
        </table>
      </div>
      <div class="col">
        <table class="info-table">
          ${row('Emergency Contact', emp.emergency_contact_name)}
          ${row('Relationship', emp.emergency_contact_relation)}
          ${row('Alternate Mobile', emp.alternate_mobile)}
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Declaration -->
<div class="decl-box">
  <div class="decl-title">Declaration</div>
  <div class="decl-text">
    I hereby declare that the details furnished above are true and correct to the best of my knowledge and belief and I undertake to inform you of any changes therein, immediately. In case any of the above information is found to be false or untrue or misleading or misrepresenting, I am aware that I may be held liable for it.
  </div>
</div>

<!-- Signatures -->
<div class="sig-row">
  <div class="sig-block">
    <div class="sig-line"></div>
    <div class="sig-label">Employee Signature</div>
  </div>
  <div class="sig-block">
    <div class="sig-line"></div>
    <div class="sig-label">Authorized Signatory</div>
  </div>
</div>

<!-- ══════ PAGE 2: Documents ══════ -->
<div class="page-break"></div>
<div class="docs-page">

  <!-- Mini header -->
  <div class="topbar" style="margin-bottom:12px;">
    <img src="${logoUrl}" class="topbar-logo" onerror="this.outerHTML='<div style=\\'color:#fff;font-size:18px;font-weight:800;letter-spacing:1px;\\'>RCS</div>'" />
    <div class="topbar-text">
      <div class="topbar-title">Employee Documents</div>
      <div class="topbar-sub">${v(emp.full_name)} — ${v(emp.employee_code)}</div>
    </div>
  </div>

  <div class="docs-header">
    <div class="docs-title">KYC & Bank Documents</div>
    <div class="docs-sub">Aadhaar Card (Front & Back) • Bank Passbook</div>
  </div>

  <div class="docs-grid">
    <!-- Aadhaar Front -->
    ${docCard('Aadhaar Card — Front', aadhaarFrontUrl)}

    <!-- Aadhaar Back -->
    ${docCard('Aadhaar Card — Back', aadhaarBackUrl)}

    <!-- Bank Passbook — larger -->
    ${docCard('Bank Passbook / Document', bankDocUrl)}
  </div>

</div>

</body>
</html>`;

  // Open print window
  const printWindow = window.open('', '_blank', 'width=800,height=1000');
  if (!printWindow) {
    throw new Error('Popup blocked. Please allow popups to print the registration form.');
  }

  printWindow.document.open();
  printWindow.document.write(htmlDoc);
  printWindow.document.close();

  printWindow.onload = () => {
    setTimeout(() => {
      printWindow.print();
    }, 700);
  };
}
