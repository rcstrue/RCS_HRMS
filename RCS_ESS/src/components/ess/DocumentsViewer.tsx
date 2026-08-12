'use client';

import { useState } from 'react';
import type { Employee } from '@/lib/ess-types';
import { getFileUrl } from '@/lib/api/config';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { ImageOff, ExternalLink } from 'lucide-react';

// ══════════════════════════════════════════════════════════════
// DocumentsViewer — Grid of uploaded documents with preview
// ══════════════════════════════════════════════════════════════

interface DocumentsViewerProps {
  employee: Employee;
}

const DOCUMENTS = [
  { key: 'profile_pic_url' as const, label: 'Profile Photo' },
  { key: 'aadhaar_front_url' as const, label: 'Aadhaar (Front)' },
  { key: 'aadhaar_back_url' as const, label: 'Aadhaar (Back)' },
  { key: 'bank_document_url' as const, label: 'Bank Document' },
];

export default function DocumentsViewer({ employee }: DocumentsViewerProps) {
  const [previewDoc, setPreviewDoc] = useState<{ url: string; label: string } | null>(null);

  const docs = DOCUMENTS.map(doc => ({
    ...doc,
    url: employee[doc.key],
  }));

  return (
    <Card className="border-0 shadow-sm">
      <CardContent className="p-4">
        <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">
          Documents
        </h3>

        <div className="grid grid-cols-2 gap-3">
          {docs.map((doc) => {
            const fullUrl = getFileUrl(doc.url);
            const hasDoc = !!fullUrl;

            return (
              <div
                key={doc.key}
                className={`relative rounded-lg overflow-hidden border h-28 flex items-center justify-center cursor-pointer transition-colors ${
                  hasDoc
                    ? 'border-gray-200 hover:border-emerald-300 bg-gray-50'
                    : 'border-dashed border-gray-300 bg-gray-50'
                }`}
                onClick={() => {
                  if (fullUrl) setPreviewDoc({ url: fullUrl, label: doc.label });
                }}
              >
                {hasDoc ? (
                  <>
                    <img
                      src={fullUrl}
                      alt={doc.label}
                      className="w-full h-full object-cover"
                      onError={(e) => {
                        e.currentTarget.style.display = 'none';
                        const parent = e.currentTarget.parentElement;
                        if (parent) {
                          parent.innerHTML = `<div class="flex flex-col items-center gap-1 text-gray-400"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg><span class="text-[10px]">Not available</span></div>`;
                        }
                      }}
                    />
                    <div className="absolute bottom-0 left-0 right-0 bg-black/50 text-white text-[10px] px-2 py-1 truncate">
                      {doc.label}
                    </div>
                  </>
                ) : (
                  <div className="flex flex-col items-center gap-1.5 text-gray-400">
                    <ImageOff className="w-5 h-5" />
                    <span className="text-[10px] text-center px-2">{doc.label}<br />Not uploaded</span>
                  </div>
                )}
              </div>
            );
          })}
        </div>

        {/* Preview Dialog */}
        <Dialog open={!!previewDoc} onOpenChange={() => setPreviewDoc(null)}>
          <DialogContent className="max-w-3xl">
            <DialogHeader>
              <DialogTitle>{previewDoc?.label || 'Document Preview'}</DialogTitle>
            </DialogHeader>
            {previewDoc && (
              <div className="relative">
                <img
                  src={previewDoc.url}
                  alt={previewDoc.label}
                  className="w-full h-auto max-h-[70vh] object-contain rounded-lg"
                />
                <Button
                  variant="outline"
                  size="sm"
                  className="absolute top-2 right-2"
                  onClick={() => window.open(previewDoc.url, '_blank')}
                >
                  <ExternalLink className="w-4 h-4 mr-1" />
                  Open Full
                </Button>
              </div>
            )}
          </DialogContent>
        </Dialog>
      </CardContent>
    </Card>
  );
}
