'use client';

import { useState, useEffect } from 'react';
import { toast } from 'sonner';
import { fetchCertificateList, generateCertificate, fetchEmployeeById, type CertificateInfo } from '@/lib/ess-api';
import { generateCertificatePDF, getCertificateFileName } from '@/lib/pdf/generateCertificatePDF';
import { generateEmployeeRegistrationForm } from '@/lib/pdf/generateEmployeeRegistrationForm';
import type { CertificateData } from '@/lib/ess-api';
import type { Employee } from '@/lib/ess-types';

import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Loader2, FileText, Award, BadgeCheck, Download, ShieldCheck, FileDown } from 'lucide-react';
import { logger } from "@/lib/logger";

// ── Certificate card config ───────────────────────────────────

const CERT_ICONS: Record<string, React.ReactNode> = {
  appointment: <FileText className="w-8 h-8" />,
  salary: <BadgeCheck className="w-8 h-8" />,
  experience: <Award className="w-8 h-8" />,
};

const CERT_COLORS: Record<string, string> = {
  appointment: 'text-blue-600 bg-blue-50',
  salary: 'text-emerald-600 bg-emerald-50',
  experience: 'text-purple-600 bg-purple-50',
};

// ── Component ─────────────────────────────────────────────────

interface Props {
  employeeId: number;
  employeeName: string;
}

