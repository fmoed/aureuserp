<?php

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/FilamentHelper.php';
require_once __DIR__.'/../../../support/tests/Helpers/CompanyHelper.php';

use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Webkul\Asset\Enums\AssetCondition;
use Webkul\Asset\Enums\AssetProductType;
use Webkul\Asset\Enums\AssetSiteUsage;
use Webkul\Asset\Enums\AssetStatus;
use Webkul\Asset\Enums\AssetTrackingMode;
use Webkul\Asset\Enums\MovementType;
use Webkul\Asset\Filament\Exports\AssetExporter;
use Webkul\Asset\Filament\Resources\AssetMovementResource;
use Webkul\Asset\Filament\Resources\AssetMovementResource\Pages\ListAssetMovements;
use Webkul\Asset\Filament\Resources\AssetResource\Actions\IssueAssetAction;
use Webkul\Asset\Filament\Resources\AssetResource\Pages\CreateAsset;
use Webkul\Asset\Filament\Resources\AssetResource\Pages\EditAsset;
use Webkul\Asset\Filament\Resources\AssetResource\Pages\ListAssets;
use Webkul\Asset\Filament\Resources\AssetResource\Pages\ViewAsset;
use Webkul\Asset\Filament\Resources\AssetResource\RelationManagers\AssignmentsRelationManager;
use Webkul\Asset\Filament\Resources\ProductResource\Pages\CreateProduct;
use Webkul\Asset\Filament\Resources\ProductResource\Pages\EditProduct;
use Webkul\Asset\Filament\Resources\ProductResource\Pages\ListProducts;
use Webkul\Asset\Models\Asset;
use Webkul\Asset\Models\AssetCategory;
use Webkul\Asset\Models\AssetMovement;
use Webkul\Asset\Models\AssetProduct;
use Webkul\Asset\Models\AssetSite;
use Webkul\Asset\Models\AssetWarehouse;
use Webkul\Asset\Services\AssetCustodyService;
use Webkul\Asset\Settings\AssetSettings;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

function qtyResetSettings(): void
{
    $settings = app(AssetSettings::class);

    $settings->tag_prefix = 'AST-';
    $settings->auto_generate_tag = true;
    $settings->tag_padding = 5;
    $settings->default_condition = 'new';
    $settings->require_serial_by_default = false;
    $settings->default_warehouse_id = null;
    $settings->enable_site_assets = true;
    $settings->require_site_on_custody = false;
    $settings->default_custody_duration_days = null;
    $settings->require_expected_return_date = false;
    $settings->prevent_duplicate_product_custody = false;
    $settings->require_attachments_on_custody = false;
    $settings->require_attachments_on_damage = false;
    $settings->require_attachments_on_termination = false;
    $settings->restrict_termination_to_managers = false;
    $settings->termination_approval_cost_threshold = null;
    $settings->enable_consumables_batch_issue = true;
    $settings->auto_fill_consumable_employees_by_site = true;
    $settings->default_consumable_issue_quantity = 1;
    $settings->require_site_on_consumable_issue = false;
    $settings->require_attachments_on_consumable_issue = false;

    $settings->save();
}

function qtyEmployee(Company $company, array $attributes = []): Employee
{
    return Employee::factory()->create([
        'company_id' => $company->id,
        'user_id'    => User::factory()->create()->id,
        'is_active'  => true,
        ...$attributes,
    ]);
}

function qtyFullAssetPermissions(): array
{
    return [
        'view_any_asset_asset',
        'view_asset_asset',
        'create_asset_asset',
        'update_asset_asset',
        'delete_asset_asset',
        'delete_any_asset_asset',
        'view_any_asset_product',
        'view_asset_product',
        'create_asset_product',
        'update_asset_product',
    ];
}

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('assets');

    qtyResetSettings();
});

afterEach(function () {
    app()->setLocale('en');
    qtyResetSettings();
});

