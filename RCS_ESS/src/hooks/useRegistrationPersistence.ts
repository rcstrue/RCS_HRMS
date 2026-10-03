import { useRef, useCallback, useEffect } from 'react';
import type { RegistrationData, RegistrationStep } from '@/types/registration';
import { logger } from '@/lib/logger';

// LocalStorage keys
const REGISTRATION_DATA_KEY = 'registration_form_data';
const REGISTRATION_MOBILE_KEY = 'registration_mobile';
const REGISTRATION_STEP_KEY = 'registration_current_step';
const REGISTRATION_COMPLETED_KEY = 'registration_completed_steps';
const REGISTRATION_PROFILE_PIC_KEY = 'registration_profile_pic';
const REGISTRATION_TIMESTAMP_KEY = 'registration_saved_timestamp';
const REGISTRATION_BANK_SKIPPED_KEY = 'registration_bank_skipped';

// Draft expires after 24 hours
const DRAFT_EXPIRY_MS = 24 * 60 * 60 * 1000;

export interface SavedDraft {
  data: RegistrationData;
  currentStep: RegistrationStep;
  completedSteps: number[];
  mobile: string;
  profilePic: string | undefined;
  savedAt: number; // timestamp
}

/**
 * Check if a saved draft exists and is not expired
 */
export function getSavedDraft(): SavedDraft | null {
  try {
    const timestampStr = localStorage.getItem(REGISTRATION_TIMESTAMP_KEY);
    if (!timestampStr) return null;

    const timestamp = parseInt(timestampStr, 10);
    const now = Date.now();

    // Check expiry
    if (now - timestamp > DRAFT_EXPIRY_MS) {
      clearSavedDraft();
      return null;
    }

    const dataStr = localStorage.getItem(REGISTRATION_DATA_KEY);
    const stepStr = localStorage.getItem(REGISTRATION_STEP_KEY);
    const completedStr = localStorage.getItem(REGISTRATION_COMPLETED_KEY);
    const mobileStr = localStorage.getItem(REGISTRATION_MOBILE_KEY);
    const profilePicStr = localStorage.getItem(REGISTRATION_PROFILE_PIC_KEY);

    if (!dataStr || !stepStr) return null;

    const data = JSON.parse(dataStr) as RegistrationData;
    const currentStep = parseInt(stepStr, 10) as RegistrationStep;
    const completedSteps = completedStr ? JSON.parse(completedStr) as number[] : [1];
    const mobile = mobileStr || '';
    const profilePic = profilePicStr || undefined;

    // Validate the data has some actual content (not just empty initial state)
    const hasAadhaarData = data.aadhaarDetails && (
      data.aadhaarDetails.fullName ||
      data.aadhaarDetails.aadhaarNumber ||
      data.aadhaarDetails.address
    );

    if (!hasAadhaarData && currentStep <= 2) {
      // Draft is essentially empty (still on step 2 with no data filled)
      return null;
    }

    return { data, currentStep, completedSteps, mobile, profilePic, savedAt: timestamp };
  } catch (err) {
    logger.error('Error reading saved draft:', err);
    clearSavedDraft();
    return null;
  }
}

/**
 * Clear all saved draft data
 */
export function clearSavedDraft(): void {
  try {
    localStorage.removeItem(REGISTRATION_DATA_KEY);
    localStorage.removeItem(REGISTRATION_MOBILE_KEY);
    localStorage.removeItem(REGISTRATION_STEP_KEY);
    localStorage.removeItem(REGISTRATION_COMPLETED_KEY);
    localStorage.removeItem(REGISTRATION_PROFILE_PIC_KEY);
    localStorage.removeItem(REGISTRATION_TIMESTAMP_KEY);
    localStorage.removeItem(REGISTRATION_BANK_SKIPPED_KEY);
  } catch (err) {
    logger.error('Error clearing draft:', err);
  }
}

/**
 * Save draft data to localStorage
 */
