<?php
/**
 * RCS HRMS Pro - Print Invoice (GST-compliant)
 */
$pageTitle = 'Print Invoice';
$id = (int)($_GET['id'] ?? 0);

$invoice = null;
$items = [];
$payments = [];
$company = null;

if ($id) {
    try {
        $invoice = $db->fetch(
            "SELECT i.*, c.name as client_name, c.client_code, c.gst_number, c.address, c.city, c.state, c.pan_number
             FROM invoices i
             LEFT JOIN clients c ON i.client_id = c.id
             WHERE i.id = ?", [$id]
        );

        if ($invoice) {
            $items = $db->fetchAll(
                "SELECT ii.*, e.employee_code, e.full_name as employee_name
                 FROM invoice_items ii
                 LEFT JOIN employees e ON ii.employee_id = e.id
                 WHERE ii.invoice_id = ? ORDER BY ii.id", [$id]
            );
            $payments = $db->fetchAll(
                "SELECT * FROM invoice_payments WHERE invoice_id = ? ORDER BY payment_date DESC", [$id]
            );
            $company = $db->fetch("SELECT * FROM companies LIMIT 1");
        }
    } catch (Exception $e) {
        // Table not found
    }
}

if (!$invoice) {
    echo '<div class="alert alert-danger m-3">Invoice not found.</div>';
    return;
}

