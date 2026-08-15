<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sales;

use App\Domain\Numbering\DocumentNumberService;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Services\Audit\AuditRecorder;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function __construct(
        private readonly AuditRecorder $recorder,
        private readonly DocumentNumberService $numbers,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Customer::class);

        $term = trim((string) $request->query('q', ''));

        $customers = Customer::query()
            ->visibleTo($request->user(), 'customers.view')
            ->when($term !== '', fn ($query) => $query->where(function ($q) use ($term): void {
                $q->where('name', 'ilike', "%{$term}%")
                    ->orWhere('code', 'ilike', "%{$term}%")
                    ->orWhere('phone', 'ilike', "%{$term}%");
            }))
            ->with('group:id,name')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Customer $customer): array => [
                'id' => $customer->getKey(),
                'code' => $customer->code,
                'name' => $customer->name,
                'type' => $customer->type,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'group' => $customer->group?->name,
                'status' => $customer->status,
                'credit_limit' => (string) $customer->credit_limit,
            ]);

        return Inertia::render('Sales/Customers/Index', [
            'customers' => $customers,
            'filters' => ['q' => $term],
        ]);
    }

    public function show(Request $request, Customer $customer): Response
    {
        $this->authorize('view', $customer);

        $customer->loadMissing(['group:id,name', 'contacts', 'addresses', 'owner:id,name']);

        return Inertia::render('Sales/Customers/Show', [
            'customer' => [
                'id' => $customer->getKey(),
                'code' => $customer->code,
                'name' => $customer->name,
                'type' => $customer->type,
                'company_name' => $customer->company_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'tax_no' => $customer->tax_no,
                'status' => $customer->status,
                'currency' => $customer->currency,
                'credit_limit' => (string) $customer->credit_limit,
                'payment_terms_days' => $customer->payment_terms_days,
                'group' => $customer->group?->name,
                'owner' => $customer->owner?->name,
                'notes' => $customer->notes,
            ],
            'contacts' => $customer->contacts->map(fn ($c): array => [
                'id' => $c->getKey(), 'name' => $c->name, 'position' => $c->position,
                'email' => $c->email, 'phone' => $c->phone, 'is_primary' => $c->is_primary,
            ])->all(),
            'addresses' => $customer->addresses->map(fn ($a): array => [
                'id' => $a->getKey(), 'label' => $a->label, 'type' => $a->type,
                'line1' => $a->line1, 'city' => $a->city, 'state' => $a->state, 'postcode' => $a->postcode,
            ])->all(),
            'orderSummary' => $this->orderSummary($customer),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Customer::class);

        return Inertia::render('Sales/Customers/Create', ['groups' => $this->groups()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Customer::class);

        $data = $this->validated($request, null);

        $customer = DB::transaction(function () use ($data, $request): Customer {
            $customer = Customer::create([
                ...$data,
                'code' => $data['code'] ?? $this->numbers->next('customer', 'CU'),
                'owner_user_id' => $request->user()?->getKey(),
            ]);

            $this->recorder->record('created', 'customers', $customer, $request->user());

            return $customer;
        });

        return redirect("/customers/{$customer->getKey()}")->with('success', "Customer {$customer->name} created.");
    }

    public function edit(Customer $customer): Response
    {
        $this->authorize('update', $customer);

        return Inertia::render('Sales/Customers/Edit', [
            'customer' => [
                'id' => $customer->getKey(),
                'code' => $customer->code,
                'name' => $customer->name,
                'type' => $customer->type,
                'company_name' => $customer->company_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'tax_no' => $customer->tax_no,
                'status' => $customer->status,
                'credit_limit' => (string) $customer->credit_limit,
                'payment_terms_days' => (string) $customer->payment_terms_days,
                'customer_group_id' => $customer->customer_group_id,
                'notes' => $customer->notes,
            ],
            'groups' => $this->groups(),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $data = $this->validated($request, $customer);

        DB::transaction(function () use ($customer, $data, $request): void {
            $customer->update($data);
            $this->recorder->record('updated', 'customers', $customer, $request->user());
        });

        return redirect("/customers/{$customer->getKey()}")->with('success', 'Customer updated.');
    }

    public function destroy(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('delete', $customer);

        DB::transaction(function () use ($customer, $request): void {
            $this->recorder->record('deleted', 'customers', $customer, $request->user());
            $customer->delete();
        });

        return redirect('/customers')->with('success', 'Customer removed.');
    }

    /** @return array<int, array{value: string, label: string}> */
    private function groups(): array
    {
        return CustomerGroup::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (CustomerGroup $g): array => ['value' => $g->getKey(), 'label' => $g->name])
            ->all();
    }

    /** @return array<string, string> */
    private function orderSummary(Customer $customer): array
    {
        $row = DB::table('orders')
            ->where('company_id', app(CompanyContext::class)->idOrFail(self::class))
            ->where('customer_id', $customer->getKey())
            ->whereNull('deleted_at')
            ->selectRaw('count(*) as orders, coalesce(sum(total), 0) as revenue, coalesce(sum(total - paid_amount), 0) as outstanding')
            ->first();

        return [
            'orders' => (string) ($row->orders ?? 0),
            'revenue' => (string) ($row->revenue ?? '0'),
            'outstanding' => (string) ($row->outstanding ?? '0'),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Customer $customer): array
    {
        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        return $request->validate([
            'code' => ['nullable', 'string', 'max:40', Rule::unique('customers', 'code')
                ->where('company_id', $companyId)->ignore($customer?->getKey())],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(['individual', 'business'])],
            'company_name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'tax_no' => ['nullable', 'string', 'max:60'],
            'status' => ['required', Rule::in(['active', 'inactive', 'blocked'])],
            'credit_limit' => ['required', 'numeric', 'min:0'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'customer_group_id' => ['nullable', 'string', Rule::exists('customer_groups', 'id')->where('company_id', $companyId)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
