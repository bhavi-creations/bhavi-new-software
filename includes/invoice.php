<?php
// Shared helpers for client invoices (invoices.php and invoice-print.php).
require_once __DIR__.'/finance.php';

const INVOICE_STATUSES = ['unpaid' => 'Unpaid', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];
const INVOICE_GST_TYPES = ['none' => 'No GST', 'cgst_sgst' => 'CGST + SGST (same state)', 'igst' => 'IGST (other state)'];

function invoice_company(): array
{
    $info = (array) require __DIR__.'/payment-details.php';
    $info['account_name'] = trim((string) ($info['account_name'] ?? '')) ?: 'Bhavi Creations';
    $info['invoice_prefix'] = trim((string) ($info['invoice_prefix'] ?? '')) ?: 'BHAVI_KKD';
    return $info;
}

/** Indian financial year label for a date, e.g. 2026-11-14 → "2026-27". */
function invoice_fy(string $date): string
{
    $ts = strtotime($date) ?: time();
    $year = (int) date('Y', $ts);
    $start = (int) date('n', $ts) >= 4 ? $year : $year - 1;
    return $start.'-'.substr((string) ($start + 1), -2);
}

/** Next free number in the invoice's year, e.g. BHAVI_KKD_2026_28. */
function invoice_next_no(string $date): string
{
    $prefix = invoice_company()['invoice_prefix'].'_'.date('Y', strtotime($date) ?: time()).'_';
    $max = 0;
    foreach (rows('SELECT invoice_no FROM invoices WHERE invoice_no LIKE ?', [str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $prefix).'%']) as $row) {
        $tail = substr($row['invoice_no'], strlen($prefix));
        if (ctype_digit($tail)) $max = max($max, (int) $tail);
    }
    return $prefix.($max + 1);
}

/** Rupees in words with Indian grouping, e.g. 150000.50 → "One Lakh Fifty Thousand Rupees and Fifty Paise Only". */
function invoice_amount_words($value): string
{
    $cents = (int) round(abs((float) $value) * 100);
    $rupees = intdiv($cents, 100); $paise = $cents % 100;
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two = static fn(int $n): string => $n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)].' '.$ones[$n % 10]);
    $three = static fn(int $n): string => trim(($n >= 100 ? $ones[intdiv($n, 100)].' Hundred ' : '').$two($n % 100));
    $words = static function (int $n) use ($two, $three): string {
        if ($n === 0) return 'Zero';
        $parts = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as [$size, $name]) {
            if ($n >= $size) {
                $chunk = intdiv($n, $size); $n %= $size;
                // Above 99 crore the crore part itself can need lakhs/thousands.
                $parts[] = ($chunk > 999 ? (string) $chunk : ($chunk > 99 ? $three($chunk) : $two($chunk))).' '.$name;
            }
        }
        if ($n) $parts[] = $three($n);
        return implode(' ', $parts);
    };
    return $words($rupees).($rupees === 1 ? ' Rupee' : ' Rupees').($paise ? ' and '.$two($paise).' Paise' : '').' Only';
}

function invoice_status_badge(string $status): string
{
    $class = ['paid' => 'active', 'unpaid' => 'on_hold', 'cancelled' => 'inactive'][$status] ?? 'on_hold';
    return '<span class="badge client-status is-'.h($class).'">'.h(INVOICE_STATUSES[$status] ?? 'Unpaid').'</span>';
}
