import { useState, useCallback, useEffect, useRef } from 'react';
import { StepIndicator } from './StepIndicator';
import { ProfileCompletion } from './ProfileCompletion';
import { Step2AadhaarFront } from './steps/Step2AadhaarFront';
import { Step3AadhaarBack } from './steps/Step3AadhaarBack';
import { Step4BankDocument } from './steps/Step4BankDocument';
import { Step5BankVerification } from './steps/Step5BankVerification';
import { Step6AdditionalDetails } from './steps/Step6AdditionalDetails';
import { Step7ClientUnit } from './steps/Step7ClientUnit';
import { Step8Review } from './steps/Step8Review';
import { createEmployee, updateEmployee } from '@/lib/api/employees';
import { logger } from "@/lib/logger";
import { useRegistrationPersistence, clearSavedDraft } from '@/hooks/useRegistrationPersistence';
import type { 
  RegistrationStep, 
  RegistrationData, 
  BasicInfo, 
  AadhaarDetails, 
  BankDetails, 
  AdditionalDetails,
  ClientUnitInfo,
  DocumentImages 
} from '@/types/registration';

interface RegistrationWizardProps {
  initialMobile?: string;
  initialProfilePic?: string;
  existingEmployeeId?: string;
  existingEmployee?: {
    full_name?: string | null;
    father_name?: string | null;
    date_of_birth?: string | null;
    gender?: string | null;
    aadhaar_number?: string | null;
    address?: string | null;
    pin_code?: string | null;
    state?: string | null;
    district?: string | null;
    bank_name?: string | null;
    account_number?: string | null;
    ifsc_code?: string | null;
    account_holder_name?: string | null;
    email?: string | null;
    uan_number?: string | null;
    esic_number?: string | null;
    marital_status?: string | null;
    blood_group?: string | null;
    nominee_name?: string | null;
    nominee_relationship?: string | null;
    nominee_dob?: string | null;
    nominee_contact?: string | null;
    client_name?: string | null;
    unit_name?: string | null;
    client_id?: string | null;
    unit_id?: string | null;
    designation?: string | null;
    profile_pic_url?: string | null;
    aadhaar_front_url?: string | null;
    aadhaar_back_url?: string | null;
    bank_document_url?: string | null;
  } | null;
  onComplete: () => void;
  onBack?: () => void;
  // Draft restoration props
  restoredData?: RegistrationData | null;
  restoredStep?: RegistrationStep | null;
  restoredCompletedSteps?: number[] | null;
}

const createInitialData = (
  mobile?: string, 
  emp?: RegistrationWizardProps['existingEmployee'],
  profilePic?: string
): RegistrationData => {
  return {
  basicInfo: {
    mobileNumber: mobile || '',
  },
  aadhaarDetails: {
    fullName: emp?.full_name || '',
    fatherHusbandName: emp?.father_name || '',
    dateOfBirth: emp?.date_of_birth || '',
    gender: (emp?.gender as AadhaarDetails['gender']) || '',
    aadhaarNumber: emp?.aadhaar_number || '',
    address: emp?.address || '',
    pinCode: emp?.pin_code || '',
    state: emp?.state || '',
    district: emp?.district || '',
  },
  bankDetails: {
    bankName: emp?.bank_name || '',
    accountNumber: emp?.account_number || '',
    ifscCode: emp?.ifsc_code || '',
    accountHolderName: emp?.account_holder_name || '',
    confirmAccountNumber: emp?.account_number || '',
  },
  additionalDetails: {
    maritalStatus: (emp?.marital_status as AdditionalDetails['maritalStatus']) || '',
    bloodGroup: emp?.blood_group || '',
    email: emp?.email || '',
    uanNumber: emp?.uan_number || '',
    esicNumber: emp?.esic_number || '',
    nomineeName: emp?.nominee_name || '',
    nomineeRelationship: (emp?.nominee_relationship as AdditionalDetails['nomineeRelationship']) || '',
    nomineeDob: emp?.nominee_dob || '',
    nomineeContact: emp?.nominee_contact || '',
  },
  clientUnitInfo: {
    clientId: emp?.client_id || null,
    clientName: emp?.client_name || '',
    unitId: emp?.unit_id || null,
    unitName: emp?.unit_name || '',
    designation: emp?.designation || '',
  },
  documents: {
    aadhaarFront: emp?.aadhaar_front_url || null,
    aadhaarBack: emp?.aadhaar_back_url || null,
    bankDocument: emp?.bank_document_url || null,
    profilePic: profilePic || emp?.profile_pic_url || null,
  },
  bankSkipped: false,
};
}

