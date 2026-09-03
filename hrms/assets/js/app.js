/**
 * RCS HRMS Pro - Main JavaScript Application
 * Version: 1.0.0
 */

(function($) {
    'use strict';

    // Global Application Object
    window.HRMS = {
        
        // Initialize application
        init: function() {
            this.initSidebar();
            this.initDropdowns();
            this.initTooltips();
            this.initDataTables();
            this.initSelect2();
            this.initDatepickers();
            this.initFormValidation();
            this.initAjaxForms();
            this.initAlerts();
        },

        // Sidebar functionality
        initSidebar: function() {
            const $sidebar = $('#sidebar');
            const $overlay = $('#sidebar-overlay');
            const $mainContent = $('.main-content');
            const $toggleBtn = $('#sidebar-toggle');
            const $closeBtn = $('#sidebar-close');
            
            // Toggle sidebar (works on both desktop and mobile)
            $toggleBtn.on('click', function() {
                if ($(window).width() > 991.98) {
                    // Desktop: toggle collapsed class
                    $sidebar.toggleClass('collapsed');
                    $mainContent.toggleClass('expanded');
                    // Save state to localStorage
                    localStorage.setItem('sidebarCollapsed', $sidebar.hasClass('collapsed'));
                } else {
                    // Mobile: toggle show class
                    $sidebar.toggleClass('show');
                    $overlay.toggleClass('show');
                }
            });
            
            // Close sidebar when clicking close button (mobile)
            $closeBtn.on('click', function() {
                $sidebar.removeClass('show');
                $overlay.removeClass('show');
            });
            
            // Close sidebar when clicking overlay (mobile)
            $overlay.on('click', function() {
                $sidebar.removeClass('show');
                $overlay.removeClass('show');
            });
            
            // Restore sidebar state on page load (desktop)
            if ($(window).width() > 991.98) {
                const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                if (isCollapsed) {
                    $sidebar.addClass('collapsed');
                    $mainContent.addClass('expanded');
                }
            }
            
            // Submenu toggle
            $('.sidebar-item.has-submenu > .sidebar-link').on('click', function(e) {
                e.preventDefault();
                const $parent = $(this).parent();
                
                // Close other submenus
                $parent.siblings('.has-submenu').removeClass('open');
                
                // Toggle current submenu
                $parent.toggleClass('open');
            });
            
            // Auto-open active submenu
            $('.sidebar-item.has-submenu .sidebar-submenu a.active').each(function() {
                $(this).closest('.sidebar-item.has-submenu').addClass('open');
            });
            
            // Handle window resize
            $(window).on('resize', function() {
                if ($(window).width() > 991.98) {
                    // Desktop mode
                    $sidebar.removeClass('show');
                    $overlay.removeClass('show');
                } else {
                    // Mobile mode
                    $sidebar.removeClass('collapsed');
                    $mainContent.removeClass('expanded');
                }
            });
        },

        // Initialize dropdowns
        initDropdowns: function() {
            // Prevent dropdown from closing when clicking inside
            $('.dropdown-menu').on('click', function(e) {
                if (!$(e.target).is('a')) {
                    e.stopPropagation();
                }
            });
        },

        // Initialize Bootstrap tooltips
        initTooltips: function() {
            $('[data-bs-toggle="tooltip"]').tooltip();
        },

        // Initialize DataTables with common options
        initDataTables: function() {
            $.extend($.fn.dataTable.defaults, {
                responsive: true,
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                     '<"row"<"col-sm-12"tr>>' +
                     '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
                language: {
                    search: "",
                    searchPlaceholder: "Search...",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    paginate: {
                        first: '<i class="bi bi-chevron-double-left"></i>',
                        previous: '<i class="bi bi-chevron-left"></i>',
                        next: '<i class="bi bi-chevron-right"></i>',
                        last: '<i class="bi bi-chevron-double-right"></i>'
                    }
                }
            });
        },

        // Initialize Select2
        initSelect2: function() {
            $('.select2').select2({
                theme: 'bootstrap-5',
                width: '100%'
            });
            
            $('.select2-ajax').each(function() {
                const $this = $(this);
                $this.select2({
                    theme: 'bootstrap-5',
                    width: '100%',
                    ajax: {
                        url: $this.data('ajax-url'),
                        dataType: 'json',
                        delay: 250,
                        processResults: function(data) {
                            return { results: data };
                        }
                    },
                    minimumInputLength: 2
                });
            });
        },

        // Initialize date pickers
        initDatepickers: function() {
            $('.datepicker').flatpickr({
                dateFormat: 'd-m-Y',
                allowInput: true
            });
            
            $('.datetimepicker').flatpickr({
                dateFormat: 'd-m-Y H:i',
                enableTime: true,
                allowInput: true
            });
            
            $('.monthpicker').flatpickr({
                dateFormat: 'F Y',
                altFormat: 'F Y',
                allowInput: true,
                static: true
            });
        },

        // Form validation
        initFormValidation: function() {
            $('form.needs-validation').on('submit', function(e) {
                const $form = $(this);
                
                if (!$form[0].checkValidity()) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                
                $form.addClass('was-validated');
            });
            
            // Custom validation for password match
            $('[data-match]').on('input', function() {
                const $this = $(this);
                const $target = $($this.data('match'));
                
                if ($this.val() !== $target.val()) {
                    $this[0].setCustomValidity('Passwords do not match');
                } else {
                    $this[0].setCustomValidity('');
                }
            });
        },

        // AJAX form submission
        initAjaxForms: function() {
            $('form.ajax-form').on('submit', function(e) {
                e.preventDefault();
                
                const $form = $(this);
                const $btn = $form.find('[type="submit"]');
                const originalText = $btn.html();
                
                // Show loading state
                $btn.prop('disabled', true).html('<i class="bi bi-spinner-border spinner-border-sm me-2"></i>Saving...');
                
                $.ajax({
                    url: $form.attr('action'),
                    method: $form.attr('method') || 'POST',
                    data: new FormData($form[0]),
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            HRMS.showAlert('success', response.message || 'Saved successfully!');
                            
                            if (response.redirect) {
                                setTimeout(function() {
                                    window.location.href = response.redirect;
                                }, 1000);
                            } else if (response.reload) {
                                setTimeout(function() {
                                    window.location.reload();
                                }, 1000);
                            } else {
                                $form[0].reset();
                                $form.removeClass('was-validated');
                            }
                        } else {
                            HRMS.showAlert('danger', response.error || 'An error occurred');
                        }
                    },
                    error: function(xhr) {
                        let message = 'An error occurred';
                        if (xhr.responseJSON && xhr.responseJSON.error) {
                            message = xhr.responseJSON.error;
                        }
                        HRMS.showAlert('danger', message);
                    },
                    complete: function() {
                        $btn.prop('disabled', false).html(originalText);
                    }
                });
            });
        },

        // Auto-dismiss alerts
        initAlerts: function() {
            setTimeout(function() {
                $('.alert-dismissible').fadeOut('slow');
            }, 5000);
        },

        // Show alert message
        showAlert: function(type, message) {
            const alertHtml = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            
            $('.page-content').prepend(alertHtml);
            
            // Auto dismiss after 5 seconds
            setTimeout(function() {
                $('.alert-dismissible').first().fadeOut('slow', function() {
                    $(this).remove();
                });
            }, 5000);
        },

        // Show loading overlay
        showLoading: function(message) {
            const $overlay = $('#loading-overlay');
            if (message) {
                $overlay.find('p').text(message);
            }
            $overlay.show();
        },

        // Hide loading overlay
        hideLoading: function() {
            $('#loading-overlay').hide();
        },

        // Confirmation dialog
        confirm: function(message, callback) {
            const $modal = $('#confirmModal');
            $('#confirmMessage').text(message);
            
            const modal = new bootstrap.Modal($modal[0]);
            modal.show();
            
            $('#confirmBtn').off('click').on('click', function() {
                modal.hide();
                if (typeof callback === 'function') {
                    callback();
                }
            });
        },

        // Delete with confirmation
        deleteRecord: function(url, callback) {
            this.confirm('Are you sure you want to delete this record? This action cannot be undone.', function() {
                $.ajax({
                    url: url,
                    method: 'DELETE',
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            HRMS.showAlert('success', response.message || 'Record deleted successfully');
                            if (typeof callback === 'function') {
                                callback();
                            } else {
                                window.location.reload();
                            }
                        } else {
                            HRMS.showAlert('danger', response.error || 'Failed to delete record');
                        }
                    },
                    error: function() {
                        HRMS.showAlert('danger', 'An error occurred while deleting');
                    }
                });
            });
        },

        // Format currency
        formatCurrency: function(amount) {
            return '₹' + parseFloat(amount).toLocaleString('en-IN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        },

        // Format date
        formatDate: function(date, format) {
            if (!date) return '';
            const d = new Date(date);
            const day = String(d.getDate()).padStart(2, '0');
            const month = String(d.getMonth() + 1).padStart(2, '0');
            const year = d.getFullYear();
            
            if (format === 'yyyy-mm-dd') {
                return `${year}-${month}-${day}`;
            }
            return `${day}-${month}-${year}`;
        },

        // Export to Excel
        exportToExcel: function(tableId, filename) {
            const $table = $('#' + tableId);
            const data = $table.DataTable().buttons.exportData();
            
            // Create workbook
            const wb = XLSX.utils.book_new();
            const ws = XLSX.utils.aoa_to_sheet([data.headers].concat(data.body));
            XLSX.utils.book_append_sheet(wb, ws, 'Sheet1');
            
            // Save file
            XLSX.writeFile(wb, filename + '.xlsx');
        },

        // Print element
        printElement: function(elementId, title) {
            const element = document.getElementById(elementId);
            if (!element) return;
            
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>${title || 'Print'}</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        body { padding: 20px; }
                        @media print {
                            .no-print { display: none !important; }
                        }
                    </style>
                </head>
                <body>
                    ${element.innerHTML}
                    <script>
                        window.onload = function() {
                            window.print();
                            window.close();
                        };
                    </script>
                </body>
                </html>
            `);
            printWindow.document.close();
        }
    };

    // Employee module
    HRMS.Employee = {
        init: function() {
            this.initEmployeeList();
            this.initEmployeeForm();
        },

        initEmployeeList: function() {
            const $table = $('#employees-table');
            if (!$table.length) return;

            $table.DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: 'index.php?page=employee/list&ajax=1',
                    type: 'GET'
                },
                columns: [
                    { data: 'employee_code' },
                    { data: 'full_name' },
                    { data: 'designation' },
                    { data: 'unit_name' },
                    { data: 'worker_category' },
                    { data: 'status', render: function(data) {
                        const badges = {
                            'Active': '<span class="badge bg-success-soft">Active</span>',
                            'Inactive': '<span class="badge bg-warning-soft">Inactive</span>',
                            'Left': '<span class="badge bg-secondary">Left</span>'
                        };
                        return badges[data] || data;
                    }},
                    { data: 'actions', orderable: false, searchable: false }
                ],
                order: [[0, 'asc']]
            });
        },

        initEmployeeForm: function() {
            // State change loads zones
            $('#unit_id').on('change', function() {
                const unitId = $(this).val();
                // Auto-populate wage based on unit and worker category
                HRMS.Employee.loadMinimumWage();
            });

            $('#worker_category').on('change', function() {
                HRMS.Employee.loadMinimumWage();
            });

            // Same as present address checkbox
            $('#same_as_present').on('change', function() {
                if (this.checked) {
                    $('#permanent_address').val($('#present_address').val());
                    $('#permanent_city').val($('#present_city').val());
                    $('#permanent_state').val($('#present_state').val());
                    $('#permanent_pincode').val($('#present_pincode').val());
                }
            });
        },

        loadMinimumWage: function() {
            const unitId = $('#unit_id').val();
            const category = $('#worker_category').val();
            
            if (!unitId || !category) return;

            $.ajax({
                url: 'index.php?page=employee/ajax&action=get_minimum_wage',
                method: 'GET',
                data: { unit_id: unitId, category: category },
                success: function(response) {
                    if (response.success) {
                        $('#basic_wage').val(response.data.basic_per_month);
                        $('#da').val(response.data.da_per_month);
                        $('#gross_salary').val(response.data.total_per_month);
                    }
                }
            });
        }
    };

    // Attendance module
    HRMS.Attendance = {
        init: function() {
            this.initUploadForm();
            this.initAttendanceGrid();
        },

        initUploadForm: function() {
            $('#attendance-upload-form').on('submit', function(e) {
                e.preventDefault();
                
                const $form = $(this);
                const $btn = $form.find('[type="submit"]');
                const originalText = $btn.html();
                
                $btn.prop('disabled', true).html('<i class="bi bi-spinner-border spinner-border-sm me-2"></i>Uploading...');
                
                $.ajax({
                    url: $form.attr('action'),
                    method: 'POST',
                    data: new FormData($form[0]),
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            let message = `Attendance uploaded successfully! Imported: ${response.data.imported}`;
                            if (response.data.not_found && response.data.not_found.length > 0) {
                                message += `<br>Not found: ${response.data.not_found.join(', ')}`;
                            }
                            HRMS.showAlert('success', message);
                        } else {
                            HRMS.showAlert('danger', response.error || 'Upload failed');
                        }
                    },
                    complete: function() {
                        $btn.prop('disabled', false).html(originalText);
                    }
                });
            });
        },

        initAttendanceGrid: function() {
            const $table = $('#attendance-table');
            if (!$table.length) return;

            $table.DataTable({
                processing: true,
                serverSide: true,
                ajax: {
                    url: 'index.php?page=attendance/view&ajax=1',
                    type: 'GET'
                },
                columns: [
                    { data: 'employee_code' },
                    { data: 'employee_name' },
                    { data: 'present_days' },
                    { data: 'absent_days' },
                    { data: 'weekly_offs' },
                    { data: 'holidays' },
                    { data: 'total_working_days' },
                    { data: 'overtime_hours' }
                ]
            });
        }
    };

    // Payroll module
    HRMS.Payroll = {
        init: function() {
            this.initPayrollProcess();
            this.initPayslipPrint();
        },

        initPayrollProcess: function() {
            $('#process-payroll-btn').on('click', function() {
                const periodId = $(this).data('period-id');
                
                HRMS.confirm('Are you sure you want to process payroll for this period?', function() {
                    HRMS.showLoading('Processing payroll...');
                    
                    $.ajax({
                        url: 'index.php?page=payroll/process&ajax=1',
                        method: 'POST',
                        data: { period_id: periodId },
                        success: function(response) {
                            HRMS.hideLoading();
                            
                            if (response.success) {
                                HRMS.showAlert('success', `Payroll processed for ${response.data.processed} employees`);
                                setTimeout(function() {
                                    window.location.reload();
                                }, 1500);
                            } else {
                                HRMS.showAlert('danger', response.error || 'Payroll processing failed');
                            }
                        },
                        error: function() {
                            HRMS.hideLoading();
                            HRMS.showAlert('danger', 'An error occurred');
                        }
                    });
                });
            });
        },

        initPayslipPrint: function() {
            $('.print-payslip').on('click', function() {
                const payslipId = $(this).data('payslip-id');
                HRMS.printElement('payslip-' + payslipId, 'Payslip');
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        HRMS.init();
        
        // Initialize modules based on current page
        const page = $('body').data('page') || '';
        
        if (page.indexOf('employee') === 0) {
            HRMS.Employee.init();
        }
        if (page.indexOf('attendance') === 0) {
            HRMS.Attendance.init();
        }
        if (page.indexOf('payroll') === 0) {
            HRMS.Payroll.init();
        }
    });

})(jQuery);
