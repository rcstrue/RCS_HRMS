'use client'

import { useCallback, useEffect, useState } from 'react'
import { useDebounce } from '@reactuses/core'
import { motion, AnimatePresence } from 'framer-motion'
import {
  Plus, Search, Pencil, Trash2, Eye, ChevronLeft, ChevronRight,
  Building2, Users, FileText, Shield, Phone, Building, CreditCard,
  Briefcase, Download, X, Loader2, ClipboardList, ArrowUpDown,
  IndianRupee, CalendarDays, MapPin, User, Mail, Badge, Landmark,
} from 'lucide-react'
import { toast } from 'sonner'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge as BadgeUI } from '@/components/ui/badge'
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table'
import {
  Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle,
} from '@/components/ui/dialog'
import {
  AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
  AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Textarea } from '@/components/ui/textarea'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Separator } from '@/components/ui/separator'
import { Skeleton } from '@/components/ui/skeleton'
import { ScrollArea } from '@/components/ui/scroll-area'

// ── Types ────────────────────────────────────────────────────────────────

interface ContractorRecord {
  id: string
  contractorName: string
  contractorAddress: string
  registrationNumber: string
  dateOfRegistration: string | null
  contractorPan: string
  establishmentName: string
  establishmentAddress: string
  natureOfWork: string
  maxWorkmen: number
  licensingAuthority: string
  licenseNumber: string
  licenseIssueDate: string | null
  licenseValidFrom: string | null
  licenseValidTo: string | null
  licenseFee: number
  contractorGstin: string
  pfRegistrationNumber: string
  contactPersonName: string
  contactPersonDesignation: string
  contactPersonMobile: string
  contactPersonEmail: string
  bankName: string
  bankAccountNumber: string
  bankIfscCode: string
  esiRegistrationNumber: string
  contractStartDate: string | null
  contractEndDate: string | null
  contractValue: number
  securityDeposit: number
  contractWorkLocation: string
  remarks: string
  createdAt: string
  updatedAt: string
}

interface Pagination {
  page: number
  limit: number
  total: number
  totalPages: number
}

interface ApiResponse {
  records: ContractorRecord[]
  pagination: Pagination
}

// ── Column config for table ──────────────────────────────────────────────

const TABLE_COLUMNS = [
  { key: 'contractorName', label: 'Contractor Name', icon: Building2, minW: '180px' },
  { key: 'registrationNumber', label: 'Reg. No', icon: FileText, minW: '120px' },
  { key: 'establishmentName', label: 'Establishment', icon: Landmark, minW: '150px' },
  { key: 'natureOfWork', label: 'Nature of Work', icon: Briefcase, minW: '140px' },
  { key: 'maxWorkmen', label: 'Workers', icon: Users, minW: '80px' },
  { key: 'licenseNumber', label: 'License No', icon: Shield, minW: '120px' },
  { key: 'licenseValidTo', label: 'Valid To', icon: CalendarDays, minW: '110px' },
  { key: 'contactPersonName', label: 'Contact Person', icon: User, minW: '140px' },
  { key: 'contactPersonMobile', label: 'Mobile', icon: Phone, minW: '120px' },
  { key: 'contractValue', label: 'Contract Value', icon: IndianRupee, minW: '120px' },
  { key: 'contractWorkLocation', label: 'Work Location', icon: MapPin, minW: '140px' },
  { key: 'contractorGstin', label: 'GSTIN', icon: CreditCard, minW: '140px' },
] as const

// ── Empty form state ─────────────────────────────────────────────────────

const EMPTY_FORM: Omit<ContractorRecord, 'id' | 'createdAt' | 'updatedAt'> = {
  contractorName: '',
  contractorAddress: '',
  registrationNumber: '',
  dateOfRegistration: '',
  contractorPan: '',
  establishmentName: '',
  establishmentAddress: '',
  natureOfWork: '',
  maxWorkmen: 0,
  licensingAuthority: '',
  licenseNumber: '',
  licenseIssueDate: '',
  licenseValidFrom: '',
  licenseValidTo: '',
  licenseFee: 0,
  contractorGstin: '',
  pfRegistrationNumber: '',
  contactPersonName: '',
  contactPersonDesignation: '',
  contactPersonMobile: '',
  contactPersonEmail: '',
  bankName: '',
  bankAccountNumber: '',
  bankIfscCode: '',
  esiRegistrationNumber: '',
  contractStartDate: '',
  contractEndDate: '',
  contractValue: 0,
  securityDeposit: 0,
  contractWorkLocation: '',
  remarks: '',
}

// ── Format helpers ───────────────────────────────────────────────────────

function formatDate(val: string | null | undefined): string {
  if (!val) return '—'
  try {
    return new Date(val).toLocaleDateString('en-IN', {
      day: '2-digit', month: 'short', year: 'numeric',
    })
  } catch {
    return val
  }
}

function formatCurrency(val: number): string {
  if (!val) return '₹0'
  return new Intl.NumberFormat('en-IN', {
    style: 'currency', currency: 'INR', maximumFractionDigits: 0,
  }).format(val)
}

