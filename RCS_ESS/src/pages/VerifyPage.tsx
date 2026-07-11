import { useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { getEmployeeById } from '@/lib/api/employees';
import { verifyCertificate } from '@/lib/ess-api';
import { getFileUrl, API_BASE_URL } from '@/lib/api/config';
import { Loader2, CheckCircle, XCircle, User, Phone, Building2, Calendar, Shield, FileText, Award, BadgeCheck, QrCode } from 'lucide-react';

// ── Types ──

interface EmployeeData {
  id: number;
  employee_code: number | null;
  full_name: string | null;
  mobile_number: string;
  designation: string | null;
  client_name: string | null;
  unit_name: string | null;
  profile_pic_url: string | null;
  status: string | null;
}

interface CertVerifyData {
  certificate_type: string;
  certificate_number: string;
  issued_at: string;
  is_valid: boolean;
  employee: {
    employee_code: string;
    full_name: string;
    designation: string;
    department: string;
    client_name: string;
    unit_name: string;
    date_of_joining: string;
    gender: string;
  };
}

const CERT_TYPE_LABELS: Record<string, string> = {
  appointment: 'Appointment Letter',
  salary: 'Salary Certificate',
  experience: 'Experience Certificate',
};

const CERT_TYPE_ICONS: Record<string, React.ReactNode> = {
  appointment: <FileText className="w-5 h-5" />,
  salary: <BadgeCheck className="w-5 h-5" />,
  experience: <Award className="w-5 h-5" />,
};

// ── Component ──

export default function VerifyPage() {
  const location = useLocation();
  const [isLoading, setIsLoading] = useState(true);
  const [employee, setEmployee] = useState<EmployeeData | null>(null);
  const [certData, setCertData] = useState<CertVerifyData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [verifyMode, setVerifyMode] = useState<'employee' | 'certificate'>('employee');

  useEffect(() => {
    const hash = window.location.hash;
    const queryIndex = hash.indexOf('?');
    if (queryIndex === -1) {
      setError('Invalid verification link — no parameters');
      setIsLoading(false);
      return;
    }

    const queryString = hash.substring(queryIndex + 1);
    const params = new URLSearchParams(queryString);
    const certCode = params.get('cert');
    const empId = params.get('id');

    if (certCode) {
      // Certificate verification mode
      setVerifyMode('certificate');
      fetchCertificateVerification(certCode);
    } else if (empId) {
      // Employee verification mode (existing)
      setVerifyMode('employee');
      fetchEmployeeVerification(parseInt(empId));
    } else {
      setError('Invalid verification link — missing parameters');
      setIsLoading(false);
    }
  }, [location]);

  async function fetchEmployeeVerification(id: number) {
    try {
      const { data, error: fetchError } = await getEmployeeById(id);
      if (fetchError) {
        setError(`Employee not found: ${fetchError}`);
      } else if (!data) {
        setError('Employee not found');
      } else {
        setEmployee(data as EmployeeData);
      }
    } catch (err) {
      setError('Failed to verify. Please try again.');
    } finally {
      setIsLoading(false);
    }
  }

  async function fetchCertificateVerification(code: string) {
    try {
      const { data, error } = await verifyCertificate(code);
      if (error) {
        setError(error);
      } else if (data) {
        setCertData(data as CertVerifyData);
      } else {
        setError('Certificate verification failed.');
      }
    } catch (err) {
      setError('Failed to verify certificate. Please try again.');
    } finally {
      setIsLoading(false);
    }
  }

  // ── Loading ──
  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <Loader2 className="w-8 h-8 animate-spin text-emerald-600" />
      </div>
    );
  }

  // ── Error ──
  if (error) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-50 p-4">
        <Card className="w-full max-w-md text-center">
          <CardContent className="pt-6">
            <XCircle className="w-16 h-16 text-red-500 mx-auto mb-4" />
            <h2 className="text-xl font-semibold mb-2">Verification Failed</h2>
            <p className="text-gray-500 mb-4">{error}</p>
            <Link to="/"><Button>Go to Home</Button></Link>
          </CardContent>
        </Card>
      </div>
    );
  }

  // ── Certificate Verification Result ──
  if (verifyMode === 'certificate' && certData) {
    const e = certData.employee;
    const issuedDate = new Date(certData.issued_at + 'Z').toLocaleDateString('en-IN', {
      day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });

    return (
      <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-gray-50 via-white to-emerald-50/30 p-4">
        <Card className="w-full max-w-md shadow-lg">
          <CardHeader className="text-center border-b pb-4">
            <div className="flex items-center justify-center gap-3 mb-3">
              <div className="w-12 h-12 bg-emerald-100 rounded-xl flex items-center justify-center">
                <Shield className="w-6 h-6 text-emerald-700" />
              </div>
              <div className="text-left">
                <p className="font-bold text-sm text-gray-800">RCS True Facilities</p>
                <p className="font-bold text-sm text-gray-800">Pvt Ltd</p>
              </div>
            </div>
            <CardTitle className="text-lg">Certificate Verification</CardTitle>
          </CardHeader>
          <CardContent className="pt-5 space-y-4">
            {/* Valid/Invalid badge */}
            <div className="flex justify-center">
              {certData.is_valid ? (
                <Badge className="bg-green-500 hover:bg-green-600 text-white px-4 py-1.5 text-sm flex items-center gap-1.5">
                  <CheckCircle className="w-4 h-4" /> Valid Certificate
                </Badge>
              ) : (
                <Badge variant="destructive" className="px-4 py-1.5 text-sm flex items-center gap-1.5">
                  <XCircle className="w-4 h-4" /> Certificate Invalid
                </Badge>
              )}
            </div>

            {/* Certificate type icon */}
            <div className="flex justify-center">
              <div className="w-14 h-14 bg-purple-100 rounded-2xl flex items-center justify-center text-purple-600">
                {CERT_TYPE_ICONS[certData.certificate_type] || <QrCode className="w-7 h-7" />}
              </div>
            </div>

            {/* Certificate type name */}
            <div className="text-center">
              <h3 className="text-lg font-bold text-gray-800">
                {CERT_TYPE_LABELS[certData.certificate_type] || certData.certificate_type}
              </h3>
              <p className="text-xs text-gray-500 mt-1">Ref: {certData.certificate_number}</p>
            </div>

            {/* Employee details */}
            <div className="bg-gray-50 rounded-xl p-4 space-y-2.5">
              <h4 className="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Employee Details</h4>

              <div className="flex items-center gap-2.5 text-sm">
                <User className="w-4 h-4 text-gray-400 flex-shrink-0" />
                <span className="text-gray-500 w-20">Name</span>
                <span className="font-semibold text-gray-800">{e.full_name || 'N/A'}</span>
              </div>

              <div className="flex items-center gap-2.5 text-sm">
                <BadgeCheck className="w-4 h-4 text-gray-400 flex-shrink-0" />
                <span className="text-gray-500 w-20">Emp Code</span>
                <span className="font-medium">{e.employee_code || 'N/A'}</span>
              </div>

              <div className="flex items-center gap-2.5 text-sm">
                <Building2 className="w-4 h-4 text-gray-400 flex-shrink-0" />
                <span className="text-gray-500 w-20">Designation</span>
                <span className="font-medium">{e.designation || 'N/A'}</span>
              </div>

              {e.department && (
                <div className="flex items-center gap-2.5 text-sm">
                  <Building2 className="w-4 h-4 text-gray-400 flex-shrink-0" />
                  <span className="text-gray-500 w-20">Department</span>
                  <span className="font-medium">{e.department}</span>
                </div>
              )}

              {e.client_name && (
                <div className="flex items-center gap-2.5 text-sm">
                  <Building2 className="w-4 h-4 text-gray-400 flex-shrink-0" />
                  <span className="text-gray-500 w-20">Client</span>
                  <span className="font-medium">{e.client_name}</span>
                </div>
              )}

              {e.unit_name && (
                <div className="flex items-center gap-2.5 text-sm">
                  <Calendar className="w-4 h-4 text-gray-400 flex-shrink-0" />
                  <span className="text-gray-500 w-20">Unit</span>
                  <span className="font-medium">{e.unit_name}</span>
                </div>
              )}

              {e.date_of_joining && (
                <div className="flex items-center gap-2.5 text-sm">
                  <Calendar className="w-4 h-4 text-gray-400 flex-shrink-0" />
                  <span className="text-gray-500 w-20">Since</span>
                  <span className="font-medium">{new Date(e.date_of_joining + 'T00:00:00').toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })}</span>
                </div>
              )}
            </div>

            {/* Issue details */}
            <div className="bg-gray-50 rounded-xl p-4 space-y-2">
              <h4 className="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Certificate Details</h4>
              <div className="flex justify-between text-sm">
                <span className="text-gray-500">Issued On</span>
                <span className="font-medium">{issuedDate}</span>
              </div>
              <div className="flex justify-between text-sm">
                <span className="text-gray-500">Status</span>
                <span className={certData.is_valid ? 'text-green-600 font-medium' : 'text-red-600 font-medium'}>
                  {certData.is_valid ? 'Active & Valid' : 'No longer valid'}
                </span>
              </div>
            </div>

            {/* Footer */}
            <div className="text-center text-xs text-gray-400 pt-2 border-t">
              <p>This certificate was issued by RCS True Facilities Pvt Ltd.</p>
              <p className="mt-1">rcsfacility@yahoo.com | 0261 2215264</p>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  // ── Employee Verification Result (existing) ──
  if (!employee) return null;

  const isVerified = employee.status === 'approved' || employee.status === 'verified' || employee.status === 'active';

  return (
    <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-gray-50 via-white to-emerald-50/30 p-4">
      <Card className="w-full max-w-md">
        <CardHeader className="text-center border-b">
          <div className="flex items-center justify-center gap-3 mb-4">
            <div className="w-12 h-12 bg-emerald-100 rounded-xl flex items-center justify-center">
              <Shield className="w-6 h-6 text-emerald-700" />
            </div>
            <div className="text-left">
              <p className="font-bold text-sm text-gray-800">RCS True Facilities</p>
              <p className="font-bold text-sm text-gray-800">Pvt Ltd</p>
            </div>
          </div>
          <CardTitle className="text-lg">Employee Verification</CardTitle>
        </CardHeader>
        <CardContent className="pt-6 space-y-4">
          <div className="flex justify-center">
            {isVerified ? (
              <Badge className="bg-green-500 hover:bg-green-600 text-white px-4 py-1 text-sm">
                <CheckCircle className="w-4 h-4 mr-1" />
                Verified Employee
              </Badge>
            ) : (
              <Badge variant="outline" className="px-4 py-1 text-sm">
                Pending Verification
              </Badge>
            )}
          </div>

          {employee.profile_pic_url && (
            <div className="flex justify-center">
              <div className="w-24 h-24 rounded-full overflow-hidden border-4 border-emerald-100">
                <img
                  src={getFileUrl(employee.profile_pic_url) || undefined}
                  alt={employee.full_name || 'Employee'}
                  className="w-full h-full object-cover"
                />
              </div>
            </div>
          )}

          <div className="space-y-3">
            <div className="text-center">
              <h3 className="text-xl font-bold">{employee.full_name}</h3>
              <p className="text-gray-500">{employee.designation || 'Staff'}</p>
            </div>

            <div className="bg-gray-50 rounded-lg p-3 space-y-2">
              <div className="flex items-center gap-2 text-sm">
                <User className="w-4 h-4 text-gray-400" />
                <span className="text-gray-500">Employee Code:</span>
                <span className="font-medium">{employee.employee_code || 'N/A'}</span>
              </div>
              <div className="flex items-center gap-2 text-sm">
                <Phone className="w-4 h-4 text-gray-400" />
                <span className="text-gray-500">Mobile:</span>
                <span className="font-medium">+91 {employee.mobile_number}</span>
              </div>
              <div className="flex items-center gap-2 text-sm">
                <Building2 className="w-4 h-4 text-gray-400" />
                <span className="text-gray-500">Client:</span>
                <span className="font-medium">{employee.client_name || '-'}</span>
              </div>
              <div className="flex items-center gap-2 text-sm">
                <Calendar className="w-4 h-4 text-gray-400" />
                <span className="text-gray-500">Location:</span>
                <span className="font-medium">{employee.unit_name || '-'}</span>
              </div>
            </div>
          </div>

          <div className="text-center text-xs text-gray-400 pt-2 border-t">
            <p>rcsfacility@yahoo.com | 0261 2215264</p>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}