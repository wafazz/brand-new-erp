<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasUlid;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use BelongsToCompany;
    use HasUlid;

    /** @var array<string, mixed> */
    protected $attributes = [
        'quantity_allocated' => '0',
        'quantity_picked' => '0',
        'quantity_shipped' => '0',
        'quantity_returned' => '0',
        'unit_cost' => '0',
        'discount_amount' => '0',
        'tax_amount' => '0',
    ];

    protected $fillable = [
        'order_id', 'product_variant_id', 'sku', 'product_name', 'variant_name', 'options',
        'quantity', 'unit_price', 'unit_cost', 'discount_amount', 'tax_amount', 'line_total',
        'price_basis', 'weight_grams',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'price_basis' => 'array',
            'quantity' => 'decimal:4',
            'quantity_allocated' => 'decimal:4',
            'quantity_picked' => 'decimal:4',
            'quantity_shipped' => 'decimal:4',
            'quantity_returned' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
            'weight_grams' => 'integer',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function marginAtSale(string $currency = Money::DEFAULT_CURRENCY): Money
    {
        $revenue = Money::of((string) $this->line_total, $currency);
        $cost = Money::of((string) $this->unit_cost, $currency)->times((string) $this->quantity);

        return $revenue->minus($cost);
    }
}
