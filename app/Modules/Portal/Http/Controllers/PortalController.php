<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Exams\Models\Examination;
use App\Modules\Exams\Services\ResultReader;
use App\Modules\Finance\Models\FeeInvoice;
use App\Modules\Finance\Models\FeePayment;
use App\Modules\Finance\Services\FinanceDocuments;
use App\Modules\Finance\Services\OnlinePaymentService;
use App\Modules\Homework\Models\Homework;
use App\Modules\Notices\Models\Notice;
use App\Modules\People\Models\Student;
use App\Modules\Portal\Services\PortalAccess;
use App\Modules\Portal\Services\PortalData;
use App\Support\Tenancy\TenantStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared screens of the parent and student portals. Subclasses decide which
 * student the request is about; every id in the URL after that (exam,
 * invoice, payment, homework) is looked up *through* that student, so a
 * changed id can only ever reach the student's own records — anything else
 * is a 404.
 */
abstract class PortalController extends Controller
{
    public const TABS = ['overview', 'attendance', 'timetable', 'homework', 'results', 'fees', 'notices'];

    public function __construct(
        protected readonly PortalAccess $access,
        protected readonly PortalData $data,
    ) {}

    /** The student this request is about, already authorised. */
    abstract protected function student(Request $request): Student;

    /** URL prefix of the student's portal pages. */
    abstract protected function base(Request $request, Student $student): string;

    /** Notice audience of this portal: "parents" or "students". */
    abstract protected function audience(): string;

    public function show(Request $request): Response
    {
        $tab = (string) $request->route('tab', 'overview');
        abort_unless(in_array($tab, self::TABS, true), 404);
        $student = $this->student($request);

        $props = match ($tab) {
            'overview' => $this->data->overview($student, $this->audience()),
            'attendance' => $this->data->attendance($student, $request->query('month')),
            'timetable' => ['periods' => $this->data->timetable($student), 'weekend' => $this->data->weekend()],
            'homework' => ['items' => $this->data->homework($student)],
            'results' => ['items' => $this->data->results($student)],
            'fees' => $this->data->fees($student),
            'notices' => ['items' => $this->data->notices($this->audience())],
        };

        return Inertia::render('portal/student', [
            'tab' => $tab,
            'base' => $this->base($request, $student),
            'student' => $this->data->profile($student),
            'data' => $props,
        ]);
    }

    public function reportCard(Request $request, ResultReader $reader): Response
    {
        $student = $this->student($request);
        $result = $this->data->publishedResult($student, (int) $request->route('exam'));
        abort_if($result === null, 404);

        $exam = Examination::query()->findOrFail($result->examination_id);

        return Inertia::render('app/exams/report-cards', $reader->props($exam, $result->class_id, $result->section_id, $student->id));
    }

    public function invoice(Request $request, FinanceDocuments $documents): Response
    {
        $invoice = $this->ownInvoice($request);

        return Inertia::render('app/fees/invoice-print', $documents->invoice($invoice));
    }

    public function receipt(Request $request, FinanceDocuments $documents): Response
    {
        $student = $this->student($request);
        $payment = FeePayment::query()->where('student_id', $student->id)->where('status', 'successful')->find((int) $request->route('payment'));
        abort_if($payment === null, 404);

        return Inertia::render('app/fees/receipt', $documents->receipt($payment));
    }

    /**
     * Start an online payment for one of the student's invoices. The invoice
     * is only marked paid when the provider's verified callback (or the
     * reconciliation job) confirms it, never from the browser coming back.
     */
    public function pay(Request $request, OnlinePaymentService $online): HttpResponse
    {
        $invoice = $this->ownInvoice($request);
        $data = $request->validate(['gateway' => ['required', 'string', Rule::in(array_keys($online->available()))]]);

        [, $checkout] = $online->start($invoice, $data['gateway'], [
            'callback' => route('payments.callback', $data['gateway']),
            'return' => route('payments.return', $data['gateway']).'?ref={ref}',
        ]);

        if (strtoupper($checkout->method) === 'GET') {
            return Inertia::location($checkout->url.($checkout->fields ? (str_contains($checkout->url, '?') ? '&' : '?').http_build_query($checkout->fields) : ''));
        }

        // The provider expects a form POST: hand over through a one-time page.
        $request->session()->put('portal.checkout', ['url' => $checkout->url, 'fields' => $checkout->fields]);

        return Inertia::location(route('portal.checkout'));
    }

    public function homeworkAttachment(Request $request, TenantStorage $storage): StreamedResponse
    {
        $student = $this->student($request);
        $homework = Homework::query()->find((int) $request->route('homework'));
        $visible = $homework !== null && collect($this->data->homework($student))->contains('id', $homework->id);
        abort_unless($visible && $homework->attachment_path && $storage->owns($homework->attachment_path) && $storage->disk()->exists($homework->attachment_path), 404);

        return $storage->disk()->download($homework->attachment_path, $homework->attachment_name ?? 'attachment');
    }

    /** Notices are per audience, not per student: the portal middleware is the gate. */
    public function noticeAttachment(Request $request, TenantStorage $storage): StreamedResponse
    {
        $notice = Notice::query()->visibleTo([$this->audience()])->whereNotNull('attachment_path')->find((int) $request->route('notice'));
        abort_unless($notice !== null && $storage->owns($notice->attachment_path) && $storage->disk()->exists($notice->attachment_path), 404);

        return $storage->disk()->download($notice->attachment_path, $notice->attachment_name ?? 'attachment');
    }

    private function ownInvoice(Request $request): FeeInvoice
    {
        $student = $this->student($request);
        $invoice = FeeInvoice::query()->where('student_id', $student->id)->where('status', '!=', 'cancelled')->find((int) $request->route('invoice'));
        abort_if($invoice === null, 404);

        return $invoice;
    }
}
