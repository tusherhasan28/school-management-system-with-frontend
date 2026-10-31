<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Modules\People\Models\Student;
use Illuminate\Http\Request;

/** Student portal: always and only the signed-in student's own record. */
class StudentPortalController extends PortalController
{
    protected function student(Request $request): Student
    {
        $student = $this->access->studentFor($request->user());
        abort_if($student === null, 404);

        return $student;
    }

    protected function base(Request $request, Student $student): string
    {
        return route('student.dashboard', [], false);
    }

    protected function audience(): string
    {
        return 'students';
    }
}
