<?php

namespace App\Support;

use App\Enums\RecurringBillingFrequency;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Deterministic recurring billing calendar helpers (business-local dates).
 *
 * Month-end rule: advancing from day N uses Carbon::addMonthsNoOverflow when available,
 * otherwise clamps to the last day of the target month (e.g. Jan 31 → Feb 28/29).
 */
final class RecurringBillingCalendar
{
    /**
     * @return array{period_key: string, period_start: string, period_end: string}
     */
    public static function periodFor(CarbonInterface $generationDate, RecurringBillingFrequency $frequency): array
    {
        $date = Carbon::parse($generationDate->toDateString())->startOfDay();

        return match ($frequency) {
            RecurringBillingFrequency::Monthly => [
                'period_key' => $date->format('Y-m'),
                'period_start' => $date->copy()->startOfMonth()->toDateString(),
                'period_end' => $date->copy()->endOfMonth()->toDateString(),
            ],
            RecurringBillingFrequency::Quarterly => [
                'period_key' => $date->format('Y').'-Q'.$date->quarter,
                'period_start' => $date->copy()->firstOfQuarter()->toDateString(),
                'period_end' => $date->copy()->lastOfQuarter()->toDateString(),
            ],
            RecurringBillingFrequency::Yearly => [
                'period_key' => $date->format('Y'),
                'period_start' => $date->copy()->startOfYear()->toDateString(),
                'period_end' => $date->copy()->endOfYear()->toDateString(),
            ],
        };
    }

    public static function advance(CarbonInterface $from, RecurringBillingFrequency $frequency): CarbonInterface
    {
        $date = Carbon::parse($from->toDateString())->startOfDay();

        return match ($frequency) {
            RecurringBillingFrequency::Monthly => $date->copy()->addMonthsNoOverflow(1),
            RecurringBillingFrequency::Quarterly => $date->copy()->addMonthsNoOverflow(3),
            RecurringBillingFrequency::Yearly => $date->copy()->addYearsNoOverflow(1),
        };
    }

    /**
     * Next occurrence on or after $fromDate, preserving preferred day-of-month when possible.
     */
    public static function nextOnOrAfter(
        CarbonInterface $fromDate,
        RecurringBillingFrequency $frequency,
        int $preferredDay,
    ): CarbonInterface {
        $cursor = Carbon::parse($fromDate->toDateString())->startOfDay();
        $candidate = self::clampDay($cursor->copy(), $preferredDay);

        if ($candidate->lt($cursor)) {
            $candidate = self::clampDay(self::advance($candidate, $frequency), $preferredDay);
        }

        // Safety: ensure we never return before fromDate.
        while ($candidate->lt($cursor)) {
            $candidate = self::clampDay(self::advance($candidate, $frequency), $preferredDay);
        }

        return $candidate;
    }

    private static function clampDay(CarbonInterface $date, int $day): CarbonInterface
    {
        $lastDay = (int) $date->copy()->endOfMonth()->day;
        $useDay = min(max($day, 1), $lastDay);

        return $date->copy()->day($useDay)->startOfDay();
    }
}
