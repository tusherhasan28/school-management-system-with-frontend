<?php

namespace App\Modules\Exams\Jobs;

use App\Jobs\Middleware\EnsureTenantIsAccessible;
use App\Modules\Exams\Models\Examination;
use App\Modules\Exams\Models\ExamResult;
use App\Modules\Exams\Notifications\ResultPublished;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;

/**
 * After results are published: email each student's primary guardian a short
 * summary (grade, GPA, pass/fail). Only the current calculation is used, and
 * nothing is sent if the exam was reopened before the job ran.
 */
class NotifyResultPublished implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $examinationId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new EnsureTenantIsAccessible];
    }

    public function handle(TenantContext $tenant): void
    {
        $exam = Examination::query()->find($this->examinationId);

        if ($exam === null || ! $exam->isPublished()) {
            return;
        }

        ExamResult::query()
            ->with(['student' => fn ($q) => $q->select(['id', 'school_id', 'full_name'])->with(['guardians' => fn ($q) => $q->wherePivot('is_primary', true)])])
            ->where('result_calculation_id', $exam->current_calculation_id)
            ->chunkById(200, function ($results) use ($exam, $tenant) {
                foreach ($results as $result) {
                    $guardian = $result->student?->guardians->first();

                    if ($guardian?->email) {
                        Notification::route('mail', $guardian->email)->notify(new ResultPublished(
                            $tenant->require()->name,
                            $exam->name,
                            $result->student->full_name,
                            $result->result_status,
                            $result->letter_grade,
                            $result->gpa,
                        ));
                    }
                }
            });
    }
}
