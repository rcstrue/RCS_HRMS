import { Toaster } from "@/components/ui/toaster";
import { Toaster as Sonner } from "@/components/ui/sonner";
import { TooltipProvider } from "@/components/ui/tooltip";
import { HashRouter, Routes, Route } from "react-router-dom";
import Index from "./pages/Index";
import NotFound from "./pages/NotFound";
import AdminLogin from "./pages/AdminLogin";
import AdminDashboard from "./pages/AdminDashboard";
import VerifyPage from "./pages/VerifyPage";
import ESSApp from "./components/ess/ESSApp";
import { RequireAuth } from "./components/RequireAuth";

const App = () => (
  <TooltipProvider>
    <Toaster />
    <Sonner />
    <HashRouter>
      <Routes>
        {/* Public routes — no auth required */}
        <Route path="/" element={<Index />} />
        <Route path="/verify" element={<VerifyPage />} />
        <Route path="/admin/login" element={<AdminLogin />} />

        {/* Protected routes — RequireAuth prevents flash of protected content */}
        {/* NOTE: /ess is NOT wrapped — ESSApp handles its own auth (shows LoginScreen
            when no session). Wrapping it would cause a redirect loop → white screen. */}
        <Route path="/ess" element={
          <ESSApp onBackToRegistration={() => window.location.hash = '/'} />
        } />
        <Route path="/admin" element={
          <RequireAuth type="admin">
            <AdminDashboard />
          </RequireAuth>
        } />
        <Route path="/admin/dashboard" element={
          <RequireAuth type="admin">
            <AdminDashboard />
          </RequireAuth>
        } />

        {/* 404 */}
        <Route path="*" element={<NotFound />} />
      </Routes>
    </HashRouter>
  </TooltipProvider>
);

export default App;
