<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\Audit\AuditRecorder;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Branch::class);

        $branches = Branch::query()
            ->visibleTo($request->user(), 'branches.view')
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $branch): array => [
                'id' => $branch->getKey(),
                'code' => $branch->code,
                'name' => $branch->name,
                'city' => $branch->city,
                'is_default' => $branch->is_default,
                'is_active' => $branch->is_active,
            ])
            ->all();

        return Inertia::render('Admin/Branches/Index', ['branches' => $branches]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Branch::class);

        $data = $this->validated($request, null);

        DB::transaction(function () use ($data, $request): void {
            $branch = Branch::create($data);
            $this->recorder->record('created', 'branches', $branch, $request->user(), branchId: $branch->getKey());
        });

        return back()->with('success', 'Branch created.');
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $this->authorize('update', $branch);

        $data = $this->validated($request, $branch);

        DB::transaction(function () use ($branch, $data, $request): void {
            $branch->update($data);
            $this->recorder->record('updated', 'branches', $branch, $request->user(), branchId: $branch->getKey());
        });

        return back()->with('success', 'Branch updated.');
    }

    public function destroy(Request $request, Branch $branch): RedirectResponse
    {
        $this->authorize('delete', $branch);

        DB::transaction(function () use ($branch, $request): void {
            $this->recorder->record('deleted', 'branches', $branch, $request->user(), branchId: $branch->getKey());
            $branch->delete();
        });

        return back()->with('success', 'Branch removed.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Branch $branch): array
    {
        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        return $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('branches', 'code')
                ->where('company_id', $companyId)
                ->ignore($branch?->getKey())],
            'name' => ['required', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:80'],
            'is_active' => ['boolean'],
        ]);
    }
}
