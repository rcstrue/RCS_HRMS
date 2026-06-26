// ══════════════════════════════════════════════════════════════
// Unit Visit Report PDF Generator — A4 Portrait, print-based
// ══════════════════════════════════════════════════════════════

import type { UnitVisit, VisitChecklistItem, ChecklistCategory } from '@/lib/ess-types';

interface VisitReportData {
  visit: UnitVisit;
  checklistItems: VisitChecklistItem[];
  categories: ChecklistCategory[];
}

const MONTHS = ['', 'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December'];

function getScoreColor(pct: number): string {
  if (pct >= 80) return '#059669';
  if (pct >= 60) return '#d97706';
  return '#dc2626';
}

function getScoreLabel(pct: number): string {
  if (pct >= 80) return 'Excellent';
  if (pct >= 60) return 'Good';
  if (pct >= 40) return 'Average';
  return 'Poor';
}

export function generateVisitReportPDF(data: VisitReportData): void {
  const { visit, checklistItems, categories } = data;
  const score = visit.score_percent ?? 0;
  const scoreColor = getScoreColor(score);
  const scoreLabel = getScoreLabel(score);

  // Group items by category
  const grouped: Record<number, VisitChecklistItem[]> = {};
  for (const item of checklistItems) {
    const catId = item.category_id;
    if (!grouped[catId]) grouped[catId] = [];
    grouped[catId].push(item);
  }

  // Build category sections
  let categoryHTML = '';
  for (const cat of categories) {
    const items = grouped[cat.id] || [];
    if (items.length === 0) continue;

    let rowsHTML = '';
    let catTotal = 0;
    let catMax = 0;
    for (const item of items) {
      const isNa = item.status === 'na';
      const isYes = item.status === 'yes';
      if (!isNa) { catMax += item.weight; if (isYes) catTotal += item.weight; }
      const statusIcon = isNa ? '<span style="color:#9CA3AF">N/A</span>' : isYes ? '<span style="color:#059669;font-weight:700">&#10003; Yes</span>' : '<span style="color:#dc2626;font-weight:700">&#10007; No</span>';
      const remarksCell = item.remarks ? `<td style="padding:6px 8px;font-size:10px;color:#6B7280;border-bottom:1px solid #E5E7EB">${item.remarks}</td>` : '<td style="padding:6px 8px;border-bottom:1px solid #E5E7EB"></td>';
      const photoCell = item.photo_url ? `<td style="padding:6px 8px;border-bottom:1px solid #E5E7EB;text-align:center"><img src="${item.photo_url}" style="width:40px;height:40px;object-fit:cover;border-radius:4px;border:1px solid #E5E7EB" /></td>` : '<td style="padding:6px 8px;border-bottom:1px solid #E5E7EB"></td>';

      rowsHTML += `<tr>
        <td style="padding:6px 8px;font-size:10.5px;color:#374151;border-bottom:1px solid #E5E7EB">${item.item_name || ''}</td>
        <td style="padding:6px 8px;text-align:center;border-bottom:1px solid #E5E7EB;width:70px">${statusIcon}</td>
        ${remarksCell}
        ${photoCell}
      </tr>`;
    }

    const catPct = catMax > 0 ? Math.round((catTotal / catMax) * 100) : 0;
    const catColor = getScoreColor(catPct);

    categoryHTML += `
      <div style="margin-bottom:16px">
        <div style="display:flex;align-items:center;justify-content:space-between;background:#F9FAFB;padding:8px 12px;border:1px solid #E5E7EB;border-radius:6px 6px 0 0">
          <span style="font-size:12px;font-weight:700;color:#374151">${cat.name}</span>
          <span style="font-size:11px;font-weight:600;color:${catColor}">${catPct}% (${catTotal}/${catMax})</span>
        </div>
        <table style="width:100%;border-collapse:collapse;border:1px solid #E5E7EB;border-top:none">
          <thead>
            <tr style="background:#F3F4F6">
              <th style="padding:6px 8px;text-align:left;font-size:9px;font-weight:600;color:#6B7280;text-transform:uppercase;letter-spacing:0.3px">Checklist Item</th>
              <th style="padding:6px 8px;text-align:center;font-size:9px;font-weight:600;color:#6B7280;text-transform:uppercase;letter-spacing:0.3px;width:70px">Status</th>
              <th style="padding:6px 8px;text-align:left;font-size:9px;font-weight:600;color:#6B7280;text-transform:uppercase;letter-spacing:0.3px">Remarks</th>
              <th style="padding:6px 8px;text-align:center;font-size:9px;font-weight:600;color:#6B7280;text-transform:uppercase;letter-spacing:0.3px;width:56px">Photo</th>
            </tr>
          </thead>
          <tbody>${rowsHTML}</tbody>
        </table>
      </div>`;
  }

  const htmlDoc = `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Unit Visit Report - ${visit.unit_name || ''}</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Segoe UI', Roboto, Arial, sans-serif; color: #374151; background: #fff; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    .report { width: 210mm; max-width: 100%; margin: 0 auto; border: 1.5px solid #374151; }
    .header { background: #059669; padding: 14px 18px; display: flex; justify-content: space-between; align-items: center; }
    .header h1 { font-size: 16px; font-weight: 700; color: #fff; }
    .header .subtitle { font-size: 10px; color: rgba(255,255,255,0.8); margin-top: 2px; }
    .header .date { font-size: 11px; color: #fff; font-weight: 600; text-align: right; }
    .info-bar { background: #F3F4F6; padding: 10px 18px; display: flex; justify-content: space-between; border-bottom: 1px solid #E5E7EB; flex-wrap: wrap; gap: 4px; }
    .info-item { font-size: 10.5px; }
    .info-item .label { color: #9CA3AF; font-weight: 500; text-transform: uppercase; font-size: 8.5px; letter-spacing: 0.3px; }
    .info-item .value { color: #374151; font-weight: 600; }
    .score-bar { padding: 16px 18px; display: flex; align-items: center; gap: 16px; border-bottom: 1px solid #E5E7EB; }
    .score-circle { width: 80px; height: 80px; border-radius: 50%; display: flex; flex-direction: column; align-items: center; justify-content: center; border: 4px solid ${scoreColor}; }
    .score-circle .pct { font-size: 24px; font-weight: 800; color: ${scoreColor}; }
    .score-circle .lbl { font-size: 9px; color: #6B7280; font-weight: 500; }
    .score-details { flex: 1; }
    .score-details .grade { font-size: 18px; font-weight: 700; color: ${scoreColor}; }
    .score-details .sub { font-size: 11px; color: #6B7280; margin-top: 2px; }
    .content { padding: 16px 18px; }
    .notes-section { padding: 12px 18px; background: #FFFBEB; border-top: 1px solid #E5E7EB; }
    .notes-section h3 { font-size: 11px; font-weight: 700; color: #92400E; text-transform: uppercase; margin-bottom: 4px; }
    .notes-section p { font-size: 11px; color: #78350F; }
    .footer { padding: 10px 18px; border-top: 1px solid #E5E7EB; text-align: center; }
    .footer p { font-size: 9px; color: #9CA3AF; font-style: italic; }
    .print-btn-wrap { padding: 12px 18px; text-align: center; }
    .print-btn { background: #059669; color: #fff; border: none; padding: 10px 32px; font-size: 14px; font-weight: 600; border-radius: 8px; cursor: pointer; }
    .print-btn:hover { background: #047857; }
    @media print {
      @page { size: A4 portrait; margin: 8mm; }
      body { background: #fff !important; }
      .report { width: 100% !important; border: 1.5px solid #374151 !important; page-break-inside: avoid; }
      .no-print { display: none !important; }
    }
  </style>
</head>
<body>
  <div class="report">
    <div class="header">
      <div>
        <h1>Unit Visit Checklist Report</h1>
        <div class="subtitle">RCS TRUE FACILITIES PVT LTD</div>
      </div>
      <div class="date">${MONTHS[visit.visit_month]} ${visit.visit_year}<br/><span style="font-size:9px;color:rgba(255,255,255,0.7)">${visit.visit_number === 1 ? 'First' : 'Second'} Visit</span></div>
    </div>

    <div class="info-bar">
      <div class="info-item"><div class="label">Employee</div><div class="value">${visit.employee_name || ''} (${visit.employee_code || ''})</div></div>
      <div class="info-item"><div class="label">Client / Unit</div><div class="value">${visit.client_name || ''} / ${visit.unit_name || ''}</div></div>
      <div class="info-item"><div class="label">Status</div><div class="value" style="text-transform:capitalize">${visit.status}</div></div>
      <div class="info-item"><div class="label">Submitted</div><div class="value">${visit.created_at ? new Date(visit.created_at).toLocaleDateString('en-IN') : ''}</div></div>
    </div>

    <div class="score-bar">
      <div class="score-circle">
        <div class="pct">${score}%</div>
        <div class="lbl">SCORE</div>
      </div>
      <div class="score-details">
        <div class="grade">${scoreLabel}</div>
        <div class="sub">${visit.total_score ?? 0} out of ${visit.max_score ?? 0} points earned</div>
      </div>
    </div>

    <div class="content">${categoryHTML}</div>

    ${visit.notes ? `<div class="notes-section"><h3>General Notes</h3><p>${visit.notes}</p></div>` : ''}

    ${visit.rejection_reason ? `<div class="notes-section" style="background:#FEF2F2;border-color:#FECACA"><h3 style="color:#991B1B">Rejection Reason</h3><p style="color:#7F1D1D">${visit.rejection_reason}</p></div>` : ''}

    <div class="footer"><p>This is a computer generated report from RCS ESS Portal.</p></div>

    <div class="print-btn-wrap no-print">
      <button class="print-btn" onclick="window.print()">Download / Print PDF</button>
      <p style="margin-top:6px;font-size:11px;color:#9CA3AF">Use browser's "Save as PDF" option to download</p>
    </div>
  </div>
</body></html>`;

  const printWindow = window.open('', '_blank', 'width=800,height=1000');
  if (!printWindow) throw new Error('Popup blocked.');
  printWindow.document.open();
  printWindow.document.write(htmlDoc);
  printWindow.document.close();
  printWindow.onload = () => { setTimeout(() => printWindow.print(), 400); };
}
