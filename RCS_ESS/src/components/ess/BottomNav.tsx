'use client';

import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Separator } from '@/components/ui/separator';
import { ChevronRight, LogOut } from 'lucide-react';
import { NAV_ITEMS, MORE_MENU_ITEMS } from './constants';

// ══════════════════════════════════════════════════════════════
// Color palette for More menu items
// ══════════════════════════════════════════════════════════════

const MENU_COLORS: Record<string, { bg: string; icon: string; text: string; desc: string }> = {
  'attendance':       { bg: 'bg-blue-50',        icon: 'text-blue-600',    text: 'text-blue-900',    desc: 'text-blue-400' },
  'leaves':           { bg: 'bg-amber-50',       icon: 'text-amber-600',   text: 'text-amber-900',   desc: 'text-amber-400' },
  'payslip':          { bg: 'bg-emerald-50',     icon: 'text-emerald-600', text: 'text-emerald-900', desc: 'text-emerald-400' },
  'tasks':            { bg: 'bg-violet-50',      icon: 'text-violet-600',  text: 'text-violet-900',  desc: 'text-violet-400' },
  'announcements':    { bg: 'bg-rose-50',        icon: 'text-rose-600',    text: 'text-rose-900',    desc: 'text-rose-400' },
  'helpdesk':         { bg: 'bg-sky-50',         icon: 'text-sky-600',     text: 'text-sky-900',     desc: 'text-sky-400' },
  'unit-visits':      { bg: 'bg-teal-50',        icon: 'text-teal-600',    text: 'text-teal-900',    desc: 'text-teal-400' },
  'manpower-status':  { bg: 'bg-blue-50',        icon: 'text-blue-600',    text: 'text-blue-900',    desc: 'text-blue-400' },
  'send-notification':{ bg: 'bg-violet-50',      icon: 'text-violet-600',  text: 'text-violet-900',  desc: 'text-violet-400' },
  'regularization':   { bg: 'bg-orange-50',      icon: 'text-orange-600',  text: 'text-orange-900',  desc: 'text-orange-400' },
  'holidays':         { bg: 'bg-pink-50',        icon: 'text-pink-600',    text: 'text-pink-900',    desc: 'text-pink-400' },
  'notifications':    { bg: 'bg-indigo-50',      icon: 'text-indigo-600',  text: 'text-indigo-900',  desc: 'text-indigo-400' },
  'profile':          { bg: 'bg-cyan-50',        icon: 'text-cyan-600',    text: 'text-cyan-900',    desc: 'text-cyan-400' },
  'settings':         { bg: 'bg-gray-100',       icon: 'text-gray-600',    text: 'text-gray-800',    desc: 'text-gray-400' },
  'new-registration': { bg: 'bg-fuchsia-50',     icon: 'text-fuchsia-600', text: 'text-fuchsia-900', desc: 'text-fuchsia-400' },
  'install-app':      { bg: 'bg-lime-50',        icon: 'text-lime-600',    text: 'text-lime-900',    desc: 'text-lime-400' },
};

// ══════════════════════════════════════════════════════════════
// Bottom Navigation Bar
// ══════════════════════════════════════════════════════════════

interface BottomNavProps {
  currentPage: string;
  showMoreMenu: boolean;
  setShowMoreMenu: (open: boolean) => void;
  onNavigate: (page: string) => void;
  isInstalled: boolean;
  canViewDirectory: boolean;
  canViewEmployees: boolean;
  canSendNotification: boolean;
}

