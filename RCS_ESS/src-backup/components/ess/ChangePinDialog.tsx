'use client';

import { useState, useRef, useEffect, useCallback } from 'react';
import { toast } from 'sonner';
import { changePin } from '@/lib/ess-api';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import { Shield, KeyRound, CheckCircle2, Loader2 } from 'lucide-react';

// ══════════════════════════════════════════════════════════════
// PinInputBoxes — auto-advance, backspace, auto-submit
// ══════════════════════════════════════════════════════════════

function PinInputBoxes({
  step,
  pins,
  setPins,
  onComplete,
}: {
  step: 'current' | 'new' | 'confirm';
  pins: string[];
  setPins: (p: string[]) => void;
  onComplete?: () => void;
}) {
  const refs = useRef<(HTMLInputElement | null)[]>([]);

  // Auto-focus first box when step changes
  useEffect(() => {
    const timer = setTimeout(() => refs.current[0]?.focus(), 80);
    return () => clearTimeout(timer);
  }, [step]);

  const fullPin = pins.join('');

  const handleChange = (index: number, value: string) => {
    // Handle paste / multi-digit input
    if (value.length > 1) {
      const digits = value.replace(/\D/g, '').slice(0, 4 - index);
      const updated = [...pins];
      digits.split('').forEach((d, i) => {
        if (index + i < 4) updated[index + i] = d;
      });
      setPins(updated);
      const nextEmpty = updated.findIndex((p, i) => i > index && p === '');
      if (nextEmpty !== -1) refs.current[nextEmpty]?.focus();
      else refs.current[3]?.focus();

      // If 4 digits entered via paste, auto-advance
      if (updated.every(p => p !== '') && onComplete) {
        setTimeout(onComplete, 150);
      }
      return;
    }

    if (!/^\d*$/.test(value)) return;

    const updated = [...pins];
    updated[index] = value;
    setPins(updated);

    // Auto-focus next box
    if (value && index < 3) {
      refs.current[index + 1]?.focus();
    }

    // Auto-advance when 4th digit entered
    if (value && index === 3 && onComplete) {
      setTimeout(onComplete, 150);
    }
  };

  const handleKeyDown = (index: number, e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Backspace' && !pins[index] && index > 0) {
      refs.current[index - 1]?.focus();
      const updated = [...pins];
      updated[index - 1] = '';
      setPins(updated);
    }
    if (e.key === 'Enter') {
      e.preventDefault();
      onComplete?.();
    }
  };

  return (
    <div className="flex items-center justify-center gap-3 py-4">
      {pins.map((digit, i) => (
        <input
          key={`${step}-${i}`}
          ref={(el) => { refs.current[i] = el; }}
          type="tel"
          inputMode="numeric"
          maxLength={4}
          value={digit}
          onChange={(e) => handleChange(i, e.target.value)}
          onKeyDown={(e) => handleKeyDown(i, e)}
          onFocus={(e) => e.target.select()}
          className={`w-14 h-14 text-center text-2xl font-bold rounded-xl border-2 transition-all focus:outline-none focus:ring-0 ${
            digit
              ? 'border-emerald-500 bg-emerald-50 text-emerald-700'
              : 'border-emerald-300 bg-white text-gray-900 focus:border-emerald-500'
          }`}
        />
      ))}
    </div>
  );
}

// ══════════════════════════════════════════════════════════════
// ChangePinDialog
// ══════════════════════════════════════════════════════════════

interface ChangePinDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  employeeId: string | number;
}