export function RegistrationWizard({ 
  initialMobile, 
  initialProfilePic,
  existingEmployeeId, 
  existingEmployee, 
  onComplete, 
  onBack: onBackToMobile,
  restoredData,
  restoredStep,
  restoredCompletedSteps,
}: RegistrationWizardProps) {
  // Profile pic from prop or restored draft
  const effectiveProfilePic = initialProfilePic || undefined;
  
  // Determine which step to start on based on existing employee data or restored draft
  const getStartStep = (): RegistrationStep => {
    // If we have a restored step from a draft, use it
    if (restoredStep) return restoredStep;
    
    if (!existingEmployee) return 2;
    // Check if profile photo is missing - need to capture it
    if (!existingEmployee.profile_pic_url && !effectiveProfilePic) return 2;
    // Find first step with missing data
    if (!existingEmployee.aadhaar_front_url || !existingEmployee.full_name || !existingEmployee.aadhaar_number) return 2;
    if (!existingEmployee.aadhaar_back_url || !existingEmployee.address) return 3;
    // Check if bank details exist (if employee doesn't have bank, skip to step 6)
    const hasBank = existingEmployee.bank_name || existingEmployee.account_number || existingEmployee.bank_document_url;
    if (!hasBank && !existingEmployee.bank_name && !existingEmployee.account_number) return 6;
    if (!existingEmployee.bank_document_url || !existingEmployee.bank_name || !existingEmployee.account_number) return 4;
    if (!existingEmployee.ifsc_code) return 5;
    if (!existingEmployee.nominee_name || !existingEmployee.marital_status) return 6;
    if (!existingEmployee.client_name || !existingEmployee.unit_name) return 7;
    return 8; // All filled, go to review
  };

  const startStep = getStartStep();
  const [currentStep, setCurrentStep] = useState<RegistrationStep>(startStep);
  
  // Mark earlier steps as completed if we're starting ahead
  const getInitialCompletedSteps = (): Set<number> => {
    // If we have restored completed steps from a draft, use them
    if (restoredCompletedSteps && restoredCompletedSteps.length > 0) {
      return new Set(restoredCompletedSteps);
    }
    const initialCompleted = new Set<number>([1]);
    for (let i = 2; i < startStep; i++) initialCompleted.add(i);
    return initialCompleted;
  };
  
  const [completedSteps, setCompletedSteps] = useState<Set<number>>(getInitialCompletedSteps);
  
  // Initialize data — from restored draft if available, otherwise fresh
  const [data, setData] = useState<RegistrationData>(() => {
    if (restoredData) {
      // Merge restored data with any new props (e.g., fresh profilePic)
      return {
        ...restoredData,
        documents: {
          ...restoredData.documents,
          // Use prop profilePic if available, otherwise keep restored
          profilePic: effectiveProfilePic || restoredData.documents.profilePic,
        },
      };
    }
    const initialData = createInitialData(initialMobile, existingEmployee, effectiveProfilePic);
    return initialData;
  });

  // Auto-save draft to localStorage on every change
  const { immediateSave } = useRegistrationPersistence(
    data,
    currentStep,
    completedSteps,
    data.basicInfo.mobileNumber || initialMobile || '',
    data.documents.profilePic || effectiveProfilePic,
    true // enabled
  );

  // Update profilePic when initialProfilePic prop changes (e.g., after photo capture)
  useEffect(() => {
    // Only update if we have a new profilePic and it's different from current
    setData(prev => {
      if (initialProfilePic && prev.documents.profilePic !== initialProfilePic) {
        return {
          ...prev,
          documents: { ...prev.documents, profilePic: initialProfilePic },
        };
      }
      return prev;
    });
  }, [initialProfilePic]);

  const updateBasicInfo = useCallback((updates: Partial<BasicInfo>) => {
    setData(prev => ({
      ...prev,
      basicInfo: { ...prev.basicInfo, ...updates },
    }));
  }, []);

  const updateAadhaarDetails = useCallback((updates: Partial<AadhaarDetails>) => {
    setData(prev => ({
      ...prev,
      aadhaarDetails: { ...prev.aadhaarDetails, ...updates },
    }));
  }, []);

  const updateBankDetails = useCallback((updates: Partial<BankDetails>) => {
    setData(prev => ({
      ...prev,
      bankDetails: { ...prev.bankDetails, ...updates },
    }));
  }, []);

  const updateAdditionalDetails = useCallback((updates: Partial<AdditionalDetails>) => {
    setData(prev => ({
      ...prev,
      additionalDetails: { ...prev.additionalDetails, ...updates },
    }));
  }, []);

  const updateClientUnitInfo = useCallback((updates: Partial<ClientUnitInfo>) => {
    setData(prev => ({
      ...prev,
      clientUnitInfo: { ...prev.clientUnitInfo, ...updates },
    }));
  }, []);

  const updateDocuments = useCallback((updates: Partial<DocumentImages>) => {
    setData(prev => ({
      ...prev,
      documents: { ...prev.documents, ...updates },
    }));
  }, []);

  const goToNextStep = useCallback(() => {
    setCompletedSteps(prev => new Set(prev).add(currentStep));
    setCurrentStep(prev => Math.min(prev + 1, 8) as RegistrationStep);
    // Save immediately on step change so the draft is up-to-date
    immediateSave();
  }, [currentStep, immediateSave]);

  const goToPreviousStep = useCallback(() => {
    if (currentStep === 2 && onBackToMobile) {
      // Clear draft when going back to mobile entry
      clearSavedDraft();
      onBackToMobile();
      return;
    }
    setCurrentStep(prev => Math.max(prev - 1, 2) as RegistrationStep);
    immediateSave();
  }, [currentStep, onBackToMobile, immediateSave]);

  const handleSubmit = useCallback(async () => {
    try {
      const employeeData = {
        mobile_number: data.basicInfo.mobileNumber,
        email: data.additionalDetails.email || null,
        uan_number: data.additionalDetails.uanNumber || null,
        esic_number: data.additionalDetails.esicNumber || null,
        marital_status: data.additionalDetails.maritalStatus || null,
        blood_group: data.additionalDetails.bloodGroup || null,
        nominee_name: data.additionalDetails.nomineeName || null,
        nominee_relationship: data.additionalDetails.nomineeRelationship || null,
        nominee_dob: data.additionalDetails.nomineeDob || null,
        nominee_contact: data.additionalDetails.nomineeContact || null,
        // Map nominee to emergency contact
        emergency_contact_name: data.additionalDetails.nomineeName || null,
        emergency_contact_relation: data.additionalDetails.nomineeRelationship || null,
        full_name: data.aadhaarDetails.fullName || null,
        father_name: data.aadhaarDetails.fatherHusbandName || null,
        date_of_birth: data.aadhaarDetails.dateOfBirth || null,
        gender: data.aadhaarDetails.gender || null,
        aadhaar_number: data.aadhaarDetails.aadhaarNumber?.replace(/\s/g, '') || null,
        address: data.aadhaarDetails.address || null,
        pin_code: data.aadhaarDetails.pinCode || null,
        state: data.aadhaarDetails.state || null,
        district: data.aadhaarDetails.district || null,
        bank_name: data.bankDetails.bankName || null,
        account_number: data.bankDetails.accountNumber || null,
        ifsc_code: data.bankDetails.ifscCode || null,
        account_holder_name: data.bankDetails.accountHolderName || null,
        // Send both client_id and client_name (API will use ID for storage)
        client_id: data.clientUnitInfo.clientId || null,
        client_name: data.clientUnitInfo.clientName || null,
        unit_id: data.clientUnitInfo.unitId || null,
        unit_name: data.clientUnitInfo.unitName || null,
        designation: data.clientUnitInfo.designation || null,
        profile_pic_url: data.documents.profilePic || null,
        aadhaar_front_url: data.documents.aadhaarFront || null,
        aadhaar_back_url: data.documents.aadhaarBack || null,
        bank_document_url: data.documents.bankDocument || null,
        status: 'pending_hr_verification',
        profile_completion: 100,
      };

      if (existingEmployeeId) {
        // Update existing employee
        const { error } = await updateEmployee(existingEmployeeId, employeeData);
        if (error) throw new Error(error);
      } else {
        // Insert new employee
        const { data: newEmployee, error } = await createEmployee(employeeData);
        if (error) throw new Error(error);

        // NOTE: We intentionally do NOT set localStorage('employee_id') here.
        // The employee_id session is set only after birth-year verification
        // succeeds (see Index.tsx submitBirthYear()). Setting it here would
        // leak Employee A's ID into Employee B's registration if the same
        // device is reused without closing the browser.
      }

      // Clear registration draft after successful submission
      localStorage.removeItem('registration_profile_pic');
      clearSavedDraft();

      // Don't call onComplete() here - let Step8Review show SuccessPage first
      // onComplete will be called by SuccessPage after WhatsApp redirect
    } catch (error) {
      logger.error('Error saving registration:', error);
      throw error;
    }
  }, [data, existingEmployeeId]);

  const renderStep = () => {
    switch (currentStep) {
      case 1:
        // Step 1 is handled outside the wizard now
        return null;
      case 2:
        return (
          <Step2AadhaarFront
            data={data.aadhaarDetails}
            documents={data.documents}
            onUpdate={updateAadhaarDetails}
            onUpdateDocuments={updateDocuments}
            onNext={goToNextStep}
            onBack={goToPreviousStep}
          />
        );
      case 3:
        return (
          <Step3AadhaarBack
            data={data.aadhaarDetails}
            documents={data.documents}
            onUpdate={updateAadhaarDetails}
            onUpdateDocuments={updateDocuments}
            onNext={goToNextStep}
            onBack={goToPreviousStep}
          />
        );
      case 4:
        return (
          <Step4BankDocument
            data={data.bankDetails}
            documents={data.documents}
            onUpdate={updateBankDetails}
            onUpdateDocuments={updateDocuments}
            onNext={goToNextStep}
            onBack={goToPreviousStep}
            onSkipBank={() => {
              setData(prev => ({ ...prev, bankSkipped: true }));
              setCompletedSteps(prev => new Set(prev).add(4));
              // Skip step 5 (bank verification) and go to step 6
              setCompletedSteps(prev => new Set(prev).add(5));
              setCurrentStep(6);
              immediateSave();
            }}
          />
        );
      case 5:
        return (
          <Step5BankVerification
            data={data.bankDetails}
            onUpdate={updateBankDetails}
            onNext={goToNextStep}
            onBack={goToPreviousStep}
          />
        );
      case 6:
        return (
          <Step6AdditionalDetails
            data={data.additionalDetails}
            aadhaarData={data.aadhaarDetails}
            onUpdate={updateAdditionalDetails}
            onNext={goToNextStep}
            onBack={goToPreviousStep}
          />
        );
      case 7:
        return (
          <Step7ClientUnit
            data={data.clientUnitInfo}
            onUpdate={updateClientUnitInfo}
            onNext={goToNextStep}
            onBack={goToPreviousStep}
          />
        );
      case 8:
        return (
          <Step8Review
            data={data}
            onBack={goToPreviousStep}
            onSubmit={handleSubmit}
            onComplete={onComplete}
          />
        );
      default:
        return null;
    }
  };

  // Calculate completion for header display
  const stepsCompleted = completedSteps.size;
  const completionPercentage = Math.round((stepsCompleted / 8) * 100);

  return (
    <div className="min-h-screen bg-background">
      {/* Header - compact for mobile */}
      <header className="sticky top-0 z-50 bg-card/95 backdrop-blur-sm border-b shadow-sm">
        <div className="container max-w-2xl mx-auto px-3 py-2 flex items-center justify-between">
          <h1 className="text-sm font-semibold text-foreground">
            Registration
          </h1>
          <ProfileCompletion percentage={completionPercentage} />
        </div>
        <StepIndicator currentStep={currentStep} completedSteps={completedSteps} />
      </header>

      {/* Main Content */}
      <main className="container max-w-2xl mx-auto px-3 py-4 pb-16">
        {renderStep()}
      </main>

      {/* Footer - minimal for mobile */}
      <footer className="fixed bottom-0 left-0 right-0 bg-card/95 backdrop-blur-sm border-t px-3 py-2">
        <p className="text-[10px] text-center text-muted-foreground">
          Data encrypted & securely stored. By proceeding, you agree to our privacy policy.
        </p>
      </footer>
    </div>
  );
}
