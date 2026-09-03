'use client';

import { getRoleBadge } from './helpers';
import { getFileUrl } from '@/lib/api/config';
import { IDCard } from '@/components/registration/IDCard';
import type { Employee, EmployeeRole } from '@/lib/ess-types';
import PageHeader from './PageHeader';

interface IdCardPageProps {
  employee: Employee;
  role: EmployeeRole;
}

export default function IdCardPage({ employee, role }: IdCardPageProps) {
  const isApproved = employee.status === 'approved' || employee.status === 'verified';

  if (!isApproved) {
    return (
      <div className="space-y-4">
        <PageHeader title="ID Card" subtitle="Your employee identity card" />
        <div className="flex flex-col items-center justify-center gap-4 py-16">
          <div className="w-16 h-16 rounded-full bg-amber-100 flex items-center justify-center">
            <svg className="w-8 h-8 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
            </svg>
          </div>
          <div className="text-center">
            <p className="font-semibold text-gray-800">ID Card Not Available</p>
            <p className="text-sm text-gray-500 mt-1">Your profile is pending verification.</p>
            <p className="text-sm text-gray-500">ID Card will be available once your account is approved.</p>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <PageHeader title="ID Card" subtitle="Your employee identity card" />
      {/* @ts-expect-error — IDCard expects registration Employee type, ESS Employee is compatible */}
      <IDCard employee={employee as any} />
    </div>
  );
}
