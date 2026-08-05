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
        <div class="doc-placeholder" style="flex-direction:column;gap:6px;">
          <span style="font-size:24px;">📄</span>
          <span style="font-size:10px;color:#888;">${url.split('/').pop()}</span>
        </div>
      </div>`;
    return `
      <div class="doc-card">
        <div class="doc-label">${title}</div>
        <img src="${url}" class="doc-img" onerror="this.outerHTML='<div class=\\'doc-placeholder\\'><span>Image not available</span></div>'" />
      </div>`;
  };

  const empName = v(emp.full_name);
  const empCode = v(emp.employee_code);

  const htmlDoc = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Registration — ${empName} (${empCode})</title>
<style>
  @page {
    size: A4;
    margin: 10mm 18mm 12mm 18mm;
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: 'Segoe UI', Arial, Helvetica, sans-serif;
    font-size: 12px;
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
    padding: 10px 18px;
    background: #0f3460;
    border-radius: 8px;
    margin-bottom: 16px;
  }
  .topbar-logo {
    height: 40px;
    width: auto;
    object-fit: contain;
  }
  .topbar-text {
    text-align: right;
  }
  .topbar-title {
    font-size: 15px;
    font-weight: 700;
    color: #ffffff;
    letter-spacing: 0.8px;
  }
  .topbar-sub {
    font-size: 10px;
    color: #a8c0e0;
    margin-top: 2px;
  }

  /* ── Profile hero ── */
  .hero {
    display: flex;
    gap: 22px;
    align-items: flex-start;
    margin-bottom: 20px;
    padding: 16px 18px;
    background: #f8f9fc;
    border-radius: 8px;
    border: 1px solid #e8ecf2;
  }
  .hero-photo {
    width: 100px;
    height: 125px;
    border-radius: 8px;
    overflow: hidden;
    border: 2.5px solid #0f3460;
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
    font-size: 19px;
    font-weight: 700;
    color: #0f3460;
    margin-bottom: 3px;
  }
  .hero-code {
    font-size: 12px;
    color: #0f3460;
    font-weight: 600;
    margin-bottom: 8px;
  }
  .hero-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
  }
  .hero-tag {
    display: inline-block;
    padding: 3px 10px;
    background: #e2ecf8;
    color: #0f3460;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 600;
  }

  /* ── Section cards ── */
  .section {
    margin-bottom: 16px;
  }
  .section-title {
    font-size: 12px;
    font-weight: 700;
    color: #ffffff;
    background: #0f3460;
    padding: 6px 14px;
    border-radius: 5px 5px 0 0;
    letter-spacing: 0.3px;
  }
  .section-body {
    border: 1px solid #e0e4ea;
    border-top: none;
    border-radius: 0 0 5px 5px;
    overflow: hidden;
  }

  /* ── Info table ── */
  .info-table {
    width: 100%;
    border-collapse: collapse;
  }
  .info-table td {
    padding: 6px 12px;
    border-bottom: 1px solid #f0f1f3;
    vertical-align: middle;
  }
  .info-table tr:last-child td {
    border-bottom: none;
  }
  .info-table .lbl {
    width: 38%;
    font-size: 10.5px;
    font-weight: 600;
    color: #555;
    white-space: nowrap;
  }
  .info-table .val {
    font-size: 11.5px;
    color: #1a1a2e;
    font-weight: 500;
  }

  /* ── Two-column layout ── */
  .two-col {
    display: flex;
    gap: 16px;
  }
  .two-col .col {
    flex: 1;
  }

  /* ── Declaration ── */
  .decl-box {
    margin-top: 16px;
    padding: 12px 16px;
    border-left: 3px solid #e94560;
    background: #fef2f2;
    border-radius: 0 5px 5px 0;
  }
  .decl-title {
    font-size: 11px;
    font-weight: 700;
    color: #e94560;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 5px;
  }
  .decl-text {
    font-size: 10.5px;
    color: #444;
    text-align: justify;
    line-height: 1.6;
  }
  .sig-row {
    display: flex;
    justify-content: flex-end;
    margin-top: 26px;
    gap: 50px;
  }
  .sig-block {
    text-align: center;
  }
  .sig-line {
    width: 130px;
    border-top: 1px solid #333;
    margin-bottom: 4px;
  }
  .sig-label {
    font-size: 10px;
    color: #555;
  }

  /* ── Page 2: Documents ── */
  .docs-page {
    padding-top: 4px;
  }
  .docs-header {
    text-align: center;
    margin-bottom: 18px;
  }
  .docs-title {
    font-size: 16px;
    font-weight: 700;
    color: #0f3460;
  }
  .docs-sub {
    font-size: 10.5px;
    color: #666;
    margin-top: 3px;
  }
  .docs-grid {
    display: flex;
    flex-direction: column;
    gap: 20px;
  }
  .doc-card {
    border: 1px solid #e0e4ea;
    border-radius: 8px;
    overflow: hidden;
  }
  .doc-label {
    font-size: 10.5px;
    font-weight: 700;
    color: #0f3460;
    background: #f0f3f8;
    padding: 6px 12px;
    border-bottom: 1px solid #e0e4ea;
  }
  .doc-img {
    width: 100%;
    height: auto;
    display: block;
    page-break-inside: avoid;
  }
  .doc-placeholder {
    min-height: 90px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #aaa;
    font-size: 11px;
    background: #fafbfc;
    padding: 20px;
  }

  /* ── Action bar (not printed) ── */
  .action-bar {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    display: flex;
    justify-content: center;
    gap: 12px;
    padding: 12px 20px;
    background: #ffffff;
    border-top: 1px solid #e0e4ea;
    box-shadow: 0 -2px 10px rgba(0,0,0,0.08);
    z-index: 100;
  }
  .action-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 24px;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    letter-spacing: 0.2px;
    transition: background 0.15s;
  }
  .btn-download {
    background: #0f3460;
    color: #ffffff;
  }
  .btn-download:hover { background: #16213e; }
  .btn-share-wa {
    background: #25D366;
    color: #ffffff;
  }
  .btn-share-wa:hover { background: #1da851; }
  .btn-share {
    background: #4A90D9;
    color: #ffffff;
  }
  .btn-share:hover { background: #3a7bc8; }

  /* Add padding at bottom so action bar doesn't overlap content */
  body { padding-bottom: 70px; }

  @media print {
    .action-bar { display: none !important; }
    body { padding-bottom: 0; }
    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head>
<body>

<!-- ── Action Bar (sticky bottom) ── -->
<div class="action-bar">
  <button class="action-btn btn-download" id="btnDownload">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
    Download PDF
  </button>
  <button class="action-btn btn-share-wa" id="btnWhatsApp">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
    WhatsApp
  </button>
  <button class="action-btn btn-share" id="btnShare" style="display:none;">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
    Share
  </button>
</div>

<script>
(function() {
  // Download PDF — uses browser print dialog
  var btnDownload = document.getElementById('btnDownload');
  if (btnDownload) {
    btnDownload.addEventListener('click', function() {
      window.print();
    });
  }

  // Share via WhatsApp
  var btnWA = document.getElementById('btnWhatsApp');
  if (btnWA) {
    btnWA.addEventListener('click', function() {
      var text = encodeURIComponent(
        'Employee Registration Form\\n' +
        'Name: ' + document.title + '\\n' +
        'View at: ' + window.location.href
      );
      window.open('https://wa.me/?text=' + text, '_blank');
    });
  }

  // Native Share API (mobile / desktop)
  var btnShare = document.getElementById('btnShare');
  if (btnShare && navigator.share) {
    btnShare.style.display = 'inline-flex';
    btnShare.addEventListener('click', function() {
      navigator.share({
        title: document.title,
        text: 'Employee Registration Form — ' + document.title,
        url: window.location.href
      }).catch(function(){});
    });
  }
})();
</script>

<!-- ══════ PAGE 1 ══════ -->

<!-- Top bar -->
<div class="topbar">
  <img src="${logoUrl}" class="topbar-logo" onerror="this.outerHTML='<div style=\\'color:#fff;font-size:22px;font-weight:800;letter-spacing:1px;\\'>RCS</div>'" />
  <div class="topbar-text">
    <div class="topbar-title">Employee Registration Form</div>
    <div class="topbar-sub">RCS True Facilities Pvt. Ltd.</div>
  </div>
</div>

<!-- Hero: Photo + Name + Tags -->
<div class="hero">
  <div class="hero-photo">
    ${photoUrl
      ? `<img src="${photoUrl}" onerror="this.outerHTML='<div style=\\'font-size:10px;color:#888;\\'>No Photo</div>'" />`
      : '<div style="font-size:10px;color:#888;">No Photo</div>'}
  </div>
  <div class="hero-info">
    <div class="hero-name">${empName}</div>
    <div class="hero-code">Employee Code: ${empCode}</div>
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
  <div class="topbar" style="margin-bottom:14px;">
    <img src="${logoUrl}" class="topbar-logo" onerror="this.outerHTML='<div style=\\'color:#fff;font-size:22px;font-weight:800;letter-spacing:1px;\\'>RCS</div>'" />
    <div class="topbar-text">
      <div class="topbar-title">Employee Documents</div>
      <div class="topbar-sub">${empName} — ${empCode}</div>
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

  // Wait for content to fully load (especially images and logo)
  printWindow.onload = () => {
    setTimeout(() => {
      printWindow.print();
    }, 800);
  };
}
