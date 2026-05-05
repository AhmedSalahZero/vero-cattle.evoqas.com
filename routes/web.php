<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserTaskController;
use App\Http\Controllers\FarmController;
use App\Http\Controllers\LotController;
use App\Http\Controllers\AnimalController;
use App\Http\Controllers\WeightRecordController;
use App\Http\Controllers\ProcurementController;
use App\Http\Controllers\HealthRecordController;
use App\Http\Controllers\VaccinationController;
use App\Http\Controllers\BreedingController;
use App\Http\Controllers\CalvingController;
use App\Http\Controllers\FeedItemController;
use App\Http\Controllers\RationController;
use App\Http\Controllers\FeedConsumptionController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryLocationController;
use App\Http\Controllers\SlaughterController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\FixedAssetController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\FiscalYearController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\ApInvoiceController;
use App\Http\Controllers\ArInvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\BankStatementController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\OpeningBalanceController;
use App\Http\Controllers\FxRateController;
use App\Http\Controllers\AnalyticsReportController;
use App\Http\Controllers\FarmCostAllocationController;

// ══════════════════════════════════════════════════════
// PUBLIC — Welcome / Login redirect
// ══════════════════════════════════════════════════════
Route::get('/', function () {
    if (auth()->check()) {
        $user = auth()->user();
        if ($user->is_super_admin) {
            return redirect()->route('companies.index');
        }
        return redirect()->route('dashboard');
    }
    return redirect()->route('login');
});