export default function BottomNav({ currentPage, showMoreMenu, setShowMoreMenu, onNavigate, isInstalled, canViewDirectory: canViewDir, canViewEmployees, canSendNotification }: BottomNavProps) {
  const filteredNavItems = NAV_ITEMS.filter((item) => {
    if (item.key === 'directory') return canViewDir;
    return true;
  });

  const filteredMoreItems = MORE_MENU_ITEMS.filter((item) => {
    if (item.key === 'install-app') return !isInstalled;
    if (item.key === 'unit-visits') return canViewEmployees;
    if (item.key === 'manpower-status') return canViewEmployees;
    if (item.key === 'send-notification') return canSendNotification;
    return true;
  });

  const visibleBottomKeys = filteredNavItems.map((item) => item.key);

  return (
    <nav className="fixed bottom-0 left-0 right-0 z-40 bg-white border-t safe-area-bottom">
      <div className="flex items-center justify-around h-16 max-w-lg mx-auto px-2">
        {filteredNavItems.map((item) => {
          const isActive = item.key === '_more'
            ? !visibleBottomKeys.includes(currentPage) && currentPage !== 'dashboard'
            : item.key === currentPage;

          if (item.key === '_more') {
            return (
              <Sheet key={item.key} open={showMoreMenu} onOpenChange={setShowMoreMenu}>
                <button
                  onClick={() => setShowMoreMenu(true)}
                  className={`flex flex-col items-center justify-center gap-0.5 flex-1 py-1.5 rounded-lg transition-colors ${isActive ? 'text-emerald-600 bg-emerald-50' : 'text-gray-500 hover:text-gray-700'}`}
                >
                  <item.icon className="w-5 h-5" />
                  <span className="text-[10px] font-medium">{item.label}</span>
                </button>
                <SheetContent side="bottom" className="rounded-t-2xl max-h-[80vh] overflow-y-auto">
                  <SheetHeader className="pb-2">
                    <SheetTitle className="text-center">More Options</SheetTitle>
                  </SheetHeader>
                  <div className="grid grid-cols-3 gap-3 px-3 pb-4">
                    {filteredMoreItems.map((menuItem) => {
                      const colors = MENU_COLORS[menuItem.key] || { bg: 'bg-gray-50', icon: 'text-gray-600', text: 'text-gray-800', desc: 'text-gray-400' };
                      return (
                        <button
                          key={menuItem.key}
                          onClick={() => { setShowMoreMenu(false); onNavigate(menuItem.key); }}
                          className={`flex flex-col items-center gap-2 p-3 rounded-2xl ${colors.bg} hover:scale-[1.03] active:scale-[0.97] transition-transform`}
                        >
                          <div className={`flex items-center justify-center w-11 h-11 rounded-xl bg-white shadow-sm`}>
                            <menuItem.icon className={`w-5 h-5 ${colors.icon}`} />
                          </div>
                          <div className="text-center">
                            <p className={`text-xs font-semibold leading-tight ${colors.text}`}>{menuItem.label}</p>
                          </div>
                        </button>
                      );
                    })}
                  </div>
                  <Separator className="mx-3" />
                  <div className="px-3 py-3">
                    <button onClick={() => onNavigate('logout')} className="flex items-center gap-3 w-full p-3 rounded-xl bg-rose-50 hover:bg-rose-100 transition-colors">
                      <div className="flex items-center justify-center w-10 h-10 rounded-xl bg-rose-100">
                        <LogOut className="w-5 h-5 text-rose-600" />
                      </div>
                      <div className="flex-1">
                        <p className="text-sm font-medium text-rose-700">Logout</p>
                        <p className="text-xs text-rose-400">Sign out of your account</p>
                      </div>
                    </button>
                  </div>
                </SheetContent>
              </Sheet>
            );
          }

          return (
            <button key={item.key} onClick={() => onNavigate(item.key)} className={`flex flex-col items-center justify-center gap-0.5 flex-1 py-1.5 rounded-lg transition-colors ${isActive ? 'text-emerald-600 bg-emerald-50' : 'text-gray-500 hover:text-gray-700'}`}>
              <item.icon className="w-5 h-5" />
              <span className="text-[10px] font-medium">{item.label}</span>
            </button>
          );
        })}
      </div>
      <div className="h-[env(safe-area-inset-bottom,0px)] bg-white" />
    </nav>
  );
}