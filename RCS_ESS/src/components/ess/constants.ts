// ══════════════════════════════════════════════════════════════
// ESS Constants — Navigation items, menu items, config
// ══════════════════════════════════════════════════════════════

import {
  LayoutDashboard,
  Users,
  Receipt,
  MoreHorizontal,
  Clock,
  CalendarDays,
  ClipboardList,
  Megaphone,
  CircleHelp,
  Settings,
  UserCircle,
  UserPlus,
  Download,
  Bell,
  Send,
  PartyPopper,
  FileEdit,
  MapPin,
  FileText,
  ClipboardCheck,
  TableProperties,
} from 'lucide-react';

export const APP_VERSION = '1.0.0'; // Must match php_payroll/config/config.php APP_VERSION

export const NAV_ITEMS = [
  { key: 'dashboard', label: 'Home', icon: LayoutDashboard },
  { key: 'directory', label: 'Employees', icon: Users },
  { key: 'expenses', label: 'Expenses', icon: Receipt },
  { key: '_more', label: 'More', icon: MoreHorizontal },
] as const;

export const MORE_MENU_ITEMS = [
  { key: 'attendance', label: 'Attendance', icon: Clock, description: 'View attendance history' },
  { key: 'leaves', label: 'Leave', icon: CalendarDays, description: 'Apply & track leave requests' },
  { key: 'payslip', label: 'Payslip', icon: FileText, description: 'View & download payslips' },
  { key: 'tasks', label: 'Tasks', icon: ClipboardList, description: 'Manage your task assignments' },
  { key: 'announcements', label: 'Notices', icon: Megaphone, description: 'Company announcements & updates' },
  { key: 'helpdesk', label: 'Help Desk', icon: CircleHelp, description: 'Submit support tickets' },
  { key: 'unit-visits', label: 'Unit Visit Checklist', icon: MapPin, description: 'Submit visit checklists' },
  { key: 'manpower-status', label: 'Manpower Status', icon: ClipboardEdit, description: 'Daily manpower budget & actual' },
  { key: 'team-monthly', label: 'Team Monthly', icon: TableProperties, description: 'Attendance & advances for team' },
  { key: 'regularization', label: 'Regularization', icon: FileEdit, description: 'Regularize missed check-ins' },
  { key: 'holidays', label: 'Holidays', icon: PartyPopper, description: 'Company holiday calendar' },
  { key: 'send-notification', label: 'Send Notification', icon: Send, description: 'Broadcast notifications to employees' },
  { key: 'notifications', label: 'Notifications', icon: Bell, description: 'View your notifications' },
  { key: 'profile', label: 'My Profile', icon: UserCircle, description: 'View your profile details' },
  { key: 'settings', label: 'Settings', icon: Settings, description: 'App preferences' },
  { key: 'new-registration', label: 'New Registration', icon: UserPlus, description: 'Register a new employee' },
  { key: 'install-app', label: 'Install App', icon: Download, description: 'Add to home screen for quick access' },
] as const;
