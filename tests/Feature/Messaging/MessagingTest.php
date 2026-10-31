<?php

namespace Tests\Feature\Messaging;

use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Messaging\Jobs\DeliverMessage;
use App\Modules\Messaging\Models\NotificationLog;
use App\Modules\Messaging\Models\NotificationTemplate;
use App\Modules\Messaging\Models\SmsProviderAccount;
use App\Modules\Messaging\Providers\SmsProviderRegistry;
use App\Modules\Messaging\Providers\SmsSendFailed;
use App\Modules\Messaging\Services\MessageService;
use App\Modules\Messaging\Services\MessageTemplates;
use App\Modules\Messaging\Services\PhoneNumber;
use App\Modules\Messaging\Notifications\InAppMessage;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\Concerns\CreatesPlans;
use Tests\Support\FakeSmsProvider;
use Tests\TenantTestCase;

/**
 * Email/SMS through the provider abstraction: templates in both languages,
 * delivery logs, retries and failures, plan quota, encrypted settings.
 */
class MessagingTest extends TenantTestCase
{
    use CreatesAcademicStructure, CreatesPlans;

    private FakeSmsProvider $sms;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['queue.default' => 'sync']);
        $this->sms = new FakeSmsProvider;
        app(SmsProviderRegistry::class)->register($this->sms);
        $this->s = $this->academicStructure();
        $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionA'], ['full_name' => 'Ayesha', 'name_bn' => 'আয়েশা'], '01711111111');
        $this->makeStudent($this->s['session'], $this->s['class6'], $this->s['sectionB'], ['full_name' => 'Tanvir'], '01722222222');
        $this->makeStudent($this->s['session'], $this->s['class7'], $this->s['class7A'], ['full_name' => 'Rafi'], '01733333333');
    }

    private function enableSms(): void
    {
        SmsProviderAccount::create(['provider' => 'fakesms', 'sender_id' => 'DHAKAMODEL', 'credentials' => ['api_key' => 'super-secret-key'], 'is_enabled' => true]);
    }

    public function test_phone_numbers_and_sms_parts(): void
    {
        $this->assertSame('8801711111111', PhoneNumber::normalize('01711-111111'));
        $this->assertSame('8801711111111', PhoneNumber::normalize('+880 1711 111111'));
        $this->assertNull(PhoneNumber::normalize('0211111111'));
        $this->assertSame(1, MessageTemplates::segments(str_repeat('a', 160)));
        $this->assertSame(2, MessageTemplates::segments(str_repeat('a', 161)));
        $this->assertSame(1, MessageTemplates::segments(str_repeat('ক', 70)));
        $this->assertSame(2, MessageTemplates::segments(str_repeat('ক', 71)));
    }

    public function test_templates_render_in_the_recipients_language_with_school_overrides(): void
    {
        $templates = app(MessageTemplates::class);
        $this->assertSame('Ayesha was absent from school on 2026-03-01. - Alpha', $templates->render('attendance.absent', 'sms', 'en', ['student' => 'Ayesha', 'date' => '2026-03-01', 'school' => 'Alpha'])['body']);
        $this->assertStringContainsString('আয়েশা আজ', $templates->render('attendance.absent', 'sms', 'bn', ['student' => 'আয়েশা', 'date' => '1', 'school' => 'x'])['body']);

        NotificationTemplate::create(['template_key' => 'attendance.absent', 'channel' => 'sms', 'body' => 'ABSENT {student} {unknown}', 'body_bn' => null]);
        // Bangla falls back to the school's English override; unknown placeholders stay as written.
        $this->assertSame('ABSENT Ayesha {unknown}', $templates->render('attendance.absent', 'sms', 'bn', ['student' => 'Ayesha'])['body']);
    }

    public function test_class_message_by_sms_and_email_is_logged_and_delivered(): void
    {
        $this->enableSms();
        $owner = $this->ownerOf($this->school);
        $guardian = \App\Modules\People\Models\Guardian::query()->where('phone', '01711111111')->sole();
        $guardian->update(['email' => 'ayesha.parent@example.com']);

        $this->actingAs($owner)->post(route('messages.send'), [
            'audience' => 'guardians_class', 'class_id' => $this->s['class6']->id, 'channels' => ['sms', 'mail'], 'message' => 'School closed tomorrow',
        ])->assertRedirect();

        $this->assertCount(2, $this->sms->sent); // two Class 6 guardians, Class 7 not included
        $this->assertSame('8801711111111', $this->sms->sent[0]['to']);
        $this->assertStringContainsString('School closed tomorrow', $this->sms->sent[0]['text']);
        Mail::assertSentCount(1); // only one guardian has an email
        $logs = NotificationLog::query()->orderBy('id')->get();
        $this->assertSame(['sent', 'sent', 'sent'], $logs->pluck('status')->all());
        $this->assertSame(1, $logs->pluck('batch_id')->unique()->count());
        $this->assertSame('FAKE-1', $logs->firstWhere('channel', 'sms')->provider_reference);

        $this->actingAs($owner)->get(route('messages.index'))->assertOk()->assertInertia(fn ($p) => $p
            ->has('logs.data', 3)->where('logs.data.2.recipient', '88017•••••111')->where('stats.sent', 3));
    }

    public function test_sms_quota_from_the_plan_skips_messages_over_the_limit(): void
    {
        $this->enableSms();
        app(SubscriptionService::class)->assign($this->school, $this->plan('tiny', ['sms_per_month' => 2]), 'monthly');

        $result = app(MessageService::class)->send('general', [
            ['name' => 'A', 'phone' => '01711111111', 'data' => ['message' => 'Hi']],
            ['name' => 'B', 'phone' => '01722222222', 'data' => ['message' => 'Hi']],
            ['name' => 'C', 'phone' => '01733333333', 'data' => ['message' => 'Hi']],
            ['name' => 'D', 'phone' => 'not a phone', 'data' => ['message' => 'Hi']],
        ], ['sms']);

        $this->assertSame(['queued' => 2, 'skipped' => 2], array_intersect_key($result, ['queued' => 1, 'skipped' => 1]));
        $this->assertCount(2, $this->sms->sent);
        $skipped = NotificationLog::query()->where('status', 'skipped')->sole();
        $this->assertSame('Monthly SMS limit of the plan reached.', $skipped->error_message);
    }

    public function test_sms_cannot_be_chosen_without_provider_or_plan(): void
    {
        $owner = $this->ownerOf($this->school);
        $this->actingAs($owner)->post(route('messages.send'), ['audience' => 'guardians_all', 'channels' => ['sms'], 'message' => 'x'])->assertSessionHasErrors('channels');

        $this->enableSms();
        app(SubscriptionService::class)->assign($this->school, $this->plan('nosms', ['sms' => false]), 'monthly');
        $this->actingAs($owner)->post(route('messages.send'), ['audience' => 'guardians_all', 'channels' => ['sms'], 'message' => 'x'])->assertSessionHasErrors('channels');
    }

    public function test_failures_are_retried_then_logged_and_can_be_sent_again(): void
    {
        $this->enableSms();
        config(['queue.default' => 'null']); // keep the job from running at dispatch
        app(MessageService::class)->send('general', [['name' => 'A', 'phone' => '01711111111', 'data' => ['message' => 'Hi']]], ['sms']);
        $log = NotificationLog::query()->sole();
        $job = new DeliverMessage($log->id, 30);

        $this->sms->failWith = new SmsSendFailed('Gateway timeout');
        try {
            $job->handle(app(SmsProviderRegistry::class));
            $this->fail('should throw to let the queue retry');
        } catch (SmsSendFailed) {
        }
        $this->assertSame(['queued', 1, 'Gateway timeout'], [$log->fresh()->status, $log->fresh()->attempts, $log->fresh()->error_message]);

        $job->failed(new SmsSendFailed('Gateway timeout')); // retries used up
        $this->assertSame('failed', $log->fresh()->status);

        $this->sms->failWith = null;
        config(['queue.default' => 'sync']);
        app(MessageService::class)->retry($log->fresh());
        $this->assertSame(['sent', 2], [$log->fresh()->status, $log->fresh()->attempts]);

        // A sent message is never sent twice, even if its job runs again.
        (new DeliverMessage($log->id, 30))->handle(app(SmsProviderRegistry::class));
        $this->assertCount(1, $this->sms->sent);

        // Permanent errors fail at once.
        config(['queue.default' => 'null']);
        app(MessageService::class)->send('general', [['name' => 'B', 'phone' => '01722222222', 'data' => ['message' => 'Hi']]], ['sms']);
        $second = NotificationLog::query()->latest('id')->first();
        $this->sms->failWith = new SmsSendFailed('Number blocked', retryable: false);
        (new DeliverMessage($second->id, 30))->handle(app(SmsProviderRegistry::class));
        $this->assertSame(['failed', 'Number blocked'], [$second->fresh()->status, $second->fresh()->error_message]);
    }

    public function test_jobs_are_rate_limited_per_school_and_provider_rate(): void
    {
        $job = new DeliverMessage(1, 30);
        $this->assertTrue(collect($job->middleware())->contains(fn ($m) => $m instanceof RateLimited));
        $limit = RateLimiter::limiter('messaging-sms')($job);
        $this->assertSame([30, 'sms:'.$this->school->id], [$limit->maxAttempts, $limit->key]);
    }

    public function test_provider_credentials_are_encrypted_and_never_sent_to_the_browser(): void
    {
        $owner = $this->ownerOf($this->school);
        $this->actingAs($owner)->put(route('messages.provider'), ['provider' => 'fakesms', 'sender_id' => 'DMS', 'is_enabled' => true, 'credentials' => ['api_key' => 'k-123456']])
            ->assertSessionHasNoErrors();

        $raw = DB::table('sms_provider_accounts')->value('credentials');
        $this->assertStringNotContainsString('k-123456', $raw);
        $this->assertSame(['api_key' => 'k-123456'], SmsProviderAccount::query()->sole()->credentials);

        // Blank keeps the stored secret.
        $this->actingAs($owner)->put(route('messages.provider'), ['provider' => 'fakesms', 'sender_id' => 'DMS2', 'is_enabled' => true, 'credentials' => ['api_key' => '']]);
        $this->assertSame('k-123456', SmsProviderAccount::query()->sole()->credentials['api_key']);

        $page = $this->actingAs($owner)->get(route('messages.settings'))->assertOk();
        $this->assertStringNotContainsString('k-123456', $page->getContent());
        $providers = collect($page->viewData('page')['props']['providers']);
        $this->assertTrue($providers->firstWhere('key', 'fakesms')['has_credentials']);
        $this->actingAs($owner)->put(route('messages.provider'), ['provider' => 'made-up'])->assertSessionHasErrors('provider');
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $principal = $this->userWithRole('Principal');
        $teacher = $this->userWithRole('Teacher');
        $this->actingAs($teacher)->get(route('messages.index'))->assertForbidden();
        $this->actingAs($principal)->get(route('messages.index'))->assertOk();
        $this->actingAs($principal)->get(route('messages.settings'))->assertForbidden();

        $beta = $this->provisionSchool('beta');
        $betaLog = $this->inSchool($beta, fn () => NotificationLog::create(['channel' => 'mail', 'recipient' => 'x@example.com', 'body' => 'b', 'status' => 'failed']));
        $this->onSchool($this->school);
        $this->actingAs($principal)->post(route('messages.retry', $betaLog->id))->assertNotFound();
    }

    public function test_fee_reminders_go_to_guardians_with_overdue_invoices(): void
    {
        $tuition = \App\Modules\Finance\Models\FeeCategory::create(['name' => 'Tuition']);
        \App\Modules\Finance\Models\FeeStructure::create(['academic_session_id' => $this->s['session']->id, 'class_id' => $this->s['class6']->id, 'fee_category_id' => $tuition->id,
            'name' => 'Tuition', 'amount' => '1500.00', 'frequency' => 'monthly', 'start_period' => '2026-01', 'applies_to' => 'class']);
        $owner = $this->ownerOf($this->school);
        $this->actingAs($owner)->post(route('fees.invoices.generate'), ['period' => '2026-02', 'due_date' => '2026-02-10'])->assertSessionHasNoErrors();
        $this->enableSms();

        $this->actingAs($owner)->post(route('messages.send'), ['audience' => 'guardians_overdue', 'channels' => ['sms']])->assertSessionHasNoErrors();
        $this->assertCount(2, $this->sms->sent); // Class 6 only
        $this->assertStringContainsString('1500.00 BDT', $this->sms->sent[0]['text']);
        $this->assertSame('fees.reminder', NotificationLog::query()->first()->template_key);
    }

    public function test_notification_centre_lists_and_marks_only_own_notifications(): void
    {
        $owner = $this->ownerOf($this->school);
        $other = $this->userWithRole('Principal');
        $owner->notify(new InAppMessage('support.notify_reply', ['reference' => 'SUP-00001'], '/app/support/1'));
        $other->notify(new InAppMessage('support.notify_reply', ['reference' => 'SUP-99999'], 'https://evil.test/'));

        $this->actingAs($owner)->getJson(route('notifications.index'))->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.title', 'Support replied on ticket SUP-00001')->assertJsonPath('unread', 1);
        $this->actingAs($other)->getJson(route('notifications.index'))->assertJsonPath('items.0.url', null); // off-site links dropped
        $foreign = $other->notifications()->first()->id;
        $this->actingAs($owner)->postJson(route('notifications.read'), ['id' => $foreign])->assertOk();
        $this->assertSame(1, $other->unreadNotifications()->count());
        $this->actingAs($owner)->postJson(route('notifications.read'), [])->assertOk();
        $this->assertSame(0, $owner->unreadNotifications()->count());
    }
}
