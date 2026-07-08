'use client';

import { useState } from 'react';
import { ClipboardList, BarChart3 } from 'lucide-react';

import ManpowerStatusEntry from './ManpowerStatusEntry';
import ManpowerStatusDashboard from './ManpowerStatusDashboard';
import { PageHeader } from './PageHeader';

interface Props {
  employeeId: number;
  unitIds: number[];
}

export default function ManpowerStatusPage({ employeeId, unitIds }: Props) {
  const [activeTab, setActiveTab] = useState<'entry' | 'dashboard'>('entry');

  return (
    <div className="space-y-4">
      {/* ── Tab Selector ── */}
      <div className="flex bg-gray-100 rounded-xl p-1 gap-1">
        <button
          onClick={() => setActiveTab('entry')}
          className={`flex-1 flex items-center justify-center gap-2 py-2.5 rounded-lg transition-all text-sm font-semibold ${
            activeTab === 'entry'
              ? 'bg-white text-blue-700 shadow-sm'
              : 'text-gray-500 hover:text-gray-700'
          }`}
        >
          <ClipboardList className="w-4 h-4" />
          Entry
        </button>
        <button
          onClick={() => setActiveTab('dashboard')}
          className={`flex-1 flex items-center justify-center gap-2 py-2.5 rounded-lg transition-all text-sm font-semibold ${
            activeTab === 'dashboard'
              ? 'bg-white text-violet-700 shadow-sm'
              : 'text-gray-500 hover:text-gray-700'
          }`}
        >
          <BarChart3 className="w-4 h-4" />
          Reports
        </button>
      </div>

      {/* ── Tab Content ── */}
      {activeTab === 'entry' && (
        <ManpowerStatusEntry employeeId={employeeId} unitIds={unitIds} />
      )}
      {activeTab === 'dashboard' && (
        <ManpowerStatusDashboard unitIds={unitIds} />
      )}
    </div>
  );
}