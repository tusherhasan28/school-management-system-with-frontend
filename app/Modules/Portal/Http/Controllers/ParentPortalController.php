<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Modules\People\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Parent portal: one of the guardian's linked children, chosen in the URL. */
class ParentPortalController extends PortalController
{
    public function dashboard(Request $request): RedirectResponse|Response
    {
        $first = $this->access->children($request->user())->first();

        return $first
            ? redirect()->route('parent.child', ['student' => $first->id])
            : Inertia::render('portal/no-children');
    }

    protected function student(Request $request): Student
    {
        $id = $request->route('student');
        abort_unless(is_numeric($id), 404);

        // Any id that is not one of this guardian's children is a 404,
        // including another school's students (the tenant scope hides them).
        $student = Student::query()->find((int) $id);
        abort_if($student === null, 404);

        return $this->access->child($request->user(), $student);
    }

    protected function base(Request $request, Student $student): string
    {
        return route('parent.child', ['student' => $student->id], false);
    }

    protected function audience(): string
    {
        return 'parents';
    }
}
