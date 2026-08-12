import { useState } from 'react';
import { Phone, ArrowRight, Loader2, Camera, LogIn, UserPlus, AlertCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ProfilePhotoCapture } from '@/components/registration/ProfilePhotoCapture';
import { uploadBase64Image } from '@/lib/api/config';
import { getEmployeeByMobile } from '@/lib/api/employees';
import { toast } from 'sonner';
import { logger } from "@/lib/logger";

interface AlreadyRegisteredEmployee {
  full_name: string | null;
  employee_code: number | string | null;
}

interface MobileEntryProps {
  onMobileSubmit: (mobile: string, profilePicUrl?: string) => void;
  checkMobileExists: (mobile: string) => Promise<boolean>;
}

export function MobileEntry({ onMobileSubmit, checkMobileExists }: MobileEntryProps) {
  const [mobileNumber, setMobileNumber] = useState('');
  const [isChecking, setIsChecking] = useState(false);
  const [showProfileCapture, setShowProfileCapture] = useState(false);
  const [profilePicUrl, setProfilePicUrl] = useState<string | null>(null);
  const [isUploadingProfile, setIsUploadingProfile] = useState(false);
  const [error, setError] = useState('');
  const [mobileError, setMobileError] = useState('');

  // Already registered state
  const [showAlreadyRegistered, setShowAlreadyRegistered] = useState(false);
  const [alreadyRegisteredEmployee, setAlreadyRegisteredEmployee] = useState<AlreadyRegisteredEmployee | null>(null);
  const [isLoadingEmployee, setIsLoadingEmployee] = useState(false);

  const validateMobile = (value: string) => {
    const cleaned = value.replace(/\D/g, '').slice(0, 10);
    setMobileNumber(cleaned);

    if (cleaned.length === 10) {
      if (!/^[6-9]/.test(cleaned)) {
        setMobileError('Mobile number must start with 6, 7, 8, or 9');
        return false;
      }
      setMobileError('');
      return true;
    }
    setMobileError('');
    return false;
  };

  const handleContinue = async () => {
    if (mobileNumber.length !== 10) {
      setMobileError('Please enter a valid 10-digit mobile number');
      return;
    }

    if (!/^[6-9]/.test(mobileNumber)) {
      setMobileError('Mobile number must start with 6, 7, 8, or 9');
      return;
    }

    setIsChecking(true);
    setError('');

    try {
      const exists = await checkMobileExists(mobileNumber);

      if (exists) {
        // Fetch employee details to show name and code
        setIsLoadingEmployee(true);
        try {
          const { data } = await getEmployeeByMobile(mobileNumber);
          if (data) {
            setAlreadyRegisteredEmployee({
              full_name: data.full_name,
              employee_code: data.employee_code,
            });
          }
        } catch (_err) {
          logger.error('MobileEntry - failed to fetch existing employee:', _err);
        }
        setIsLoadingEmployee(false);
        setShowAlreadyRegistered(true);
      } else {
        // New user - show profile photo capture
        setShowProfileCapture(true);
      }
    } catch (_err) {
      // If backend is unreachable, proceed to registration
      setShowProfileCapture(true);
    } finally {
      setIsChecking(false);
    }
  };

  const handleProfileCapture = async (imageData: string) => {
    setIsUploadingProfile(true);
    try {
      const { url, error } = await uploadBase64Image(imageData, 'profile-photo.jpg', 'profile');
      if (error || !url) {
        toast.error(error || 'Upload failed. Please try again.');
        setIsUploadingProfile(false);
        return;
      }
      setProfilePicUrl(url);
      toast.success('Profile photo captured successfully.');
    } catch (err) {
      logger.error('MobileEntry - upload error:', err);
      toast.error('Upload failed. Please try again.');
    } finally {
      setIsUploadingProfile(false);
    }
  };

  const handleProfileRetake = () => {
    setProfilePicUrl(null);
  };

  const handleSkipProfile = () => {
    onMobileSubmit(mobileNumber, undefined);
  };

  const handleProceedWithProfile = () => {
    // Store in localStorage as backup
    if (profilePicUrl) {
      localStorage.setItem('registration_profile_pic', profilePicUrl);
    }
    onMobileSubmit(mobileNumber, profilePicUrl || undefined);
  };

  const handleGoToLogin = () => {
    window.location.hash = '#/ess';
  };

  const handleRegisterNewMobile = () => {
    setShowAlreadyRegistered(false);
    setAlreadyRegisteredEmployee(null);
    setMobileNumber('');
    setMobileError('');
    setError('');
  };

  // Profile Photo Capture Screen (for new users)
  if (showProfileCapture) {
    return (
      <div className="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-background via-background to-primary/5">
        <div className="w-full max-w-md">
          <div className="form-section animate-slide-up">
            <div className="text-center mb-6">
              <div className="w-16 h-16 rounded-full bg-primary/10 flex items-center justify-center mx-auto mb-4">
                <Camera className="w-8 h-8 text-primary" />
              </div>
              <h1 className="text-xl font-bold text-foreground mb-2">
                Capture Profile Photo
              </h1>
              <p className="text-sm text-muted-foreground">
                Take a clear photo for your employee profile
              </p>
            </div>

            <div className="space-y-4">
              {isUploadingProfile && (
                <div className="flex items-center justify-center gap-2 p-4 text-sm text-muted-foreground">
                  <Loader2 className="w-4 h-4 animate-spin" />
                  Uploading profile photo...
                </div>
              )}

              <ProfilePhotoCapture
                onCapture={handleProfileCapture}
                capturedImage={profilePicUrl}
                onRetake={handleProfileRetake}
              />

              <div className="p-3 bg-muted/50 rounded-lg">
                <p className="text-sm text-muted-foreground text-center">
                  Mobile: +91 {mobileNumber}
                </p>
              </div>

              {error && (
                <p className="text-sm text-destructive text-center">{error}</p>
              )}

              <div className="flex gap-3">
                <Button
                  variant="outline"
                  onClick={() => {
                    setShowProfileCapture(false);
                    setProfilePicUrl(null);
                  }}
                  className="flex-1"
                >
                  Back
                </Button>
                <Button
                  onClick={handleProceedWithProfile}
                  className="flex-1"
                  disabled={!profilePicUrl || isUploadingProfile}
                >
                  Continue
                  <ArrowRight className="w-4 h-4 ml-2" />
                </Button>
              </div>
              {!profilePicUrl && (
                <p className="text-xs text-center text-muted-foreground">
                  Please capture a profile photo to continue, or skip if unavailable
                </p>
              )}
              <Button
                variant="ghost"
                onClick={handleSkipProfile}
                className="w-full text-muted-foreground"
                size="sm"
              >
                Skip for now
              </Button>
            </div>
          </div>
        </div>
      </div>
    );
  }

  // Already Registered Screen
  if (showAlreadyRegistered) {
    const empName = alreadyRegisteredEmployee?.full_name || 'Employee';
    const empCode = alreadyRegisteredEmployee?.employee_code != null
      ? String(alreadyRegisteredEmployee.employee_code).padStart(4, '0')
      : null;

    return (
      <div className="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-amber-50 via-background to-orange-50">
        <div className="w-full max-w-md">
          <div className="form-section animate-slide-up">
            {/* Warning Icon */}
            <div className="text-center mb-6">
              <div className="w-20 h-20 mx-auto bg-amber-100 rounded-full flex items-center justify-center shadow-lg">
                <AlertCircle className="w-10 h-10 text-amber-600" />
              </div>
            </div>

            {/* Heading */}
            <h1 className="text-xl font-bold text-foreground text-center mb-1">
              This Mobile No. is Already Registered
            </h1>
            <p className="text-sm text-muted-foreground text-center mb-6">
              An employee account with this mobile number already exists.
            </p>

            {/* Employee Details Card */}
            <div className="bg-muted/70 rounded-xl p-5 mb-6 space-y-3">
              <div className="flex items-center gap-3">
                <div className="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center flex-shrink-0">
                  <span className="text-lg font-bold text-primary">
                    {empName.charAt(0).toUpperCase()}
                  </span>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground uppercase tracking-wide">Employee Name</p>
                  <p className="text-xl font-bold text-foreground">{empName}</p>
                </div>
              </div>

              {empCode && (
                <div className="border-t border-border/50 pt-3">
                  <p className="text-xs text-muted-foreground uppercase tracking-wide">Employee Code</p>
                  <p className="text-xl font-bold text-foreground font-mono">EMP-{empCode}</p>
                </div>
              )}

              <div className="border-t border-border/50 pt-3">
                <p className="text-xs text-muted-foreground uppercase tracking-wide">Mobile Number</p>
                <p className="text-lg font-medium text-foreground">+91 {mobileNumber}</p>
              </div>
            </div>

            {/* Loading state */}
            {isLoadingEmployee && (
              <div className="flex items-center justify-center gap-2 mb-4 text-sm text-muted-foreground">
                <Loader2 className="w-4 h-4 animate-spin" />
                Loading employee details...
              </div>
            )}

            {/* Action Buttons */}
            <div className="space-y-3">
              <button
                onClick={handleGoToLogin}
                className="w-full bg-primary text-primary-foreground font-semibold py-3.5 px-6 rounded-xl shadow-lg hover:bg-primary/90 transition-all active:scale-95 flex items-center justify-center gap-2"
              >
                <LogIn className="w-5 h-5" />
                Go to Login
              </button>

              <button
                onClick={handleRegisterNewMobile}
                className="w-full bg-transparent border-2 border-border text-foreground font-medium py-3.5 px-6 rounded-xl hover:bg-muted/50 transition-all active:scale-95 flex items-center justify-center gap-2"
              >
                <UserPlus className="w-5 h-5" />
                Register with New Mobile Number
              </button>
            </div>
          </div>
        </div>
      </div>
    );
  }

  // Default: Mobile Entry Screen
  return (
    <div className="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-background via-background to-primary/5">
      <div className="w-full max-w-md">
        <div className="form-section animate-slide-up">
          <div className="text-center mb-8">
            <div className="w-16 h-16 rounded-full bg-primary/10 flex items-center justify-center mx-auto mb-4">
              <Phone className="w-8 h-8 text-primary" />
            </div>
            <h1 className="text-2xl font-bold text-foreground mb-2">
              Employee Registration
            </h1>
            <p className="text-muted-foreground">
              Enter your mobile number to get started
            </p>
          </div>

          <div className="space-y-6">
            <div className="space-y-2">
              <Label htmlFor="mobile">Mobile Number</Label>
              <div className="relative">
                <span className="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
                  +91
                </span>
                <Input
                  id="mobile"
                  type="tel"
                  inputMode="numeric"
                  value={mobileNumber}
                  onChange={(e) => validateMobile(e.target.value)}
                  placeholder="Enter 10-digit mobile"
                  className={`pl-12 text-lg h-12 ${mobileError ? 'border-destructive' : ''}`}
                />
              </div>
              {mobileError && (
                <p className="text-xs text-destructive">{mobileError}</p>
              )}
            </div>

            {error && (
              <p className="text-sm text-destructive text-center">{error}</p>
            )}

            <Button
              onClick={handleContinue}
              disabled={mobileNumber.length !== 10 || isChecking}
              className="w-full h-12 text-lg"
              size="lg"
            >
              {isChecking ? (
                <Loader2 className="w-5 h-5 mr-2 animate-spin" />
              ) : (
                <ArrowRight className="w-5 h-5 mr-2" />
              )}
              {isChecking ? 'Checking...' : 'Continue'}
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
}
