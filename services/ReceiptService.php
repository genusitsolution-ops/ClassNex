<?php
declare(strict_types=1);

/** Builds receipt PDFs on demand from the ledger, so re-downloads are always identical and accurate. */
final class ReceiptService
{
    public static function data(int $receiptId): array
    {
        $r = DB::one('SELECT r.*, p.method, p.reference, p.paid_on, p.status AS payment_status, p.refunded_paise, p.notes,
                             s.name AS student_name, s.student_code, s.guardian_name, s.country_code, s.whatsapp,
                             a.name AS academy_name, a.logo_path, a.address, a.city, a.state, a.pincode, a.mobile AS academy_mobile, a.email AS academy_email, a.gstin, a.signature_path, a.receipt_footer,
                             u.name AS collected_by_name, br.name AS branch_name, br.address AS branch_address, br.contact_number AS branch_phone
                      FROM receipts r JOIN payments p ON p.id = r.payment_id JOIN students s ON s.id = r.student_id
                      JOIN academies a ON a.id = r.academy_id LEFT JOIN users u ON u.id = p.collected_by LEFT JOIN academy_branches br ON br.id = r.branch_id WHERE r.id = ?', [$receiptId]);
        if (!$r) {
            throw ApiException::notFound('Receipt');
        }
        $r['parent_name'] = DB::val('SELECT p.name FROM parents p JOIN parent_student_mapping m ON m.parent_id = p.id WHERE m.student_id = ? ORDER BY m.is_primary DESC LIMIT 1', [$r['student_id']]) ?: $r['guardian_name'];
        $r['lines'] = DB::all('SELECT i.invoice_no, i.title, SUM(a.amount_paise) AS amount_paise FROM payment_allocations a JOIN fee_invoices i ON i.id = a.invoice_id
                               WHERE a.payment_id = ? AND a.reason = \'payment\' GROUP BY i.id, i.invoice_no, i.title ORDER BY i.due_date', [$r['payment_id']]);
        $allocatedOriginal = array_sum(array_map('intval', array_column($r['lines'], 'amount_paise')));
        $r['advance_paise'] = max(0, (int) $r['amount_paise'] - $allocatedOriginal);
        return $r;
    }

    public static function pdf(int $receiptId): string
    {
        $r = self::data($receiptId);
        $pdf = new SimplePdf('Receipt ' . $r['receipt_no']);
        $m = 40;
        $W = SimplePdf::W - 2 * $m;
        // header band
        $pdf->rect(0, 0, SimplePdf::W, 6, [36, 55, 160]);
        $y = 28;
        $logoX = $m;
        if ($r['logo_path'] && is_file(CNX_ROOT . '/' . $r['logo_path'])) {
            $pdf->image((string) file_get_contents(CNX_ROOT . '/' . $r['logo_path']), $m, $y, 56);
            $logoX = $m + 66;
        }
        $pdf->font(true, 16)->color(20, 30, 70);
        $pdf->text($logoX, $y, $r['academy_name']);
        $yy = $y + 22;
        if ($r['branch_name']) {
            $pdf->font(true, 10)->color(36, 55, 160);
            $pdf->text($logoX, $yy, 'Branch: ' . $r['branch_name']);
            $yy += 14;
        }
        $pdf->font(false, 9)->color(90, 90, 90);
        $addr = $r['branch_address'] ?: trim(implode(', ', array_filter([$r['address'], $r['city'], $r['state'], $r['pincode']])));
        if ($addr) {
            $yy += $pdf->para($logoX, $yy, 300, $addr);
        }
        $phone = $r['branch_phone'] ?: $r['academy_mobile'];
        $contact = implode('  |  ', array_filter([$phone ? 'Ph: ' . $phone : null, $r['academy_email'], $r['gstin'] ? 'GSTIN: ' . $r['gstin'] : null]));
        if ($contact) {
            $pdf->text($logoX, $yy, $contact);
        }
        $pdf->font(true, 20)->color(36, 55, 160);
        $pdf->text($m, $y, 'RECEIPT', 'R', $W);
        $pdf->font(false, 9)->color(60, 60, 60);
        $pdf->text($m, $y + 26, 'Receipt No: ' . $r['receipt_no'], 'R', $W);
        $pdf->text($m, $y + 39, 'Date: ' . date('d M Y', strtotime($r['paid_on'])), 'R', $W);
        if ($r['status'] === 'cancelled' || $r['payment_status'] === 'reversed') {
            $pdf->font(true, 11)->color(200, 30, 30);
            $pdf->text($m, $y + 54, 'CANCELLED / REVERSED', 'R', $W);
        }

        $y = 120;
        $pdf->line($m, $y, $m + $W, $y);
        $y += 14;
        $pdf->rect($m, $y, $W, 62, [246, 248, 253]);
        $pdf->font(false, 8.5)->color(110, 110, 110);
        $pdf->text($m + 12, $y + 10, 'RECEIVED FROM');
        $pdf->text($m + $W / 2, $y + 10, 'STUDENT');
        $pdf->font(true, 11)->color(25, 25, 25);
        $pdf->text($m + 12, $y + 24, $r['parent_name'] ?: $r['student_name']);
        $pdf->text($m + $W / 2, $y + 24, $r['student_name']);
        $pdf->font(false, 9)->color(80, 80, 80);
        $pdf->text($m + 12, $y + 40, $r['country_code'] . ' ' . $r['whatsapp']);
        $pdf->text($m + $W / 2, $y + 40, 'Student ID: ' . $r['student_code']);

        $y += 80;
        $pdf->y = $y;
        $pdf->margin = $m;
        $rows = [];
        foreach ($r['lines'] as $l) {
            $rows[] = [$l['title'], $l['invoice_no'], Money::format((int) $l['amount_paise'], 'Rs. ')];
        }
        if ($r['advance_paise'] > 0) {
            $rows[] = ['Advance (credited to future fees)', '-', Money::format($r['advance_paise'], 'Rs. ')];
        }
        $pdf->table([
            ['label' => 'Description', 'w' => $W - 230],
            ['label' => 'Invoice', 'w' => 120],
            ['label' => 'Amount', 'w' => 110, 'align' => 'R'],
        ], $rows, 22, 9.5);
        $y = $pdf->y + 10;
        $pdf->font(true, 12)->color(25, 25, 25);
        $pdf->text($m, $y, 'Amount Paid', 'L');
        $pdf->text($m, $y, Money::format((int) $r['amount_paise'], 'Rs. '), 'R', $W);
        $y += 18;
        $pdf->font(false, 9)->color(90, 90, 90);
        $pdf->text($m, $y, 'Amount in words: ' . self::words((int) $r['amount_paise']));
        $y += 22;
        $pdf->line($m, $y, $m + $W, $y);
        $y += 10;
        $pdf->font(false, 9.5)->color(50, 50, 50);
        $method = ucwords(str_replace('_', ' ', $r['method']));
        $pdf->text($m, $y, 'Payment Method: ' . $method . ($r['reference'] ? '   |   Ref: ' . $r['reference'] : ''));
        $pdf->text($m, $y + 16, 'Remaining Balance: ' . Money::format((int) $r['balance_after_paise'], 'Rs. '));
        if ((int) $r['refunded_paise'] > 0) {
            $pdf->text($m, $y + 32, 'Refunded: ' . Money::format((int) $r['refunded_paise'], 'Rs. '));
        }
        if ($r['collected_by_name']) {
            $pdf->text($m, $y + 48, 'Collected by: ' . $r['collected_by_name']);
        }
        // signature
        $sy = $y + 30;
        if ($r['signature_path'] && is_file(CNX_ROOT . '/' . $r['signature_path'])) {
            $pdf->image((string) file_get_contents(CNX_ROOT . '/' . $r['signature_path']), $m + $W - 130, $sy - 10, 110);
        }
        $pdf->line($m + $W - 150, $sy + 40, $m + $W, $sy + 40, [120, 120, 120]);
        $pdf->font(false, 9)->color(80, 80, 80);
        $pdf->text($m + $W - 150, $sy + 45, 'Authorised Signature', 'C', 150);

        $fy = SimplePdf::H - 60;
        $pdf->line($m, $fy, $m + $W, $fy);
        $pdf->font(false, 8)->color(120, 120, 120);
        if ($r['receipt_footer']) {
            $pdf->text($m, $fy + 8, $r['receipt_footer'], 'C', $W);
        }
        $pdf->text($m, $fy + 22, 'This is a computer-generated receipt. Generated by ClassNex – All-in-One Class Management', 'C', $W);
        $logo = CNX_ROOT . '/assets/img/classnex-logo-small.jpg';
        if (is_file($logo)) {
            $pdf->image((string) file_get_contents($logo), $m + $W / 2 - 20, $fy + 34, 40);
        }
        return $pdf->output();
    }

    /** Indian-system number to words, e.g. "Rupees One Thousand Five Hundred Only". */
    public static function words(int $paise): string
    {
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $two = static fn(int $n) => $n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
        $three = static fn(int $n) => trim(($n >= 100 ? $ones[intdiv($n, 100)] . ' Hundred ' : '') . $two($n % 100));
        $r = intdiv($paise, 100);
        $p = $paise % 100;
        if ($r === 0 && $p === 0) {
            return 'Rupees Zero Only';
        }
        $parts = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as [$div, $name]) {
            if ($r >= $div) {
                $parts[] = ($div === 10000000 ? self::words(intdiv($r, $div) * 100) : $two(intdiv($r, $div))) . " $name";
                $r %= $div;
            }
        }
        if ($r > 0) {
            $parts[] = $three($r);
        }
        $out = 'Rupees ' . trim(str_replace(['Rupees ', ' Only'], '', implode(' ', $parts)));
        if ($p) {
            $out .= ' and ' . $two($p) . ' Paise';
        }
        return $out . ' Only';
    }
}
