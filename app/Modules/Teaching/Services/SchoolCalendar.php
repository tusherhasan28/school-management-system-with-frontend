<?php

namespace App\Modules\Teaching\Services;

use App\Modules\Setup\Services\SchoolSettings;
use App\Modules\Teaching\Models\Holiday;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;

/**
 * Working days and holidays for the current school. Weekend days are a school
 * setting (ISO day numbers; default Friday) because Bangladeshi institutions
 * differ (Friday only, or Friday + Saturday).
 */
class SchoolCalendar
{
    public const DEFAULT_WEEKEND = [5]; // Friday

    public function __construct(
        private readonly SchoolSettings $settings,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @return list<int>
     */
    public function weekendDays(): array
    {
        $days = $this->settings->get('calendar.weekend_days', self::DEFAULT_WEEKEND);

        return array_values(array_map('intval', is_array($days) ? $days : self::DEFAULT_WEEKEND));
    }

    /**
     * Today's calendar date in the school's timezone, as a plain date (midnight
     * in the app timezone) so it compares correctly with parsed Y-m-d dates.
     */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now($this->tenant->require()->timezone)->toDateString());
    }

    public function holidayOn(CarbonInterface $date): ?Holiday
    {
        return Holiday::query()
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->first();
    }

    public function isWeekend(CarbonInterface $date): bool
    {
        return in_array($date->dayOfWeekIso, $this->weekendDays(), true);
    }

    /**
     * Null when the date is a school day, otherwise a short reason.
     */
    public function closedReason(CarbonInterface $date): ?string
    {
        if ($this->isWeekend($date)) {
            return __('attendance.weekend');
        }

        $holiday = $this->holidayOn($date);

        return $holiday ? __('attendance.holiday', ['name' => $holiday->name]) : null;
    }

    public function workingDays(CarbonInterface $from, CarbonInterface $to): int
    {
        $holidays = Holiday::query()
            ->whereDate('end_date', '>=', $from->toDateString())
            ->whereDate('start_date', '<=', $to->toDateString())
            ->get(['start_date', 'end_date']);

        $count = 0;

        foreach (CarbonPeriod::create($from->toDateString(), $to->toDateString()) as $day) {
            if ($this->isWeekend($day)) {
                continue;
            }

            $closed = $holidays->contains(fn (Holiday $h) => $day->betweenIncluded($h->start_date, $h->end_date));

            if (! $closed) {
                $count++;
            }
        }

        return $count;
    }
}
