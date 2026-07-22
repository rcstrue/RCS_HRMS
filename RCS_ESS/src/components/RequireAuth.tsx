// ══════════════════════════════════════════════════════════════
// Route-level Auth Guard — prevents flash of protected content
// ══════════════════════════════════════════════════════════════
//
// R11: Previously, auth checks ran INSIDE components via useEffect — meaning
// the protected UI briefly rendered before the redirect kicked in. This guard
// runs the check BEFORE rendering the child, so unauthenticated users see
// nothing but a redirect (no flash of protected content, no data leak).
//
// Usage in App.tsx:
//   <Route path="/ess" element={<RequireAuth type="ess"><ESSApp /></RequireAuth>} />
//   <Route path="/admin" element={<RequireAuth type="admin"><AdminDashboard /></RequireAuth>} />

import { type ReactNode, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Loader2 } from 'lucide-react';

type AuthType = 'ess' | 'admin';

interface RequireAuthProps {
  children: ReactNode;
  type: AuthType;
  /** Where to redirect if not authenticated */
  redirectTo?: string;
}

export function RequireAuth({ children, type, redirectTo }: RequireAuthProps) {
  const navigate = useNavigate();
  const [checking, setChecking] = useState(true);

  useEffect(() => {
    let authenticated = false;
    let dest = redirectTo ?? '';

    if (type === 'ess') {
      // ESS auth: check for ess_employee session in localStorage.
      // (R11: the JWT is in the HttpOnly cookie, but the session object
      // in localStorage is the SPA's indicator that the user is logged in.)
      authenticated = !!localStorage.getItem('ess_employee');
      if (!dest) dest = '/ess'; // ESS login is at /ess (LoginScreen renders inside ESSApp)
    } else if (type === 'admin') {
      // Admin auth: check for admin_token in localStorage.
      authenticated = !!localStorage.getItem('admin_token');
      if (!dest) dest = '/admin/login';
    }

    if (!authenticated) {
      navigate(dest, { replace: true });
      return;
    }

    setChecking(false);
  }, [type, redirectTo, navigate]);

  // While checking auth, show a minimal spinner — no protected content renders.
  if (checking) {
    return (
      <div style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        minHeight: '100vh',
        background: '#f8fafc',
      }}>
        <Loader2 style={{ width: 32, height: 32, color: '#6366f1', animation: 'spin 1s linear infinite' }} />
      </div>
    );
  }

  return <>{children}</>;
}