$totalPaid = array_sum(array_column($payments, 'amount'));
$balance = floatval($invoice['total_amount']) - $totalPaid;
$cgst = floatval($invoice['cgst_amount'] ?? 0);
$sgst = floatval($invoice['sgst_amount'] ?? 0);
$igst = floatval($invoice['igst_amount'] ?? 0);
$subtotal = floatval($invoice['subtotal'] ?? ($invoice['total_amount'] - $cgst - $sgst - $igst));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice #<?php echo sanitize($invoice['invoice_number']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, sans-serif; font-size: 13px; color: #333; }
        .page { width: 210mm; margin: 0 auto; padding: 15mm 20mm; }
        .header { display: flex; justify-content: space-between; margin-bottom: 30px; border-bottom: 3px solid #1a5276; padding-bottom: 20px; }
        .company-name { font-size: 22px; font-weight: 700; color: #1a5276; }
        .company-details { font-size: 11px; color: #666; margin-top: 5px; line-height: 1.6; }
        .invoice-title { font-size: 28px; font-weight: 700; color: #1a5276; text-align: right; }
        .invoice-meta { text-align: right; font-size: 12px; margin-top: 5px; }
        .parties { display: flex; gap: 40px; margin-bottom: 25px; }
        .party { flex: 1; }
        .party h4 { font-size: 11px; text-transform: uppercase; color: #1a5276; letter-spacing: 1px; margin-bottom: 8px; border-bottom: 1px solid #ddd; padding-bottom: 4px; }
        .party p { font-size: 12px; line-height: 1.7; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        table.items thead th { background: #1a5276; color: #fff; padding: 8px 10px; font-size: 11px; text-transform: uppercase; text-align: left; }
        table.items thead th.text-right, table.items td.text-right { text-align: right; }
        table.items tbody td { padding: 8px 10px; border-bottom: 1px solid #eee; font-size: 12px; }
        table.items tbody tr:nth-child(even) { background: #f9f9f9; }
        .totals { width: 280px; margin-left: auto; }
        .totals table { width: 100%; }
        .totals td { padding: 5px 10px; font-size: 12px; }
        .totals tr.grand td { font-size: 16px; font-weight: 700; border-top: 2px solid #1a5276; color: #1a5276; padding-top: 8px; }
        .totals td:last-child { text-align: right; }
        .amount-words { background: #f0f6ff; padding: 12px 15px; border-radius: 6px; margin: 20px 0; font-size: 12px; }
        .amount-words strong { color: #1a5276; }
        .footer { margin-top: 40px; padding-top: 15px; border-top: 1px solid #ddd; }
        .footer .notes { font-size: 11px; color: #666; margin-bottom: 30px; }
        .signatures { display: flex; justify-content: space-between; margin-top: 60px; }
        .signatures div { text-align: center; width: 200px; }
        .signatures .line { border-top: 1px solid #333; padding-top: 5px; font-size: 12px; }
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .page { padding: 10mm 15mm; }
            .no-print { display: none; }
        }
        .no-print { text-align: center; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()" style="padding: 8px 20px; font-size: 14px; cursor: pointer; background: #1a5276; color: #fff; border: none; border-radius: 4px;">
            🖨️ Print Invoice
        </button>
        <button onclick="window.close()" style="padding: 8px 20px; font-size: 14px; cursor: pointer; background: #666; color: #fff; border: none; border-radius: 4px; margin-left: 10px;">
            Close
        </button>
    </div>

    <div class="page">
        <!-- Header -->
        <div class="header">
            <div>
                <div class="company-name"><?php echo sanitize($company['name'] ?? 'RCS TRUE FACILITIES PVT LTD'); ?></div>
                <div class="company-details">
                    <?php if (!empty($company['address'])) echo sanitize($company['address']) . '<br>'; ?>
                    <?php if (!empty($company['city'])) echo sanitize($company['city']); ?>
                    <?php if (!empty($company['state'])) echo ', ' . sanitize($company['state']); ?>
                    <?php if (!empty($company['pincode'])) echo ' - ' . sanitize($company['pincode']); ?><br>
                    <?php if (!empty($company['gst_number'])) echo '<strong>GSTIN:</strong> ' . sanitize($company['gst_number']) . '<br>'; ?>
                    <?php if (!empty($company['pan_number'])) echo '<strong>PAN:</strong> ' . sanitize($company['pan_number']) . '<br>'; ?>
                    <?php if (!empty($company['phone'])) echo '<strong>Ph:</strong> ' . sanitize($company['phone']); ?>
                </div>
            </div>
            <div>
                <div class="invoice-title">TAX INVOICE</div>
                <div class="invoice-meta">
                    <strong>Invoice #:</strong> <?php echo sanitize($invoice['invoice_number']); ?><br>
                    <strong>Date:</strong> <?php echo formatDate($invoice['invoice_date']); ?><br>
                    <strong>Due Date:</strong> <?php echo formatDate($invoice['due_date']); ?><br>
                    <?php if (!empty($invoice['sac_code'])) echo '<strong>SAC:</strong> ' . sanitize($invoice['sac_code']); ?>
                </div>
            </div>
        </div>

        <!-- Parties -->
        <div class="parties">
            <div class="party">
                <h4>Bill To</h4>
                <p>
                    <strong><?php echo sanitize($invoice['client_name']); ?></strong><br>
                    <?php if (!empty($invoice['address'])) echo sanitize($invoice['address']) . '<br>'; ?>
                    <?php if (!empty($invoice['city'])) echo sanitize($invoice['city']) . ', ' . sanitize($invoice['state'] ?? '') . '<br>'; ?>
                    <?php if (!empty($invoice['gst_number'])) echo '<strong>GSTIN:</strong> ' . sanitize($invoice['gst_number']) . '<br>'; ?>
                    <?php if (!empty($invoice['pan_number'])) echo '<strong>PAN:</strong> ' . sanitize($invoice['pan_number']); ?>
                </p>
            </div>
            <div class="party">
                <h4>Place of Supply</h4>
                <p>
                    <?php echo sanitize($invoice['place_of_supply'] ?? $invoice['state'] ?? 'Gujarat'); ?><br>
                    <?php if (!empty($invoice['period_from'])): ?>
                    <strong>Period:</strong> <?php echo formatDate($invoice['period_from']); ?> to <?php echo formatDate($invoice['period_to']); ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- Items Table -->
        <table class="items">
            <thead>
                <tr>
                    <th style="width:30px;">#</th>
                    <th>Description</th>
                    <th style="width:60px;" class="text-right">Days</th>
                    <th style="width:90px;" class="text-right">Rate</th>
                    <th style="width:90px;" class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php $sn = 1; foreach ($items as $item): ?>
                <tr>
                    <td><?php echo $sn++; ?></td>
                    <td><?php echo sanitize($item['description']); ?></td>
                    <td class="text-right"><?php echo $item['days_worked']; ?></td>
                    <td class="text-right"><?php echo number_format($item['rate_per_day'], 2); ?></td>
                    <td class="text-right"><strong><?php echo number_format($item['amount'], 2); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Totals -->
        <div class="totals">
            <table>
                <tr><td>Subtotal</td><td><?php echo number_format($subtotal, 2); ?></td></tr>
                <?php if ($cgst > 0): ?>
                <tr><td>CGST @ <?php echo $invoice['cgst_rate'] ?? 9; ?>%</td><td><?php echo number_format($cgst, 2); ?></td></tr>
                <?php endif; ?>
                <?php if ($sgst > 0): ?>
                <tr><td>SGST @ <?php echo $invoice['sgst_rate'] ?? 9; ?>%</td><td><?php echo number_format($sgst, 2); ?></td></tr>
                <?php endif; ?>
                <?php if ($igst > 0): ?>
                <tr><td>IGST @ <?php echo $invoice['igst_rate'] ?? 0; ?>%</td><td><?php echo number_format($igst, 2); ?></td></tr>
                <?php endif; ?>
                <tr class="grand"><td>Grand Total</td><td>₹<?php echo number_format($invoice['total_amount'], 2); ?></td></tr>
            </table>
        </div>

        <!-- Amount in Words -->
        <div class="amount-words">
            <strong>Amount in Words:</strong> Rupees <?php echo number_to_words_indian($invoice['total_amount']); ?> Only
        </div>

        <!-- Footer -->
        <div class="footer">
            <?php if (!empty($invoice['terms_conditions'])): ?>
            <div class="notes">
                <strong>Terms & Conditions:</strong> <?php echo sanitize($invoice['terms_conditions']); ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($invoice['notes'])): ?>
            <div class="notes">
                <strong>Notes:</strong> <?php echo sanitize($invoice['notes']); ?>
            </div>
            <?php endif; ?>

            <!-- Bank Details -->
            <?php if (!empty($company['bank_name'])): ?>
            <div style="font-size: 11px; color: #666; margin-bottom: 10px;">
                <strong>Bank Details:</strong>
                <?php echo sanitize($company['bank_name']); ?>
                <?php if (!empty($company['bank_account'])) echo ' | A/C: ' . sanitize($company['bank_account']); ?>
                <?php if (!empty($company['bank_ifsc'])) echo ' | IFSC: ' . sanitize($company['bank_ifsc']); ?>
                <?php if (!empty($company['bank_branch'])) echo ' | Branch: ' . sanitize($company['bank_branch']); ?>
            </div>
            <?php endif; ?>

            <div class="signatures">
                <div class="line">Authorized Signatory</div>
                <div class="line">Receiver's Signature</div>
            </div>
        </div>
    </div>
</body>
</html>

<?php
// Helper: convert number to Indian words
function number_to_words_indian($num) {
    $num = round(floatval($num), 2);
    $exploded = explode('.', (string)$num);
    $whole = (int)$exploded[0];
    $decimal = isset($exploded[1]) ? (int)substr($exploded[1] . '00', 0, 2) : 0;

    $words = '';
    if ($whole > 0) {
        $words = convert_to_words($whole);
    }
    if ($decimal > 0) {
        $words .= ($words ? ' and ' : '') . convert_to_words($decimal) . ' Paise';
    }
    if (empty($words)) {
        $words = 'Zero';
    }
    return $words;
}

function convert_to_words($num) {
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
             'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    if ($num < 20) return $ones[$num];
    if ($num < 100) return $tens[(int)($num / 10)] . ($num % 10 ? ' ' . $ones[$num % 10] : '');
    if ($num < 1000) return $ones[(int)($num / 100)] . ' Hundred' . ($num % 100 ? ' and ' . convert_to_words($num % 100) : '');

    $results = [];
    $scales = [
        1000000000 => 'Crore',
        10000000 => 'Lakh',
        100000 => 'Thousand',
        1000 => 'Thousand',
        100 => 'Hundred'
    ];

    foreach ($scales as $scale => $name) {
        if ($num >= $scale) {
            $quotient = (int)($num / $scale);
            $remainder = $num % $scale;
            $results[] = convert_to_words($quotient) . ' ' . $name;
            $num = $remainder;
        }
    }

    if ($num > 0) {
        $results[] = convert_to_words($num);
    }

    return implode(' ', $results);
}
?>
