<?php

namespace App\Modules\Academics\Services;

use App\Modules\Academics\Models\AcademicSession;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class AcademicSessionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): AcademicSession
    {
        $session = AcademicSession::create($data);
        $this->audit->tenant('academic_session.created', $session, new: $data);

        return $session;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(AcademicSession $session, array $data): AcademicSession
    {
        $old = $session->only(array_keys($data));
        $session->update($data);
        $this->audit->tenant('academic_session.updated', $session, $old, $data);

        return $session;
    }

    /**
     * Exactly one current session per school.
     */
    public function makeCurrent(AcademicSession $session): void
    {
        DB::transaction(function () use ($session) {
            AcademicSession::query()->where('is_current', true)->whereKeyNot($session->id)->update(['is_current' => false]);

            $session->is_current = true;
            if ($session->status === 'planned') {
                $session->status = 'active';
            }
            $session->save();
        });

        $this->audit->tenant('academic_session.made_current', $session);
    }
}
