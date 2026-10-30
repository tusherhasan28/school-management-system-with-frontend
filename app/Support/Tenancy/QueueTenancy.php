<?php

namespace App\Support\Tenancy;

use App\Models\School;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Carries the tenant across the queue boundary.
 *
 * Every job dispatched while a tenant context is active gets the school id in
 * its payload. The worker restores the context before the job runs and puts
 * the previous state back afterwards, whatever the outcome, so one job's
 * tenant never leaks into the next job on the same worker. With the sync
 * driver the job runs inside the dispatching request, whose context is
 * restored the same way.
 */
class QueueTenancy
{
    public const PAYLOAD_KEY = 'school_id';

    /** @var list<School|null> */
    private static array $stack = [];

    public static function register(): void
    {
        Queue::createPayloadUsing(function (string $connection, ?string $queue, array $payload): array {
            $context = app(TenantContext::class);

            return $context->check() ? [self::PAYLOAD_KEY => $context->id()] : [];
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $context = app(TenantContext::class);
            self::$stack[] = $context->school();
            $context->clear();

            $schoolId = $event->job->payload()[self::PAYLOAD_KEY] ?? null;

            if ($schoolId === null) {
                return;
            }

            // Loaded regardless of status: EnsureTenantIsAccessible decides whether to run.
            $school = School::query()->find($schoolId);

            if ($school === null) {
                $event->job->fail(Exceptions\TenantContextException::missing());

                return;
            }

            $context->set($school);
        });

        $restore = function (): void {
            if (self::$stack === []) {
                return;
            }

            $context = app(TenantContext::class);
            $context->clear();

            $previous = array_pop(self::$stack);

            if ($previous !== null) {
                $context->set($previous);
            }
        };

        // A failing job fires JobExceptionOccurred (and maybe JobFailed); a
        // successful one fires JobProcessed. Exactly one of these restores.
        Event::listen(JobProcessed::class, $restore);
        Event::listen(JobExceptionOccurred::class, $restore);
    }
}