// ══════════════════════════════════════════════════════
// AUTHENTICATED ROUTES
// ══════════════════════════════════════════════════════
Route::middleware(['auth', \App\Http\Middleware\CheckSubscriptionActive::class])->group(function () {

    // ── Dashboard ──────────────────────────────────────
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // ── Profile ────────────────────────────────────────
    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/',    [ProfileController::class, 'edit'])->name('edit');
        Route::patch('/',  [ProfileController::class, 'update'])->name('update');
        Route::delete('/', [ProfileController::class, 'destroy'])->name('destroy');
    });

    // ── Theme Toggle ───────────────────────────────────
    // FIX: was response()->json() — Inertia requires a redirect, not JSON.
    Route::post('/theme', function () {
        $theme = request('theme', 'dark');
        abort_unless(in_array($theme, ['light', 'dark']), 422);
        auth()->user()->update(['theme' => $theme]);
        return back();
    })->name('theme.toggle');

    // ── Language Toggle ────────────────────────────────
    // FIX: was response()->json() — caused "plain JSON response" Inertia error.
    // back() redirects to the same page; Inertia re-fetches it as a proper
    // Inertia response, picking up the new language from HandleInertiaRequests.
    Route::post('/language', function () {
        $language = request('language', 'en');
        abort_unless(in_array($language, ['en', 'ar']), 422);
        auth()->user()->update(['language' => $language]);
        return back();
    })->name('language.toggle');

    // ══════════════════════════════════════════════════════
    // SUPER ADMIN — Company Management
    // ══════════════════════════════════════════════════════
    Route::prefix('companies')->name('companies.')->group(function () {
        Route::get('/',                            [CompanyController::class, 'index'])->name('index');
        Route::get('/create',                      [CompanyController::class, 'create'])->name('create');
        Route::post('/',                           [CompanyController::class, 'store'])->name('store');
        Route::get('/{company}',                   [CompanyController::class, 'show'])->name('show');
        Route::get('/{company}/edit',              [CompanyController::class, 'edit'])->name('edit');
        Route::put('/{company}',                   [CompanyController::class, 'update'])->name('update');
        Route::delete('/{company}',                [CompanyController::class, 'destroy'])->name('destroy');
        Route::patch('/{company}/toggle-active',   [CompanyController::class, 'toggleActive'])->name('toggle-active');
        Route::patch('/{company}/accounting-mode', [CompanyController::class, 'updateAccountingMode'])->name('accounting-mode');
    });

    // ══════════════════════════════════════════════════════
    // USER MANAGEMENT
    // ══════════════════════════════════════════════════════
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/',                          [UserController::class, 'index'])->name('index');
        Route::get('/create',                    [UserController::class, 'create'])->name('create');
        Route::post('/',                         [UserController::class, 'store'])->name('store');
        Route::get('/{user}/edit',               [UserController::class, 'edit'])->name('edit');
        Route::put('/{user}',                    [UserController::class, 'update'])->name('update');
        Route::delete('/{user}',                 [UserController::class, 'destroy'])->name('destroy');
        Route::patch('/{user}/toggle-active',    [UserController::class, 'toggleActive'])->name('toggle-active');
    });

    // ══════════════════════════════════════════════════════
    // USER TASKS (personal to-do list)
    // ══════════════════════════════════════════════════════
    Route::prefix('tasks')->name('tasks.')->group(function () {
        Route::get('/badge-count',               [UserTaskController::class, 'badgeCount'])->name('badge');
        Route::get('/',                          [UserTaskController::class, 'index'])->name('index');
        Route::post('/',                         [UserTaskController::class, 'store'])->name('store');
        Route::put('/{task}',                    [UserTaskController::class, 'update'])->name('update');
        Route::patch('/{task}/status',           [UserTaskController::class, 'updateStatus'])->name('status');
        Route::delete('/{task}',                 [UserTaskController::class, 'destroy'])->name('destroy');
    });

    // ══════════════════════════════════════════════════════
    // FARM MANAGEMENT
    // ══════════════════════════════════════════════════════
    Route::prefix('farms')->name('farms.')->group(function () {
        Route::get('/',                          [FarmController::class, 'index'])->name('index');
        Route::get('/create',                    [FarmController::class, 'create'])->name('create');
        Route::post('/',                         [FarmController::class, 'store'])->name('store');
        Route::get('/{farm}',                    [FarmController::class, 'show'])->name('show');
        Route::get('/{farm}/edit',               [FarmController::class, 'edit'])->name('edit');
        Route::put('/{farm}',                    [FarmController::class, 'update'])->name('update');
        Route::delete('/{farm}',                 [FarmController::class, 'destroy'])->name('destroy');
        Route::patch('/{farm}/toggle-active',    [FarmController::class, 'toggleActive'])->name('toggle-active');
    });

    // ══════════════════════════════════════════════════════
    // LOT MANAGEMENT
    // ══════════════════════════════════════════════════════
    Route::prefix('lots')->name('lots.')->group(function () {
        Route::get('/',                          [LotController::class, 'index'])->name('index');
        Route::get('/create',                    [LotController::class, 'create'])->name('create');
        Route::post('/',                         [LotController::class, 'store'])->name('store');
        Route::get('/{lot}',                     [LotController::class, 'show'])->name('show');
        Route::get('/{lot}/edit',                [LotController::class, 'edit'])->name('edit');
        Route::put('/{lot}',                     [LotController::class, 'update'])->name('update');
        Route::delete('/{lot}',                  [LotController::class, 'destroy'])->name('destroy');
        Route::patch('/{lot}/close',             [LotController::class, 'close'])->name('close');
    });

    // ══════════════════════════════════════════════════════
    // ANIMAL MANAGEMENT
    // ══════════════════════════════════════════════════════
    Route::prefix('animals')->name('animals.')->group(function () {
        Route::get('/',                          [AnimalController::class, 'index'])->name('index');
        Route::get('/create',                    [AnimalController::class, 'create'])->name('create');
        Route::post('/',                         [AnimalController::class, 'store'])->name('store');
        Route::post('/bulk',                     [AnimalController::class, 'storeBulk'])->name('storeBulk');  // Mode A & B bulk entry
        Route::get('/{animal}',                  [AnimalController::class, 'show'])->name('show');
        Route::get('/{animal}/edit',             [AnimalController::class, 'edit'])->name('edit');
        Route::match(['PUT', 'POST'], '/{animal}', [AnimalController::class, 'update'])->name('update');
        Route::delete('/{animal}',               [AnimalController::class, 'destroy'])->name('destroy');
        Route::patch('/{animal}/transfer',       [AnimalController::class, 'transfer'])->name('transfer');
    });

    // ══════════════════════════════════════════════════════
    // WEIGHT RECORDS
    // ══════════════════════════════════════════════════════
    Route::prefix('weights')->name('weights.')->group(function () {
        Route::get('/',                          [WeightRecordController::class, 'index'])->name('index');
        Route::post('/',                         [WeightRecordController::class, 'store'])->name('store');
        // FIX 1: batch entry — Vue batch panel POSTs to route('weights.batch')
        Route::post('/batch',                    [WeightRecordController::class, 'batchStore'])->name('batch');
        // FIX 2: JSON endpoint for Vue panels — fetch animals for a lot
        // Registered as a static path BEFORE /{weightRecord} wildcard to avoid collision
        Route::get('/lot-animals/{lot}',         [WeightRecordController::class, 'lotAnimals'])->name('lot-animals');
        Route::patch('/{weightRecord}',          [WeightRecordController::class, 'update'])->name('update');
        Route::delete('/{weightRecord}',         [WeightRecordController::class, 'destroy'])->name('destroy');
    });

   
    // ══════════════════════════════════════════════════════════════════
