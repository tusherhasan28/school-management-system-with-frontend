<?php

namespace App\Modules\Messaging\Jobs;

use App\Jobs\Middleware\EnsureTenantIsAccessible;
use App\Modules\Messaging\Mail\SchoolMessage;
use App\Modules\Messaging\Models\NotificationLog;
use App\Modules\Messaging\Models\SmsProviderAccount;
use App\Modules\Messaging\Providers\SmsProviderRegistry;
use App\Modules\Messaging\Providers\SmsSendFailed;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers one logged message. Runs in the dispatching school's context
 * (QueueTenancy), at most the provider's per-minute rate per school, with
 * retries; the log row records every outcome. A message already sent is
 * never sent again.
 */
class DeliverMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    public int $schoolId;

    public function __construct(public int $logId, public int $perMinute = 60)
    {
        $this->schoolId = app(TenantContext::class)->require()->id;
        $this->onQueue('messages');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return config('messaging.retry_backoff', [60, 300, 900]);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        // Over the limit, the job is released and tried again shortly (it does not fail).
        return [new EnsureTenantIsAccessible, new RateLimited('messaging-sms')];
    }

    public function handle(SmsProviderRegistry $providers): void
    {
        $log = NotificationLog::query()->find($this->logId);

        if ($log === null || $log->status === 'sent' || $log->status === 'skipped') {
            return;
        }

        $log->increment('attempts');

        try {
            $reference = $log->channel === 'sms' ? $this->sms($log, $providers) : $this->mail($log);
        } catch (SmsSendFailed $e) {
            $log->forceFill(['error_message' => mb_substr($e->getMessage(), 0, 500)])->save();

            if (! $e->retryable) {
                $this->markFailed($log, $e->getMessage());
                $this->delete();

                return;
            }

            throw $e;
        } catch (Throwable $e) {
            // Never store exception details that could carry credentials or URLs with keys.
            $log->forceFill(['error_message' => mb_substr(class_basename($e).': '.preg_replace('/(key|token|secret|password)=\S+/i', '$1=[hidden]', $e->getMessage()), 0, 500)])->save();

            throw $e;
        }

        $log->forceFill(['status' => 'sent', 'sent_at' => now(), 'provider_reference' => $reference, 'error_message' => null])->save();
    }

    public function failed(?Throwable $e): void
    {
        $log = NotificationLog::query()->find($this->logId);

        if ($log && $log->status === 'queued') {
            $this->markFailed($log, $log->error_message ?? ($e ? class_basename($e) : 'failed'));
        }
    }

    private function sms(NotificationLog $log, SmsProviderRegistry $providers): string
    {
        $account = SmsProviderAccount::query()->where('provider', $log->provider)->where('is_enabled', true)->first();
        $provider = $providers->get((string) $log->provider);

        if ($account === null || $provider === null) {
            throw new SmsSendFailed('SMS provider is not available.', retryable: false);
        }

        return $provider->send($log->recipient, $log->body, $account);
    }

    private function mail(NotificationLog $log): string
    {
        $school = app(TenantContext::class)->require();
        Mail::to($log->recipient)->send(new SchoolMessage((string) $log->subject, $log->body, $school->name));

        return 'mail';
    }

    private function markFailed(NotificationLog $log, string $message): void
    {
        $log->forceFill(['status' => 'failed', 'error_message' => mb_substr($message, 0, 500)])->save();
    }
}