it('creates quantity-tracked durable asset products, forces Consumable to Quantity, and locks tracking_mode once assets exist', function () {
    $user = FilamentHelper::actingAs(qtyFullAssetPermissions());
    $company = $user->defaultCompany ?? Company::query()->firstOrFail();
    $category = AssetCategory::factory()->company($company)->create();

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'company_id'    => $company->id,
            'name'          => 'USB-C 65W Charger',
            'category_id'   => $category->id,
            'type'          => AssetProductType::Asset->value,
            'tracking_mode' => AssetTrackingMode::Quantity->value,
            'cost'          => 120,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $qtyProduct = AssetProduct::query()->where('name', 'USB-C 65W Charger')->firstOrFail();

    expect($qtyProduct->type)->toBe(AssetProductType::Asset)
        ->and($qtyProduct->tracking_mode)->toBe(AssetTrackingMode::Quantity)
        ->and($qtyProduct->isQuantityTracked())->toBeTrue()
        ->and($qtyProduct->isQuantityTrackedAsset())->toBeTrue();

    $consumable = AssetProduct::factory()
        ->company($company)
        ->create([
            'category_id'   => $category->id,
            'type'          => AssetProductType::Consumable,
            'tracking_mode' => AssetTrackingMode::Unit,
        ]);

    expect($consumable->tracking_mode)->toBe(AssetTrackingMode::Quantity);

    Asset::factory()->forProduct($qtyProduct)->create([
        'company_id'       => $company->id,
        'initial_quantity' => 25,
        'quantity'         => 25,
    ]);

    expect(fn () => $qtyProduct->update(['tracking_mode' => AssetTrackingMode::Unit]))
        ->toThrow(ValidationException::class);

    Livewire::test(EditProduct::class, ['record' => $qtyProduct->id])
        ->assertFormFieldDisabled('tracking_mode');
});

