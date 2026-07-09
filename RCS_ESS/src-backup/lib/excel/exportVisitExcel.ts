// ══════════════════════════════════════════════════
// Unit Visit Excel Export
// ══════════════════════════════════════════════════

import * as XLSX from 'xlsx';
import type { UnitVisit } from '@/lib/ess-types';

const MONTHS = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

export function exportVisitsToExcel(visits: UnitVisit[], filename?: string): void {
  const rows = visits.map((v, i) => ({
    '#': i + 1,
    'Date': v.created_at ? new Date(v.created_at).toLocaleDateString('en-IN') : '',
    'Employee': v.employee_name || '',
    'Employee Code': v.employee_code || '',
    'Client': v.client_name || '',
    'Unit': v.unit_name || '',
    'Visit': v.visit_number === 1 ? 'First' : 'Second',
    'Month': `${MONTHS[v.visit_month]} ${v.visit_year}`,
    'Score %': v.score_percent ?? 0,
    'Score': `${v.total_score ?? 0}/${v.max_score ?? 0}`,
    'Status': v.status.charAt(0).toUpperCase() + v.status.slice(1),
    'Notes': v.notes || '',
  }));

  const ws = XLSX.utils.json_to_sheet(rows);
  ws['!cols'] = [
    { wch: 4 }, { wch: 12 }, { wch: 20 }, { wch: 12 }, { wch: 20 },
    { wch: 24 }, { wch: 8 }, { wch: 16 }, { wch: 10 }, { wch: 10 },
    { wch: 10 }, { wch: 30 },
  ];
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Unit Visits');
  XLSX.writeFile(wb, filename || `Unit_Visits_${new Date().toISOString().slice(0, 10)}.xlsx`);
}