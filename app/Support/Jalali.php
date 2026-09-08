<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Converts Gregorian dates to Jalali (Shamsi) without external dependencies.
 */
class Jalali
{
    private const MONTHS = [
        'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
    ];

    private const WEEKDAYS = [
        'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه',
    ];

    private const GREGORIAN_CUMULATIVE_DAYS = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

    public static function format(?CarbonInterface $date, bool $withTime = true): ?string
    {
        if ($date === null) {
            return null;
        }

        [$year, $month, $day] = self::toJalali($date->year, $date->month, $date->day);

        $formatted = sprintf('%d/%02d/%02d', $year, $month, $day);

        return $withTime
            ? $formatted.' - '.$date->format('H:i')
            : $formatted;
    }

    public static function formatLong(?CarbonInterface $date): ?string
    {
        if ($date === null) {
            return null;
        }

        [$year, $month, $day] = self::toJalali($date->year, $date->month, $date->day);

        return sprintf('%s %d %s %d', self::WEEKDAYS[$date->dayOfWeek], $day, self::MONTHS[$month - 1], $year);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function toJalali(int $gy, int $gm, int $gd): array
    {
        $jy = ($gy <= 1600) ? 0 : 979;
        $gy -= ($gy <= 1600) ? 621 : 1600;
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400) - 80 + $gd + self::GREGORIAN_CUMULATIVE_DAYS[$gm - 1];

        $jy += 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        $jm = ($days < 186) ? 1 + intdiv($days, 31) : 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));

        return [$jy, $jm, $jd];
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (8 * intdiv($jy, 33)) + intdiv(($jy % 33) + 3, 4) + $jd
            + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);

        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $days--;
            $gy += 100 * intdiv($days, 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;

        foreach ([31, 0, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31] as $month => $length) {
            if ($month === 1) {
                $length = (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) ? 29 : 28;
            }
            if ($gd <= $length) {
                break;
            }
            $gd -= $length;
        }

        return [$gy, $month + 1, $gd];
    }

    /**
     * Converts a user-entered Jalali date (1405/06/14, ۱۴۰۵-۰۶-۱۴, …) to a Gregorian Y-m-d string.
     */
    public static function parseJalaliInput(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = strtr(trim($value), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        if (! preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $normalized, $matches)) {
            throw new \InvalidArgumentException('قالب تاریخ شمسی صحیح نیست (نمونه: ۱۴۰۵/۰۶/۰۱).');
        }

        [$jy, $jm, $jd] = [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
        if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) {
            throw new \InvalidArgumentException('تاریخ شمسی وارد شده معتبر نیست.');
        }

        [$gy, $gm, $gd] = self::toGregorian($jy, $jm, $jd);

        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }
}
