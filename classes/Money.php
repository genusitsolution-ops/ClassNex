<?php
declare(strict_types=1);

/** All amounts are integer paise. Never use floats for money arithmetic. */
final class Money
{
    /** Parses user input like "1500", "1,500.5", "₹1500.50" into paise. */
    public static function toPaise(mixed $v): int
    {
        if (is_int($v)) {
            return $v * 100;
        }
        $s = trim(str_replace([',', '₹', ' ', 'Rs.', 'Rs'], '', (string) $v));
        if ($s === '') {
            return 0;
        }
        if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $s)) {
            throw ApiException::validation(['amount' => 'Enter a valid amount, e.g. 1500 or 1500.50']);
        }
        $neg = str_starts_with($s, '-');
        $s = ltrim($s, '-');
        [$r, $p] = array_pad(explode('.', $s, 2), 2, '0');
        $paise = (int) $r * 100 + (int) str_pad(substr($p, 0, 2), 2, '0');
        return $neg ? -$paise : $paise;
    }

    public static function toRupees(int $paise): string
    {
        $neg = $paise < 0;
        $paise = abs($paise);
        return ($neg ? '-' : '') . intdiv($paise, 100) . '.' . str_pad((string) ($paise % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Indian digit grouping: 1,23,456.00 */
    public static function format(int $paise, string $symbol = '₹', bool $decimals = true): string
    {
        $neg = $paise < 0;
        $paise = abs($paise);
        $r = (string) intdiv($paise, 100);
        if (strlen($r) > 3) {
            $last3 = substr($r, -3);
            $rest = substr($r, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $r = $rest . ',' . $last3;
        }
        $p = $paise % 100;
        $out = $symbol . $r . ($decimals || $p ? '.' . str_pad((string) $p, 2, '0', STR_PAD_LEFT) : '');
        return ($neg ? '-' : '') . $out;
    }

    public static function percentOf(int $paise, int $basisPoints): int
    {
        // basis points: 1050 = 10.50 %
        return intdiv($paise * $basisPoints + 5000, 10000);
    }
}
