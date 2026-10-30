<?php

namespace App\Modules\Setup\Services;

use App\Models\School;
use App\Modules\Academics\Models\AcademicSession;
use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Academics\Models\Section;
use App\Modules\Academics\Models\Subject;
use App\Modules\Academics\Services\AcademicSessionService;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Resumable first-run setup. Progress lives in school_settings
 * ("setup.progress") so the owner can leave and continue later.
 */
class SetupWizard
{
    public const STEPS = ['profile', 'session', 'classes', 'subjects'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SchoolSettings $settings,
        private readonly AcademicSessionService $sessions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{completed: list<string>, finished_at: string|null}
     */
    public function progress(): array
    {
        $value = $this->settings->get('setup.progress', []);

        return [
            'completed' => array_values(array_intersect(self::STEPS, $value['completed'] ?? [])),
            'finished_at' => $value['finished_at'] ?? null,
        ];
    }

    public function isFinished(): bool
    {
        return $this->progress()['finished_at'] !== null;
    }

    public function complete(string $step): void
    {
        $progress = $this->progress();
        $progress['completed'] = array_values(array_unique([...$progress['completed'], $step]));
        $this->settings->put('setup.progress', $progress);
    }

    public function finish(): void
    {
        $progress = $this->progress();
        $progress['finished_at'] = now()->toIso8601String();
        $this->settings->put('setup.progress', $progress);
        $this->audit->tenant('setup.finished');
    }

    /**
     * Update the current school's own profile. Only whitelisted fields; the
     * school is always the tenant context, never an id from the request.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveProfile(array $data): School
    {
        $school = $this->tenant->require();
        $old = $school->only(array_keys($data));

        $school->fill($data)->save();
        $this->audit->tenant('school.profile_updated', $school, $old, $school->only(array_keys($data)));
        $this->complete('profile');

        return $school;
    }

    /**
     * @param  array{name: string, start_date: string, end_date: string}  $data
     */
    public function saveSession(array $data): AcademicSession
    {
        $session = AcademicSession::query()->where('name', $data['name'])->first()
            ?? $this->sessions->create($data);

        $this->sessions->makeCurrent($session);
        $this->complete('session');

        return $session;
    }

    /**
     * @param  list<array{name: string, name_bn?: string|null, sections?: list<string>}>  $classes
     */
    public function saveClasses(array $classes, string $stage): void
    {
        DB::transaction(function () use ($classes, $stage) {
            $order = (int) SchoolClass::query()->max('level_order');

            foreach ($classes as $row) {
                $class = SchoolClass::query()->firstOrNew(['name' => $row['name']]);

                if (! $class->exists) {
                    $class->fill([
                        'name_bn' => $row['name_bn'] ?? null,
                        'level_order' => ++$order,
                        'education_stage' => $stage,
                    ])->save();
                }

                foreach ($row['sections'] ?? [] as $sectionName) {
                    $section = Section::query()->firstOrNew(['class_id' => $class->id, 'name' => $sectionName]);
                    $section->class_id = $class->id;
                    $section->save();
                }
            }
        });

        $this->audit->tenant('setup.classes_created', new: ['count' => count($classes)]);
        $this->complete('classes');
    }

    /**
     * @param  list<array{name: string, name_bn?: string|null, code?: string|null}>  $subjects
     */
    public function saveSubjects(array $subjects): void
    {
        DB::transaction(function () use ($subjects) {
            foreach ($subjects as $row) {
                Subject::query()->firstOrCreate(['name' => $row['name']], [
                    'name_bn' => $row['name_bn'] ?? null,
                    'code' => $row['code'] ?? null,
                ]);
            }
        });

        $this->audit->tenant('setup.subjects_created', new: ['count' => count($subjects)]);
        $this->complete('subjects');
    }

    /**
     * Suggested classes for an institution type. Schools can rename, remove or
     * add classes; nothing in the code depends on these labels.
     *
     * @return array{stage: string, classes: list<array{name: string, name_bn: string}>}
     */
    public static function classPreset(string $institutionType): array
    {
        $bnDigits = fn (int $n) => strtr((string) $n, ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯']);
        $numbered = fn (int $from, int $to, string $en, string $bn) => array_map(
            fn (int $n) => ['name' => "{$en} {$n}", 'name_bn' => "{$bn} {$bnDigits($n)}"],
            range($from, $to),
        );

        return match ($institutionType) {
            'kindergarten' => ['stage' => 'early_years', 'classes' => [
                ['name' => 'Play', 'name_bn' => 'প্লে'],
                ['name' => 'Nursery', 'name_bn' => 'নার্সারি'],
                ['name' => 'KG', 'name_bn' => 'কেজি'],
            ]],
            'college' => ['stage' => 'higher_secondary', 'classes' => [
                ['name' => 'HSC 1st Year', 'name_bn' => 'একাদশ শ্রেণি'],
                ['name' => 'HSC 2nd Year', 'name_bn' => 'দ্বাদশ শ্রেণি'],
            ]],
            'madrasa' => ['stage' => 'madrasa', 'classes' => [
                ...$numbered(1, 5, 'Ebtedayee', 'ইবতেদায়ি'),
                ...$numbered(6, 10, 'Dakhil', 'দাখিল'),
                ['name' => 'Alim 1st Year', 'name_bn' => 'আলিম ১ম বর্ষ'],
                ['name' => 'Alim 2nd Year', 'name_bn' => 'আলিম ২য় বর্ষ'],
            ]],
            default => ['stage' => 'secondary', 'classes' => $numbered(1, 10, 'Class', 'শ্রেণি')],
        };
    }

    /**
     * @return list<array{name: string, name_bn: string, code: string}>
     */
    public static function subjectPreset(string $institutionType): array
    {
        $common = [
            ['name' => 'Bangla', 'name_bn' => 'বাংলা', 'code' => 'BAN'],
            ['name' => 'English', 'name_bn' => 'ইংরেজি', 'code' => 'ENG'],
            ['name' => 'Mathematics', 'name_bn' => 'গণিত', 'code' => 'MATH'],
        ];

        return match ($institutionType) {
            'kindergarten' => [...$common, ['name' => 'Drawing', 'name_bn' => 'চিত্রাঙ্কন', 'code' => 'DRAW']],
            'madrasa' => [...$common,
                ['name' => 'Quran Majid & Tajweed', 'name_bn' => 'কুরআন মাজিদ ও তাজবিদ', 'code' => 'QUR'],
                ['name' => 'Arabic', 'name_bn' => 'আরবি', 'code' => 'ARB'],
                ['name' => 'Aqaid & Fiqh', 'name_bn' => 'আকাইদ ও ফিকহ', 'code' => 'FIQ'],
            ],
            'college' => [...array_slice($common, 0, 2),
                ['name' => 'Information & Communication Technology', 'name_bn' => 'তথ্য ও যোগাযোগ প্রযুক্তি', 'code' => 'ICT'],
                ['name' => 'Physics', 'name_bn' => 'পদার্থবিজ্ঞান', 'code' => 'PHY'],
                ['name' => 'Chemistry', 'name_bn' => 'রসায়ন', 'code' => 'CHEM'],
                ['name' => 'Accounting', 'name_bn' => 'হিসাববিজ্ঞান', 'code' => 'ACC'],
            ],
            default => [...$common,
                ['name' => 'Science', 'name_bn' => 'বিজ্ঞান', 'code' => 'SCI'],
                ['name' => 'Bangladesh & Global Studies', 'name_bn' => 'বাংলাদেশ ও বিশ্বপরিচয়', 'code' => 'BGS'],
                ['name' => 'Religion & Moral Education', 'name_bn' => 'ধর্ম ও নৈতিক শিক্ষা', 'code' => 'REL'],
                ['name' => 'Information & Communication Technology', 'name_bn' => 'তথ্য ও যোগাযোগ প্রযুক্তি', 'code' => 'ICT'],
            ],
        };
    }
}
