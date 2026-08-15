<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalogue;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Stock;
use App\Models\TaxRate;
use App\Models\UnitOfMeasure;
use App\Services\Audit\AuditRecorder;
use App\Support\CompanyContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Product::class);

        $term = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        $products = Product::query()
            ->when($term !== '', fn ($query) => $query->where(function ($q) use ($term): void {
                $q->where('name', 'ilike', "%{$term}%")->orWhere('sku', 'ilike', "%{$term}%");
            }))
            ->when(in_array($status, ['active', 'inactive', 'discontinued'], true), fn ($query) => $query->where('status', $status))
            ->with(['category:id,name', 'brand:id,name'])
            ->withCount('variants')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Product $product): array => [
                'id' => $product->getKey(),
                'sku' => $product->sku,
                'name' => $product->name,
                'type' => $product->type,
                'status' => $product->status,
                'category' => $product->category?->name,
                'brand' => $product->brand?->name,
                'variants_count' => (int) $product->getAttribute('variants_count'),
                'is_stock_tracked' => $product->is_stock_tracked,
            ]);

        return Inertia::render('Catalogue/Products/Index', [
            'products' => $products,
            'filters' => ['q' => $term, 'status' => $status],
        ]);
    }

    public function show(Product $product): Response
    {
        $this->authorize('view', $product);

        $product->loadMissing(['category:id,name', 'brand:id,name', 'unitOfMeasure:id,code,name', 'taxRate:id,name,rate_percent', 'variants']);

        $stockByVariant = $this->stockByVariant($product);

        return Inertia::render('Catalogue/Products/Show', [
            'product' => [
                'id' => $product->getKey(),
                'sku' => $product->sku,
                'name' => $product->name,
                'type' => $product->type,
                'status' => $product->status,
                'description' => $product->description,
                'category' => $product->category?->name,
                'brand' => $product->brand?->name,
                'unit' => $product->unitOfMeasure?->code,
                'tax_rate' => $product->taxRate === null ? null : $product->taxRate->name.' ('.$product->taxRate->rate_percent.'%)',
                'is_stock_tracked' => $product->is_stock_tracked,
                'has_variants' => $product->has_variants,
            ],
            'variants' => $product->variants->map(fn (ProductVariant $variant): array => [
                'id' => $variant->getKey(),
                'sku' => $variant->sku,
                'name' => $variant->name,
                'barcode' => $variant->barcode,
                'cost_price' => (string) $variant->cost_price,
                'average_cost' => (string) $variant->average_cost,
                'selling_price' => (string) $variant->selling_price,
                'is_default' => $variant->is_default,
                'is_active' => $variant->is_active,
                'on_hand' => $stockByVariant[$variant->getKey()]['on_hand'] ?? '0.0000',
                'reserved' => $stockByVariant[$variant->getKey()]['reserved'] ?? '0.0000',
            ])->all(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Product::class);

        return Inertia::render('Catalogue/Products/Create', $this->references());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $data = $this->validated($request, null);

        $product = DB::transaction(function () use ($data, $request): Product {
            $variants = $data['variants'];
            unset($data['variants']);

            $product = Product::create([...$data, 'has_variants' => count($variants) > 1]);

            $this->syncVariants($product, $variants);
            $this->recorder->record('created', 'products', $product, $request->user());

            return $product;
        });

        return redirect("/products/{$product->getKey()}")->with('success', "Product {$product->name} created.");
    }

    public function edit(Product $product): Response
    {
        $this->authorize('update', $product);

        $product->loadMissing('variants');

        return Inertia::render('Catalogue/Products/Edit', [
            ...$this->references(),
            'product' => [
                'id' => $product->getKey(),
                'sku' => $product->sku,
                'name' => $product->name,
                'type' => $product->type,
                'status' => $product->status,
                'description' => $product->description,
                'category_id' => $product->category_id,
                'brand_id' => $product->brand_id,
                'unit_of_measure_id' => $product->unit_of_measure_id,
                'tax_rate_id' => $product->tax_rate_id,
                'is_stock_tracked' => $product->is_stock_tracked,
                'variants' => $product->variants->map(fn (ProductVariant $variant): array => [
                    'id' => $variant->getKey(),
                    'sku' => $variant->sku,
                    'name' => $variant->name,
                    'barcode' => $variant->barcode,
                    'cost_price' => (string) $variant->cost_price,
                    'selling_price' => (string) $variant->selling_price,
                    'is_default' => $variant->is_default,
                    'is_active' => $variant->is_active,
                ])->all(),
            ],
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $data = $this->validated($request, $product);

        DB::transaction(function () use ($product, $data, $request): void {
            $variants = $data['variants'];
            unset($data['variants']);

            $product->update([...$data, 'has_variants' => count($variants) > 1]);

            $this->syncVariants($product, $variants);
            $this->recorder->record('updated', 'products', $product, $request->user());
        });

        return redirect("/products/{$product->getKey()}")->with('success', 'Product updated.');
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);

        DB::transaction(function () use ($product, $request): void {
            $this->recorder->record('deleted', 'products', $product, $request->user());
            $product->delete();
        });

        return redirect('/products')->with('success', 'Product removed.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $variants
     */
    private function syncVariants(Product $product, array $variants): void
    {
        $kept = [];
        $hasDefault = false;

        foreach ($variants as $index => $row) {
            $isDefault = ! $hasDefault && ((bool) ($row['is_default'] ?? false) || $index === array_key_first($variants));
            $hasDefault = $hasDefault || $isDefault;

            $payload = [
                'sku' => $row['sku'],
                'name' => $row['name'],
                'barcode' => $row['barcode'] ?? null,
                'cost_price' => $row['cost_price'],
                'selling_price' => $row['selling_price'],
                'is_default' => $isDefault,
                'is_active' => (bool) ($row['is_active'] ?? true),
            ];

            $variant = isset($row['id'])
                ? ProductVariant::query()->where('product_id', $product->getKey())->whereKey($row['id'])->firstOrFail()
                : new ProductVariant(['product_id' => $product->getKey()]);

            $variant->fill([...$payload, 'product_id' => $product->getKey()])->save();

            $kept[] = $variant->getKey();
        }

        ProductVariant::query()
            ->where('product_id', $product->getKey())
            ->whereNotIn('id', $kept)
            ->each(fn (ProductVariant $variant) => $variant->forceFill(['is_active' => false])->save());
    }

    /** @return array<string, array<string, string>> */
    private function stockByVariant(Product $product): array
    {
        $rows = DB::table((new Stock)->getTable())
            ->where('company_id', app(CompanyContext::class)->idOrFail(self::class))
            ->whereIn('product_variant_id', $product->variants->modelKeys())
            ->groupBy('product_variant_id')
            ->selectRaw('product_variant_id, sum(on_hand) as on_hand, sum(reserved) as reserved')
            ->get();

        $indexed = [];

        foreach ($rows as $row) {
            $indexed[(string) $row->product_variant_id] = [
                'on_hand' => (string) $row->on_hand,
                'reserved' => (string) $row->reserved,
            ];
        }

        return $indexed;
    }

    /** @return array<string, array<int, array{value: string, label: string}>> */
    private function references(): array
    {
        return [
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Category $c): array => ['value' => $c->getKey(), 'label' => $c->name])->all(),
            'brands' => Brand::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Brand $b): array => ['value' => $b->getKey(), 'label' => $b->name])->all(),
            'units' => UnitOfMeasure::query()->orderBy('code')->get()
                ->map(fn (UnitOfMeasure $u): array => ['value' => $u->getKey(), 'label' => $u->code.' — '.$u->name])->all(),
            'taxRates' => TaxRate::query()->where('is_active', true)->orderBy('name')->get()
                ->map(fn (TaxRate $t): array => ['value' => $t->getKey(), 'label' => $t->name.' ('.$t->rate_percent.'%)'])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Product $product): array
    {
        $companyId = app(CompanyContext::class)->idOrFail(self::class);

        $data = $request->validate([
            'sku' => ['required', 'string', 'max:60', Rule::unique('products', 'sku')
                ->where('company_id', $companyId)->ignore($product?->getKey())],
            'name' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::in(['product', 'service', 'bundle'])],
            'status' => ['required', Rule::in(['active', 'inactive', 'discontinued'])],
            'description' => ['nullable', 'string', 'max:4000'],
            'category_id' => ['nullable', 'string', Rule::exists('categories', 'id')->where('company_id', $companyId)],
            'brand_id' => ['nullable', 'string', Rule::exists('brands', 'id')->where('company_id', $companyId)],
            'unit_of_measure_id' => ['nullable', 'string', Rule::exists('unit_of_measures', 'id')->where('company_id', $companyId)],
            'tax_rate_id' => ['nullable', 'string', Rule::exists('tax_rates', 'id')->where('company_id', $companyId)],
            'is_stock_tracked' => ['required', 'boolean'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.id' => ['nullable', 'string', Rule::exists('product_variants', 'id')->where('company_id', $companyId)],
            'variants.*.sku' => ['required', 'string', 'max:60'],
            'variants.*.name' => ['required', 'string', 'max:200'],
            'variants.*.barcode' => ['nullable', 'string', 'max:60'],
            'variants.*.cost_price' => ['required', 'numeric', 'min:0'],
            'variants.*.selling_price' => ['required', 'numeric', 'min:0'],
            'variants.*.is_default' => ['nullable', 'boolean'],
            'variants.*.is_active' => ['nullable', 'boolean'],
        ]);

        $skus = array_column($data['variants'], 'sku');

        if (count($skus) !== count(array_unique($skus))) {
            abort(422, 'Two variants cannot share a SKU.');
        }

        return $data;
    }
}
