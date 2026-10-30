<?php

namespace App\Modules\Teaching\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Services\SchoolSettings;
use App\Modules\Teaching\Models\Holiday;
use App\Modules\Teaching\Services\SchoolCalendar;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Holidays, weekly off-days and attendance notification settings.
 */
class CalendarController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request, SchoolCalendar $calendar, SchoolSettings $settings): Response
    {
        $year = $request->integer('year') ?: (int) $calendar->today()->format('Y');

        return Inertia::render('app/teaching/calendar', [
            'year' => $year,
            'holidays' => Holiday::query()
                ->where(fn ($q) => $q->whereYear('start_date', $year)->orWhereYear('end_date', $year))
                ->orderBy('start_date')
                ->get()
                ->map(fn (Holiday $h) => [
                    'id' => $h->id, 'name' => $h->name, 'name_bn' => $h->name_bn, 'holiday_type' => $h->holiday_type,
                    'start_date' => $h->start_date->toDateString(), 'end_date' => $h->end_date->toDateString(),
                ]),
            'weekend' => $calendar->weekendDays(),
            'notifyAbsent' => (bool) $settings->get('attendance.notify_absent', false),
            'workingDays' => $calendar->workingDays($calendar->today()->setDate($year, 1, 1), $calendar->today()->setDate($year, 12, 31)),
            'can' => ['manage' => $request->user()->can('calendar.manage')],
        ]);
    }

    public function storeHoliday(Request $request): RedirectResponse
    {
        $this->authorize('calendar.manage');
        $holiday = Holiday::create($this->validated($request));
        $this->audit->tenant('holiday.created', $holiday, new: $holiday->only(['name', 'start_date', 'end_date']));

        return $this->saved();
    }

    public function updateHoliday(Request $request, Holiday $holiday): RedirectResponse
    {
        $this->authorize('calendar.manage');
        $holiday->update($this->validated($request));
        $this->audit->tenant('holiday.updated', $holiday);

        return $this->saved();
    }

    public function destroyHoliday(Holiday $holiday): RedirectResponse
    {
        $this->authorize('calendar.manage');
        $this->audit->tenant('holiday.deleted', $holiday, $holiday->only(['name', 'start_date', 'end_date']));
        $holiday->delete();

        return $this->saved();
    }

    public function updateSettings(Request $request, SchoolSettings $settings): RedirectResponse
    {
        $this->authorize('calendar.manage');

        $data = $request->validate([
            'weekend' => ['array', 'max:3'],
            'weekend.*' => ['integer', 'between:1,7', 'distinct'],
            'notify_absent' => ['boolean'],
        ]);

        $settings->put('calendar.weekend_days', array_map('intval', $data['weekend'] ?? []));
        $settings->put('attendance.notify_absent', (bool) ($data['notify_absent'] ?? false));
        $this->audit->tenant('calendar.settings_updated', new: $data);

        return $this->saved();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'name_bn' => ['nullable', 'string', 'max:160'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'holiday_type' => ['required', Rule::in(['public', 'religious', 'school', 'exam_break', 'other'])],
        ]);
    }

    private function saved(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('teaching.saved')]);

        return back();
    }
}
