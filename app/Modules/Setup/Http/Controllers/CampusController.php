<?php

namespace App\Modules\Setup\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Setup\Http\Requests\CampusRequest;
use App\Modules\Setup\Models\Campus;
use App\Modules\Setup\Services\CampusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CampusController extends Controller
{
    public function __construct(private readonly CampusService $campuses) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Campus::class);

        $search = trim((string) $request->query('search', ''));

        $campuses = Campus::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('name_bn', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")))
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Campus $campus) => $this->present($campus));

        return Inertia::render('app/campuses/index', [
            'campuses' => $campuses,
            'filters' => ['search' => $search],
            'can' => ['manage' => $request->user()->can('create', Campus::class)],
        ]);
    }

    public function show(Campus $campus): Response
    {
        $this->authorize('view', $campus);

        return Inertia::render('app/campuses/show', ['campus' => $this->present($campus)]);
    }

    public function store(CampusRequest $request): RedirectResponse
    {
        $this->campuses->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('campus.created')]);

        return back();
    }

    public function update(CampusRequest $request, Campus $campus): RedirectResponse
    {
        $this->campuses->update($campus, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('campus.updated')]);

        return back();
    }

    public function destroy(Campus $campus): RedirectResponse
    {
        $this->authorize('delete', $campus);

        $this->campuses->delete($campus);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('campus.deleted')]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Campus $campus): array
    {
        return $campus->only(['id', 'name', 'name_bn', 'code', 'address', 'phone', 'is_main']);
    }
}
