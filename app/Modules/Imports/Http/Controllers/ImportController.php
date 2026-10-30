<?php

namespace App\Modules\Imports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academics\Services\AcademicOptions;
use App\Modules\Imports\Models\DataImportJob;
use App\Modules\Imports\Services\StudentImportService;
use App\Support\Tenancy\TenantRule;
use App\Support\Tenancy\TenantStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function __construct(private readonly StudentImportService $imports) {}

    public function index(Request $request, AcademicOptions $options): Response
    {
        $this->authorize('viewAny', DataImportJob::class);

        return Inertia::render('app/imports/index', [
            'imports' => DataImportJob::query()->with('user:id,name,school_id')->latest('id')->paginate(15)
                ->through(fn (DataImportJob $j) => $this->present($j)),
            'sessions' => $options->sessions(),
            'classes' => $options->classes(),
            'currentSessionId' => $options->currentSessionId(),
            'columns' => StudentImportService::COLUMNS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', DataImportJob::class);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt,xlsx'],
            'academic_session_id' => ['required', 'integer', TenantRule::exists('academic_sessions')],
            'class_id' => ['required', 'integer', TenantRule::exists('classes')],
            'section_id' => ['nullable', 'integer', TenantRule::exists('sections')->where('class_id', $request->integer('class_id'))],
        ]);

        try {
            $job = $this->imports->upload($request->file('file'), [
                'academic_session_id' => (int) $data['academic_session_id'],
                'class_id' => (int) $data['class_id'],
                'section_id' => isset($data['section_id']) ? (int) $data['section_id'] : null,
            ]);
        } catch (RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return to_route('imports.show', $job);
    }

    public function show(DataImportJob $import): Response
    {
        $this->authorize('view', $import);

        return Inertia::render('app/imports/show', ['import' => $this->present($import) + ['preview' => $import->preview ?? []]]);
    }

    public function confirm(DataImportJob $import): RedirectResponse
    {
        $this->authorize('update', $import);

        try {
            $this->imports->confirm($import);
        } catch (RuntimeException) {
            return back()->withErrors(['import' => __('imports.cannot_start')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('imports.queued')]);

        return back();
    }

    public function errors(DataImportJob $import, TenantStorage $storage): StreamedResponse
    {
        $this->authorize('view', $import);

        // The stored path must sit inside this school's directory.
        abort_unless($import->error_report_path && $storage->owns($import->error_report_path), 404);

        return $storage->disk()->download($import->error_report_path, 'import-'.$import->id.'-problems.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function template(): StreamedResponse
    {
        $this->authorize('create', DataImportJob::class);

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, StudentImportService::COLUMNS, escape: '\\');
            fputcsv($out, ['', 'Rahim Ahmed', 'রহিম আহমেদ', '2015-03-14', 'male', 'A', '1', 'Karim Ahmed', '01711000000', 'father', '', 'Mirpur, Dhaka'], escape: '\\');
            fclose($out);
        }, 'student-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DataImportJob $job): array
    {
        return [
            'id' => $job->id,
            'original_name' => $job->original_name,
            'status' => $job->status,
            'total_rows' => $job->total_rows,
            'valid_rows' => $job->valid_rows,
            'error_rows' => $job->error_rows,
            'duplicate_rows' => $job->duplicate_rows,
            'imported_rows' => $job->imported_rows,
            'has_report' => $job->error_report_path !== null,
            'failure_message' => $job->failure_message,
            'user' => $job->relationLoaded('user') ? $job->user?->name : null,
            'created_at' => $job->created_at?->toIso8601String(),
        ];
    }
}
