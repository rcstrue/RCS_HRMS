import { RotateCcw, Trash2, Clock, MapPin } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import type { SavedDraft } from '@/hooks/useRegistrationPersistence';
import { formatTimeAgo } from '@/hooks/useRegistrationPersistence';
import { STEPS } from '@/types/registration';

interface DraftRecoveryDialogProps {
  draft: SavedDraft;
  onRestore: (draft: SavedDraft) => void;
  onDiscard: () => void;
}

export function DraftRecoveryDialog({ draft, onRestore, onDiscard }: DraftRecoveryDialogProps) {
  const stepInfo = STEPS.find(s => s.id === draft.currentStep);
  const timeAgo = formatTimeAgo(draft.savedAt);

  // Build a summary of what was filled
  const filledFields: string[] = [];
  if (draft.data.aadhaarDetails.fullName) filledFields.push(draft.data.aadhaarDetails.fullName);
  if (draft.data.aadhaarDetails.aadhaarNumber) filledFields.push(`Aadhaar: ${draft.data.aadhaarDetails.aadhaarNumber.slice(0, 4)}-XXXX-XXXX`);
  if (draft.data.bankDetails.bankName) filledFields.push(draft.data.bankDetails.bankName);
  if (draft.data.clientUnitInfo.clientName) filledFields.push(draft.data.clientUnitInfo.clientName);

  return (
    <Dialog open={true} onOpenChange={() => { /* prevent closing by clicking outside */ }}>
      <DialogContent className="sm:max-w-md" onPointerDownOutside={(e) => e.preventDefault()} onInteractOutside={(e) => e.preventDefault()}>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <RotateCcw className="w-5 h-5 text-primary" />
            Resume Registration?
          </DialogTitle>
          <DialogDescription asChild>
            <div className="space-y-3 pt-1">
              <p className="text-muted-foreground">
                You have an unsaved registration form. Would you like to continue where you left off?
              </p>

              {/* Draft info card */}
              <div className="bg-muted/50 rounded-lg p-3 space-y-2 border">
                <div className="flex items-center gap-2 text-sm">
                  <Clock className="w-3.5 h-3.5 text-muted-foreground" />
                  <span className="text-muted-foreground">Saved</span>
                  <span className="font-medium">{timeAgo}</span>
                </div>
                <div className="flex items-center gap-2 text-sm">
                  <MapPin className="w-3.5 h-3.5 text-muted-foreground" />
                  <span className="text-muted-foreground">Step</span>
                  <span className="font-medium">
                    {draft.currentStep} of 8 — {stepInfo?.title || 'Unknown'}
                  </span>
                </div>
                {filledFields.length > 0 && (
                  <div className="text-xs text-muted-foreground pt-1 border-t mt-2">
                    <span className="font-medium">Filled: </span>
                    {filledFields.join(' • ')}
                  </div>
                )}
              </div>

              <p className="text-xs text-muted-foreground">
                Drafts are automatically deleted after 24 hours.
              </p>
            </div>
          </DialogDescription>
        </DialogHeader>

        <DialogFooter className="flex-col sm:flex-col gap-2 pt-2">
          <Button
            onClick={() => onRestore(draft)}
            className="w-full h-11"
          >
            <RotateCcw className="w-4 h-4 mr-2" />
            Continue from Step {draft.currentStep}
          </Button>
          <Button
            variant="outline"
            onClick={onDiscard}
            className="w-full h-11 text-destructive hover:text-destructive"
          >
            <Trash2 className="w-4 h-4 mr-2" />
            Start Fresh
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
