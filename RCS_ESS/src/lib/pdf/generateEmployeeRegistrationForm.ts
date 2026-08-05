import { getFileUrl } from '@/lib/api/config';
import type { Employee } from '@/lib/ess-types';

/**
 * Employee Registration Form — A4 Portrait
 * Uses browser native print (window.print) for clean PDF download.
 * Page 1: Registration form matching RCS branding
 * Page 2: Document images (Aadhaar Front, Aadhaar Back, Bank Passbook)
 */

export function generateEmployeeRegistrationForm(emp: Employee): void {
  const photoUrl = getFileUrl(emp.profile_pic_url || emp.profile_pic_cropped_url);
  const aadhaarFrontUrl = getFileUrl(emp.aadhaar_front_url);
  const aadhaarBackUrl = getFileUrl(emp.aadhaar_back_url);
  const bankDocUrl = getFileUrl(emp.bank_document_url);

  // Format date as DD-MM-YYYY
  function fmtDate(dateStr?: string | null): string {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    const day = String(d.getDate()).padStart(2, '0');
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const year = d.getFullYear();
    return `${day}-${month}-${year}`;
  }

  const field = (label: string, value?: string | null | number) => {
    const display = value !== undefined && value !== null && String(value).trim() !== ''
      ? String(value).trim()
      : '';
    return `<div style="display:flex;align-items:baseline;margin-bottom:6px;">
      <span style="font-size:9.5px;font-weight:600;min-width:110px;flex-shrink:0;color:#333;">${label}</span>
      <span style="font-size:10px;border-bottom:1px solid #888;flex:1;padding-bottom:1px;padding-left:6px;color:#000;min-height:14px;">${display}</span>
    </div>`;
  };

  const docSection = (title: string, url: string | null) => {
    if (!url) return '';
    const isPDF = url.toLowerCase().endsWith('.pdf');
    if (isPDF) {
      return `<div style="margin-bottom:12px;">
        <div style="font-size:10px;font-weight:700;color:#333;margin-bottom:4px;">${title}</div>
        <div style="border:1px solid #ccc;padding:12px;text-align:center;background:#f9f9f9;border-radius:4px;">
          <div style="font-size:10px;color:#666;">PDF Document</div>
          <div style="font-size:8px;color:#999;margin-top:2px;word-break:break-all;">${url.split('/').pop()}</div>
        </div>
      </div>`;
    }
    return `<div style="margin-bottom:12px;">
      <div style="font-size:10px;font-weight:700;color:#333;margin-bottom:4px;">${title}</div>
      <img src="${url}" style="width:100%;max-width:280px;border:1px solid #ddd;border-radius:4px;" onerror="this.parentElement.innerHTML='<div style=\\'border:1px dashed #ccc;padding:16px;text-align:center;color:#999;font-size:10px;\\'>Image not available</div>'" />
    </div>`;
  };

  const htmlDoc = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Employee Registration - ${emp.full_name || emp.employee_code}</title>
<style>
  @page {
    size: A4;
    margin: 12mm 15mm 15mm 15mm;
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 10px;
    color: #333;
    line-height: 1.4;
  }
  .page-break { page-break-after: always; }

  /* Header */
  .header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    border-bottom: 2px solid #0056a6;
    padding-bottom: 10px;
    margin-bottom: 12px;
  }
  .header-left {
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .header-right {
    text-align: right;
    font-size: 9px;
    color: #444;
  }
  .company-name {
    font-size: 13px;
    font-weight: 700;
    color: #0056a6;
    letter-spacing: 0.5px;
  }
  .cin-no {
    font-size: 8.5px;
    color: #666;
    margin-top: 2px;
  }
  .address-line {
    font-size: 8.5px;
    color: #555;
    margin-top: 1px;
  }

  /* Employee ID & Photo row */
  .id-photo-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
    padding: 8px 0;
  }
  .emp-id {
    font-size: 11px;
    font-weight: 700;
    color: #0056a6;
  }
  .photo-frame {
    width: 70px;
    height: 85px;
    border: 1.5px solid #0056a6;
    border-radius: 4px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f5f5f5;
  }
  .photo-frame img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  /* Two-column personal info */
  .personal-section {
    display: flex;
    gap: 24px;
  }
  .personal-col {
    flex: 1;
  }

  /* Declaration */
  .declaration {
    margin-top: 16px;
    border: 1px solid #999;
    padding: 10px 14px;
    border-radius: 3px;
  }
  .declaration-title {
    font-size: 11px;
    font-weight: 700;
    color: #8B0000;
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .declaration-text {
    font-size: 9px;
    color: #333;
    text-align: justify;
    line-height: 1.5;
  }

  /* Signature */
  .signature-area {
    display: flex;
    justify-content: flex-end;
    margin-top: 20px;
    padding-right: 20px;
  }
  .signature-label {
    font-size: 9px;
    font-weight: 600;
    color: #333;
    border-top: 1px solid #333;
    padding-top: 4px;
    min-width: 120px;
    text-align: center;
  }

  /* Page 2 */
  .docs-page {
    padding-top: 8px;
  }
  .docs-title {
    font-size: 13px;
    font-weight: 700;
    color: #0056a6;
    text-align: center;
    margin-bottom: 6px;
  }
  .docs-subtitle {
    font-size: 9px;
    color: #666;
    text-align: center;
    margin-bottom: 16px;
  }
  .docs-grid {
    display: flex;
    flex-direction: column;
    align-items: center;
  }
  .docs-grid-images {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 16px;
    width: 100%;
  }
  .doc-item {
    flex: 0 0 280px;
  }

  /* Print button */
  .print-btn {
    display: block;
    margin: 10px auto;
    padding: 8px 24px;
    background: #0056a6;
    color: #fff;
    border: none;
    border-radius: 4px;
    font-size: 12px;
    cursor: pointer;
  }
  .print-btn:hover { background: #003d7a; }

  @media print {
    .print-btn { display: none !important; }
    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
</style>
</head>
<body>

<button class="print-btn" onclick="window.print()">Download / Print PDF</button>

<!-- ===== PAGE 1: Registration Form ===== -->
<div class="header">
  <div class="header-left">
    <div>
      <div class="company-name">RCS TRUE FACILITIES PVT. LTD.</div>
      <div class="cin-no">CIN No.: U74900GJ2011PTC065060</div>
      <div class="address-line">Office 116, 2nd Floor, Shreenathji Square,</div>
      <div class="address-line">Opposite Sardar Patel Garden, Near Old Station Road,</div>
      <div class="address-line">Vadodara-390007, PIN : 392001, Gujarat</div>
      <div class="address-line">Web: www.rcsfacility.com | Email: indiaservices@rcs.com</div>
    </div>
  </div>
  <div class="header-right">
    <div style="font-size:10px;color:#888;font-style:italic;">Trust The Professionals &gt;&gt;&gt;</div>
  </div>
</div>

<div class="id-photo-row">
  <div>
    <div class="emp-id">Employee Id: <span style="color:#000;">${emp.employee_code || ''}</span></div>
  </div>
  <div class="photo-frame">
    ${photoUrl
      ? `<img src="${photoUrl}" onerror="this.style.display='none';this.parentElement.innerHTML='<div style=\\'font-size:8px;color:#999;\\'>No Photo</div>'" />`
      : '<div style="font-size:8px;color:#999;">No Photo</div>'}
  </div>
</div>

<div class="personal-section">
  <!-- Left Column -->
  <div class="personal-col">
    ${field('First Name', emp.full_name)}
    ${field('Middle Name', emp.father_name)}
    ${field('Last Name', '')}
    ${field('Mobile No', emp.mobile_number)}
    ${field('Residence Address', emp.address)}
    ${field('D O B', fmtDate(emp.date_of_birth))}
    ${field('Gender', emp.gender)}
    ${field('Name (As in Adhaar)', emp.full_name)}
    ${field('Pan Card', '')}
    ${field('PF UAN', emp.uan_number)}
    ${field('Husband / Wife Name', emp.nominee_name)}
    ${field('Relation Name', emp.nominee_relationship)}
    ${field('State', (emp.state || '').toUpperCase())}
    ${field('Zone', (emp.unit_name || '').toUpperCase())}
    ${field('Bank Name', emp.bank_name)}
    ${field('Bank A/C', emp.account_number)}
  </div>

  <!-- Right Column -->
  <div class="personal-col">
    ${field('Email', emp.email)}
    ${field('Marriage Status', emp.marital_status)}
    ${field('Adhaar Card No', emp.aadhaar_number)}
    ${field('Blood Group', emp.blood_group)}
    ${field('EPIC No', '')}
    ${field('Nominee Name', emp.nominee_name)}
    ${field('Mobile No. (Res.)', emp.alternate_mobile || emp.mobile_number)}
    ${field('Designation', emp.designation)}
    ${field('Department', (emp.unit_name || '').toUpperCase())}
    ${field('Ifsc No', (emp.ifsc_code || '').toUpperCase())}
  </div>
</div>

<div class="declaration">
  <div class="declaration-title">Declaration</div>
  <div class="declaration-text">
    I hereby declare that the details furnished above are true and correct to the best of my knowledge and belief and I undertake to inform you of any changes therein, immediately. In case any of the above information is found to be false or untrue or misleading or misrepresenting, I am aware that I may be held liable for it.
  </div>
</div>

<div class="signature-area">
  <div class="signature-label">Signature</div>
</div>

<!-- ===== PAGE 2: Documents ===== -->
<div class="page-break"></div>
<div class="docs-page">
  <div class="docs-title">Employee Documents — ${emp.full_name || ''}</div>
  <div class="docs-subtitle">Employee Code: ${emp.employee_code || ''} | Department: ${emp.unit_name || ''}</div>

  <div class="docs-grid">
    <div class="docs-grid-images">
      ${docSection('Aadhaar Card (Front)', aadhaarFrontUrl)}
      ${docSection('Aadhaar Card (Back)', aadhaarBackUrl)}
      ${docSection('Bank Passbook', bankDocUrl)}
    </div>
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
    }, 600);
  };
}
