<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\NotificationTemplate;

/**
 * Built-in message texts (Bangla and English) and the school's own
 * overrides. Placeholders are {name}; values are inserted as plain text.
 */
class MessageTemplates
{
    /** @var array<string, array{placeholders: list<string>, sms: array{en: string, bn: string}, mail: array{subject_en: string, subject_bn: string, en: string, bn: string}}> */
    public const DEFAULTS = [
        'attendance.absent' => [
            'placeholders' => ['student', 'date', 'school'],
            'sms' => ['en' => '{student} was absent from school on {date}. - {school}', 'bn' => '{student} আজ {date} তারিখে বিদ্যালয়ে অনুপস্থিত ছিল। - {school}'],
            'mail' => [
                'subject_en' => '{student} was absent today', 'subject_bn' => '{student} আজ অনুপস্থিত',
                'en' => "Dear guardian,\n\n{student} was marked absent on {date}. If this is unexpected, please contact the school.\n\n{school}",
                'bn' => "সম্মানিত অভিভাবক,\n\n{student} {date} তারিখে অনুপস্থিত ছিল। এটি অপ্রত্যাশিত হলে বিদ্যালয়ে যোগাযোগ করুন।\n\n{school}",
            ],
        ],
        'results.published' => [
            'placeholders' => ['student', 'exam', 'result', 'school'],
            'sms' => ['en' => '{exam} result of {student}: {result}. Details in the parent portal. - {school}', 'bn' => '{student}-এর {exam} ফলাফল: {result}। বিস্তারিত অভিভাবক পোর্টালে। - {school}'],
            'mail' => [
                'subject_en' => '{exam} results: {student}', 'subject_bn' => '{exam} ফলাফল: {student}',
                'en' => "Dear guardian,\n\nThe {exam} results are published. {student}: {result}.\n\nThe full report card is in the parent portal.\n\n{school}",
                'bn' => "সম্মানিত অভিভাবক,\n\n{exam}-এর ফলাফল প্রকাশিত হয়েছে। {student}: {result}।\n\nপূর্ণ রিপোর্ট কার্ড অভিভাবক পোর্টালে।\n\n{school}",
            ],
        ],
        'fees.reminder' => [
            'placeholders' => ['student', 'amount', 'due_date', 'school'],
            'sms' => ['en' => 'Fee reminder: {amount} is due for {student} (due {due_date}). - {school}', 'bn' => 'বেতন স্মরণিকা: {student}-এর {amount} বকেয়া (শেষ তারিখ {due_date})। - {school}'],
            'mail' => [
                'subject_en' => 'Fee reminder for {student}', 'subject_bn' => '{student}-এর বেতন স্মরণিকা',
                'en' => "Dear guardian,\n\n{amount} is due for {student}. Due date: {due_date}.\n\nPlease pay at the school office or online in the parent portal.\n\n{school}",
                'bn' => "সম্মানিত অভিভাবক,\n\n{student}-এর {amount} বকেয়া আছে। শেষ তারিখ: {due_date}।\n\nবিদ্যালয়ের অফিসে বা অভিভাবক পোর্টালে অনলাইনে পরিশোধ করুন।\n\n{school}",
            ],
        ],
        'general' => [
            'placeholders' => ['message', 'school'],
            'sms' => ['en' => '{message} - {school}', 'bn' => '{message} - {school}'],
            'mail' => ['subject_en' => 'Message from {school}', 'subject_bn' => '{school} থেকে বার্তা', 'en' => "{message}\n\n{school}", 'bn' => "{message}\n\n{school}"],
        ],
    ];

    /**
     * @param  array<string, string>  $data
     * @return array{subject: ?string, body: string}
     */
    public function render(string $key, string $channel, string $locale, array $data): array
    {
        $default = self::DEFAULTS[$key] ?? throw new \InvalidArgumentException("Unknown message template [{$key}].");
        $custom = NotificationTemplate::query()->where('template_key', $key)->where('channel', $channel)->where('is_active', true)->first();
        $bn = $locale === 'bn';

        if ($channel === 'sms') {
            $body = $custom ? (($bn ? $custom->body_bn : null) ?: $custom->body) : $default['sms'][$bn ? 'bn' : 'en'];
            $subject = null;
        } else {
            $body = $custom ? (($bn ? $custom->body_bn : null) ?: $custom->body) : $default['mail'][$bn ? 'bn' : 'en'];
            $subject = $custom ? (($bn ? $custom->subject_bn : null) ?: $custom->subject) : $default['mail'][$bn ? 'subject_bn' : 'subject_en'];
        }

        return ['subject' => $subject === null ? null : $this->fill($subject, $data), 'body' => $this->fill($body, $data)];
    }

    /** @param  array<string, string>  $data */
    private function fill(string $text, array $data): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => array_key_exists($m[1], $data) ? str_replace(["\r", "\0"], '', (string) $data[$m[1]]) : $m[0], $text);
    }

    /** SMS parts: GSM-7 text fits 160 (153 when split), anything else (Bangla) 70 (67). */
    public static function segments(string $text): int
    {
        $gsm = ! preg_match('/[^\x{20}-\x{7E}\n\r£¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉÄÖÑÜ§¿äöñüà]/u', $text);
        $len = mb_strlen($text);
        [$single, $multi] = $gsm ? [160, 153] : [70, 67];

        return $len <= $single ? 1 : (int) ceil($len / $multi);
    }
}
