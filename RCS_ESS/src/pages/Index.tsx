import { useState, useEffect, useRef } from 'react';
import { useEmployeeSession } from '@/hooks/useEmployeeSession';
import { MobileEntry } from '@/components/registration/MobileEntry';
import { GoToESS } from '@/components/registration/GoToESS';
import { RegistrationWizard } from '@/components/registration/RegistrationWizard';
import { DraftRecoveryDialog } from '@/components/registration/DraftRecoveryDialog';
import { loginByBirthYear } from '@/lib/api/employees';
import { getSavedDraft, clearSavedDraft } from '@/hooks/useRegistrationPersistence';
import type { SavedDraft } from '@/hooks/useRegistrationPersistence';
import type { RegistrationData, RegistrationStep } from '@/types/registration';
import { Loader2, ArrowLeft, Calendar } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type AppView = 'loading' | 'mobile-entry' | 'registration' | 'birth-year-login' | 'go-to-ess';

const Index = () => {
  const {
    employee,
    setEmployee,
    isLoading,
    checkMobileExists,
  } = useEmployeeSession();

  const [view, setView] = useState<AppView>('loading');
  const [registrationMobile, setRegistrationMobile] = useState('');
  const [registrationProfilePic, setRegistrationProfilePic] = useState<string | undefined>();
  const [postRegistrationMobile, setPostRegistrationMobile] = useState('');

  // Birth year login state
  const [birthYear, setBirthYear] = useState('');
  const [birthYearError, setBirthYearError] = useState('');
  const [isBirthYearLoading, setIsBirthYearLoading] = useState(false);
  const birthYearRef = useRef<HTMLInputElement>(null);

  // Draft recovery state
  const [savedDraft, setSavedDraft] = useState<SavedDraft | null>(null);
  const [showDraftDialog, setShowDraftDialog] = useState(false);

  // Restored draft data to pass to RegistrationWizard
  const [restoredData, setRestoredData] = useState<RegistrationData | null>(null);
  const [restoredStep, setRestoredStep] = useState<RegistrationStep | null>(null);
  const [restoredCompletedSteps, setRestoredCompletedSteps] = useState<number[] | null>(null);

  // On mount, check for saved registration progress
  useEffect(() => {
    if (isLoading) return;

    // Check if there's a recoverable draft
    const draft = getSavedDraft();
    if (draft) {
      setSavedDraft(draft);
      setShowDraftDialog(true);
    } else {
      setView('mobile-entry');
    }
  }, [isLoading]);

  // Handle draft restoration
  const handleRestoreDraft = (draft: SavedDraft) => {
    setShowDraftDialog(false);
    setRestoredData(draft.data);
    setRestoredStep(draft.currentStep);
    setRestoredCompletedSteps(draft.completedSteps);
    setRegistrationMobile(draft.mobile);
    setRegistrationProfilePic(draft.profilePic);
    setSavedDraft(null);
    setView('registration');
  };

  // Handle draft discard
  const handleDiscardDraft = () => {
    setShowDraftDialog(false);
    clearSavedDraft();
    setSavedDraft(null);
    setRestoredData(null);
    setRestoredStep(null);
    setRestoredCompletedSteps(null);
    // Also clear stale employee session when discarding a draft
    localStorage.removeItem('employee_id');
    setEmployee(null);
    setView('mobile-entry');
  };

  // Show loading while checking session
  if (isLoading && view === 'loading') {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background">
        <Loader2 className="w-8 h-8 animate-spin text-primary" />
      </div>
    );
  }

  const handleMobileSubmit = (mobile: string, profilePicUrl?: string) => {
    // Clear any leftover draft data before starting fresh
    clearSavedDraft();

    // Clear stale employee session — prevents Employee A's data from
    // leaking into Employee B's registration when the same device is used
    localStorage.removeItem('employee_id');
    setEmployee(null);

    setRegistrationMobile(mobile);
    setRegistrationProfilePic(profilePicUrl);
    // Clear any restored draft props since this is a fresh start
    setRestoredData(null);
    setRestoredStep(null);
    setRestoredCompletedSteps(null);
    setView('registration');
  };

  const handleRegistrationComplete = () => {
    // Clear all saved registration data after final submit
    clearSavedDraft();

    // Clear the employee cache — the registration session is over.
    // The birth-year verification step will re-set employee_id only
    // after the user successfully verifies with their birth year.
    localStorage.removeItem('employee_id');
    setEmployee(null);

    // Store the mobile from registration for birth year verification
    setPostRegistrationMobile(registrationMobile);
    setView('birth-year-login');
    setRegistrationProfilePic(undefined);
    // Clear restored draft props
    setRestoredData(null);
    setRestoredStep(null);
    setRestoredCompletedSteps(null);
  };

  const handleBirthYearChange = (value: string) => {
    const cleaned = value.replace(/\D/g, '').slice(0, 4);
    setBirthYear(cleaned);
    setBirthYearError('');
    if (cleaned.length === 4) {
      submitBirthYear(cleaned);
    }
  };

  const submitBirthYear = async (year: string) => {
    // Validate reasonable year range
    const yearNum = parseInt(year);
    if (yearNum < 1950 || yearNum > 2010) {
      setBirthYearError('Please enter a valid birth year (1950-2010)');
      setBirthYear('');
      birthYearRef.current?.focus();
      return;
    }

    setIsBirthYearLoading(true);
    setBirthYearError('');

    const result = await loginByBirthYear(postRegistrationMobile, year);

    if (result.error || !result.data?.success || !result.data.employee) {
      setBirthYearError(result.error || result.data?.error || 'Verification failed. Please try again.');
      setBirthYear('');
      setIsBirthYearLoading(false);
      birthYearRef.current?.focus();
      return;
    }

    // Success — set employee and go to ESS
    const emp = result.data.employee as unknown as Parameters<typeof setEmployee>[0];
    setEmployee(emp);
    localStorage.setItem('employee_id', String(result.data.employee.id));
    setView('go-to-ess');
    setIsBirthYearLoading(false);
  };

  const handleBackToMobile = () => {
    setView('mobile-entry');
    setBirthYear('');
    setBirthYearError('');
    setPostRegistrationMobile('');
    // Clear restored draft props
    setRestoredData(null);
    setRestoredStep(null);
    setRestoredCompletedSteps(null);
  };

  switch (view) {
    case 'mobile-entry':
      return (
        <MobileEntry
          onMobileSubmit={handleMobileSubmit}
          checkMobileExists={checkMobileExists}
        />
      );

    case 'registration':
      return (
        <>
          <RegistrationWizard
            initialMobile={registrationMobile}
            initialProfilePic={registrationProfilePic}
            existingEmployeeId={employee?.id}
            existingEmployee={employee || null}
            onComplete={handleRegistrationComplete}
            onBack={() => {
              clearSavedDraft();
              // Clear stale employee session when going back to mobile entry
              localStorage.removeItem('employee_id');
              setEmployee(null);
              handleBackToMobile();
              setRegistrationMobile('');
              setRegistrationProfilePic(undefined);
            }}
            restoredData={restoredData}
            restoredStep={restoredStep}
            restoredCompletedSteps={restoredCompletedSteps}
          />
          {/* Draft recovery dialog (shown only during initial load) */}
          {showDraftDialog && savedDraft && (
            <DraftRecoveryDialog
              draft={savedDraft}
              onRestore={handleRestoreDraft}
              onDiscard={handleDiscardDraft}
            />
          )}
        </>
      );

    case 'birth-year-login':
      return (
        <>
          <div className="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-background via-background to-primary/5">
            <div className="w-full max-w-md">
              <div className="form-section animate-slide-up">
                {/* Header */}
                <div className="text-center mb-8">
                  <div className="w-16 h-16 rounded-full bg-primary/10 flex items-center justify-center mx-auto mb-4">
                    <Calendar className="w-8 h-8 text-primary" />
                  </div>
                  <h1 className="text-2xl font-bold text-foreground mb-2">
                    Verify with Birth Year
                  </h1>
                  <p className="text-muted-foreground">
                    Enter your year of birth to confirm your identity
                  </p>
                </div>

                {/* Mobile display */}
                <div className="p-4 bg-muted rounded-lg mb-6">
                  <p className="text-sm text-muted-foreground">Mobile Number</p>
                  <p className="text-lg font-medium">+91 {postRegistrationMobile}</p>
                </div>

                {/* Year input */}
                <div className="space-y-2 mb-6">
                  <Label htmlFor="birth-year">Birth Year (YYYY)</Label>
                  <Input
                    ref={birthYearRef}
                    id="birth-year"
                    type="tel"
                    inputMode="numeric"
                    maxLength={4}
                    placeholder="e.g. 1995"
                    value={birthYear}
                    onChange={(e) => handleBirthYearChange(e.target.value)}
                    onFocus={(e) => e.target.select()}
                    className="h-14 text-center text-2xl font-mono tracking-widest"
                    disabled={isBirthYearLoading}
                  />
                </div>

                {/* Error */}
                {birthYearError && (
                  <p className="text-sm text-destructive text-center mb-4">{birthYearError}</p>
                )}

                {/* Loading */}
                {isBirthYearLoading && (
                  <div className="flex items-center justify-center gap-2 mb-4 text-sm text-muted-foreground">
                    <Loader2 className="w-4 h-4 animate-spin" />
                    Verifying...
                  </div>
                )}

                {/* Back button */}
                <Button
                  variant="outline"
                  onClick={handleBackToMobile}
                  className="w-full h-12"
                >
                  <ArrowLeft className="w-4 h-4 mr-2" />
                  Back
                </Button>

                <p className="text-xs text-center text-muted-foreground mt-4">
                  This is a one-time verification step for security.
                </p>
              </div>
            </div>
          </div>
          {/* Draft recovery dialog (shown only during initial load) */}
          {showDraftDialog && savedDraft && (
            <DraftRecoveryDialog
              draft={savedDraft}
              onRestore={handleRestoreDraft}
              onDiscard={handleDiscardDraft}
            />
          )}
        </>
      );

    case 'go-to-ess':
      if (!employee) {
        setView('mobile-entry');
        return null;
      }
      return (
        <>
          <GoToESS
            employee={{
              full_name: employee.full_name,
              employee_code: employee.employee_code,
            }}
          />
          {/* Draft recovery dialog (shown only during initial load) */}
          {showDraftDialog && savedDraft && (
            <DraftRecoveryDialog
              draft={savedDraft}
              onRestore={handleRestoreDraft}
              onDiscard={handleDiscardDraft}
            />
          )}
        </>
      );

    default:
      // Show draft dialog even during loading state if we have one
      if (showDraftDialog && savedDraft) {
        return (
          <DraftRecoveryDialog
            draft={savedDraft}
            onRestore={handleRestoreDraft}
            onDiscard={handleDiscardDraft}
          />
        );
      }
      return null;
  }
};

export default Index;
