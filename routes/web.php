<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Catalogue\ProductController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Finance\CommissionController;
use App\Http\Controllers\Finance\CommissionPlanController;
use App\Http\Controllers\Finance\InvoiceController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Marketing\AttributionReportController;
use App\Http\Controllers\Marketing\CampaignController;
use App\Http\Controllers\Marketing\ChannelController;
use App\Http\Controllers\Marketing\MarketerController;
use App\Http\Controllers\Pos\TillController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Purchasing\ApprovalController;
use App\Http\Controllers\Purchasing\GoodsReceiptController;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Purchasing\PurchaseRequestController;
use App\Http\Controllers\Purchasing\SupplierBillController;
use App\Http\Controllers\Purchasing\SupplierController;
use App\Http\Controllers\Sales\CustomerController;
use App\Http\Controllers\Sales\LeadController;
use App\Http\Controllers\Sales\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:6,1');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::get('/', fn () => redirect('/dashboard'));

Route::middleware(['auth', 'company'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/admin/branches', [BranchController::class, 'index'])->name('branches.index');
    Route::post('/admin/branches', [BranchController::class, 'store'])->name('branches.store');
    Route::put('/admin/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
    Route::delete('/admin/branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');

    Route::get('/admin/audit', [AuditLogController::class, 'index'])->name('audit.index');

    Route::get('/admin/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/admin/users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('/admin/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/admin/users/{member}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('/admin/users/{member}', [UserController::class, 'update'])->name('users.update');

    Route::get('/admin/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::post('/admin/roles/{role}/scope', [RoleController::class, 'updateScope'])->name('roles.scope.update');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');

    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
    Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
    Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');

    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
    Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
    Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/transition', [OrderController::class, 'transition'])->name('orders.transition');
    Route::post('/orders/{order}/payments', [OrderController::class, 'recordPayment'])->name('orders.payments.store');
    Route::post('/orders/{order}/refunds', [OrderController::class, 'refund'])->name('orders.refunds.store');
    Route::post('/orders/{order}/invoice', [InvoiceController::class, 'issue'])->name('orders.invoice.store');

    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])->name('invoices.payments.store');
    Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');

    Route::get('/inventory', [StockController::class, 'index'])->name('inventory.index');
    Route::get('/inventory/create', [StockController::class, 'create'])->name('inventory.create');
    Route::post('/inventory', [StockController::class, 'store'])->name('inventory.store');
    Route::get('/inventory/{stock}', [StockController::class, 'show'])->name('inventory.show');
    Route::post('/inventory/{stock}/adjust', [StockController::class, 'adjust'])->name('inventory.adjust');

    Route::get('/commissions', [CommissionController::class, 'index'])->name('commissions.index');
    Route::get('/commissions/{commission}', [CommissionController::class, 'show'])->name('commissions.show');
    Route::post('/commissions/{commission}/transition', [CommissionController::class, 'transition'])->name('commissions.transition');

    Route::get('/commission-plans', [CommissionPlanController::class, 'index'])->name('commission_plans.index');
    Route::post('/commission-plans', [CommissionPlanController::class, 'store'])->name('commission_plans.store');
    Route::get('/commission-plans/{plan}', [CommissionPlanController::class, 'show'])->name('commission_plans.show');
    Route::put('/commission-plans/{plan}', [CommissionPlanController::class, 'update'])->name('commission_plans.update');
    Route::post('/commission-plans/{plan}/rules', [CommissionPlanController::class, 'storeRule'])->name('commission_plans.rules.store');
    Route::put('/commission-rules/{rule}', [CommissionPlanController::class, 'updateRule'])->name('commission_rules.update');
    Route::post('/commission-rules/{rule}/versions', [CommissionPlanController::class, 'storeVersion'])->name('commission_rules.versions.store');

    Route::get('/channels', [ChannelController::class, 'index'])->name('channels.index');
    Route::post('/channels', [ChannelController::class, 'store'])->name('channels.store');
    Route::put('/channels/{channel}', [ChannelController::class, 'update'])->name('channels.update');

    Route::get('/marketers', [MarketerController::class, 'index'])->name('marketers.index');
    Route::post('/marketers', [MarketerController::class, 'store'])->name('marketers.store');
    Route::put('/marketers/{marketer}', [MarketerController::class, 'update'])->name('marketers.update');

    Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
    Route::put('/campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
    Route::post('/campaigns/{campaign}/costs', [CampaignController::class, 'storeCost'])->name('campaigns.costs.store');

    Route::get('/attribution', [AttributionReportController::class, 'index'])->name('attribution.index');

    Route::get('/pos', [TillController::class, 'index'])->name('pos.index');
    Route::post('/pos/open', [TillController::class, 'open'])->name('pos.open');
    Route::get('/pos/{session}', [TillController::class, 'show'])->name('pos.show');
    Route::post('/pos/{session}/sell', [TillController::class, 'sell'])->name('pos.sell');
    Route::post('/pos/{session}/refund', [TillController::class, 'refund'])->name('pos.refund');
    Route::post('/pos/{session}/cash', [TillController::class, 'cash'])->name('pos.cash');
    Route::get('/pos/receipt/{order}', [TillController::class, 'receipt'])->name('pos.receipt');
    Route::post('/pos/{session}/close', [TillController::class, 'close'])->name('pos.close');

    Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
    Route::get('/leads/create', [LeadController::class, 'create'])->name('leads.create');
    Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');
    Route::get('/leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
    Route::get('/leads/{lead}/edit', [LeadController::class, 'edit'])->name('leads.edit');
    Route::put('/leads/{lead}', [LeadController::class, 'update'])->name('leads.update');

    Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::get('/suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create');
    Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
    Route::get('/suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit');
    Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
    Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');

    Route::get('/purchase-requests', [PurchaseRequestController::class, 'index'])->name('purchase_requests.index');
    Route::get('/purchase-requests/create', [PurchaseRequestController::class, 'create'])->name('purchase_requests.create');
    Route::post('/purchase-requests', [PurchaseRequestController::class, 'store'])->name('purchase_requests.store');
    Route::get('/purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'show'])->name('purchase_requests.show');
    Route::post('/purchase-requests/{purchaseRequest}/submit', [PurchaseRequestController::class, 'submit'])->name('purchase_requests.submit');
    Route::post('/purchase-requests/{purchaseRequest}/decide', [PurchaseRequestController::class, 'decide'])->name('purchase_requests.decide');

    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase_orders.index');
    Route::get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])->name('purchase_orders.create');
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase_orders.store');
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchase_orders.show');
    Route::post('/purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->name('purchase_orders.submit');
    Route::post('/purchase-orders/{purchaseOrder}/decide', [PurchaseOrderController::class, 'decide'])->name('purchase_orders.decide');
    Route::post('/purchase-orders/{purchaseOrder}/receipts', [GoodsReceiptController::class, 'store'])->name('purchase_orders.receipts.store');
    Route::get('/purchase-orders/{purchaseOrder}/bills/create', [SupplierBillController::class, 'create'])->name('purchase_orders.bills.create');
    Route::post('/purchase-orders/{purchaseOrder}/bills', [SupplierBillController::class, 'store'])->name('purchase_orders.bills.store');

    Route::get('/goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show'])->name('goods_receipts.show');
    Route::post('/goods-receipts/{goodsReceipt}/costs', [GoodsReceiptController::class, 'addCost'])->name('goods_receipts.costs.store');

    Route::get('/supplier-bills', [SupplierBillController::class, 'index'])->name('supplier_bills.index');
    Route::get('/supplier-bills/{supplierBill}', [SupplierBillController::class, 'show'])->name('supplier_bills.show');
    Route::post('/supplier-bills/{supplierBill}/approve', [SupplierBillController::class, 'approve'])->name('supplier_bills.approve');
    Route::post('/supplier-bills/{supplierBill}/payments', [SupplierBillController::class, 'pay'])->name('supplier_bills.payments.store');

    Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('/approvals/{approvalRequest}/decide', [ApprovalController::class, 'decide'])->name('approvals.decide');
});