//  ADD THESE ROUTES to the existing procurement resource group
//  inside Route::middleware(['auth', 'verified']) in web.php
//
//  📌 METHODOLOGY REFERENCE: LivestockPurchaseOrderLifecycle (#40)
//
//  Replace the existing procurement routes block with this full block.
//  The existing routes (index, create, store, show, edit, update,
//  destroy, confirm) are preserved — new routes added below them.
// ══════════════════════════════════════════════════════════════════
 
        // ── Procurement (Purchase Orders) ────────────────────────────────
        Route::prefix('procurement')->name('procurement.')->group(function () {
        
            // Standard CRUD
            Route::get('/',             [ProcurementController::class, 'index'])   ->name('index');
            Route::get('/create',       [ProcurementController::class, 'create'])  ->name('create');
            Route::post('/',            [ProcurementController::class, 'store'])   ->name('store');
            Route::get('/{procurementRecord}',       [ProcurementController::class, 'show'])   ->name('show');
            Route::get('/{procurementRecord}/edit',  [ProcurementController::class, 'edit'])   ->name('edit');
            Route::put('/{procurementRecord}',       [ProcurementController::class, 'update']) ->name('update');
            Route::delete('/{procurementRecord}',    [ProcurementController::class, 'destroy'])->name('destroy');
        
            // PO lifecycle transitions
            // confirmPo: draft → open (replaces old confirm route)
            Route::patch('/{procurementRecord}/confirm-po',     [ProcurementController::class, 'confirmPo'])
                ->name('confirm-po');
        
            // force-close: closes PO short with mandatory reason
            Route::patch('/{procurementRecord}/force-close',    [ProcurementController::class, 'forceClose'])
                ->name('force-close');
        
            // receive: renders Animals/CreateEdit.vue with PO context
            // Hit by the [+ Receive Animals] button on Index.vue
            Route::get('/{procurementRecord}/receive',          [ProcurementController::class, 'receive'])
                ->name('receive');
        
            // confirm-receipt: animals form submitted (from purchase-mode Animals/CreateEdit.vue)
            Route::post('/{procurementRecord}/confirm-receipt', [ProcurementController::class, 'confirmReceipt'])
                ->name('confirm-receipt');

            // ── Inventory PO routes ──────────────────────────────────
            // 📌 METHODOLOGY REFERENCE: InventoryPurchaseOrderLifecycle (#45)

            // store-inventory-po: create new inventory items PO
            Route::post('/inventory', [ProcurementController::class, 'storeInventoryPo'])
                ->name('store-inventory-po');

            // edit-inventory-po: render CreateEdit.vue in inventory edit mode
            Route::get('/{procurementRecord}/edit-inventory', [ProcurementController::class, 'editInventoryPo'])
                ->name('edit-inventory-po');

            // update-inventory-po: save edits to draft inventory PO
            Route::put('/{procurementRecord}/inventory', [ProcurementController::class, 'updateInventoryPo'])
                ->name('update-inventory-po');

            // confirm-inventory-po: draft → open for inventory PO
            Route::patch('/{procurementRecord}/confirm-inventory-po', [ProcurementController::class, 'confirmInventoryPo'])
                ->name('confirm-inventory-po');

            // receive-inventory: renders Procurement/InventoryReceive.vue
            Route::get('/{procurementRecord}/receive-inventory', [ProcurementController::class, 'receiveInventory'])
                ->name('receive-inventory');

            // confirm-inventory-receipt: InventoryReceive.vue form submitted
            Route::post('/{procurementRecord}/confirm-inventory-receipt', [ProcurementController::class, 'confirmInventoryReceipt'])
                ->name('confirm-inventory-receipt');

            // force-close-inventory: closes inventory PO short with mandatory reason
            Route::patch('/{procurementRecord}/force-close-inventory', [ProcurementController::class, 'forceCloseInventory'])
                ->name('force-close-inventory');
        });

    // ══════════════════════════════════════════════════════
    // HEALTH RECORDS
    // ══════════════════════════════════════════════════════
    Route::prefix('health')->name('health.')->group(function () {
        Route::get('/',                          [HealthRecordController::class, 'index'])->name('index');
        Route::get('/create',                    [HealthRecordController::class, 'create'])->name('create');
        Route::post('/',                         [HealthRecordController::class, 'store'])->name('store');
        // NOTE: no show() route — health records use CreateEdit pattern (§9.1)
        Route::get('/{healthRecord}/edit',       [HealthRecordController::class, 'edit'])->name('edit');
        Route::put('/{healthRecord}',            [HealthRecordController::class, 'update'])->name('update');
        Route::delete('/{healthRecord}',         [HealthRecordController::class, 'destroy'])->name('destroy');
    });

    // ══════════════════════════════════════════════════════
    // VACCINATIONS
    // ══════════════════════════════════════════════════════
    Route::prefix('vaccinations')->name('vaccinations.')->group(function () {
        Route::get('/vaccines',                      [VaccinationController::class, 'vaccines'])->name('vaccines');       // static before wildcard
        Route::get('/',                              [VaccinationController::class, 'index'])->name('index');
        Route::post('/',                             [VaccinationController::class, 'store'])->name('store');              // single animal
        Route::post('/batch',                        [VaccinationController::class, 'batchStore'])->name('batch');         // bulk (SESSION 14)
        Route::put('/{vaccinationRecord}',           [VaccinationController::class, 'update'])->name('update');
        Route::delete('/{vaccinationRecord}',        [VaccinationController::class, 'destroy'])->name('destroy');
    }); // FIX: closing brace was at column 0 — corrected to 4-space indent

    // ══════════════════════════════════════════════════════
    // BREEDING
    // ══════════════════════════════════════════════════════
    Route::prefix('breeding')->name('breeding.')->group(function () {
        Route::get('/',                          [BreedingController::class, 'index'])->name('index');
        Route::post('/',                         [BreedingController::class, 'store'])->name('store');
        Route::put('/{breedingRecord}',          [BreedingController::class, 'update'])->name('update');
        Route::delete('/{breedingRecord}',       [BreedingController::class, 'destroy'])->name('destroy');
    });

    // ══════════════════════════════════════════════════════
    // CALVINGS
    // ══════════════════════════════════════════════════════
    Route::prefix('calvings')->name('calvings.')->group(function () {
        Route::get('/',                          [CalvingController::class, 'index'])->name('index');
        Route::post('/',                         [CalvingController::class, 'store'])->name('store');
        Route::put('/{calvingRecord}',           [CalvingController::class, 'update'])->name('update');
        Route::delete('/{calvingRecord}',        [CalvingController::class, 'destroy'])->name('destroy');
    });

    // ══════════════════════════════════════════════════════
    // FEED — ITEMS CATALOG
    // ══════════════════════════════════════════════════════
    Route::prefix('feed/items')->name('feed.items.')->group(function () {
        Route::get('/',                          [FeedItemController::class, 'index'])->name('index');
        Route::post('/',                         [FeedItemController::class, 'store'])->name('store');
        Route::put('/{feedItem}',                [FeedItemController::class, 'update'])->name('update');
        Route::delete('/{feedItem}',             [FeedItemController::class, 'destroy'])->name('destroy');
    });

    // ══════════════════════════════════════════════════════
    // FEED — RATIONS
    // ══════════════════════════════════════════════════════
    Route::prefix('feed/rations')->name('feed.rations.')->group(function () {
        Route::get('/',                          [RationController::class, 'index'])->name('index');
        Route::post('/',                         [RationController::class, 'store'])->name('store');
        Route::put('/{ration}',                  [RationController::class, 'update'])->name('update');
        Route::delete('/{ration}',               [RationController::class, 'destroy'])->name('destroy');
    });

    // ══════════════════════════════════════════════════════
    // FEED — CONSUMPTION
    // ══════════════════════════════════════════════════════
    Route::prefix('feed/consumption')->name('feed.consumption.')->group(function () {
        Route::get('/',                      [FeedConsumptionController::class, 'index'])->name('index');
        Route::post('/',                     [FeedConsumptionController::class, 'store'])->name('store');
        Route::post('/batch',                [FeedConsumptionController::class, 'batchStore'])->name('batch');
        Route::put('/{feedConsumption}',     [FeedConsumptionController::class, 'update'])->name('update');
        Route::delete('/{feedConsumption}',  [FeedConsumptionController::class, 'destroy'])->name('destroy');
    });

    // ══════════════════════════════════════════════════════════════
    // INVENTORY LOCATIONS (must be registered BEFORE inventory items
    // to avoid the /inventory/{item} wildcard swallowing /locations/*)
    // ══════════════════════════════════════════════════════════════
    Route::prefix('inventory/locations')->name('inventory.locations.')->group(function () {
        Route::get('/',                                    [InventoryLocationController::class, 'index'])->name('index');
        Route::get('/create',                              [InventoryLocationController::class, 'create'])->name('create');
        Route::post('/',                                   [InventoryLocationController::class, 'store'])->name('store');
        Route::get('/{inventoryLocation}/edit',            [InventoryLocationController::class, 'edit'])->name('edit');
        Route::patch('/{inventoryLocation}',               [InventoryLocationController::class, 'update'])->name('update');
        Route::delete('/{inventoryLocation}',              [InventoryLocationController::class, 'destroy'])->name('destroy');
        Route::patch('/{inventoryLocation}/toggle-active', [InventoryLocationController::class, 'toggleActive'])->name('toggle-active');
    });

    // ══════════════════════════════════════════════════════════════
    // INVENTORY ITEMS
    // Static routes (/create, /record-transaction, /record-transfer)
    // MUST appear before wildcard /{inventoryItem} routes.
    // ══════════════════════════════════════════════════════════════
    Route::prefix('inventory')->name('inventory.')->group(function () {
        // ── Static routes first (no wildcards) ──────────────────────
        Route::get('/',                    [InventoryController::class, 'index'])->name('index');
        Route::get('/create',              [InventoryController::class, 'create'])->name('create');
        Route::post('/',                   [InventoryController::class, 'store'])->name('store');
        Route::post('/record-transaction', [InventoryController::class, 'recordTransaction'])->name('record-transaction');
        Route::post('/record-transfer',    [InventoryController::class, 'recordTransfer'])->name('record-transfer');
        Route::patch('/transactions/{inventoryTransaction}/supplier', [InventoryController::class, 'patchSupplier'])->name('patch-supplier');

        // ── Wildcard routes after ────────────────────────────────────
        Route::get('/{inventoryItem}/edit',            [InventoryController::class, 'edit'])->name('edit');
        Route::put('/{inventoryItem}',                 [InventoryController::class, 'update'])->name('update');
        Route::patch('/{inventoryItem}/toggle-active', [InventoryController::class, 'toggleActive'])->name('toggle-active');
        Route::delete('/{inventoryItem}',              [InventoryController::class, 'destroy'])->name('destroy');
        Route::get('/{inventoryItem}/transactions',    [InventoryController::class, 'transactions'])->name('transactions');
    });

    // ══════════════════════════════════════════════════════════════
    // SLAUGHTER
    // ══════════════════════════════════════════════════════════════
    Route::prefix('slaughter')->name('slaughter.')->group(function () {
        Route::get('/',                          [SlaughterController::class, 'index'])->name('index');
        Route::post('/',                         [SlaughterController::class, 'store'])->name('store');
        Route::get('/animals',                   [SlaughterController::class, 'getAnimals'])->name('animals');  // static before wildcard
        Route::get('/{slaughterRecord}',         [SlaughterController::class, 'show'])->name('show');
        Route::put('/{slaughterRecord}',         [SlaughterController::class, 'update'])->name('update');
        Route::delete('/{slaughterRecord}',      [SlaughterController::class, 'destroy'])->name('destroy');
    });

    // ══════════════════════════════════════════════════════════════
    // SALES
    // FIX: was indented 8 spaces (visually nested inside slaughter)
    // ══════════════════════════════════════════════════════════════
    Route::prefix('sales')->name('sales.')->group(function () {
        Route::get('/',                        [SalesController::class, 'index'])->name('index');
        Route::get('/create',                  [SalesController::class, 'create'])->name('create');
        Route::post('/',                       [SalesController::class, 'store'])->name('store');
        Route::get('/animals',                 [SalesController::class, 'getAnimals'])->name('animals');  // static before wildcard
        Route::get('/{salesRecord}',           [SalesController::class, 'show'])->name('show');
        Route::get('/{salesRecord}/edit',      [SalesController::class, 'edit'])->name('edit');
        Route::put('/{salesRecord}',           [SalesController::class, 'update'])->name('update');
        Route::delete('/{salesRecord}',        [SalesController::class, 'destroy'])->name('destroy');
        Route::patch('/{salesRecord}/confirm', [SalesController::class, 'confirm'])->name('confirm');
        Route::post('/{salesRecord}/cancel',   [SalesController::class, 'cancel'])->name('cancel');
    });

    // ══════════════════════════════════════════════════════
    // SUPPLIERS
    // ══════════════════════════════════════════════════════
    Route::prefix('suppliers')->name('suppliers.')->group(function () {
        Route::get('/',                          [SupplierController::class, 'index'])->name('index');
        Route::get('/create',                    [SupplierController::class, 'create'])->name('create');
        Route::post('/',                         [SupplierController::class, 'store'])->name('store');
        Route::get('/{supplier}/edit',           [SupplierController::class, 'edit'])->name('edit');
        Route::patch('/{supplier}',              [SupplierController::class, 'update'])->name('update');
        Route::delete('/{supplier}',             [SupplierController::class, 'destroy'])->name('destroy');
        Route::patch('/{supplier}/toggle-active',[SupplierController::class, 'toggleActive'])->name('toggle-active');
    });

    // ══════════════════════════════════════════════════════
    // CUSTOMERS
    // ══════════════════════════════════════════════════════
    Route::prefix('customers')->name('customers.')->group(function () {
        Route::get('/',                           [CustomerController::class, 'index'])->name('index');
        Route::get('/create',                     [CustomerController::class, 'create'])->name('create');
        Route::post('/',                          [CustomerController::class, 'store'])->name('store');
        Route::get('/{customer}/edit',            [CustomerController::class, 'edit'])->name('edit');
        Route::patch('/{customer}',               [CustomerController::class, 'update'])->name('update');
        Route::delete('/{customer}',              [CustomerController::class, 'destroy'])->name('destroy');
        Route::patch('/{customer}/toggle-active', [CustomerController::class, 'toggleActive'])->name('toggle-active');
    });

    // ══════════════════════════════════════════════════════
    // FIXED ASSETS
    // ══════════════════════════════════════════════════════
    Route::prefix('fixed-assets')->name('fixed-assets.')->group(function () {
        Route::get('/',                                    [FixedAssetController::class, 'index'])->name('index');
        Route::get('/create',                              [FixedAssetController::class, 'create'])->name('create');
        Route::post('/',                                   [FixedAssetController::class, 'store'])->name('store');
        Route::get('/{fixedAsset}',                        [FixedAssetController::class, 'show'])->name('show');
        Route::get('/{fixedAsset}/edit',                   [FixedAssetController::class, 'edit'])->name('edit');
        Route::put('/{fixedAsset}',                        [FixedAssetController::class, 'update'])->name('update');
        Route::delete('/{fixedAsset}',                     [FixedAssetController::class, 'destroy'])->name('destroy');
        Route::post('/{fixedAsset}/schedule',              [FixedAssetController::class, 'generateSchedule'])->name('schedule');
        Route::post('/{fixedAsset}/post-period',           [FixedAssetController::class, 'postPeriod'])->name('post-period');
        Route::post('/{fixedAsset}/dispose',               [FixedAssetController::class, 'dispose'])->name('dispose');
    });

    // ══════════════════════════════════════════════════════════════════
    // PHASE 3 — ACCOUNTING ENGINE
    // ══════════════════════════════════════════════════════════════════

    // ── Chart of Accounts ──────────────────────────────────────────
    Route::prefix('accounts')->name('accounts.')->group(function () {
        Route::get('/',                          [AccountController::class, 'index'])->name('index');
        Route::get('/create',                    [AccountController::class, 'create'])->name('create');
        Route::post('/',                         [AccountController::class, 'store'])->name('store');
        Route::get('/{account}/edit',            [AccountController::class, 'edit'])->name('edit');
        Route::put('/{account}',                 [AccountController::class, 'update'])->name('update');
        Route::delete('/{account}',              [AccountController::class, 'destroy'])->name('destroy');
        Route::patch('/{account}/toggle-active', [AccountController::class, 'toggleActive'])->name('toggle-active');
    });

    // ── Fiscal Years ───────────────────────────────────────────────
    Route::prefix('fiscal-years')->name('fiscal-years.')->group(function () {
        Route::get('/',                          [FiscalYearController::class, 'index'])->name('index');
        Route::post('/',                         [FiscalYearController::class, 'store'])->name('store');
        Route::delete('/{fiscalYear}',           [FiscalYearController::class, 'destroy'])->name('destroy');
        Route::patch('/{fiscalYear}/close',      [FiscalYearController::class, 'close'])->name('close');
        Route::patch('/{fiscalYear}/reopen',     [FiscalYearController::class, 'reopen'])->name('reopen');
        Route::patch('/{fiscalYear}/lock',       [FiscalYearController::class, 'lock'])->name('lock');
    });

    // ── Journal Entries ────────────────────────────────────────────
    // IMPORTANT: data-for-company must be BEFORE /{journalEntry} wildcard
    Route::prefix('journal-entries')->name('journal-entries.')->group(function () {
        Route::get('/data-for-company',              [JournalEntryController::class, 'dataForCompany'])->name('data-for-company');
        Route::get('/',                              [JournalEntryController::class, 'index'])->name('index');
        Route::get('/create',                        [JournalEntryController::class, 'create'])->name('create');
        Route::post('/',                             [JournalEntryController::class, 'store'])->name('store');
        Route::get('/{journalEntry}',                [JournalEntryController::class, 'show'])->name('show');
        Route::get('/{journalEntry}/edit',           [JournalEntryController::class, 'edit'])->name('edit');
        Route::put('/{journalEntry}',                [JournalEntryController::class, 'update'])->name('update');
        Route::delete('/{journalEntry}',             [JournalEntryController::class, 'destroy'])->name('destroy');
        Route::patch('/{journalEntry}/post',         [JournalEntryController::class, 'post'])->name('post');
        Route::post('/{journalEntry}/reverse',       [JournalEntryController::class, 'reverse'])->name('reverse');
    });

    // ── Opening Balances ───────────────────────────────────────────
    // One entry_type='opening_balance' journal entry per FY per company.
    // Draft entries are editable; posted entries are read-only.
    // IMPORTANT: Add to RouteServiceProvider::boot() so Laravel resolves
    // {openingBalance} → JournalEntry model:
    //   Route::model('openingBalance', \App\Models\JournalEntry::class);
    Route::prefix('opening-balances')->name('opening-balances.')->group(function () {
        Route::get('/',                              [OpeningBalanceController::class, 'index'])->name('index');
        Route::get('/create',                        [OpeningBalanceController::class, 'create'])->name('create');
        Route::post('/',                             [OpeningBalanceController::class, 'store'])->name('store');
        Route::get('/{openingBalance}/edit',         [OpeningBalanceController::class, 'edit'])->name('edit');
        Route::put('/{openingBalance}',              [OpeningBalanceController::class, 'update'])->name('update');
        Route::post('/{openingBalance}/post',        [OpeningBalanceController::class, 'post'])->name('post');
    });

    // ── AP Invoices (Payables) ─────────────────────────────────────
    // IMPORTANT: data-for-company must be BEFORE the /{apInvoice} wildcard
    Route::prefix('ap-invoices')->name('ap-invoices.')->group(function () {
        Route::get('/data-for-company',                                     [ApInvoiceController::class, 'dataForCompany'])->name('data-for-company');
        Route::get('/',                                                     [ApInvoiceController::class, 'index'])->name('index');
        Route::get('/create',                                               [ApInvoiceController::class, 'create'])->name('create');
        Route::post('/',                                                    [ApInvoiceController::class, 'store'])->name('store');
        // ── Accrual matching — MUST be before /{apInvoice} wildcard ──
        Route::get('/{apInvoice}/pending-accruals',                         [ApInvoiceController::class, 'pendingAccrualsEndpoint'])->name('pending-accruals');
        Route::patch('/{apInvoice}/match-accruals',                         [ApInvoiceController::class, 'matchAccruals'])->name('match-accruals');
        Route::patch('/{apInvoice}/unmatch-accrual/{inventoryTransaction}', [ApInvoiceController::class, 'unmatchAccrual'])->name('unmatch-accrual');
        // ── Wildcard routes last ──────────────────────────────────────
        Route::get('/{apInvoice}',               [ApInvoiceController::class, 'show'])->name('show');
        Route::get('/{apInvoice}/edit',          [ApInvoiceController::class, 'edit'])->name('edit');
        Route::put('/{apInvoice}',               [ApInvoiceController::class, 'update'])->name('update');
        Route::delete('/{apInvoice}',            [ApInvoiceController::class, 'destroy'])->name('destroy');
        Route::patch('/{apInvoice}/cancel',      [ApInvoiceController::class, 'cancel'])->name('cancel');
    });

    // ── AR Invoices (Receivables) ──────────────────────────────────
    // IMPORTANT: data-for-company must be BEFORE the /{arInvoice} wildcard
    Route::prefix('ar-invoices')->name('ar-invoices.')->group(function () {
        Route::get('/data-for-company',          [ArInvoiceController::class, 'dataForCompany'])->name('data-for-company');
        Route::get('/',                          [ArInvoiceController::class, 'index'])->name('index');
        Route::get('/create',                    [ArInvoiceController::class, 'create'])->name('create');
        Route::post('/',                         [ArInvoiceController::class, 'store'])->name('store');
        Route::get('/{arInvoice}',               [ArInvoiceController::class, 'show'])->name('show');
        Route::get('/{arInvoice}/edit',          [ArInvoiceController::class, 'edit'])->name('edit');
        Route::put('/{arInvoice}',               [ArInvoiceController::class, 'update'])->name('update');
        Route::delete('/{arInvoice}',            [ArInvoiceController::class, 'destroy'])->name('destroy');
        Route::patch('/{arInvoice}/cancel',      [ArInvoiceController::class, 'cancel'])->name('cancel');
    });

    // ── FX Rates ───────────────────────────────────────────────────
    // IMPORTANT: /latest must be BEFORE /{fxRate} wildcard
    Route::prefix('fx-rates')->name('fx-rates.')->group(function () {
        Route::get('/latest',      [FxRateController::class, 'latest'])->name('latest');
        Route::get('/',            [FxRateController::class, 'index'])->name('index');
        Route::post('/',           [FxRateController::class, 'store'])->name('store');
        Route::delete('/{fxRate}', [FxRateController::class, 'destroy'])->name('destroy');
    });

    // ── Payments & Collections ─────────────────────────────────────
    // IMPORTANT: data-for-company and /checks must be BEFORE /{payment} wildcard
    Route::prefix('payments')->name('payments.')->group(function () {
        Route::get('/data-for-company',  [PaymentController::class, 'dataForCompany'])->name('data-for-company');
        Route::get('/checks',            [PaymentController::class, 'getChecks'])->name('checks');
        Route::get('/',                  [PaymentController::class, 'index'])->name('index');
        Route::post('/',                 [PaymentController::class, 'store'])->name('store');
        Route::delete('/{payment}',      [PaymentController::class, 'destroy'])->name('destroy');
        // 📌 METHODOLOGY REFERENCE: CheckCollectionLifecycle (#30)
        Route::patch('/{payment}/update-check-status', [PaymentController::class, 'updateCheckStatus'])->name('update-check-status');
        Route::post('/{payment}/return-check',         [PaymentController::class, 'returnCheck'])->name('return-check');
    });

    // ── Bank Statements ────────────────────────────────────────────
    Route::prefix('bank-statements')->name('bank-statements.')->group(function () {
        Route::get('/',                                  [BankStatementController::class, 'index'])->name('index');
        Route::post('/',                                 [BankStatementController::class, 'store'])->name('store');
        Route::put('/{bankStatement}',                   [BankStatementController::class, 'update'])->name('update');
        Route::delete('/{bankStatement}',                [BankStatementController::class, 'destroy'])->name('destroy');
        Route::patch('/{bankStatement}/match',           [BankStatementController::class, 'match'])->name('match');
        Route::patch('/{bankStatement}/unmatch',         [BankStatementController::class, 'unmatch'])->name('unmatch');
        Route::patch('/{bankStatement}/reconcile',       [BankStatementController::class, 'reconcile'])->name('reconcile');
    });

    Route::prefix('farm-cost-allocations')->name('farm-cost-allocations.')->group(function () {
 
    // ── Index & Create/Edit/Delete (draft only) ────────────────────
    Route::get('/',           [FarmCostAllocationController::class, 'index'])->name('index');
    Route::get('/create',     [FarmCostAllocationController::class, 'create'])->name('create');
    Route::post('/',          [FarmCostAllocationController::class, 'store'])->name('store');
    Route::get('/{farmCostAllocation}/edit',
                              [FarmCostAllocationController::class, 'edit'])->name('edit');
    Route::put('/{farmCostAllocation}',
                              [FarmCostAllocationController::class, 'update'])->name('update');
    Route::delete('/{farmCostAllocation}',
                              [FarmCostAllocationController::class, 'destroy'])->name('destroy');
 
    // ── Actions ───────────────────────────────────────────────────
    Route::post('/{farmCostAllocation}/post',
                              [FarmCostAllocationController::class, 'post'])->name('post');
    Route::post('/{farmCostAllocation}/settle',
                              [FarmCostAllocationController::class, 'settle'])->name('settle');
 
    // ── AJAX preview rates ────────────────────────────────────────
    Route::get('/preview-rates',
                              [FarmCostAllocationController::class, 'previewRates'])->name('preview-rates');
 
    // ── Overhead Types (nested resource, panel-based CRUD) ─────────
    Route::get('/overhead-types',
                              [FarmCostAllocationController::class, 'overheadTypes'])->name('overhead-types.index');
    Route::post('/overhead-types',
                              [FarmCostAllocationController::class, 'storeOverheadType'])->name('overhead-types.store');
    Route::put('/overhead-types/{farmOverheadType}',
                              [FarmCostAllocationController::class, 'updateOverheadType'])->name('overhead-types.update');
    Route::delete('/overhead-types/{farmOverheadType}',
                              [FarmCostAllocationController::class, 'destroyOverheadType'])->name('overhead-types.destroy');
    });

    // ── Budgets ────────────────────────────────────────────────────
    Route::prefix('budgets')->name('budgets.')->group(function () {
        Route::get('/',                          [BudgetController::class, 'index'])->name('index');
        Route::post('/bulk-upsert',              [BudgetController::class, 'bulkUpsert'])->name('bulk-upsert');
        Route::post('/copy-from-actuals',        [BudgetController::class, 'copyFromActuals'])->name('copy-from-actuals');
        Route::delete('/account',                [BudgetController::class, 'destroyByAccount'])->name('destroy-by-account');
    });

    // ══════════════════════════════════════════════════════════════════
    // PHASE 4 — FINANCIAL INTELLIGENCE & REPORTING
    // ══════════════════════════════════════════════════════════════════

    // ── Dashboard JSON endpoints (live refresh) ────────────────────
    // The main dashboard GET /dashboard is registered above (ungrouped)
    // so its route name stays 'dashboard' not 'dashboard.index'.
    Route::prefix('dashboard')->name('dashboard.')->group(function () {
        Route::get('/kpis',        [DashboardController::class, 'kpis'])->name('kpis');
        Route::get('/chart-data',  [DashboardController::class, 'chartData'])->name('chart-data');
    });

    // ── Reports ────────────────────────────────────────────────────
    // All methods render 'Accounting/Reports/Index' with an activeTab prop.
    // FIX: previously rendered separate non-existent component names
    // (Reports/TrialBalance, Reports/ProfitAndLoss, etc.)
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/',                          [ReportController::class, 'index'])->name('index');
        Route::get('/trial-balance',             [ReportController::class, 'trialBalance'])->name('trial-balance');
        Route::get('/profit-loss',               [ReportController::class, 'profitAndLoss'])->name('profit-loss');
        Route::get('/balance-sheet',             [ReportController::class, 'balanceSheet'])->name('balance-sheet');
        Route::get('/cash-flow',                 [ReportController::class, 'cashFlow'])->name('cash-flow');
        Route::get('/gross-margin',              [ReportController::class, 'grossMarginRanking'])->name('gross-margin');
        Route::get('/adg-fcr',                   [ReportController::class, 'adgFcr'])->name('adg-fcr');
        Route::get('/cogs/{lotId}',              [ReportController::class, 'cogsForLot'])->name('cogs-lot');
        Route::get('/animal/{animalId}/costing', [ReportController::class, 'animalCosting'])->name('animal-costing');
    });

 // ══════════════════════════════════════════════════════════════════