export function ChangePinDialog({ open, onOpenChange, employeeId }: ChangePinDialogProps) {
  const [step, setStep] = useState<'current' | 'new' | 'confirm'>('current');
  const [currentPin, setCurrentPin] = useState(['', '', '', '']);
  const [newPin, setNewPin] = useState(['', '', '', '']);
  const [confirmPin, setConfirmPin] = useState(['', '', '', '']);
  const [loading, setLoading] = useState(false);

  const resetAndClose = () => {
    setStep('current');
    setCurrentPin(['', '', '', '']);
    setNewPin(['', '', '', '']);
    setConfirmPin(['', '', '', '']);
    setLoading(false);
    onOpenChange(false);
  };

  const handleCurrentComplete = useCallback(() => {
    if (currentPin.join('').length !== 4) return;
    setStep('new');
  }, [currentPin]);

  const handleNewComplete = useCallback(() => {
    const full = newPin.join('');
    if (full.length !== 4) return;
    if (full === currentPin.join('')) {
      toast.error('New PIN must be different from current PIN');
      setNewPin(['', '', '', '']);
      return;
    }
    setStep('confirm');
  }, [newPin, currentPin]);

  const handleConfirmComplete = useCallback(async () => {
    const confirmFull = confirmPin.join('');
    const newFull = newPin.join('');
    if (confirmFull.length !== 4) return;

    if (confirmFull !== newFull) {
      toast.error('PINs do not match. Please try again.');
      setConfirmPin(['', '', '', '']);
      setStep('new');
      return;
    }

    setLoading(true);
    try {
      const { error } = await changePin(employeeId, currentPin.join(''), newFull);
      if (error) {
        toast.error(error);
        return;
      }
      toast.success('PIN changed successfully!');
      resetAndClose();
    } catch {
      toast.error('Failed to change PIN. Please try again.');
    } finally {
      setLoading(false);
    }
  }, [confirmPin, newPin, currentPin, employeeId]); // eslint-disable-line react-hooks/exhaustive-deps

  const handleBack = () => {
    if (step === 'confirm') { setConfirmPin(['', '', '', '']); setStep('new'); }
    else if (step === 'new') { setNewPin(['', '', '', '']); setStep('current'); }
  };

  const stepTitles = { current: 'Enter Current PIN', new: 'Enter New PIN', confirm: 'Confirm New PIN' };
  const stepIcons = { current: Shield, new: KeyRound, confirm: CheckCircle2 };
  const StepIcon = stepIcons[step];

  // Reset pins when dialog opens
  useEffect(() => {
    if (open) {
      setStep('current');
      setCurrentPin(['', '', '', '']);
      setNewPin(['', '', '', '']);
      setConfirmPin(['', '', '', '']);
    }
  }, [open]);

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) resetAndClose(); }}>
      <DialogContent className="sm:max-w-sm">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <StepIcon className="w-5 h-5 text-emerald-600" />
            {stepTitles[step]}
          </DialogTitle>
          <DialogDescription>
            {step === 'current' && 'Verify your current PIN first'}
            {step === 'new' && 'Choose a new 4-digit PIN'}
            {step === 'confirm' && 'Re-enter your new PIN to confirm'}
          </DialogDescription>
        </DialogHeader>

        {step === 'current' && (
          <PinInputBoxes
            step="current"
            pins={currentPin}
            setPins={setCurrentPin}
            onComplete={handleCurrentComplete}
          />
        )}
        {step === 'new' && (
          <PinInputBoxes
            step="new"
            pins={newPin}
            setPins={setNewPin}
            onComplete={handleNewComplete}
          />
        )}
        {step === 'confirm' && (
          <PinInputBoxes
            step="confirm"
            pins={confirmPin}
            setPins={setConfirmPin}
            onComplete={handleConfirmComplete}
          />
        )}

        <div className="flex items-center justify-center gap-2 mb-2">
          {(['current', 'new', 'confirm'] as const).map((s) => (
            <div
              key={s}
              className={`h-1.5 rounded-full transition-all ${
                s === step ? 'w-8 bg-emerald-500' :
                ['current', 'new', 'confirm'].indexOf(s) < ['current', 'new', 'confirm'].indexOf(step)
                  ? 'w-8 bg-emerald-300'
                  : 'w-8 bg-gray-200'
              }`}
            />
          ))}
        </div>

        <DialogFooter className="gap-2 sm:gap-0">
          {step !== 'current' && (
            <Button variant="outline" onClick={handleBack} disabled={loading}>Back</Button>
          )}
          <Button
            onClick={
              step === 'current' ? handleCurrentComplete :
              step === 'new' ? handleNewComplete :
              handleConfirmComplete
            }
            disabled={
              loading ||
              (step === 'current' && currentPin.join('').length !== 4) ||
              (step === 'new' && newPin.join('').length !== 4) ||
              (step === 'confirm' && confirmPin.join('').length !== 4)
            }
            className="gap-2"
          >
            {loading ? (
              <><Loader2 className="w-4 h-4 animate-spin" /> Verifying...</>
            ) : step === 'confirm' ? (
              'Change PIN'
            ) : (
              'Next'
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}