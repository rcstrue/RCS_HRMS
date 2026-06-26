<?php
$pageTitle = 'Employee Entry Form';
?>

<style>
@media print {
    .btn, form, .no-print { display: none !important; }
    .container { max-width: 100% !important; padding: 0 !important; }
    body { font-size: 12px; }
    .entry-form { 
        border: 2px solid #000 !important;
        padding: 20px !important;
    }
    .form-field {
        border-bottom: 1px solid #999 !important;
        min-height: 22px !important;
    }
    .section-title {
        background-color: #f0f0f0 !important;
        padding: 5px 10px !important;
        font-weight: bold !important;
    }
}
.entry-form {
    border: 2px solid #dee2e6;
    padding: 20px;
    background: #fff;
}
.form-field {
    border-bottom: 1px dashed #ccc;
    min-height: 22px;
    padding: 2px 5px;
}
.form-row {
    margin-bottom: 12px;
}
.section-title {
    background-color: #e9ecef;
    padding: 6px 12px;
    font-weight: bold;
    margin-top: 16px;
    margin-bottom: 10px;
}
.form-label-bold {
    font-weight: 600;
    font-size: 12px;
    margin-bottom: 2px;
}
</style>

<div class="container-fluid">
    <h4 class="mb-3 no-print"><?= sanitize($pageTitle) ?></h4>

    <div class="no-print mb-3">
        <button type="button" onclick="window.print()" class="btn btn-sm btn-primary"><i class="bi bi-printer"></i> Print Blank Form</button>
    </div>

    <!-- Entry Form -->
    <div class="entry-form">
        <!-- Header -->
        <div class="text-center mb-3">
            <div style="border: 2px solid #000; padding: 10px; display: inline-block; margin-bottom: 5px;">
                <span style="font-size: 10px; color: #888;">[Company Logo]</span>
            </div>
            <h4 class="mb-1">COMPANY NAME</h4>
            <p class="small text-muted mb-0">Address Line 1, City, State - PIN Code</p>
            <p class="small text-muted mb-0">Phone: XXXX-XXXXXX | Email: info@company.com</p>
        </div>

        <h5 class="text-center mb-3 text-decoration-underline">NEW EMPLOYEE ENTRY FORM</h5>
        <p class="text-center small text-muted mb-3">Please fill in all details in CAPITAL LETTERS</p>

        <!-- Section A: Personal Details -->
        <div class="section-title">A. PERSONAL DETAILS</div>

        <div class="row form-row">
            <div class="col-md-4">
                <div class="form-label-bold">1. Full Name</div>
                <div class="form-field"></div>
            </div>
            <div class="col-md-4">
                <div class="form-label-bold">2. Father's / Husband's Name</div>
                <div class="form-field"></div>
            </div>
            <div class="col-md-4">
                <div class="form-label-bold">3. Date of Birth</div>
                <div class="form-field">DD / MM / YYYY</div>
            </div>
        </div>

        <div class="row form-row">
            <div class="col-md-3">
                <div class="form-label-bold">4. Gender</div>
                <div class="form-field">☐ Male &nbsp; ☐ Female &nbsp; ☐ Other</div>
            </div>
            <div class="col-md-3">
                <div class="form-label-bold">5. Blood Group</div>
                <div class="form-field"></div>
            </div>
            <div class="col-md-3">
                <div class="form-label-bold">6. Marital Status</div>
                <div class="form-field">☐ Single &nbsp; ☐ Married</div>
            </div>
            <div class="col-md-3">
                <div class="form-label-bold">7. Nationality</div>
                <div class="form-field">Indian</div>
            </div>
        </div>

        <div class="row form-row">
            <div class="col-md-6">
                <div class="form-label-bold">8. Present Address</div>
                <div class="form-field" style="min-height: 50px;"></div>
            </div>
            <div class="col-md-6">
                <div class="form-label-bold">9. Permanent Address</div>
                <div class="form-field" style="min-height: 50px;"></div>
            </div>
        </div>

        <div class="row form-row">
            <div class="col-md-4">
                <div class="form-label-bold">10. Mobile Number</div>
                <div class="form-field"></div>
            </div>
            <div class="col-md-4">
                <div class="form-label-bold">11. Email Address</div>
                <div class="form-field"></div>
            </div>
            <div class="col-md-4">
                <div class="form-label-bold">12. State</div>
                <div class="form-field"></div>
            </div>
        </div>

        <!-- Section B: Identity Documents -->
        <div class="section-title">B. IDENTITY DOCUMENTS</div>

        <div class="row form-row">
            <div class="col-md-4">
                <div class="form-label-bold">13. Aadhaar Number</div>
                <div class="form-field"></div>
            </div>
            <div class="col-md-4">
                <div class="form-label-bold">14. PAN Number</div>
                <div class="form-field"></div>
            </div>
            <div class="col-md-4">
                <div class="form-label-bold">15. UAN Number (if any)</div>
                <div class="form-field"></div>
            </div>
        </div>

        <div class="row form-row">
            <div class="col-md-4">
                <div class="form-label-bold">16. ESI Number (if any)</div>
                <div class="form-field"></div>
            </div>
            <div class="col-md-4">
                <div class="form-label-bold">17. PF Number (if any)</div>
                <div class="form-field"></div>
            </div>
            <div class="col-md-4">
                <div class="form-label-bold">18. Passport No (if any)</div>
                <div class="form-field"></div>
            </div>
        </div>

        <!-- Section C: Bank Details -->
        <div class="section-title">C. BANK DETAILS</div>

        <div class="row form-row">
            <div class="col-md-4">
                <div class="form-label-bold">19. Bank Name</div>
                <div class="form-field"></div>
            </div>
            <div class="col-md-4">
                <div class="form-label-bold">20. Account Number</div>
                <div class="form-field"></div>
            </div>
            <div class="col-md-4">
                <div class="form-label-bold">21. IFSC Code</div>
                <div class="form-field"></div>
            </div>
        </div>

        <div class="row form-row">
            <div class="col-md-4">
                <div class="form-label-bold">22. Account Type</div>
                <div class="form-field">☐ Savings &nbsp; ☐ Current</div>
            </div>
            <div class="col-md-4">
                <div class="form-label-bold">23. Branch Name</div>
                <div class="form-field"></div>
            </div>
            <div class="col-md-4">
                <div class="form-label-bold">24. Passbook attached</div>
                <div class="form-field">☐ Yes &nbsp; ☐ No</div>
            </div>
        </div>

        <!-- Section D: Employment Details -->
        <div class="section-title">D. EMPLOYMENT DETAILS</div>

        <div class="row form-row">
            <div class="col-md-3">
                <div class="form-label-bold">25. Designation</div>
                <div class="form-field"></div>
            </div>
            <div class="col-md-3">
                <div class="form-label-bold">26. Department</div>
                <div class="form-field"></div>
            </div>
            <div class="col-md-3">
                <div class="form-label-bold">27. Date of Joining</div>
                <div class="form-field">DD / MM / YYYY</div>
            </div>
            <div class="col-md-3">
                <div class="form-label-bold">28. Client / Unit</div>
                <div class="form-field"></div>
            </div>
        </div>

        <!-- Section E: Education & Experience -->
        <div class="section-title">E. EDUCATION & EXPERIENCE</div>

        <div class="row form-row">
            <div class="col-md-4">
                <div class="form-label-bold">29. Highest Qualification</div>
                <div class="form-field"></div>
            </div>
            <div class="col-md-4">
                <div class="form-label-bold">30. Total Experience</div>
                <div class="form-field">Years: ____ Months: ____</div>
            </div>
            <div class="col-md-4">
                <div class="form-label-bold">31. Previous Employer</div>
                <div class="form-field"></div>
            </div>
        </div>

        <!-- Section F: Nominee / Emergency -->
        <div class="section-title">F. NOMINEE & EMERGENCY CONTACT</div>

        <div class="row form-row">
            <div class="col-md-4">
                <div class="form-label-bold">32. Nominee Name</div>
                <div class="form-field"></div>
            </div>
            <div class="col-md-4">
                <div class="form-label-bold">33. Relationship</div>
                <div class="form-field"></div>
            </div>
            <div class="col-md-4">
                <div class="form-label-bold">34. Nominee Contact</div>
                <div class="form-field"></div>
            </div>
        </div>

        <div class="row form-row">
            <div class="col-md-4">
                <div class="form-label-bold">35. Emergency Contact Name</div>
                <div class="form-field"></div>
            </div>
            <div class="col-md-4">
                <div class="form-label-bold">36. Emergency Contact No</div>
                <div class="form-field"></div>
            </div>
            <div class="col-md-4">
                <div class="form-label-bold">37. Relationship</div>
                <div class="form-field"></div>
            </div>
        </div>

        <!-- Section G: Documents Checklist -->
        <div class="section-title">G. DOCUMENTS SUBMITTED</div>

        <div class="row form-row">
            <div class="col-md-3">
                <label>☐ Aadhaar Copy</label>
            </div>
            <div class="col-md-3">
                <label>☐ PAN Card Copy</label>
            </div>
            <div class="col-md-3">
                <label>☐ Passport Photo (2)</label>
            </div>
            <div class="col-md-3">
                <label>☐ Bank Passbook Copy</label>
            </div>
        </div>
        <div class="row form-row">
            <div class="col-md-3">
                <label>☐ Educational Certificates</label>
            </div>
            <div class="col-md-3">
                <label>☐ Experience Certificate</label>
            </div>
            <div class="col-md-3">
                <label>☐ Salary Slip (Previous)</label>
            </div>
            <div class="col-md-3">
                <label>☐ Relieving Letter</label>
            </div>
        </div>
        <div class="row form-row">
            <div class="col-md-3">
                <label>☐ UAN / PF Transfer</label>
            </div>
            <div class="col-md-3">
                <label>☐ ESI Card (if applicable)</label>
            </div>
            <div class="col-md-3">
                <label>☐ Address Proof</label>
            </div>
            <div class="col-md-3">
                <label>☐ Other: ____________</label>
            </div>
        </div>

        <!-- Declaration -->
        <div class="mt-4 p-3" style="border: 1px solid #000;">
            <p class="fw-bold mb-2">DECLARATION:</p>
            <p class="small mb-2">I hereby declare that all the information provided above is true and correct to the best of my knowledge. I understand that any false information may lead to termination of my employment.</p>
        </div>

        <!-- Signatures -->
        <div class="row mt-4">
            <div class="col-md-4">
                <div class="border-bottom" style="width: 180px;"></div>
                <p class="small mt-1 mb-0">Employee Signature</p>
                <p class="small text-muted mb-0">Date: ____/____/________</p>
            </div>
            <div class="col-md-4">
                <div class="border-bottom" style="width: 180px;"></div>
                <p class="small mt-1 mb-0">HR / Admin Signature</p>
                <p class="small text-muted mb-0">Date: ____/____/________</p>
            </div>
            <div class="col-md-4">
                <div class="border-bottom" style="width: 180px;"></div>
                <p class="small mt-1 mb-0">Authorized Signatory</p>
                <p class="small text-muted mb-0">Date: ____/____/________</p>
            </div>
        </div>
    </div>
</div>
