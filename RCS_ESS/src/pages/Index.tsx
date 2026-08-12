import { useState, useEffect, useRef } from 'react';
import { useEmployeeSession } from '@/hooks/useEmployeeSession';
import { MobileEntry } from '@/components/registration/MobileEntry';
import { GoToESS } from '@/components/registration/GoToESS';
import { RegistrationWizard } from '@/components/registration/RegistrationWizard';
import { loginByBirthYear } from '@/lib/api/employees';
import { Loader2, ArrowLeft, Calendar } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

// LocalStorage keys for form persistence
const REGISTRATION_DATA_KEY = 'registration_form_data';
const REGISTRATION_MOBILE_KEY = 'registration_mobile';
const REGISTRATION_STEP_KEY = 'registration_current_step';

type AppView = 'loading' | 'mobile-entry' | 'registration' | 'birth-year-login' | 'go-to-ess';

const Index = () => {
  const {
    employee,
    setEmployee,
    isLoading,
    login,
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

  // 🔥 Check for saved registration progress on mount
  useEffect(() => {
    if (isLoading) return; // Wait for employee session to load

    const savedData = localStorage.getItem(REGISTRATION_DATA_KEY);
    const savedMobile = localStorage.getItem(REGISTRATION_MOBILE_KEY);

    // IF localStorage has data → open form directly
    if (savedData && savedMobile) {
      setRegistrationMobile(savedMobile);
      setView('registration');
    } else {
      setView('mobile-entry');
    }
  }, [isLoading]);

  // Show loading while checking session
  if (isLoading && view === 'loading') {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background">
        <Loader2 className="w-8 h-8 animate-spin text-primary" />
      </div>
    );
  }

  const handleMobileSubmit = (mobile: string, profilePicUrl?: string) => {
    // 📌 Save mobile to localStorage for persistence
    localStorage.setItem(REGISTRATION_MOBILE_KEY, mobile);

    setRegistrationMobile(mobile);
    setRegistrationProfilePic(profilePicUrl);
    setView('registration');
  };

  const handleLoginSubmit = async (mobile: string, dob: string) => {
    const result = await login(mobile, dob);
    if (result.success) {
      setPostRegistrationMobile(mobile);
      setView('birth-year-login');
    }
    return result;
  };

  const handleRegistrationComplete = () => {
    // ✅ Clear all saved registration data after final submit
    localStorage.removeItem(REGISTRATION_DATA_KEY);
    localStorage.removeItem(REGISTRATION_MOBILE_KEY);
    localStorage.removeItem(REGISTRATION_STEP_KEY);
    localStorage.removeItem('registration_completed_steps');
    localStorage.removeItem('registration_profile_pic');

    // Store the mobile from registration for birth year verification
    setPostRegistrationMobile(registrationMobile);
    setView('birth-year-login');
    setRegistrationProfilePic(undefined);
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
  };

  // Clear saved registration data helper
  const clearRegistrationData = () => {
    localStorage.removeItem(REGISTRATION_DATA_KEY);
    localStorage.removeItem(REGISTRATION_MOBILE_KEY);
    localStorage.removeItem(REGISTRATION_STEP_KEY);
    localStorage.removeItem('registration_completed_steps');
    localStorage.removeItem('registration_profile_pic');
  };

  switch (view) {
    case 'mobile-entry':
      return (
        <MobileEntry
          onMobileSubmit={handleMobileSubmit}
          onLoginSubmit={handleLoginSubmit}
          checkMobileExists={checkMobileExists}
        />
      );

    case 'registration':
      return (
        <RegistrationWizard
          initialMobile={registrationMobile}
          initialProfilePic={registrationProfilePic}
          existingEmployeeId={employee?.id}
          existingEmployee={employee || null}
          onComplete={handleRegistrationComplete}
          onBack={() => {
            clearRegistrationData();
            setView('mobile-entry');
            setRegistrationMobile('');
            setRegistrationProfilePic(undefined);
          }}
        />
      );

    case 'birth-year-login':
      return (
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
      );

    case 'go-to-ess':
      if (!employee) {
        setView('mobile-entry');
        return null;
      }
      return (
        <GoToESS
          employee={{
            full_name: employee.full_name,
            employee_code: employee.employee_code,
          }}
        />
      );

    default:
      return null;
  }
};

export default Index;
