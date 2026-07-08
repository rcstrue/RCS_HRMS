import { useState, useEffect, useCallback } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Badge } from '@/components/ui/badge';
import { toast } from 'sonner';
import { Loader2, Plus, Trash2, Edit2, Briefcase, Zap, AlertTriangle, CheckCircle2, ArrowRight } from 'lucide-react';
import {
  getDesignations,
  createDesignation,
  updateDesignation,
  deleteDesignation,
  getAutoRolePreview,
  applyAutoRole,
  Designation,
  AutoRoleDesignation,
  AutoRolePreview,
  AutoRoleApplyResult,
} from '@/lib/api/designations';

const ROLE_LABELS: Record<string, string> = {
  regional_manager: 'Regional Manager',
  manager: 'Manager',
  supervisor: 'Supervisor',
  employee: 'Employee',
  field_officer: 'Field Officer',
};

const ROLE_COLORS: Record<string, string> = {
  regional_manager: 'bg-purple-500/10 text-purple-700 border-purple-500/30',
  manager: 'bg-blue-500/10 text-blue-700 border-blue-500/30',
  supervisor: 'bg-amber-500/10 text-amber-700 border-amber-500/30',
  employee: 'bg-gray-500/10 text-gray-600 border-gray-500/30',
  field_officer: 'bg-blue-500/10 text-blue-700 border-blue-500/30',
};

function RoleBadge({ role }: { role: string }) {
  const label = ROLE_LABELS[role] || role || 'Employee';
  const color = ROLE_COLORS[role] || ROLE_COLORS['employee'];
  return (
    <Badge variant="outline" className={color}>
      {label}
    </Badge>
  );
}