it('registers quantity-tracked durable asset lots without serial numbers even when require_serial_by_default is enabled', function () {
    $user = FilamentHelper::actingAs(qtyFullAssetPermissions());
    $company = $user->defaultCompany ?? Company::query()->firstOrFail();
    $warehouse = AssetWarehouse::factory()->company($company)->create();
    $qtyProduct = AssetProduct::factory()->company($company)->quantityTracked()->create([
        'name' => 'Laptop Power Adapter',
        'cost' => 150,
    ]);

    app(AssetSettings::class)->fill([
        'require_serial_by_default' => true,
    ])->save();

    Livewire::test(CreateAsset::class)
        ->fillForm([
            'company_id'       => $company->id,
            'product_id'       => $qtyProduct->id,
            'initial_quantity' => 40,
            'condition'        => AssetCondition::New->value,
            'cost'             => 150,
            'warehouse_id'     => $warehouse->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertFormFieldHidden('serial_no')
        ->assertFormFieldVisible('initial_quantity')
        ->assertFormFieldVisible('condition')
        ->assertFormFieldVisible('warranty_until');

    $lot = Asset::query()->where('product_id', $qtyProduct->id)->firstOrFail();

    expect($lot->serial_no)->toBeNull()
        ->and((float) $lot->initial_quantity)->toBe(40.0)
        ->and((float) $lot->quantity)->toBe(40.0)
        ->and($lot->status)->toBe(AssetStatus::Available)
        ->and($lot->condition)->toBe(AssetCondition::New)
        ->and($lot->movements()->where('type', MovementType::Registered)->count())->toBe(1);
});

it('issues single and batch quantities of durable assets into returnable child custody records, maintains product valuation, and guards employees', function () {
    $user = FilamentHelper::actingAs(qtyFullAssetPermissions());
    $company = $user->defaultCompany ?? Company::query()->firstOrFail();
    $warehouse = AssetWarehouse::factory()->company($company)->create();
    $site = AssetSite::factory()->company($company)->create([
        'usage_type' => AssetSiteUsage::AssetLocation,
    ]);
    $department = Department::factory()->create(['company_id' => $company->id]);
    $empA = qtyEmployee($company, ['department_id' => $department->id]);
    $empB = qtyEmployee($company, ['department_id' => $department->id]);

    $qtyProduct = AssetProduct::factory()->company($company)->quantityTracked()->create([
        'name' => 'Barcode Scanner Dock',
        'cost' => 100,
    ]);

    $lot = Asset::factory()->forProduct($qtyProduct)->create([
        'company_id'       => $company->id,
        'warehouse_id'     => $warehouse->id,
        'initial_quantity' => 100,
        'quantity'         => 100,
        'cost'             => 10000,
        'status'           => AssetStatus::Available,
    ]);

    Livewire::test(ViewAsset::class, ['record' => $lot->id])
        ->callAction('issue', data: [
            'allocation_mode' => 'employee_custody',
            'employee_ids'    => [$empA->id],
            'quantity'        => 15,
            'condition_out'   => AssetCondition::Good->value,
        ])
        ->assertHasNoActionErrors();

    $lot->refresh();
    $childA = Asset::query()
        ->where('parent_asset_id', $lot->id)
        ->where('current_employee_id', $empA->id)
        ->firstOrFail();

    expect((float) $lot->quantity)->toBe(85.0)
        ->and((float) $lot->initial_quantity)->toBe(100.0)
        ->and((float) $lot->cost)->toBe(8500.0)
        ->and($childA->status)->toBe(AssetStatus::Held)
        ->and((float) $childA->quantity)->toBe(15.0)
        ->and((float) $childA->initial_quantity)->toBe(0.0)
        ->and((float) $childA->cost)->toBe(1500.0)
        ->and($childA->condition)->toBe(AssetCondition::Good);

    expect(fn () => $empA->delete())->toThrow(ValidationException::class);

    $service = app(AssetCustodyService::class);
    $service->issue($lot->fresh(), [
        'employee_ids'  => [$empA->id, $empB->id],
        'quantity'      => 10,
        'condition_out' => AssetCondition::Good->value,
    ]);

    $childA->refresh();
    $childB = Asset::query()
        ->where('parent_asset_id', $lot->id)
        ->where('current_employee_id', $empB->id)
        ->firstOrFail();

    expect((float) $lot->fresh()->quantity)->toBe(65.0)
        ->and((float) $lot->fresh()->cost)->toBe(6500.0)
        ->and((float) $childA->quantity)->toBe(25.0)
        ->and((float) $childA->cost)->toBe(2500.0)
        ->and((float) $childB->quantity)->toBe(10.0)
        ->and((float) $childB->cost)->toBe(1000.0);

    $service->issue($lot->fresh(), [
        'allocation_mode' => 'site_asset',
        'site_id'         => $site->id,
        'quantity'        => 5,
    ]);

    $siteChild = Asset::query()
        ->where('parent_asset_id', $lot->id)
        ->where('current_site_id', $site->id)
        ->whereNull('current_employee_id')
        ->firstOrFail();

    expect((float) $lot->fresh()->quantity)->toBe(60.0)
        ->and((float) $lot->fresh()->cost)->toBe(6000.0)
        ->and((float) $siteChild->quantity)->toBe(5.0)
        ->and((float) $siteChild->cost)->toBe(500.0);

    Livewire::test(ListProducts::class)
        ->assertTableColumnStateSet('assets_count', 100, record: $qtyProduct)
        ->assertTableColumnStateSet('available_assets_count', 60, record: $qtyProduct)
        ->assertTableColumnStateSet('held_assets_count', 40, record: $qtyProduct)
        ->assertTableColumnStateSet('terminated_assets_count', 0, record: $qtyProduct)
        ->assertTableColumnStateSet('assets_sum_cost', 10000, record: $qtyProduct);

    $service->issue($lot->fresh(), [
        'employee_id' => $empB->id,
        'quantity'    => 60,
    ]);

    expect((float) $lot->fresh()->quantity)->toBe(0.0);

    Livewire::test(ListAssets::class)
        ->assertCanNotSeeTableRecords([$lot->fresh()])
        ->assertCanSeeTableRecords([$childA->fresh(), $childB->fresh()]);
});

it('supports partial and full transfer-merge, partial and full return with movement preservation, and partial scrap on quantity-tracked durable assets', function () {
    $user = FilamentHelper::actingAs(qtyFullAssetPermissions());
    $company = $user->defaultCompany ?? Company::query()->firstOrFail();
    $warehouse = AssetWarehouse::factory()->company($company)->create();
    $empA = qtyEmployee($company);
    $empB = qtyEmployee($company);

    $qtyProduct = AssetProduct::factory()->company($company)->quantityTracked()->create();
    $lot = Asset::factory()->forProduct($qtyProduct)->create([
        'company_id'       => $company->id,
        'warehouse_id'     => $warehouse->id,
        'initial_quantity' => 30,
        'quantity'         => 30,
        'cost'             => 3000,
    ]);

    $service = app(AssetCustodyService::class);
    $service->issue($lot, [
        'employee_id'   => $empA->id,
        'quantity'      => 20,
        'condition_out' => AssetCondition::Good->value,
    ]);

    $childA = Asset::query()
        ->where('parent_asset_id', $lot->id)
        ->where('current_employee_id', $empA->id)
        ->firstOrFail();

    Livewire::test(ViewAsset::class, ['record' => $childA->id])
        ->callAction('transfer', data: [
            'allocation_mode' => 'employee_custody',
            'employee_id'     => $empB->id,
            'quantity'        => 8,
            'condition_in'    => AssetCondition::Good->value,
        ])
        ->assertHasNoActionErrors();

    $childA->refresh();
    $childB = Asset::query()
        ->where('parent_asset_id', $lot->id)
        ->where('current_employee_id', $empB->id)
        ->firstOrFail();

    expect((float) $childA->quantity)->toBe(12.0)
        ->and((float) $childA->cost)->toBe(1200.0)
        ->and((float) $childB->quantity)->toBe(8.0)
        ->and((float) $childB->cost)->toBe(800.0);

    $childBMergeTargetMovementCount = AssetMovement::query()->where('asset_id', $childB->id)->count();

    $service->transfer($childB, [
        'employee_id'  => $empA->id,
        'quantity'     => 8,
        'condition_in' => AssetCondition::Good->value,
    ]);

    $archivedChildB = Asset::withTrashed()->find($childB->id);

    expect(Asset::query()->find($childB->id))->toBeNull()
        ->and($archivedChildB)->not->toBeNull()
        ->and($archivedChildB->trashed())->toBeTrue()
        ->and($archivedChildB->asset_tag)->toBe($childB->asset_tag)
        ->and((float) $archivedChildB->quantity)->toBe(0.0)
        ->and((float) $archivedChildB->cost)->toBe(0.0)
        ->and(AssetMovement::query()->where('asset_id', $childB->id)->count())->toBe($childBMergeTargetMovementCount)
        ->and((float) $childA->fresh()->quantity)->toBe(20.0)
        ->and((float) $childA->fresh()->cost)->toBe(2000.0);

    Livewire::test(ViewAsset::class, ['record' => $childA->id])
        ->callAction('return', data: [
            'quantity'     => 5,
            'condition_in' => AssetCondition::Good->value,
            'warehouse_id' => $warehouse->id,
        ])
        ->assertHasNoActionErrors();

    expect((float) $lot->fresh()->quantity)->toBe(15.0)
        ->and((float) $lot->fresh()->cost)->toBe(1500.0)
        ->and((float) $childA->fresh()->quantity)->toBe(15.0)
        ->and((float) $childA->fresh()->cost)->toBe(1500.0);

    $childABeforeFullReturnCount = AssetMovement::query()->where('asset_id', $childA->id)->count();

    $service->return($childA->fresh(), [
        'quantity'     => 15,
        'condition_in' => AssetCondition::Good->value,
        'warehouse_id' => $warehouse->id,
    ]);

    $archivedChildA = Asset::withTrashed()->find($childA->id);

    expect(Asset::query()->find($childA->id))->toBeNull()
        ->and($archivedChildA)->not->toBeNull()
        ->and($archivedChildA->trashed())->toBeTrue()
        ->and(AssetMovement::query()->where('asset_id', $childA->id)->count())->toBe($childABeforeFullReturnCount)
        ->and((float) $lot->fresh()->quantity)->toBe(30.0)
        ->and((float) $lot->fresh()->cost)->toBe(3000.0)
        ->and(AssetMovement::query()->where('asset_id', $lot->id)->where('type', MovementType::Returned)->count())->toBe(2);

    Livewire::test(ViewAsset::class, ['record' => $lot->id])
        ->callAction('scrap', data: [
            'quantity' => 4,
            'note'     => 'Damaged batch adapters',
        ])
        ->assertHasNoActionErrors();

    $scrappedChild = Asset::query()
        ->where('parent_asset_id', $lot->id)
        ->where('status', AssetStatus::Scrapped)
        ->firstOrFail();

    expect((float) $lot->fresh()->quantity)->toBe(26.0)
        ->and((float) $lot->fresh()->cost)->toBe(2600.0)
        ->and((float) $scrappedChild->quantity)->toBe(4.0)
        ->and((float) $scrappedChild->cost)->toBe(400.0);

    Livewire::test(AssignmentsRelationManager::class, [
        'ownerRecord' => $lot->fresh(),
        'pageClass'   => ViewAsset::class,
    ])->assertCanSeeTableRecords(
        AssetMovement::query()->whereIn('asset_id', [$lot->id, $childA->id, $childB->id, $scrappedChild->id])->get(),
    );

    Livewire::test(ListProducts::class)
        ->assertTableColumnStateSet('available_assets_count', 26, record: $qtyProduct)
        ->assertTableColumnStateSet('terminated_assets_count', 4, record: $qtyProduct);
});

it('rejects quantity-tracked products in bulk unit registration and streams PDF barcode labels on ViewAsset and ListAssets', function () {
    $user = FilamentHelper::actingAs(qtyFullAssetPermissions());
    $company = $user->defaultCompany ?? Company::query()->firstOrFail();
    $warehouse = AssetWarehouse::factory()->company($company)->create();

    $qtyProduct = AssetProduct::factory()->company($company)->quantityTracked()->create([
        'name' => 'شاحن جداري سريع',
    ]);
    $unitProduct = AssetProduct::factory()->company($company)->create([
        'name' => 'ThinkPad X1',
    ]);

    expect(fn () => app(AssetCustodyService::class)->bulkRegister([
        'company_id'    => $company->id,
        'product_id'    => $qtyProduct->id,
        'tracking_mode' => 'without_serials',
        'quantity'      => 5,
        'warehouse_id'  => $warehouse->id,
    ]))->toThrow(ValidationException::class);

    $lot = Asset::factory()->forProduct($qtyProduct)->create([
        'company_id'       => $company->id,
        'warehouse_id'     => $warehouse->id,
        'asset_tag'        => 'AST-90101',
        'initial_quantity' => 25,
        'quantity'         => 25,
    ]);

    $unit = Asset::factory()->forProduct($unitProduct)->create([
        'company_id'   => $company->id,
        'warehouse_id' => $warehouse->id,
        'asset_tag'    => 'AST-90102',
        'serial_no'    => 'SN-X1-90102',
    ]);

    app()->setLocale('ar');

    $viewTest = Livewire::test(ViewAsset::class, ['record' => $lot->id])
        ->callAction('printLabels', data: ['copies' => 3])
        ->assertHasNoActionErrors()
        ->assertFileDownloaded('Asset-Labels-AST-90101.pdf');

    expect(base64_decode((string) data_get($viewTest->effects, 'download.content')))
        ->toStartWith('%PDF-');

    app()->setLocale('en');

    $bulkTest = Livewire::test(ListAssets::class)
        ->callTableBulkAction('printLabels', [$lot, $unit])
        ->assertFileDownloaded('Asset-Labels.pdf');

    expect(base64_decode((string) data_get($bulkTest->effects, 'download.content')))
        ->toStartWith('%PDF-');

    Livewire::test(ListAssets::class)
        ->callAction('bulkRegister', data: [
            'company_id'        => $company->id,
            'product_id'        => $qtyProduct->id,
            'tracking_mode'     => 'without_serials',
            'quantity'          => 3,
            'initial_placement' => 'warehouse',
            'warehouse_id'      => $warehouse->id,
        ])
        ->assertHasActionErrors(['product_id']);
});

it('enforces split movement logging, consumable cost conservation, terminal irreversibility, and historical locks', function () {
    $user = FilamentHelper::actingAs(qtyFullAssetPermissions());
    $company = $user->defaultCompany ?? Company::query()->firstOrFail();
    $warehouse = AssetWarehouse::factory()->company($company)->create();
    $empA = qtyEmployee($company);
    $service = app(AssetCustodyService::class);

    $qtyProduct = AssetProduct::factory()->company($company)->quantityTracked()->create();
    $otherQtyProduct = AssetProduct::factory()->company($company)->quantityTracked()->create();

    $directHeldLot = Asset::query()->create([
        'company_id'          => $company->id,
        'product_id'          => $qtyProduct->id,
        'status'              => AssetStatus::Held,
        'current_employee_id' => $empA->id,
        'initial_quantity'    => 10,
        'quantity'            => 10,
        'cost'                => 1000,
    ]);

    $service->return($directHeldLot, [
        'quantity'     => 4,
        'condition_in' => AssetCondition::Good->value,
        'warehouse_id' => $warehouse->id,
    ]);

    $splitChild = Asset::query()
        ->where('parent_asset_id', $directHeldLot->id)
        ->where('current_employee_id', $empA->id)
        ->firstOrFail();

    expect((float) $directHeldLot->fresh()->quantity)->toBe(4.0)
        ->and((float) $directHeldLot->fresh()->cost)->toBe(400.0)
        ->and((float) $splitChild->quantity)->toBe(6.0)
        ->and((float) $splitChild->cost)->toBe(600.0)
        ->and(AssetMovement::query()->where('asset_id', $splitChild->id)->where('type', MovementType::Issued)->count())->toBe(1);

    expect(fn () => $directHeldLot->fresh()->update(['cost' => 9999]))->toThrow(ValidationException::class)
        ->and(fn () => $directHeldLot->fresh()->update(['initial_quantity' => 50]))->toThrow(ValidationException::class)
        ->and(fn () => $directHeldLot->fresh()->update(['product_id' => $otherQtyProduct->id]))->toThrow(ValidationException::class);

    Livewire::test(EditAsset::class, ['record' => $directHeldLot->id])
        ->assertFormFieldIsDisabled('product_id')
        ->assertFormFieldIsDisabled('cost')
        ->assertFormFieldIsDisabled('initial_quantity');

    $movement = AssetMovement::query()->where('asset_id', $splitChild->id)->firstOrFail();
    expect(property_exists(AssetMovement::class, 'allowCleanupDelete'))->toBeFalse()
        ->and(fn () => $movement->delete())->toThrow(ValidationException::class);

    $consumableProduct = AssetProduct::factory()->company($company)->consumable()->create();
    $consumableLot = Asset::factory()->forProduct($consumableProduct)->create([
        'company_id'       => $company->id,
        'warehouse_id'     => $warehouse->id,
        'initial_quantity' => 10,
        'quantity'         => 10,
        'cost'             => 500,
        'status'           => AssetStatus::Available,
    ]);

    $service->issue($consumableLot, [
        'employee_id' => $empA->id,
        'quantity'    => 4,
    ]);

    expect((float) $consumableLot->fresh()->quantity)->toBe(6.0)
        ->and((float) $consumableLot->fresh()->cost)->toBe(300.0);

    $service->issue($consumableLot->fresh(), [
        'employee_id' => $empA->id,
        'quantity'    => 6,
    ]);

    expect($consumableLot->fresh()->status)->toBe(AssetStatus::Consumed)
        ->and((float) $consumableLot->fresh()->cost)->toBe(0.0)
        ->and(fn () => $consumableLot->fresh()->update(['status' => AssetStatus::Available, 'warehouse_id' => $warehouse->id]))->toThrow(ValidationException::class);

    $scrappedUnit = Asset::factory()->forProduct(AssetProduct::factory()->company($company)->create())->create([
        'company_id' => $company->id,
        'status'     => AssetStatus::Scrapped,
    ]);

    expect(fn () => $scrappedUnit->update(['status' => AssetStatus::Available, 'warehouse_id' => $warehouse->id]))
        ->toThrow(ValidationException::class);
});

it('formats fractional quantities accurately, excludes depleted lots from export, preserves worst condition on merge, and enforces private attachment storage', function () {
    $user = FilamentHelper::actingAs(qtyFullAssetPermissions());
    $company = $user->defaultCompany ?? Company::query()->firstOrFail();
    $warehouse = AssetWarehouse::factory()->company($company)->create();
    $empA = qtyEmployee($company);
    $service = app(AssetCustodyService::class);

    $consumableProduct = AssetProduct::factory()->company($company)->consumable()->create([
        'name' => 'Ink Cartridge Bulk',
    ]);
    $fractionalLot = Asset::factory()->forProduct($consumableProduct)->create([
        'company_id'       => $company->id,
        'warehouse_id'     => $warehouse->id,
        'initial_quantity' => 5.5,
        'cost'             => 550,
        'status'           => AssetStatus::Available,
    ]);

    expect(Asset::formatQuantity(2.25))->toBe('2.25')
        ->and(Asset::formatQuantity(15.0))->toBe('15');

    $service->issue($fractionalLot, [
        'employee_id' => $empA->id,
        'quantity'    => 3.25,
    ]);

    Livewire::test(ListAssets::class)
        ->assertTableColumnFormattedStateSet('quantity', '2.25 / 5.5', record: $fractionalLot->fresh());

    Livewire::test(ViewAsset::class, ['record' => $fractionalLot->id])
        ->assertSeeText('2.25')
        ->assertSeeText('5.5')
        ->assertDontSeeText('2.2500');

    Livewire::test(ListAssetMovements::class)
        ->assertTableColumnFormattedStateSet('quantity', '3.25', record: AssetMovement::query()->where('asset_id', $fractionalLot->id)->where('type', MovementType::Issued)->firstOrFail());

    $qtyProduct = AssetProduct::factory()->company($company)->quantityTracked()->create();
    $goodWarehouseLot = Asset::factory()->forProduct($qtyProduct)->create([
        'company_id'       => $company->id,
        'warehouse_id'     => $warehouse->id,
        'initial_quantity' => 10,
        'quantity'         => 10,
        'condition'        => AssetCondition::Good,
        'cost'             => 1000,
    ]);

    $depletedLot = Asset::factory()->forProduct($qtyProduct)->create([
        'company_id'       => $company->id,
        'warehouse_id'     => $warehouse->id,
        'initial_quantity' => 10,
        'quantity'         => 10,
        'status'           => AssetStatus::Available,
    ]);
    $service->issue($depletedLot, [
        'employee_id' => $empA->id,
        'quantity'    => 10,
    ]);

    expect(AssetExporter::modifyQuery(Asset::query())->pluck('id')->all())
        ->not->toContain($depletedLot->id)
        ->toContain($goodWarehouseLot->id);

    $service->issue($goodWarehouseLot, [
        'employee_id'   => $empA->id,
        'quantity'      => 4,
        'condition_out' => AssetCondition::Good->value,
    ]);

    $heldChild = Asset::query()
        ->where('parent_asset_id', $goodWarehouseLot->id)
        ->where('current_employee_id', $empA->id)
        ->firstOrFail();

    $service->return($heldChild, [
        'quantity'     => 2,
        'condition_in' => AssetCondition::Damaged->value,
        'return_note'  => 'Broken connector pins',
        'warehouse_id' => $warehouse->id,
    ]);

    expect($goodWarehouseLot->fresh()->condition)->toBe(AssetCondition::Damaged);

    $service->return($heldChild->fresh(), [
        'quantity'     => 2,
        'condition_in' => AssetCondition::Good->value,
        'warehouse_id' => $warehouse->id,
    ]);

    expect($goodWarehouseLot->fresh()->condition)->toBe(AssetCondition::Damaged);

    $uploadField = IssueAssetAction::attachmentUploadField('attachments', 'Attachments');
    expect($uploadField->getDiskName())->toBe('local')
        ->and($uploadField->getVisibility())->toBe('private');

    $modalComponents = collect(AssetMovementResource::movementModalSchema());
    $attachmentsSection = $modalComponents->last();
    $modalUpload = $attachmentsSection->getDefaultChildComponents()[0];
    expect($modalUpload->getDiskName())->toBe('local')
        ->and($modalUpload->getVisibility())->toBe('private');
});