export default function CertificatesPage({ employeeId, employeeName }: Props) {
  const [certificates, setCertificates] = useState<CertificateInfo[]>([]);
  const [loading, setLoading] = useState(true);
  const [generating, setGenerating] = useState<string | null>(null); // type being generated
  const [regLoading, setRegLoading] = useState(false);

  useEffect(() => {
    fetchCertificateList().then(({ data, error }) => {
      if (error) { toast.error(error); setLoading(false); return; }
      setCertificates(data?.certificates ?? []);
      setLoading(false);
    });
  }, []);

  const handleDownloadRegistration = async () => {
    setRegLoading(true);
    try {
      const { data, error } = await fetchEmployeeById(employeeId);
      if (error) { toast.error(error); return; }
      if (!data) { toast.error('Failed to fetch employee data'); return; }
      generateEmployeeRegistrationForm(data as Employee);
      toast.success('Registration form opened! Use the download button to save as PDF.');
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : String(err);
      if (msg.includes('Pop-up') || msg.includes('popup')) {
        toast.error('Please allow pop-ups in your browser to download the registration form.');
      } else {
        toast.error('Failed to open registration form. Please try again.');
      }
    } finally {
      setRegLoading(false);
    }
  };

  const handleDownload = async (type: string) => {
    setGenerating(type);
    try {
      const { data, error } = await generateCertificate(type);
      if (error) { toast.error(error); return; }
      if (!data) { toast.error('Failed to generate certificate'); return; }

      await generateCertificatePDF(data as CertificateData);
      toast.success(`${data.certificate_type === 'appointment' ? 'Appointment Letter' : data.certificate_type === 'salary' ? 'Salary Certificate' : 'Experience Certificate'} generated!`);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : String(err);
      if (msg.includes('Pop-up')) {
        toast.error('Please allow pop-ups in your browser to download certificates.');
      } else {
        toast.error('Failed to generate PDF. Please try again.');
        logger.error('Certificate generation error:', err);
      }
    } finally {
      setGenerating(null);
    }
  };

  // ── Loading state ──
  if (loading) {
    return (
      <div className="flex items-center justify-center py-20">
        <Loader2 className="w-8 h-8 animate-spin text-gray-400" />
      </div>
    );
  }

  return (
    <div className="px-4 pt-4 pb-24 space-y-3">
      {/* Header */}
      <div className="mb-2">
        <h1 className="text-lg font-bold text-gray-800">My Certificates</h1>
        <p className="text-xs text-gray-500 mt-0.5">Download official documents issued by the company</p>
      </div>

      {/* Registration Form Card — always available */}
      <Card className="border border-gray-200 overflow-hidden">
        <CardContent className="p-4">
          <div className="flex items-start gap-3">
            <div className="flex-shrink-0 w-14 h-14 rounded-xl flex items-center justify-center bg-purple-50 text-purple-600">
              <FileDown className="w-8 h-8" />
            </div>
            <div className="flex-1 min-w-0">
              <h3 className="text-sm font-semibold text-gray-800">Registration Form</h3>
              <p className="text-xs text-gray-500 mt-0.5 leading-relaxed">
                Download your complete employee registration form with KYC &amp; bank documents
              </p>
              <div className="mt-2">
                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-green-50 text-green-700">
                  <span className="w-1.5 h-1.5 rounded-full bg-green-500" /> Available
                </span>
              </div>
            </div>
            <div className="flex-shrink-0">
              <Button
                size="sm"
                className="bg-purple-600 hover:bg-purple-700 text-white h-9 px-3"
                onClick={handleDownloadRegistration}
                disabled={regLoading}
              >
                {regLoading ? (
                  <><Loader2 className="w-4 h-4 animate-spin mr-1" /> Loading</>
                ) : (
                  <><Download className="w-4 h-4 mr-1" /> PDF</>
                )}
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Certificate Cards */}
      {certificates.length === 0 ? (
        <div className="px-2 py-6 text-center">
          <ShieldCheck className="w-10 h-10 text-gray-300 mx-auto mb-2" />
          <p className="text-xs text-gray-400">No company certificates available. Certificates are shown for active employees.</p>
        </div>
      ) : certificates.map((cert) => {
        const isGenerating = generating === cert.type;
        const colors = CERT_COLORS[cert.type] || 'text-gray-600 bg-gray-50';

        return (
          <Card key={cert.type} className="border border-gray-200 overflow-hidden">
            <CardContent className="p-4">
              <div className="flex items-start gap-3">
                {/* Icon */}
                <div className={`flex-shrink-0 w-14 h-14 rounded-xl flex items-center justify-center ${colors}`}>
                  {CERT_ICONS[cert.type]}
                </div>

                {/* Details */}
                <div className="flex-1 min-w-0">
                  <h3 className="text-sm font-semibold text-gray-800">{cert.name}</h3>
                  <p className="text-xs text-gray-500 mt-0.5 leading-relaxed">{cert.description}</p>

                  {/* Status badge */}
                  <div className="mt-2">
                    <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium
                      ${cert.available
                        ? 'bg-green-50 text-green-700'
                        : 'bg-gray-100 text-gray-500'
                      }`}>
                      {cert.available ? (
                        <><span className="w-1.5 h-1.5 rounded-full bg-green-500" /> Available</>
                      ) : (
                        'Unavailable'
                      )}
                    </span>
                  </div>
                </div>

                {/* Download Button */}
                <div className="flex-shrink-0">
                  <Button
                    size="sm"
                    className="bg-emerald-600 hover:bg-emerald-700 text-white h-9 px-3"
                    onClick={() => handleDownload(cert.type)}
                    disabled={!cert.available || !!generating}
                  >
                    {isGenerating ? (
                      <><Loader2 className="w-4 h-4 animate-spin mr-1" /> Loading</>
                    ) : (
                      <><Download className="w-4 h-4 mr-1" /> PDF</>
                    )}
                  </Button>
                </div>
              </div>
            </CardContent>
          </Card>
        );
      })}

      {/* Security notice */}
      <div className="flex items-start gap-2 p-3 bg-amber-50 rounded-lg mt-2">
        <ShieldCheck className="w-4 h-4 text-amber-600 flex-shrink-0 mt-0.5" />
        <p className="text-[11px] text-amber-700 leading-relaxed">
          Each certificate includes a unique QR code for online verification. Certificates are signed digitally and can be verified by scanning the QR code or visiting the verification link.
        </p>
      </div>
    </div>
  );
}