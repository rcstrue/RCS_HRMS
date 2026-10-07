import { createRoot } from "react-dom/client";
import App from "./App.tsx";
import "./index.css";

// ══════════════════════════════════════════════════════════════
// ESS Shared Login Link Handler — runs before React Router starts
// ══════════════════════════════════════════════════════════════
// Handles URLs like:
//   #/ess/8469841414        (mobile only — employee types PIN)
//   #/ess/8469841414-1988 (mobile + 4-digit PIN — temporary first-time)
//
// The hash is cleaned to #/ess BEFORE HashRouter processes it, so the
// non-existent route /ess/8469841414 is never hit.
// ══════════════════════════════════════════════════════════════
(function normalizeSharedLoginHash() {
  const rawHash = window.location.hash;
  // Match both #/ess/XXXX (with leading slash from HashRouter convention)
  // and #ess/XXXX (without leading slash, shared-link format).
  const mobileMatch = rawHash.match(/^#\/?ess[\/\-](\d{10,14})(?:-(\d{4}))?/);
  if (mobileMatch) {
    const mobileDigits = mobileMatch[1];
    const pinDigits = mobileMatch[2];

    // Store for LoginScreen (reads from sessionStorage, not localStorage,
    // to avoid persisting across browser sessions).
    try {
      sessionStorage.setItem("ess_login_mobile", mobileDigits);
      if (pinDigits) {
        sessionStorage.setItem("ess_login_pin", pinDigits);
      }
    } catch {
      // sessionStorage unavailable — fall back silently
    }

    // Clean the hash to the valid ESS route before HashRouter reads it.
    // Use replaceState (not pushState) so back-button doesn't cycle through
    // the shared-login URL after the employee logs out.
    window.history.replaceState(
      null,
      "",
      window.location.pathname + window.location.search + "#/ess"
    );

    // Restore the hash value in a way that doesn't trigger router re-process.
    // Since replaceState has already written #/ess, we don't touch hash again —
    // React Router sees #/ess, renders the /ess route, and LoginScreen picks up
    // the sessionStorage values.
  }
})();

const rootEl = document.getElementById("root");
if (rootEl) {
  createRoot(rootEl).render(<App />);
}