export function saveDraft(
  data: RegistrationData,
  currentStep: RegistrationStep,
  completedSteps: Set<number>,
  mobile: string,
  profilePic?: string
): void {
  try {
    localStorage.setItem(REGISTRATION_DATA_KEY, JSON.stringify(data));
    localStorage.setItem(REGISTRATION_STEP_KEY, String(currentStep));
    localStorage.setItem(REGISTRATION_COMPLETED_KEY, JSON.stringify(Array.from(completedSteps)));
    localStorage.setItem(REGISTRATION_MOBILE_KEY, mobile);
    localStorage.setItem(REGISTRATION_TIMESTAMP_KEY, String(Date.now()));

    if (profilePic) {
      localStorage.setItem(REGISTRATION_PROFILE_PIC_KEY, profilePic);
    } else {
      localStorage.removeItem(REGISTRATION_PROFILE_PIC_KEY);
    }
  } catch (err) {
    // localStorage might be full (especially with base64 images)
    logger.warn('Could not save draft to localStorage:', err);
    // Try saving without document images as they can be very large
    try {
      const dataWithoutImages: RegistrationData = {
        ...data,
        documents: {
          aadhaarFront: null,
          aadhaarBack: null,
          bankDocument: null,
          profilePic: null,
        },
      };
      localStorage.setItem(REGISTRATION_DATA_KEY, JSON.stringify(dataWithoutImages));
      localStorage.setItem(REGISTRATION_STEP_KEY, String(currentStep));
      localStorage.setItem(REGISTRATION_COMPLETED_KEY, JSON.stringify(Array.from(completedSteps)));
      localStorage.setItem(REGISTRATION_MOBILE_KEY, mobile);
      localStorage.setItem(REGISTRATION_TIMESTAMP_KEY, String(Date.now()));
      localStorage.removeItem(REGISTRATION_PROFILE_PIC_KEY);
    } catch (retryErr) {
      logger.error('Could not save draft even without images:', retryErr);
    }
  }
}

/**
 * Format time ago from timestamp
 */
export function formatTimeAgo(timestamp: number): string {
  const seconds = Math.floor((Date.now() - timestamp) / 1000);

  if (seconds < 60) return 'just now';
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes} min${minutes > 1 ? 's' : ''} ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours} hour${hours > 1 ? 's' : ''} ago`;
  const days = Math.floor(hours / 24);
  return `${days} day${days > 1 ? 's' : ''} ago`;
}

/**
 * Hook that auto-saves registration form data to localStorage with debounce
 */
export function useRegistrationPersistence(
  data: RegistrationData,
  currentStep: RegistrationStep,
  completedSteps: Set<number>,
  mobile: string,
  profilePic?: string,
  enabled: boolean = true
) {
  const debounceTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const lastSavedRef = useRef<string>('');

  // Debounced save - saves 1 second after the last change
  const debouncedSave = useCallback(() => {
    if (!enabled) return;

    if (debounceTimerRef.current) {
      clearTimeout(debounceTimerRef.current);
    }

    debounceTimerRef.current = setTimeout(() => {
      // Create a hash of the current state to avoid unnecessary saves
      const stateHash = JSON.stringify({ data, currentStep, completedSteps: Array.from(completedSteps) });
      if (stateHash !== lastSavedRef.current) {
        saveDraft(data, currentStep, completedSteps, mobile, profilePic);
        lastSavedRef.current = stateHash;
      }
    }, 1000); // 1 second debounce
  }, [data, currentStep, completedSteps, mobile, profilePic, enabled]);

  // Immediate save (e.g., on step change)
  const immediateSave = useCallback(() => {
    if (!enabled) return;

    if (debounceTimerRef.current) {
      clearTimeout(debounceTimerRef.current);
      debounceTimerRef.current = null;
    }
    saveDraft(data, currentStep, completedSteps, mobile, profilePic);
    lastSavedRef.current = JSON.stringify({ data, currentStep, completedSteps: Array.from(completedSteps) });
  }, [data, currentStep, completedSteps, mobile, profilePic, enabled]);

  // Auto-save on data/step changes
  useEffect(() => {
    debouncedSave();

    return () => {
      if (debounceTimerRef.current) {
        clearTimeout(debounceTimerRef.current);
      }
    };
  }, [debouncedSave]);

  // Save before page unload (refresh, close tab, navigate away)
  useEffect(() => {
    if (!enabled) return;

    const handleBeforeUnload = () => {
      if (debounceTimerRef.current) {
        clearTimeout(debounceTimerRef.current);
      }
      saveDraft(data, currentStep, completedSteps, mobile, profilePic);
    };

    window.addEventListener('beforeunload', handleBeforeUnload);
    return () => window.removeEventListener('beforeunload', handleBeforeUnload);
  }, [data, currentStep, completedSteps, mobile, profilePic, enabled]);

  return { immediateSave };
}
