<?php

declare(strict_types=1);

namespace App\Http\Controllers\Purchasing;

use App\Domain\Numbering\DocumentNumberService;
use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\Audit\AuditRecorder;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function __construct(
        private readonly AuditRecorder $recorder,
        private readonly DocumentNumberService $numbers,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Supplier::class);

        $term = trim((string) $request->query('q', ''));

        $suppliers = Supplier::query()
            ->when($term !== '', fn ($query) => $query->where(function ($q) use ($term): void {
                $q->where('name', 'ilike', "%{$term}%")->orWhere('code', 'ilike', "%{$term}%");
            }))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Supplier $supplier): array => [
                'id' => $supplier->getKey(),
                'code' => $supplier->code,
                'name' => $supplier->name,
                'email' => $supplier->email,
                'phone' => $supplier->phone,
                'currency' => $supplier->currency,
                'status' => $supplier->status,
                'payment_terms_days' => $supplier->payment_terms_days,
            ]);

        return Inertia::render('Purchasing/Suppliers/Index', [
            'suppliers' => $suppliers,
            'filters' => ['q' => $term],
        ]);
    }

    public function show(Supplier $supplier): Response
    {
        $this->authorize('view', $supplier);

        $supplier->loadMissing(['contacts', 'addresses']);

        return Inertia::render('Purchasing/Suppliers/Show', [
            'supplier' => $this->present($supplier),
            'contacts' => $supplier->contacts->map(fn ($c): array => [
                'id' => $c->getKey(), 'name' => $c->name, 'position' => $c->position,
                'email' => $c->email, 'phone' => $c->phone, 'is_primary' => $c->is_primary,
            ])->all(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Supplier::class);

        return Inertia::render('Purchasing/Suppliers/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Supplier::class);

        $data = $this->validated($request, null);

        $supplier = DB::transaction(function () use ($data, $request): Supplier {
            $supplier = Supplier::create([
                ...$data,
                'code' => $data['code'] ?? $this->numbers->next('supplier', 'SP'),
            ]);

            $this->recorder->record('created', 'suppliers', $supplier, $request->user());

            return $supplier;
        });

        return redirect("/suppliers/{$supplier->getKey()}")->with('success', "Supplier {$supplier->name} created.");
    }

    public function edit(Supplier $supplier): Response
    {
        $this->authorize('update', $supplier);

        return Inertia::render('Purchasing/Suppliers/Edit', ['supplier' => $this->present($supplier)]);
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $this->validated($request, $supplier);

        DB::transaction(function () use ($supplier, $data, $request): void {
            $supplier->update($data);
            $this->recorder->record('updated', 'suppliers', $supplier, $request->user());
        });

        return redirect("/suppliers/{$supplier->getKey()}")->with('success', 'Supplier updated.');
    }

    public function destroy(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('delete', $supplier);

        DB::transaction(function () use ($supplier, $request): void {
            $this->recorder->record('deleted', 'suppliers', $supplier, $request->user());
            $supplier->delete();
        });

        return redirect('/suppliers')->with('success', 'Supplier removed.');
    }

    /** @return array<string, mixed> */
    private function present(Supplier $supplier): array
    {
        return [
            'id' => $supplier->getKey(),
            'code' => $supplier->code,
            'name' => $supplier->name,
            'registration_no' => $supplier->registration_no,
            'tax_no' => $supplier->tax_no,
            'email' => $supplier->email,
            'phone' => $supplier->phone,
            'currency' => $supplier->currency,
            'credit_limit' => (string) $supplier->credit_limit,
            'payment_terms_days' => (string) $supplier->payment_terms_days,
            'status' => $supplier->status,
            'notes' => $supplier->notes,
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Supplier $supplier): array
    {
        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        return $request->validate([
            'code' => ['nullable', 'string', 'max:40', Rule::unique('suppliers', 'code')
                ->where('company_id', $companyId)->ignore($supplier?->getKey())],
            'name' => ['required', 'string', 'max:160'],
            'registration_no' => ['nullable', 'string', 'max:60'],
            'tax_no' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'currency' => ['required', 'string', 'size:3'],
            'credit_limit' => ['required', 'numeric', 'min:0'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'status' => ['required', Rule::in(['active', 'inactive', 'blocked'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