//  VERO — Analytics Report Routes
//  Paste this block inside routes/web.php, inside the
//  Route::middleware(['auth', 'verified']) group.
//
//  Place it after the existing /reports group and before the
//  closing }); of the auth middleware.
//
//  Also add to the sidebar in AuthenticatedLayout.vue:
//    Under "Accounting" group, after the existing Reports link:
//
//    <Link :href="route('analytics.index')" ...>
//      <svg><!-- bar chart with upward arrow icon --></svg>
//      <span>{{ isRtl ? 'تقارير تحليلية' : 'Analytics' }}</span>
//    </Link>
// ══════════════════════════════════════════════════════════════════
 
Route::prefix('analytics')->name('analytics.')->group(function () {
    Route::get('/',                      [AnalyticsReportController::class, 'index'])->name('index');
    Route::get('/financial-ratios',      [AnalyticsReportController::class, 'financialRatios'])->name('financial-ratios');
    Route::get('/top-customers',         [AnalyticsReportController::class, 'topCustomers'])->name('top-customers');
    Route::get('/top-customers-breed',   [AnalyticsReportController::class, 'topCustomersByBreed'])->name('top-customers-breed');
    Route::get('/top-suppliers',         [AnalyticsReportController::class, 'topSuppliers'])->name('top-suppliers');
    Route::get('/top-suppliers-item',    [AnalyticsReportController::class, 'topSuppliersByItem'])->name('top-suppliers-item');
    Route::get('/feeding-duration',      [AnalyticsReportController::class, 'feedingDuration'])->name('feeding-duration');
    Route::post('/ratio-comment',        [AnalyticsReportController::class, 'saveRatioComment'])->name('ratio-comment');
});
 

}); // end auth middleware

require __DIR__.'/auth.php';