// ── CSV Export ───────────────────────────────────────────────────────────

function exportCSV(records: ContractorRecord[]) {
  if (!records.length) return
  const headers = [
    'Contractor Name', 'Address', 'Registration No', 'Date of Registration', 'PAN',
    'Establishment Name', 'Establishment Address', 'Nature of Work', 'Max Workmen',
    'Licensing Authority', 'License No', 'License Issue Date', 'License Valid From',
    'License Valid To', 'License Fee', 'GSTIN', 'PF Reg No',
    'Contact Person', 'Contact Designation', 'Contact Mobile', 'Contact Email',
    'Bank Name', 'Bank Account No', 'Bank IFSC', 'ESI Reg No',
    'Contract Start', 'Contract End', 'Contract Value', 'Security Deposit',
    'Work Location', 'Remarks',
  ]
  const keys: (keyof ContractorRecord)[] = [
    'contractorName', 'contractorAddress', 'registrationNumber', 'dateOfRegistration', 'contractorPan',
    'establishmentName', 'establishmentAddress', 'natureOfWork', 'maxWorkmen',
    'licensingAuthority', 'licenseNumber', 'licenseIssueDate', 'licenseValidFrom',
    'licenseValidTo', 'licenseFee', 'contractorGstin', 'pfRegistrationNumber',
    'contactPersonName', 'contactPersonDesignation', 'contactPersonMobile', 'contactPersonEmail',
    'bankName', 'bankAccountNumber', 'bankIfscCode', 'esiRegistrationNumber',
    'contractStartDate', 'contractEndDate', 'contractValue', 'securityDeposit',
    'contractWorkLocation', 'remarks',
  ]
  const rows = records.map(r =>
    keys.map(k => {
      const v = r[k]
      if (v === null || v === undefined) return ''
      const s = String(v)
      return s.includes(',') || s.includes('"') || s.includes('\n')
        ? `"${s.replace(/"/g, '""')}"`
        : s
    }).join(',')
  )
  const csv = [headers.join(','), ...rows].join('\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = `Form_A_Labour_Contractor_Register_${new Date().toISOString().slice(0, 10)}.csv`
  link.click()
  URL.revokeObjectURL(link.href)
}

// ── Form Field Component ─────────────────────────────────────────────────

function FormField({ label, children, required }: {
  label: string; children: React.ReactNode; required?: boolean
}) {
  return (
    <div className="space-y-1.5">
      <Label className="text-xs font-medium text-muted-foreground">
        {label}{required && <span className="text-destructive ml-0.5">*</span>}
      </Label>
      {children}
    </div>
  )
}

// ── Main Page Component ──────────────────────────────────────────────────

export default function Home() {
  // ── State ──────────────────────────────────────────────────────────────
  const [records, setRecords] = useState<ContractorRecord[]>([])
  const [pagination, setPagination] = useState<Pagination>({ page: 1, limit: 20, total: 0, totalPages: 0 })
  const [searchQuery, setSearchQuery] = useState('')
  const debouncedSearch = useDebounce(searchQuery, 350)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  // Form dialog
  const [formOpen, setFormOpen] = useState(false)
  const [editingId, setEditingId] = useState<string | null>(null)
  const [formData, setFormData] = useState(EMPTY_FORM)
  const [formTab, setFormTab] = useState('identity')

  // View dialog
  const [viewOpen, setViewOpen] = useState(false)
  const [viewRecord, setViewRecord] = useState<ContractorRecord | null>(null)
  const [viewTab, setViewTab] = useState('identity')

  // Delete confirmation
  const [deleteId, setDeleteId] = useState<string | null>(null)
  const [deleting, setDeleting] = useState(false)

  // Sort
  const [sortBy, setSortBy] = useState('createdAt')
  const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>('desc')

  // ── Fetch records ──────────────────────────────────────────────────────
  const fetchRecords = useCallback(async () => {
    setLoading(true)
    try {
      const params = new URLSearchParams({
        page: String(pagination.page),
        limit: String(pagination.limit),
        sortBy,
        sortOrder,
      })
      if (debouncedSearch) params.set('search', debouncedSearch)

      const res = await fetch(`/api/contractors?${params}`)
      if (!res.ok) throw new Error('Failed to fetch')
      const data: ApiResponse = await res.json()
      setRecords(data.records)
      setPagination(data.pagination)
    } catch {
      toast.error('Failed to load contractors')
    } finally {
      setLoading(false)
    }
  }, [pagination.page, pagination.limit, debouncedSearch, sortBy, sortOrder])

  useEffect(() => { fetchRecords() }, [fetchRecords])

  // ── Handlers ───────────────────────────────────────────────────────────
  function openCreateForm() {
    setEditingId(null)
    setFormData(EMPTY_FORM)
    setFormTab('identity')
    setFormOpen(true)
  }

  function openEditForm(record: ContractorRecord) {
    setEditingId(record.id)
    setFormData({
      contractorName: record.contractorName,
      contractorAddress: record.contractorAddress,
      registrationNumber: record.registrationNumber,
      dateOfRegistration: record.dateOfRegistration?.slice(0, 10) ?? '',
      contractorPan: record.contractorPan,
      establishmentName: record.establishmentName,
      establishmentAddress: record.establishmentAddress,
      natureOfWork: record.natureOfWork,
      maxWorkmen: record.maxWorkmen,
      licensingAuthority: record.licensingAuthority,
      licenseNumber: record.licenseNumber,
      licenseIssueDate: record.licenseIssueDate?.slice(0, 10) ?? '',
      licenseValidFrom: record.licenseValidFrom?.slice(0, 10) ?? '',
      licenseValidTo: record.licenseValidTo?.slice(0, 10) ?? '',
      licenseFee: record.licenseFee,
      contractorGstin: record.contractorGstin,
      pfRegistrationNumber: record.pfRegistrationNumber,
      contactPersonName: record.contactPersonName,
      contactPersonDesignation: record.contactPersonDesignation,
      contactPersonMobile: record.contactPersonMobile,
      contactPersonEmail: record.contactPersonEmail,
      bankName: record.bankName,
      bankAccountNumber: record.bankAccountNumber,
      bankIfscCode: record.bankIfscCode,
      esiRegistrationNumber: record.esiRegistrationNumber,
      contractStartDate: record.contractStartDate?.slice(0, 10) ?? '',
      contractEndDate: record.contractEndDate?.slice(0, 10) ?? '',
      contractValue: record.contractValue,
      securityDeposit: record.securityDeposit,
      contractWorkLocation: record.contractWorkLocation,
      remarks: record.remarks,
    })
    setFormTab('identity')
    setFormOpen(true)
  }

  function openView(record: ContractorRecord) {
    setViewRecord(record)
    setViewTab('identity')
    setViewOpen(true)
  }

  function updateForm(field: string, value: string | number) {
    setFormData(prev => ({ ...prev, [field]: value }))
  }

  async function handleSave() {
    if (!formData.contractorName.trim()) {
      toast.error('Contractor name is required')
      return
    }
    setSaving(true)
    try {
      const url = editingId ? `/api/contractors/${editingId}` : '/api/contractors'
      const method = editingId ? 'PUT' : 'POST'
      const res = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(formData),
      })
      if (!res.ok) {
        const err = await res.json().catch(() => ({ error: 'Save failed' }))
        throw new Error(err.error || 'Save failed')
      }
      toast.success(editingId ? 'Contractor updated successfully' : 'Contractor added successfully')
      setFormOpen(false)
      fetchRecords()
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Failed to save')
    } finally {
      setSaving(false)
    }
  }

  async function handleDelete() {
    if (!deleteId) return
    setDeleting(true)
    try {
      const res = await fetch(`/api/contractors/${deleteId}`, { method: 'DELETE' })
      if (!res.ok) throw new Error('Delete failed')
      toast.success('Contractor deleted successfully')
      setDeleteId(null)
      fetchRecords()
    } catch {
      toast.error('Failed to delete contractor')
    } finally {
      setDeleting(false)
    }
  }

  function handleSort(column: string) {
    if (sortBy === column) {
      setSortOrder(prev => prev === 'asc' ? 'desc' : 'asc')
    } else {
      setSortBy(column)
      setSortOrder('asc')
    }
    setPagination(prev => ({ ...prev, page: 1 }))
  }

  function goToPage(page: number) {
    setPagination(prev => ({ ...prev, page }))
  }

  // ── License status helper ──────────────────────────────────────────────
  function getLicenseStatus(record: ContractorRecord) {
    if (!record.licenseValidTo) return { label: 'No Date', variant: 'secondary' as const }
    const now = new Date()
    const validTo = new Date(record.licenseValidTo)
    const daysLeft = Math.ceil((validTo.getTime() - now.getTime()) / (1000 * 60 * 60 * 24))
    if (daysLeft < 0) return { label: 'Expired', variant: 'destructive' as const }
    if (daysLeft <= 30) return { label: `${daysLeft}d Left`, variant: 'outline' as const }
    return { label: 'Active', variant: 'default' as const }
  }

  // ── Render ─────────────────────────────────────────────────────────────
  return (
    <main className="min-h-screen bg-background">
      {/* Header */}
      <header className="sticky top-0 z-40 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/60">
        <div className="mx-auto max-w-[1600px] px-4 sm:px-6 lg:px-8">
          <div className="flex h-14 items-center justify-between gap-4">
            <div className="flex items-center gap-3">
              <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                <ClipboardList className="h-5 w-5" />
              </div>
              <div>
                <h1 className="text-lg font-semibold leading-tight">Form A — Labour Contractor Register</h1>
                <p className="text-xs text-muted-foreground hidden sm:block">Contract Labour (R&A) Act, 1970 — Rule 75</p>
              </div>
            </div>
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                onClick={() => exportCSV(records)}
                disabled={!records.length || loading}
              >
                <Download className="h-4 w-4 mr-1.5" />
                <span className="hidden sm:inline">Export CSV</span>
              </Button>
              <Button size="sm" onClick={openCreateForm}>
                <Plus className="h-4 w-4 mr-1.5" />
                <span className="hidden sm:inline">Add Contractor</span>
                <span className="sm:hidden">Add</span>
              </Button>
            </div>
          </div>
        </div>
      </header>

      {/* Search & Stats Bar */}
      <div className="mx-auto max-w-[1600px] px-4 sm:px-6 lg:px-8 py-4">
        <div className="flex flex-col sm:flex-row gap-3 items-start sm:items-center justify-between">
          <div className="relative w-full sm:max-w-sm">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder="Search contractors, license, establishment..."
              value={searchQuery}
              onChange={e => { setSearchQuery(e.target.value); setPagination(prev => ({ ...prev, page: 1 })) }}
              className="pl-9 h-9"
            />
            {searchQuery && (
              <button
                onClick={() => { setSearchQuery(''); setPagination(prev => ({ ...prev, page: 1 })) }}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
              >
                <X className="h-3.5 w-3.5" />
              </button>
            )}
          </div>
          <div className="flex items-center gap-4 text-sm text-muted-foreground">
            <span className="flex items-center gap-1.5">
              <Users className="h-4 w-4" />
              <strong className="text-foreground">{pagination.total}</strong> contractor{pagination.total !== 1 ? 's' : ''}
            </span>
            {pagination.totalPages > 1 && (
              <span>Page {pagination.page} of {pagination.totalPages}</span>
            )}
          </div>
        </div>
      </div>

      {/* Data Table */}
      <div className="mx-auto max-w-[1600px] px-4 sm:px-6 lg:px-8 pb-6">
        <Card className="overflow-hidden">
          <CardContent className="p-0">
            <ScrollArea className="max-h-[calc(100vh-250px)]">
              <Table>
                <TableHeader>
                  <TableRow className="bg-muted/50 hover:bg-muted/50">
                    <TableHead className="w-12 text-center">#</TableHead>
                    {TABLE_COLUMNS.map(col => (
                      <TableHead
                        key={col.key}
                        style={{ minWidth: col.minW }}
                        className="cursor-pointer select-none"
                        onClick={() => handleSort(col.key)}
                      >
                        <div className="flex items-center gap-1">
                          <col.icon className="h-3.5 w-3.5 text-muted-foreground" />
                          {col.label}
                          {sortBy === col.key && (
                            <ArrowUpDown className={`h-3 w-3 ${sortOrder === 'desc' ? 'rotate-180' : ''} transition-transform`} />
                          )}
                        </div>
                      </TableHead>
                    ))}
                    <TableHead className="w-32 text-center">Status</TableHead>
                    <TableHead className="w-28 text-center">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {loading ? (
                    Array.from({ length: 5 }).map((_, i) => (
                      <TableRow key={`skel-${i}`}>
                        <TableCell className="text-center"><Skeleton className="h-4 w-6 mx-auto" /></TableCell>
                        {TABLE_COLUMNS.map((col, j) => (
                          <TableCell key={j}><Skeleton className="h-4 w-full max-w-[120px]" /></TableCell>
                        ))}
                        <TableCell><Skeleton className="h-6 w-16 mx-auto" /></TableCell>
                        <TableCell><Skeleton className="h-8 w-20 mx-auto" /></TableCell>
                      </TableRow>
                    ))
                  ) : records.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={TABLE_COLUMNS.length + 3} className="h-40 text-center">
                        <div className="flex flex-col items-center gap-2 text-muted-foreground">
                          <Building2 className="h-10 w-10 opacity-30" />
                          <p className="text-sm font-medium">No contractors found</p>
                          <p className="text-xs">
                            {debouncedSearch ? 'Try a different search term' : 'Click "Add Contractor" to create your first entry'}
                          </p>
                        </div>
                      </TableCell>
                    </TableRow>
                  ) : (
                    records.map((record, idx) => {
                      const status = getLicenseStatus(record)
                      return (
                        <TableRow key={record.id}>
                          <TableCell className="text-center text-xs text-muted-foreground font-mono">
                            {(pagination.page - 1) * pagination.limit + idx + 1}
                          </TableCell>
                          <TableCell className="font-medium">{record.contractorName}</TableCell>
                          <TableCell className="font-mono text-xs">{record.registrationNumber || '—'}</TableCell>
                          <TableCell>{record.establishmentName || '—'}</TableCell>
                          <TableCell>{record.natureOfWork || '—'}</TableCell>
                          <TableCell className="text-center font-mono">{record.maxWorkmen || '—'}</TableCell>
                          <TableCell className="font-mono text-xs">{record.licenseNumber || '—'}</TableCell>
                          <TableCell className="text-xs">{formatDate(record.licenseValidTo)}</TableCell>
                          <TableCell>{record.contactPersonName || '—'}</TableCell>
                          <TableCell className="font-mono text-xs">{record.contactPersonMobile || '—'}</TableCell>
                          <TableCell className="font-mono text-xs">{formatCurrency(record.contractValue)}</TableCell>
                          <TableCell className="text-xs">{record.contractWorkLocation || '—'}</TableCell>
                          <TableCell className="font-mono text-xs">{record.contractorGstin || '—'}</TableCell>
                          <TableCell className="text-center">
                            <BadgeUI variant={status.variant} className="text-[10px] px-1.5 py-0">
                              {status.label}
                            </BadgeUI>
                          </TableCell>
                          <TableCell>
                            <div className="flex items-center justify-center gap-1">
                              <Button
                                variant="ghost" size="sm" className="h-7 w-7 p-0"
                                onClick={() => openView(record)} title="View"
                              >
                                <Eye className="h-3.5 w-3.5" />
                              </Button>
                              <Button
                                variant="ghost" size="sm" className="h-7 w-7 p-0"
                                onClick={() => openEditForm(record)} title="Edit"
                              >
                                <Pencil className="h-3.5 w-3.5" />
                              </Button>
                              <Button
                                variant="ghost" size="sm" className="h-7 w-7 p-0 text-destructive hover:text-destructive"
                                onClick={() => setDeleteId(record.id)} title="Delete"
                              >
                                <Trash2 className="h-3.5 w-3.5" />
                              </Button>
                            </div>
                          </TableCell>
                        </TableRow>
                      )
                    })
                  )}
                </TableBody>
              </Table>
            </ScrollArea>

            {/* Pagination */}
            {pagination.totalPages > 1 && (
              <div className="flex items-center justify-between border-t px-4 py-3">
                <p className="text-xs text-muted-foreground">
                  Showing {(pagination.page - 1) * pagination.limit + 1}–
                  {Math.min(pagination.page * pagination.limit, pagination.total)} of {pagination.total}
                </p>
                <div className="flex items-center gap-1">
                  <Button
                    variant="outline" size="sm" className="h-8 w-8 p-0"
                    disabled={pagination.page <= 1}
                    onClick={() => goToPage(pagination.page - 1)}
                  >
                    <ChevronLeft className="h-4 w-4" />
                  </Button>
                  {Array.from({ length: Math.min(5, pagination.totalPages) }, (_, i) => {
                    let pageNum: number
                    if (pagination.totalPages <= 5) {
                      pageNum = i + 1
                    } else if (pagination.page <= 3) {
                      pageNum = i + 1
                    } else if (pagination.page >= pagination.totalPages - 2) {
                      pageNum = pagination.totalPages - 4 + i
                    } else {
                      pageNum = pagination.page - 2 + i
                    }
                    return (
                      <Button
                        key={pageNum}
                        variant={pageNum === pagination.page ? 'default' : 'outline'}
                        size="sm" className="h-8 w-8 p-0"
                        onClick={() => goToPage(pageNum)}
                      >
                        {pageNum}
                      </Button>
                    )
                  })}
                  <Button
                    variant="outline" size="sm" className="h-8 w-8 p-0"
                    disabled={pagination.page >= pagination.totalPages}
                    onClick={() => goToPage(pagination.page + 1)}
                  >
                    <ChevronRight className="h-4 w-4" />
                  </Button>
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* ═══════════ CREATE / EDIT DIALOG ═══════════ */}
      <Dialog open={formOpen} onOpenChange={open => { setFormOpen(open); if (!open) setEditingId(null) }}>
        <DialogContent className="sm:max-w-3xl max-h-[90vh] flex flex-col p-0 gap-0">
          <div className="px-6 pt-6 pb-0">
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2">
                {editingId ? <Pencil className="h-5 w-5" /> : <Plus className="h-5 w-5" />}
                {editingId ? 'Edit Contractor' : 'Add New Contractor'}
              </DialogTitle>
              <DialogDescription>
                {editingId ? 'Update contractor details in the register.' : 'Fill in the details to add a new contractor to the register.'}
              </DialogDescription>
            </DialogHeader>
          </div>

          <Tabs value={formTab} onValueChange={setFormTab} className="flex-1 flex flex-col px-6">
            <TabsList className="w-full justify-start rounded-none border-b bg-transparent p-0 h-auto">
              <TabsTrigger value="identity" className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:shadow-none px-4 py-2">Identity</TabsTrigger>
              <TabsTrigger value="license" className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:shadow-none px-4 py-2">License & Work</TabsTrigger>
              <TabsTrigger value="compliance" className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:shadow-none px-4 py-2">Compliance</TabsTrigger>
              <TabsTrigger value="contact" className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:shadow-none px-4 py-2">Contact & Bank</TabsTrigger>
              <TabsTrigger value="contract" className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:shadow-none px-4 py-2">Contract</TabsTrigger>
            </TabsList>

            <ScrollArea className="flex-1 max-h-[50vh] py-4">
              {/* ── Tab 1: Identity ─────────────────────── */}
              <TabsContent value="identity" className="mt-0">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <FormField label="Contractor Name" required>
                    <Input value={formData.contractorName} onChange={e => updateForm('contractorName', e.target.value)} placeholder="Enter contractor name" />
                  </FormField>
                  <FormField label="Registration Number">
                    <Input value={formData.registrationNumber} onChange={e => updateForm('registrationNumber', e.target.value)} placeholder="e.g. MH/BN/2024/001" />
                  </FormField>
                  <FormField label="Date of Registration">
                    <Input type="date" value={formData.dateOfRegistration} onChange={e => updateForm('dateOfRegistration', e.target.value)} />
                  </FormField>
                  <FormField label="PAN of Contractor">
                    <Input value={formData.contractorPan} onChange={e => updateForm('contractorPan', e.target.value)} placeholder="e.g. ABCDE1234F" className="uppercase" maxLength={10} />
                  </FormField>
                  <div className="sm:col-span-2">
                    <FormField label="Contractor Address">
                      <Textarea value={formData.contractorAddress} onChange={e => updateForm('contractorAddress', e.target.value)} placeholder="Full address of contractor" rows={2} />
                    </FormField>
                  </div>
                  <Separator className="sm:col-span-2" />
                  <FormField label="Establishment Name (Principal Employer)">
                    <Input value={formData.establishmentName} onChange={e => updateForm('establishmentName', e.target.value)} placeholder="Name of principal establishment" />
                  </FormField>
                  <div className="sm:col-span-2">
                    <FormField label="Establishment Address">
                      <Textarea value={formData.establishmentAddress} onChange={e => updateForm('establishmentAddress', e.target.value)} placeholder="Address of establishment" rows={2} />
                    </FormField>
                  </div>
                </div>
              </TabsContent>

              {/* ── Tab 2: License & Work ───────────────── */}
              <TabsContent value="license" className="mt-0">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <FormField label="Nature of Work">
                    <Input value={formData.natureOfWork} onChange={e => updateForm('natureOfWork', e.target.value)} placeholder="e.g. Housekeeping, Security" />
                  </FormField>
                  <FormField label="Maximum No. of Workmen">
                    <Input type="number" min={0} value={formData.maxWorkmen || ''} onChange={e => updateForm('maxWorkmen', parseInt(e.target.value) || 0)} placeholder="0" />
                  </FormField>
                  <FormField label="Licensing Authority">
                    <Input value={formData.licensingAuthority} onChange={e => updateForm('licensingAuthority', e.target.value)} placeholder="e.g. Labour Commissioner, Mumbai" />
                  </FormField>
                  <FormField label="License Number">
                    <Input value={formData.licenseNumber} onChange={e => updateForm('licenseNumber', e.target.value)} placeholder="License / Registration number" />
                  </FormField>
                  <FormField label="Date of Issue of License">
                    <Input type="date" value={formData.licenseIssueDate} onChange={e => updateForm('licenseIssueDate', e.target.value)} />
                  </FormField>
                  <FormField label="License Fee Paid (₹)">
                    <Input type="number" step="0.01" min={0} value={formData.licenseFee || ''} onChange={e => updateForm('licenseFee', parseFloat(e.target.value) || 0)} placeholder="0.00" />
                  </FormField>
                  <FormField label="License Valid From">
                    <Input type="date" value={formData.licenseValidFrom} onChange={e => updateForm('licenseValidFrom', e.target.value)} />
                  </FormField>
                  <FormField label="License Valid To">
                    <Input type="date" value={formData.licenseValidTo} onChange={e => updateForm('licenseValidTo', e.target.value)} />
                  </FormField>
                  <FormField label="Work Location / Site Address">
                    <Input value={formData.contractWorkLocation} onChange={e => updateForm('contractWorkLocation', e.target.value)} placeholder="Actual work site address" />
                  </FormField>
                </div>
              </TabsContent>

              {/* ── Tab 3: Compliance ───────────────────── */}
              <TabsContent value="compliance" className="mt-0">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <FormField label="GSTIN of Contractor">
                    <Input value={formData.contractorGstin} onChange={e => updateForm('contractorGstin', e.target.value)} placeholder="e.g. 27ABCDE1234F1Z5" className="uppercase" maxLength={15} />
                  </FormField>
                  <FormField label="PF Registration Number">
                    <Input value={formData.pfRegistrationNumber} onChange={e => updateForm('pfRegistrationNumber', e.target.value)} placeholder="e.g. MHBAN0012345000" className="uppercase" />
                  </FormField>
                  <FormField label="ESI Registration Number">
                    <Input value={formData.esiRegistrationNumber} onChange={e => updateForm('esiRegistrationNumber', e.target.value)} placeholder="e.g. 31-00-123456-000-0001" className="uppercase" />
                  </FormField>
                </div>
              </TabsContent>

              {/* ── Tab 4: Contact & Bank ──────────────── */}
              <TabsContent value="contact" className="mt-0">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <FormField label="Contact Person Name">
                    <Input value={formData.contactPersonName} onChange={e => updateForm('contactPersonName', e.target.value)} placeholder="Full name" />
                  </FormField>
                  <FormField label="Designation">
                    <Input value={formData.contactPersonDesignation} onChange={e => updateForm('contactPersonDesignation', e.target.value)} placeholder="e.g. Manager, Proprietor" />
                  </FormField>
                  <FormField label="Mobile Number">
                    <Input value={formData.contactPersonMobile} onChange={e => updateForm('contactPersonMobile', e.target.value)} placeholder="10-digit mobile" maxLength={10} />
                  </FormField>
                  <FormField label="Email Address">
                    <Input type="email" value={formData.contactPersonEmail} onChange={e => updateForm('contactPersonEmail', e.target.value)} placeholder="email@example.com" />
                  </FormField>
                  <Separator className="sm:col-span-2" />
                  <FormField label="Bank Name">
                    <Input value={formData.bankName} onChange={e => updateForm('bankName', e.target.value)} placeholder="e.g. State Bank of India" />
                  </FormField>
                  <FormField label="Bank Account Number">
                    <Input value={formData.bankAccountNumber} onChange={e => updateForm('bankAccountNumber', e.target.value)} placeholder="Account number" className="font-mono" />
                  </FormField>
                  <FormField label="Bank IFSC Code">
                    <Input value={formData.bankIfscCode} onChange={e => updateForm('bankIfscCode', e.target.value)} placeholder="e.g. SBIN0001234" className="uppercase" maxLength={11} />
                  </FormField>
                </div>
              </TabsContent>

              {/* ── Tab 5: Contract ──────────────────────── */}
              <TabsContent value="contract" className="mt-0">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  <FormField label="Contract Period From">
                    <Input type="date" value={formData.contractStartDate} onChange={e => updateForm('contractStartDate', e.target.value)} />
                  </FormField>
                  <FormField label="Contract Period To">
                    <Input type="date" value={formData.contractEndDate} onChange={e => updateForm('contractEndDate', e.target.value)} />
                  </FormField>
                  <FormField label="Contract Value (₹)">
                    <Input type="number" step="0.01" min={0} value={formData.contractValue || ''} onChange={e => updateForm('contractValue', parseFloat(e.target.value) || 0)} placeholder="0.00" />
                  </FormField>
                  <FormField label="Security Deposit (₹)">
                    <Input type="number" step="0.01" min={0} value={formData.securityDeposit || ''} onChange={e => updateForm('securityDeposit', parseFloat(e.target.value) || 0)} placeholder="0.00" />
                  </FormField>
                  <div className="sm:col-span-2">
                    <FormField label="Remarks">
                      <Textarea value={formData.remarks} onChange={e => updateForm('remarks', e.target.value)} placeholder="Any additional remarks or notes" rows={3} />
                    </FormField>
                  </div>
                </div>
              </TabsContent>
            </ScrollArea>
          </Tabs>

          <div className="px-6 py-4 border-t flex flex-col-reverse sm:flex-row gap-2 sm:justify-end bg-muted/30">
            <Button variant="outline" onClick={() => setFormOpen(false)} disabled={saving}>Cancel</Button>
            <Button onClick={handleSave} disabled={saving}>
              {saving && <Loader2 className="h-4 w-4 mr-1.5 animate-spin" />}
              {editingId ? 'Update Contractor' : 'Add Contractor'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* ═══════════ VIEW DIALOG ═══════════ */}
      <Dialog open={viewOpen} onOpenChange={setViewOpen}>
        <DialogContent className="sm:max-w-3xl max-h-[90vh] flex flex-col p-0 gap-0">
          {viewRecord && (
            <>
              <div className="px-6 pt-6 pb-0">
                <DialogHeader>
                  <DialogTitle className="flex items-center gap-2">
                    <Eye className="h-5 w-5" />
                    {viewRecord.contractorName}
                  </DialogTitle>
                  <DialogDescription>
                    Contractor Register — View Details
                  </DialogDescription>
                </DialogHeader>
              </div>

              <Tabs value={viewTab} onValueChange={setViewTab} className="flex-1 flex flex-col px-6">
                <TabsList className="w-full justify-start rounded-none border-b bg-transparent p-0 h-auto">
                  <TabsTrigger value="identity" className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:shadow-none px-4 py-2">Identity</TabsTrigger>
                  <TabsTrigger value="license" className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:shadow-none px-4 py-2">License & Work</TabsTrigger>
                  <TabsTrigger value="compliance" className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:shadow-none px-4 py-2">Compliance</TabsTrigger>
                  <TabsTrigger value="contact" className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:shadow-none px-4 py-2">Contact & Bank</TabsTrigger>
                  <TabsTrigger value="contract" className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:shadow-none px-4 py-2">Contract</TabsTrigger>
                </TabsList>

                <ScrollArea className="flex-1 max-h-[55vh] py-4">
                  <AnimatePresence mode="wait">
                    <motion.div
                      key={viewTab}
                      initial={{ opacity: 0, x: 10 }}
                      animate={{ opacity: 1, x: 0 }}
                      exit={{ opacity: 0, x: -10 }}
                      transition={{ duration: 0.15 }}
                    >
                      {viewTab === 'identity' && (
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                          <ViewField label="Contractor Name" value={viewRecord.contractorName} />
                          <ViewField label="Registration Number" value={viewRecord.registrationNumber} />
                          <ViewField label="Date of Registration" value={formatDate(viewRecord.dateOfRegistration)} />
                          <ViewField label="PAN" value={viewRecord.contractorPan} />
                          <div className="sm:col-span-2"><ViewField label="Contractor Address" value={viewRecord.contractorAddress} /></div>
                          <ViewField label="Establishment Name" value={viewRecord.establishmentName} />
                          <div className="sm:col-span-2"><ViewField label="Establishment Address" value={viewRecord.establishmentAddress} /></div>
                        </div>
                      )}
                      {viewTab === 'license' && (
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                          <ViewField label="Nature of Work" value={viewRecord.natureOfWork} />
                          <ViewField label="Max. Workmen" value={String(viewRecord.maxWorkmen)} />
                          <ViewField label="Licensing Authority" value={viewRecord.licensingAuthority} />
                          <ViewField label="License Number" value={viewRecord.licenseNumber} />
                          <ViewField label="License Issue Date" value={formatDate(viewRecord.licenseIssueDate)} />
                          <ViewField label="License Fee" value={formatCurrency(viewRecord.licenseFee)} />
                          <ViewField label="License Valid From" value={formatDate(viewRecord.licenseValidFrom)} />
                          <ViewField label="License Valid To" value={formatDate(viewRecord.licenseValidTo)} />
                          <ViewField label="Work Location" value={viewRecord.contractWorkLocation} />
                        </div>
                      )}
                      {viewTab === 'compliance' && (
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                          <ViewField label="GSTIN" value={viewRecord.contractorGstin} />
                          <ViewField label="PF Registration No" value={viewRecord.pfRegistrationNumber} />
                          <ViewField label="ESI Registration No" value={viewRecord.esiRegistrationNumber} />
                        </div>
                      )}
                      {viewTab === 'contact' && (
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                          <ViewField label="Contact Person" value={viewRecord.contactPersonName} />
                          <ViewField label="Designation" value={viewRecord.contactPersonDesignation} />
                          <ViewField label="Mobile" value={viewRecord.contactPersonMobile} />
                          <ViewField label="Email" value={viewRecord.contactPersonEmail} />
                          <Separator className="sm:col-span-2 my-1" />
                          <ViewField label="Bank Name" value={viewRecord.bankName} />
                          <ViewField label="Account Number" value={viewRecord.bankAccountNumber} />
                          <ViewField label="IFSC Code" value={viewRecord.bankIfscCode} />
                        </div>
                      )}
                      {viewTab === 'contract' && (
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                          <ViewField label="Contract Start" value={formatDate(viewRecord.contractStartDate)} />
                          <ViewField label="Contract End" value={formatDate(viewRecord.contractEndDate)} />
                          <ViewField label="Contract Value" value={formatCurrency(viewRecord.contractValue)} />
                          <ViewField label="Security Deposit" value={formatCurrency(viewRecord.securityDeposit)} />
                          <div className="sm:col-span-2"><ViewField label="Remarks" value={viewRecord.remarks} /></div>
                          <Separator className="sm:col-span-2 my-1" />
                          <ViewField label="Created" value={new Date(viewRecord.createdAt).toLocaleString('en-IN')} />
                          <ViewField label="Last Updated" value={new Date(viewRecord.updatedAt).toLocaleString('en-IN')} />
                        </div>
                      )}
                    </motion.div>
                  </AnimatePresence>
                </ScrollArea>
              </Tabs>

              <div className="px-6 py-4 border-t flex justify-end gap-2 bg-muted/30">
                <Button variant="outline" onClick={() => { setViewOpen(false); openEditForm(viewRecord) }}>
                  <Pencil className="h-4 w-4 mr-1.5" />Edit
                </Button>
                <Button onClick={() => setViewOpen(false)}>Close</Button>
              </div>
            </>
          )}
        </DialogContent>
      </Dialog>

      {/* ═══════════ DELETE CONFIRMATION ═══════════ */}
      <AlertDialog open={!!deleteId} onOpenChange={open => { if (!open) setDeleteId(null) }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Contractor</AlertDialogTitle>
            <AlertDialogDescription>
              This action cannot be undone. The contractor record and all associated data will be permanently removed from the register.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deleting}>Cancel</AlertDialogCancel>
            <Button
              variant="destructive"
              onClick={handleDelete}
              disabled={deleting}
            >
              {deleting && <Loader2 className="h-4 w-4 mr-1.5 animate-spin" />}
              Delete
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </main>
  )
}

// ── View Field Component ────────────────────────────────────────────────

function ViewField({ label, value }: { label: string; value: string }) {
  return (
    <div className="space-y-0.5">
      <p className="text-xs font-medium text-muted-foreground">{label}</p>
      <p className="text-sm">{value || <span className="text-muted-foreground italic">Not provided</span>}</p>
    </div>
  )
}