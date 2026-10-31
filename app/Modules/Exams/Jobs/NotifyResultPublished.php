<?php

namespace App\Modules\Exams\Jobs;

use App\Jobs\Middleware\EnsureTenantIsAccessible;
use App\Modules\Exams\Models\Examination;
use App\Modules\Exams\Models\ExamResult;
use App\Modules\Messaging\Services\MessageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * After results are published: send each student's primary guardian a short
 * summary (grade, GPA, pass/fail) by the school's chosen channels. Only the current calculation is used, and
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

    public function handle(MessageService $messages): void
    {
        $exam = Examination::query()->find($this->examinationId);

        if ($exam === null || ! $exam->isPublished()) {
            return;
        }

        $channels = $messages->channelsFor('results.published');
        $locale = app(\App\Support\Tenancy\TenantContext::class)->require()->locale === 'en' ? 'en' : 'bn';

        ExamResult::query()
            ->with(['student' => fn ($q) => $q->select(['id', 'school_id', 'full_name', 'name_bn'])->with(['guardians' => fn ($q) => $q->wherePivot('is_primary', true)])])
            ->where('result_calculation_id', $exam->current_calculation_id)
            ->chunkById(200, function ($results) use ($exam, $messages, $channels, $locale) {
                $recipients = $results->map(function (ExamResult $r) use ($exam, $messages, $locale) {
                    $summary = __('result_'.$r->result_status, [], $locale).($r->letter_grade ? ', '.$r->letter_grade : '').($r->gpa !== null ? ', GPA '.$r->gpa : '');

                    return $r->student ? $messages->guardianRecipient($r->student, ['exam' => $locale === 'bn' && $exam->name_bn ? $exam->name_bn : $exam->name, 'result' => $summary]) : null;
                })->filter()->values()->all();

                if ($recipients !== []) {
                    $messages->send('results.published', $recipients, $channels);
                }
            });
    }
}
