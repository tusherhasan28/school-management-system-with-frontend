<?php

namespace App\Modules\Notices\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notices\Models\Event;
use App\Modules\Notices\Models\Notice;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Notices (with audiences, schedule and attachment) and events. Needs notices.manage. */
class NoticeController extends Controller
{
    public function __construct(private readonly TenantStorage $storage, private readonly AuditLogger $audit, private readonly TenantContext $tenant) {}

    public function index(Request $request): Response
    {
        $this->authorizeManage($request);
        $f = $request->validate(['status' => ['nullable', Rule::in(['draft', 'published', 'scheduled', 'expired'])], 'q' => ['nullable', 'string', 'max:100']]);

        return Inertia::render('app/notices/index', [
            'notices' => Notice::query()
                ->with('author:id,school_id,name')
                ->when($f['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('title', 'like', "%{$term}%")->orWhere('title_bn', 'like', "%{$term}%")))
                ->when($f['status'] ?? null, fn ($q, $s) => match ($s) {
                    'scheduled' => $q->where('status', 'published')->where('publish_at', '>', now()),
                    'expired' => $q->where('status', 'published')->where('expires_at', '<=', now()),
                    default => $q->where('status', $s),
                })
                ->newestFirst()->paginate(20)->withQueryString()
                ->through(fn (Notice $n) => [
                    'id' => $n->id, 'title' => $n->title, 'title_bn' => $n->title_bn, 'status' => $this->state($n), 'audiences' => $n->audiences,
                    'is_pinned' => $n->is_pinned, 'publish_at' => $n->publishedOn()->toIso8601String(), 'expires_at' => $n->expires_at?->toIso8601String(),
                    'author' => $n->author?->name, 'has_attachment' => $n->attachment_path !== null,
                ]),
            'filters' => ['status' => $f['status'] ?? '', 'q' => $f['q'] ?? ''],
            'events' => Event::query()->orderByDesc('starts_on')->limit(50)->get()->map(fn (Event $e) => $e->only(['id', 'title', 'title_bn', 'description', 'description_bn', 'location', 'is_public']) + [
                'starts_on' => $e->starts_on->toDateString(), 'ends_on' => $e->ends_on?->toDateString(), 'starts_at' => $e->starts_at ? substr($e->starts_at, 0, 5) : null,
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeManage($request);

        return Inertia::render('app/notices/form', ['notice' => null]);
    }

    public function edit(Request $request, Notice $notice): Response
    {
        $this->authorizeManage($request);
        $tz = $this->tenant->require()->timezone;

        return Inertia::render('app/notices/form', [
            'notice' => $notice->only(['id', 'title', 'title_bn', 'body', 'body_bn', 'audiences', 'is_pinned', 'status', 'attachment_name']) + [
                'publish_at' => $notice->publish_at?->timezone($tz)->format('Y-m-d\TH:i'),
                'expires_at' => $notice->expires_at?->timezone($tz)->format('Y-m-d\TH:i'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);
        $notice = new Notice(['created_by' => $request->user()->id]);
        $this->save($request, $notice);

        return to_route('notices.index');
    }

    /** POST (multipart for the attachment). */
    public function update(Request $request, Notice $notice): RedirectResponse
    {
        $this->authorizeManage($request);
        $this->save($request, $notice);

        return to_route('notices.index');
    }

    public function destroy(Request $request, Notice $notice): RedirectResponse
    {
        $this->authorizeManage($request);

        if ($notice->attachment_path && $this->storage->owns($notice->attachment_path)) {
            $this->storage->disk()->delete($notice->attachment_path);
        }

        $notice->delete();
        $this->audit->tenant('notice.deleted', null, ['id' => $notice->id, 'title' => $notice->title]);

        return back();
    }

    public function attachment(Request $request, Notice $notice): StreamedResponse
    {
        $this->authorizeManage($request);
        abort_unless($notice->attachment_path && $this->storage->owns($notice->attachment_path), 404);

        return $this->storage->disk()->download($notice->attachment_path, $notice->attachment_name ?? 'attachment');
    }

    public function saveEvent(Request $request, ?Event $event = null): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'title_bn' => ['nullable', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:5000'],
            'description_bn' => ['nullable', 'string', 'max:5000'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:190'],
            'is_public' => ['boolean'],
        ]);
        ($event ?? new Event(['created_by' => $request->user()->id]))->fill($data)->save();

        return back();
    }

    public function destroyEvent(Request $request, Event $event): RedirectResponse
    {
        $this->authorizeManage($request);
        $event->delete();

        return back();
    }

    private function save(Request $request, Notice $notice): void
    {
        $tz = $this->tenant->require()->timezone;
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'title_bn' => ['nullable', 'string', 'max:190'],
            'body' => ['nullable', 'string', 'max:50000'],
            'body_bn' => ['nullable', 'string', 'max:50000'],
            'audiences' => ['required', 'array', 'min:1'],
            'audiences.*' => [Rule::in(Notice::AUDIENCES)],
            'is_pinned' => ['boolean'],
            'publish_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:publish_at'],
            'status' => ['required', Rule::in(['draft', 'published'])],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'max:10240'],
            'remove_attachment' => ['boolean'],
        ]);

        $notice->fill([
            'title' => $data['title'], 'title_bn' => $data['title_bn'] ?? null, 'body' => $data['body'] ?? null, 'body_bn' => $data['body_bn'] ?? null,
            'audiences' => array_values(array_unique($data['audiences'])), 'is_pinned' => (bool) ($data['is_pinned'] ?? false), 'status' => $data['status'],
            'publish_at' => isset($data['publish_at']) ? \Carbon\CarbonImmutable::parse($data['publish_at'], $tz)->utc() : null,
            'expires_at' => isset($data['expires_at']) ? \Carbon\CarbonImmutable::parse($data['expires_at'], $tz)->utc() : null,
        ]);

        $old = $notice->attachment_path;

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $path = $this->storage->path('notices/'.Str::random(32).'.'.$file->extension());
            $this->storage->disk()->putFileAs(dirname($path), $file, basename($path));
            $notice->forceFill(['attachment_path' => $path, 'attachment_name' => Str::limit($file->getClientOriginalName(), 180, '')]);
        } elseif ($request->boolean('remove_attachment')) {
            $notice->forceFill(['attachment_path' => null, 'attachment_name' => null]);
        }

        $notice->save();

        if ($old && $old !== $notice->attachment_path && $this->storage->owns($old)) {
            $this->storage->disk()->delete($old);
        }

        $this->audit->tenant('notice.saved', $notice, [], ['title' => $notice->title, 'status' => $notice->status, 'audiences' => $notice->audiences]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.notice_saved')]);
    }

    private function state(Notice $n): string
    {
        return match (true) {
            $n->status === 'draft' => 'draft',
            $n->publish_at?->isFuture() === true => 'scheduled',
            $n->expires_at !== null && $n->expires_at->isPast() => 'expired',
            default => 'published',
        };
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can('notices.manage'), 403);
    }
}