export function DesignationManagement() {
  const [designations, setDesignations] = useState<Designation[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [isDialogOpen, setIsDialogOpen] = useState(false);
  const [editingDesignation, setEditingDesignation] = useState<Designation | null>(null);
  const [newName, setNewName] = useState('');
  const [newDesiView, setNewDesiView] = useState(1);
  const [deleteConfirm, setDeleteConfirm] = useState<Designation | null>(null);

  // Auto-role state
  const [autoRoleData, setAutoRoleData] = useState<AutoRolePreview | null>(null);
  const [isLoadingAutoRole, setIsLoadingAutoRole] = useState(false);
  const [isApplyingAutoRole, setIsApplyingAutoRole] = useState(false);
  const [applyResult, setApplyResult] = useState<AutoRoleApplyResult | null>(null);

  const fetchDesignations = useCallback(async () => {
    setIsLoading(true);
    const { data, error } = await getDesignations();
    if (data) {
      setDesignations(data);
    } else if (error) {
      toast.error('Failed to load designations');
    }
    setIsLoading(false);
  }, []);

  useEffect(() => {
    fetchDesignations();
  }, [fetchDesignations]);

  const handleOpenDialog = (designation?: Designation) => {
    if (designation) {
      setEditingDesignation(designation);
      setNewName(designation.name);
      setNewDesiView(designation.desi_view ?? 1);
    } else {
      setEditingDesignation(null);
      setNewName('');
      setNewDesiView(1);
    }
    setIsDialogOpen(true);
  };

  const handleCloseDialog = () => {
    setIsDialogOpen(false);
    setEditingDesignation(null);
    setNewName('');
    setNewDesiView(1);
  };

  const handleSave = async () => {
    if (!newName.trim()) {
      toast.error('Designation name is required');
      return;
    }

    setIsSaving(true);

    if (editingDesignation) {
      const { error } = await updateDesignation(editingDesignation.id, {
        name: newName.trim(),
        desi_view: newDesiView
      });
      if (error) {
        toast.error('Failed to update designation');
      } else {
        toast.success('Designation updated');
        fetchDesignations();
        handleCloseDialog();
      }
    } else {
      const { error } = await createDesignation(newName.trim(), newDesiView);
      if (error) {
        toast.error('Failed to create designation');
      } else {
        toast.success('Designation created');
        fetchDesignations();
        handleCloseDialog();
      }
    }
    setIsSaving(false);
  };

  const handleDelete = async () => {
    if (!deleteConfirm) return;

    setIsSaving(true);
    const { error } = await deleteDesignation(deleteConfirm.id);
    if (error) {
      toast.error('Failed to delete designation');
    } else {
      toast.success('Designation deleted');
      fetchDesignations();
    }
    setDeleteConfirm(null);
    setIsSaving(false);
  };

  const handleToggleView = async (designation: Designation) => {
    const newValue = (designation.desi_view ?? 1) === 1 ? 0 : 1;
    const { error, data } = await updateDesignation(designation.id, { desi_view: newValue });
    if (error) {
      toast.error('Failed to update: ' + error);
      fetchDesignations();
    } else {
      if (data) {
        setDesignations(prev =>
          prev.map(d => d.id === designation.id ? { ...d, desi_view: data.desi_view } : d)
        );
        toast.success(data.desi_view === 1 ? 'Now visible in registration' : 'Hidden from registration');
      } else {
        fetchDesignations();
      }
    }
  };

  // ─── Auto Role: Load Preview ──────────────────────────────────
  const handleLoadAutoRolePreview = async () => {
    setIsLoadingAutoRole(true);
    setApplyResult(null);
    const { data, error } = await getAutoRolePreview();
    if (data) {
      setAutoRoleData(data);
    } else {
      toast.error('Failed to load role preview: ' + (error || 'Unknown error'));
    }
    setIsLoadingAutoRole(false);
  };

  // ─── Auto Role: Apply ─────────────────────────────────────────
  const handleApplyAutoRole = async () => {
    setIsApplyingAutoRole(true);
    const { data, error } = await applyAutoRole();
    if (error) {
      toast.error('Failed to apply auto role: ' + error);
    } else if (data) {
      setApplyResult(data);
      toast.success(`Done! ${data.employees_updated} employees updated, ${data.admins_skipped} admins skipped.`);
      // Refresh the preview
      handleLoadAutoRolePreview();
      // Also refresh employee list
      fetchDesignations();
    }
    setIsApplyingAutoRole(false);
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="w-8 h-8 animate-spin text-primary" />
      </div>
    );
  }

  return (
    <>
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Briefcase className="w-5 h-5" />
              Designation Management
              <Badge variant="secondary" className="ml-2">{designations.length}</Badge>
            </div>
            <Button onClick={() => handleOpenDialog()} size="sm">
              <Plus className="w-4 h-4 mr-1" />
              Add Designation
            </Button>
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="rounded-md border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-12">#</TableHead>
                  <TableHead>Designation Name</TableHead>
                  <TableHead className="text-center w-40">Show in Registration</TableHead>
                  <TableHead className="text-right w-28">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {designations.map((designation, index) => (
                  <TableRow key={designation.id}>
                    <TableCell className="text-muted-foreground">{index + 1}</TableCell>
                    <TableCell className="font-medium">{designation.name}</TableCell>
                    <TableCell className="text-center">
                      <div className="flex items-center justify-center gap-2">
                        <Switch
                          checked={(designation.desi_view ?? 1) === 1}
                          onCheckedChange={() => handleToggleView(designation)}
                        />
                        <span className={`text-xs font-medium ${
                          (designation.desi_view ?? 1) === 1
                            ? 'text-green-600'
                            : 'text-muted-foreground'
                        }`}>
                          {(designation.desi_view ?? 1) === 1 ? 'Visible' : 'Hidden'}
                        </span>
                      </div>
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex justify-end gap-1">
                        <Button
                          variant="ghost"
                          size="icon"
                          onClick={() => handleOpenDialog(designation)}
                          className="h-8 w-8"
                        >
                          <Edit2 className="w-4 h-4" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon"
                          onClick={() => setDeleteConfirm(designation)}
                          className="h-8 w-8 text-destructive hover:text-destructive"
                        >
                          <Trash2 className="w-4 h-4" />
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
                {designations.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={4} className="text-center py-12 text-muted-foreground">
                      <Briefcase className="w-12 h-12 mx-auto mb-4 opacity-50" />
                      <p className="text-lg font-medium">No designations found</p>
                      <p className="text-sm mb-4">Add designations to assign to employees</p>
                      <Button
                        variant="outline"
                        onClick={() => handleOpenDialog()}
                        className="mt-2"
                      >
                        <Plus className="w-4 h-4 mr-2" />
                        Add your first designation
                      </Button>
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </div>

          <div className="mt-4 p-4 bg-muted/50 rounded-lg">
            <p className="text-sm text-muted-foreground">
              <strong>Show in Registration</strong>: When enabled, the designation will appear in the dropdown
              during employee registration. Disable to hide it from the list.
            </p>
          </div>
        </CardContent>
      </Card>

      {/* ═══════════════════════════════════════════════════════════ */}
      {/* AUTO ROLE ASSIGNMENT CARD                                    */}
      {/* ═══════════════════════════════════════════════════════════ */}
      <Card className="mt-6">
        <CardHeader>
          <CardTitle className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Zap className="w-5 h-5 text-amber-500" />
              Auto Assign App Role by Designation
            </div>
            <Button
              variant="outline"
              size="sm"
              onClick={handleLoadAutoRolePreview}
              disabled={isLoadingAutoRole}
            >
              {isLoadingAutoRole ? <Loader2 className="w-4 h-4 animate-spin mr-1" /> : null}
              {autoRoleData ? 'Refresh Preview' : 'Load Preview'}
            </Button>
          </CardTitle>
        </CardHeader>
        <CardContent>
          {!autoRoleData && !isLoadingAutoRole && (
            <div className="text-center py-10 text-muted-foreground">
              <Zap className="w-12 h-12 mx-auto mb-4 opacity-30" />
              <p className="text-lg font-medium mb-1">Auto Role Assignment</p>
              <p className="text-sm max-w-md mx-auto">
                Click &quot;Load Preview&quot; to see how each designation will be mapped to an app_role,
                then apply the changes in one click.
              </p>
            </div>
          )}

          {isLoadingAutoRole && (
            <div className="flex items-center justify-center py-10">
              <Loader2 className="w-8 h-8 animate-spin text-primary" />
            </div>
          )}

          {autoRoleData && !isLoadingAutoRole && (
            <>
              {/* Summary bar */}
              <div className="flex flex-wrap items-center gap-4 mb-4 p-4 bg-muted/50 rounded-lg">
                <div>
                  <span className="text-sm text-muted-foreground">Total Designations:</span>
                  <span className="ml-1 font-semibold">{autoRoleData.total_designations}</span>
                </div>
                <div>
                  <span className="text-sm text-muted-foreground">Total Employees:</span>
                  <span className="ml-1 font-semibold">{autoRoleData.total_employees}</span>
                </div>
                <div className={autoRoleData.employees_needing_update > 0 ? 'text-orange-600' : 'text-green-600'}>
                  <AlertTriangle className="w-4 h-4 inline mr-1" />
                  <span className="text-sm font-semibold">
                    {autoRoleData.employees_needing_update} need role update
                  </span>
                </div>
              </div>

              {/* Designation → Role table */}
              <div className="rounded-md border mb-4">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead className="w-12">#</TableHead>
                      <TableHead>Designation</TableHead>
                      <TableHead className="text-center">Employees</TableHead>
                      <TableHead>Current Roles</TableHead>
                      <TableHead className="text-center w-44">
                        <div className="flex items-center justify-center gap-1">
                          Proposed Role
                          <ArrowRight className="w-3 h-3" />
                        </div>
                      </TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {autoRoleData.designations
                      .filter(d => d.total_employees > 0)
                      .map((d, index) => (
                        <TableRow key={d.designation_id} className={d.needs_update ? 'bg-orange-50/50' : ''}>
                          <TableCell className="text-muted-foreground">{index + 1}</TableCell>
                          <TableCell className="font-medium">{d.designation}</TableCell>
                          <TableCell className="text-center">
                            <span className="font-semibold">{d.total_employees}</span>
                          </TableCell>
                          <TableCell>
                            <div className="flex flex-wrap gap-1">
                              {Object.entries(d.current_roles).map(([role, count]) => (
                                <Badge key={role} variant="outline" className="text-xs">
                                  {ROLE_LABELS[role] || role}: {count}
                                </Badge>
                              ))}
                              {Object.keys(d.current_roles).length === 0 && (
                                <span className="text-xs text-muted-foreground">No employees</span>
                              )}
                            </div>
                          </TableCell>
                          <TableCell className="text-center">
                            <RoleBadge role={d.proposed_role} />
                            {d.needs_update && (
                              <AlertTriangle className="w-3.5 h-3.5 inline ml-1 text-orange-500" />
                            )}
                          </TableCell>
                        </TableRow>
                      ))}
                    {autoRoleData.designations.filter(d => d.total_employees > 0).length === 0 && (
                      <TableRow>
                        <TableCell colSpan={5} className="text-center py-8 text-muted-foreground">
                          No active employees found with any designation.
                        </TableCell>
                      </TableRow>
                    )}
                  </TableBody>
                </Table>
              </div>

              {/* Mapping rules info */}
              <div className="mb-4 p-4 bg-blue-50/50 rounded-lg border border-blue-200/50">
                <p className="text-sm font-medium text-blue-800 mb-2">Mapping Rules:</p>
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-1 text-xs text-blue-700">
                  <p>• Designation with &quot;Regional Manager&quot; → Regional Manager</p>
                  <p>• Designation with &quot;Manager&quot; / &quot;Field Officer&quot; / &quot;Area Manager&quot; → Manager</p>
                  <p>• Designation with &quot;Supervisor&quot; / &quot;Team Lead&quot; → Supervisor</p>
                  <p>• All other designations → Employee</p>
                </div>
              </div>

              {/* Apply button */}
              <div className="flex items-center justify-between">
                <p className="text-sm text-muted-foreground">
                  Admin employees (employee_role = admin) will be skipped.
                </p>
                <Button
                  onClick={handleApplyAutoRole}
                  disabled={isApplyingAutoRole || autoRoleData.employees_needing_update === 0}
                  className="min-w-[200px]"
                >
                  {isApplyingAutoRole ? (
                    <>
                      <Loader2 className="w-4 h-4 animate-spin mr-2" />
                      Applying...
                    </>
                  ) : (
                    <>
                      <Zap className="w-4 h-4 mr-2" />
                      Apply Auto Role
                    </>
                  )}
                </Button>
              </div>

              {/* Apply result */}
              {applyResult && (
                <div className="mt-4 p-4 bg-green-50/50 rounded-lg border border-green-200/50">
                  <div className="flex items-center gap-2 mb-2">
                    <CheckCircle2 className="w-5 h-5 text-green-600" />
                    <span className="font-semibold text-green-800">
                      Applied! {applyResult.employees_updated} employees updated
                    </span>
                  </div>
                  <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-sm text-green-700">
                    <div>Total: {applyResult.total_employees}</div>
                    <div>Updated: {applyResult.employees_updated}</div>
                    <div>Unchanged: {applyResult.employees_unchanged}</div>
                    <div>Admins Skipped: {applyResult.admins_skipped}</div>
                  </div>
                  {applyResult.errors.length > 0 && (
                    <div className="mt-2 text-sm text-red-600">
                      <p className="font-medium">Errors ({applyResult.errors.length}):</p>
                      {applyResult.errors.slice(0, 5).map((err, i) => (
                        <p key={i} className="text-xs">{err}</p>
                      ))}
                      {applyResult.errors.length > 5 && (
                        <p className="text-xs">...and {applyResult.errors.length - 5} more</p>
                      )}
                    </div>
                  )}
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>

      {/* Add/Edit Dialog */}
      <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {editingDesignation ? 'Edit Designation' : 'Add Designation'}
            </DialogTitle>
            <DialogDescription>
              {editingDesignation
                ? 'Update the designation name and visibility settings.'
                : 'Create a new designation for employee assignments.'}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <label className="text-sm font-medium">Designation Name *</label>
              <Input
                value={newName}
                onChange={(e) => setNewName(e.target.value)}
                placeholder="e.g. Security Guard"
              />
            </div>
            <div className="flex items-center justify-between rounded-lg border p-4">
              <div>
                <label className="text-sm font-medium">Show in Registration</label>
                <p className="text-xs text-muted-foreground mt-1">
                  When enabled, this designation will appear in the employee registration form
                </p>
              </div>
              <Switch
                checked={newDesiView === 1}
                onCheckedChange={(checked) => setNewDesiView(checked ? 1 : 0)}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={handleCloseDialog}>
              Cancel
            </Button>
            <Button onClick={handleSave} disabled={isSaving}>
              {isSaving ? <Loader2 className="w-4 h-4 animate-spin mr-2" /> : null}
              {editingDesignation ? 'Update' : 'Create'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation Dialog */}
      <Dialog open={!!deleteConfirm} onOpenChange={() => setDeleteConfirm(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete Designation</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete "{deleteConfirm?.name}"? This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDeleteConfirm(null)}>
              Cancel
            </Button>
            <Button variant="destructive" onClick={handleDelete} disabled={isSaving}>
              {isSaving ? <Loader2 className="w-4 h-4 animate-spin mr-2" /> : null}
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}