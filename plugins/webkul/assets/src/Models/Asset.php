<?php

namespace Webkul\Asset\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Webkul\Asset\Database\Factories\AssetFactory;
use Webkul\Asset\Enums\AssetCondition;
use Webkul\Asset\Enums\AssetProductType;
use Webkul\Asset\Enums\AssetSourceType;
use Webkul\Asset\Enums\AssetStatus;
use Webkul\Asset\Enums\MovementType;
use Webkul\Asset\Services\AssetCustodyService;
use Webkul\Asset\Settings\AssetSettings;
use Webkul\Chatter\Traits\HasChatter;
use Webkul\Chatter\Traits\HasLogActivity;
use Webkul\Employee\Models\Employee;
use Webkul\Field\Traits\HasCustomFields;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\CompanyContext;
use Webkul\Support\Support\CompanyConsistencyGuard;
use Webkul\Support\Traits\BelongsToCompany;
use Webkul\Support\Traits\ChecksCompanyConsistency;

class Asset extends Model
{
    use BelongsToCompany;
    use ChecksCompanyConsistency, HasChatter, HasCustomFields, HasFactory, HasLogActivity, SoftDeletes;

    protected $table = 'assets';

    public ?AssetSourceType $initialSourceType = null;

    public ?Model $initialReference = null;

    public ?array $initialAttachments = null;

    public ?int $initialConsumableEmployeeId = null;

    public ?int $initialConsumableSiteId = null;

    public bool $allowServiceCostAdjustment = false;

    protected ?bool $cachedOperationalHistory = null;

    protected $fillable = [
        'company_id',
        'product_id',
        'parent_asset_id',
        'warehouse_id',
        'asset_tag',
        'serial_no',
        'status',
        'condition',
        'initial_quantity',
        'quantity',
        'current_employee_id',
        'current_site_id',
        'issued_at',
        'expected_return_at',
        'custody_note',
        'purchase_date',
        'warranty_until',
        'cost',
        'note',
        'creator_id',
        'initial_attachments',
    ];

    protected $casts = [
        'status'             => AssetStatus::class,
        'condition'          => AssetCondition::class,
        'initial_quantity'   => 'decimal:4',
        'quantity'           => 'decimal:4',
        'issued_at'          => 'datetime',
        'expected_return_at' => 'date',
        'purchase_date'      => 'date',
        'warranty_until'     => 'date',
        'cost'               => 'decimal:4',
    ];

    protected function resolveProduct(): ?AssetProduct
    {
        if ($this->relationLoaded('product') && $this->product) {
            return $this->product;
        }

        if (blank($this->product_id)) {
            return null;
        }

        return AssetProduct::withoutGlobalScopes()
            ->withTrashed()
            ->whereKey($this->product_id)
            ->first();
    }

    public function isConsumable(): bool
    {
        return $this->resolveProduct()?->isConsumable() ?? false;
    }

    public function isQuantityTracked(): bool
    {
        return $this->resolveProduct()?->isQuantityTracked() ?? false;
    }

    public function isQuantityTrackedAsset(): bool
    {
        return $this->resolveProduct()?->isQuantityTrackedAsset() ?? false;
    }

    public static function resolveDefaultWarehouseId(?int $companyId): ?int
    {
        $defaultId = app(AssetSettings::class)->default_warehouse_id;

        if (blank($defaultId)) {
            return null;
        }

        $exists = AssetWarehouse::withoutGlobalScopes()
            ->whereKey($defaultId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();

        return $exists ? (int) $defaultId : null;
    }

    protected static function booted(): void
    {
        static::creating(function (self $asset): void {
            if (blank($asset->company_id)) {
                $asset->company_id = app(CompanyContext::class)->currentId();
            }

            if (blank($asset->creator_id)) {
                $asset->creator_id = Auth::id();
            }

            if (blank($asset->company_id)) {
                throw ValidationException::withMessages([
                    'company_id' => __('assets::services/asset-custody.company-required'),
                ]);
            }

            if (blank($asset->product_id)) {
                throw ValidationException::withMessages([
                    'product_id' => __('assets::services/asset-custody.product-required'),
                ]);
            }

            $product = AssetProduct::withoutGlobalScopes()->withTrashed()->find($asset->product_id);

            if (! $product || $product->trashed() || ! $product->is_active) {
                throw ValidationException::withMessages([
                    'product_id' => __('assets::services/asset-custody.product-required'),
                ]);
            }

            $isConsumable = $product->type === AssetProductType::Consumable;
            $isQuantityTrackedAsset = $product->isQuantityTrackedAsset();
            $isQuantityTracked = $isConsumable || $isQuantityTrackedAsset;
            $isChildRecord = filled($asset->parent_asset_id);

            if ($isConsumable) {
                if ($isChildRecord) {
                    $enteredQuantity = (float) ($asset->quantity ?? 1);

                    if ($enteredQuantity <= 0) {
                        throw ValidationException::withMessages([
                            'quantity' => __('assets::services/asset-custody.quantity-must-be-positive'),
                        ]);
                    }

                    $asset->initial_quantity = (float) ($asset->initial_quantity ?? 0);
                    $asset->quantity = $enteredQuantity;
                } else {
                    $enteredQuantity = (float) ($asset->initial_quantity ?? $asset->quantity ?? 1);

                    if ($enteredQuantity <= 0) {
                        throw ValidationException::withMessages([
                            'initial_quantity' => __('assets::services/asset-custody.quantity-must-be-positive'),
                        ]);
                    }

                    $asset->initial_quantity = $enteredQuantity;
                }

                $asset->serial_no = null;
                $asset->warranty_until = null;
                $asset->condition = AssetCondition::New;
                $asset->expected_return_at = null;
            } elseif ($isQuantityTrackedAsset) {
                $asset->serial_no = null;

                if ($isChildRecord) {
                    $enteredQuantity = (float) ($asset->quantity ?? 1);

                    if ($enteredQuantity <= 0) {
                        throw ValidationException::withMessages([
                            'quantity' => __('assets::services/asset-custody.quantity-must-be-positive'),
                        ]);
                    }

                    $asset->initial_quantity = (float) ($asset->initial_quantity ?? 0);
                    $asset->quantity = $enteredQuantity;
                } else {
                    $enteredQuantity = (float) ($asset->initial_quantity ?? $asset->quantity ?? 1);

                    if ($enteredQuantity <= 0) {
                        throw ValidationException::withMessages([
                            'initial_quantity' => __('assets::services/asset-custody.quantity-must-be-positive'),
                        ]);
                    }

                    $asset->initial_quantity = $enteredQuantity;
                    $asset->quantity = (float) ($asset->quantity ?? $enteredQuantity);
                }
            } else {
                $asset->initial_quantity = 1;
                $asset->quantity = 1;
            }

            if (! $isChildRecord && (blank($asset->cost) || (float) $asset->cost === 0.0) && filled($product->cost)) {
                $asset->cost = $product->cost;
            }

            if (blank($asset->status)) {
                if (blank($asset->warehouse_id) && (filled($asset->current_employee_id) || filled($asset->current_site_id))) {
                    $asset->status = AssetStatus::Held;
                    $asset->issued_at ??= now();
                } else {
                    $asset->status = AssetStatus::Available;
                }
            }

            if ($asset->status === AssetStatus::Available && blank($asset->warehouse_id)) {
                $asset->warehouse_id = static::resolveDefaultWarehouseId((int) $asset->company_id);
            }

            if ($isConsumable && (filled($asset->current_employee_id) || filled($asset->current_site_id) || $asset->status === AssetStatus::Consumed)) {
                app(AssetCustodyService::class)->validateConsumableHolder($asset);

                if (app(AssetSettings::class)->require_attachments_on_consumable_issue && blank($asset->initialAttachments)) {
                    throw ValidationException::withMessages([
                        'initial_attachments' => __('assets::services/asset-custody.attachments-required'),
                    ]);
                }

                $asset->initialConsumableEmployeeId = filled($asset->current_employee_id) ? (int) $asset->current_employee_id : null;
                $asset->initialConsumableSiteId = filled($asset->current_site_id) ? (int) $asset->current_site_id : null;
                $asset->status = AssetStatus::Consumed;
                $asset->quantity = 0;
                $asset->warehouse_id = null;
                $asset->current_employee_id = null;
                $asset->current_site_id = null;
            } elseif ($isConsumable && ! $isChildRecord) {
                $asset->quantity = $asset->initial_quantity;
            }

            CompanyConsistencyGuard::assert(
                $asset->company_id,
                [$asset->getAttributes()],
                $asset->companyConsistentFields(),
            );

            if ($asset->status === AssetStatus::Available) {
                if (blank($asset->warehouse_id) || ! AssetWarehouse::withoutGlobalScopes()->whereKey($asset->warehouse_id)->where('is_active', true)->whereNull('deleted_at')->exists()) {
                    throw ValidationException::withMessages([
                        'warehouse_id' => __('assets::services/asset-custody.warehouse-required'),
                    ]);
                }

                $asset->current_employee_id = null;
                $asset->current_site_id = null;
                $asset->issued_at = null;
                $asset->expected_return_at = null;
                $asset->custody_note = null;
            } elseif ($asset->status === AssetStatus::Held) {
                app(AssetCustodyService::class)->validateHeldState($asset);
                $asset->issued_at ??= now();

                if (app(AssetSettings::class)->require_expected_return_date && filled($asset->current_employee_id) && blank($asset->expected_return_at)) {
                    throw ValidationException::withMessages([
                        'expected_return_at' => __('assets::services/asset-custody.expected-return-required'),
                    ]);
                }
            } elseif (in_array($asset->status, [AssetStatus::Scrapped, AssetStatus::Lost], true)) {
                $asset->warehouse_id = null;
                $asset->current_employee_id = null;
                $asset->current_site_id = null;
                $asset->issued_at = null;
                $asset->expected_return_at = null;
                $asset->custody_note = null;
            }

            $settings = app(AssetSettings::class);

            if (! $isQuantityTracked && $settings->require_serial_by_default && blank($asset->serial_no)) {
                throw ValidationException::withMessages([
                    'serial_no' => __('assets::services/asset-custody.serial-required'),
                ]);
            }

            if (blank($asset->condition)) {
                $asset->condition = AssetCondition::tryFrom((string) $settings->default_condition) ?? AssetCondition::New;
            }

            if (blank($asset->asset_tag)) {
                if (! $settings->auto_generate_tag) {
                    throw ValidationException::withMessages([
                        'asset_tag' => __('assets::services/asset-custody.tag-required'),
                    ]);
                }

                $asset->asset_tag = app(AssetCustodyService::class)->nextAssetTag((int) $asset->company_id);
            }
        });

        static::created(function (self $asset): void {
            if (filled($asset->parent_asset_id)) {
                return;
            }

            if (in_array($asset->status, [AssetStatus::Available, AssetStatus::Held, AssetStatus::Consumed], true)) {
                $isConsumed = $asset->status === AssetStatus::Consumed;
                $sourceType = $asset->initialSourceType ?? AssetSourceType::Manual;
                $toEmployeeId = $isConsumed ? $asset->initialConsumableEmployeeId : $asset->current_employee_id;
                $toSiteId = $isConsumed ? $asset->initialConsumableSiteId : $asset->current_site_id;
                $occurredAt = $asset->issued_at ?? $asset->created_at ?? now();
                $performedBy = $asset->creator_id ?? Auth::id();
                $note = $asset->custody_note ?? $asset->note;

                AssetMovement::query()->create([
                    'company_id'           => $asset->company_id,
                    'asset_id'             => $asset->id,
                    'type'                 => MovementType::Registered,
                    'quantity'             => $asset->initial_quantity ?? 1,
                    'source_type'          => $sourceType,
                    'reference_type'       => $asset->initialReference?->getMorphClass(),
                    'reference_id'         => $asset->initialReference?->getKey(),
                    'to_warehouse_id'      => $asset->warehouse_id,
                    'to_employee_id'       => $toEmployeeId,
                    'to_site_id'           => $toSiteId,
                    'condition'            => $asset->condition,
                    'occurred_at'          => $occurredAt,
                    'expected_return_at'   => $asset->expected_return_at,
                    'performed_by_user_id' => $performedBy,
                    'note'                 => $asset->note ?? $asset->custody_note,
                    'attachments'          => $asset->initialAttachments,
                ]);

                // Record a dedicated Issued movement when registered directly into custody.
                if (in_array($asset->status, [AssetStatus::Held, AssetStatus::Consumed], true)) {
                    AssetMovement::query()->create([
                        'company_id'           => $asset->company_id,
                        'asset_id'             => $asset->id,
                        'type'                 => MovementType::Issued,
                        'quantity'             => $asset->initial_quantity ?? 1,
                        'source_type'          => $sourceType,
                        'reference_type'       => $asset->initialReference?->getMorphClass(),
                        'reference_id'         => $asset->initialReference?->getKey(),
                        'to_employee_id'       => $toEmployeeId,
                        'to_site_id'           => $toSiteId,
                        'condition'            => $asset->condition,
                        'occurred_at'          => $occurredAt,
                        'expected_return_at'   => $asset->expected_return_at,
                        'performed_by_user_id' => $performedBy,
                        'note'                 => $note,
                        'attachments'          => $asset->initialAttachments,
                    ]);
                }
            }
        });

        static::updating(function (self $asset): void {
            $originalStatus = $asset->getOriginal('status');
            $originalStatusEnum = $originalStatus instanceof AssetStatus
                ? $originalStatus
                : AssetStatus::tryFrom((string) $originalStatus);

            if (
                $asset->isDirty('status')
                && in_array($originalStatusEnum, [AssetStatus::Scrapped, AssetStatus::Lost, AssetStatus::Consumed], true)
                && $asset->status !== $originalStatusEnum
            ) {
                throw ValidationException::withMessages([
                    'status' => __('assets::models/asset.terminal-status-irreversible'),
                ]);
            }

            if ($asset->isDirty('product_id')) {
                if ($asset->hasOperationalHistory(fresh: true)) {
                    throw ValidationException::withMessages([
                        'product_id' => __('assets::models/asset.product-immutable'),
                    ]);
                }

                $originalProduct = AssetProduct::withoutGlobalScopes()
                    ->withTrashed()
                    ->find($asset->getOriginal('product_id'));

                $newProduct = AssetProduct::withoutGlobalScopes()
                    ->withTrashed()
                    ->find($asset->product_id);

                if (
                    $originalProduct?->type !== $newProduct?->type
                    || $originalProduct?->tracking_mode !== $newProduct?->tracking_mode
                ) {
                    throw ValidationException::withMessages([
                        'product_id' => __('assets::models/asset.product-type-immutable'),
                    ]);
                }
            }

            if ($asset->isDirty('cost') && ! $asset->allowServiceCostAdjustment && $asset->hasOperationalHistory(fresh: true)) {
                throw ValidationException::withMessages([
                    'cost' => __('assets::models/asset.cost-immutable'),
                ]);
            }

            if ($asset->isDirty('initial_quantity') && $asset->hasOperationalHistory(fresh: true)) {
                throw ValidationException::withMessages([
                    'initial_quantity' => __('assets::models/asset.initial-quantity-immutable'),
                ]);
            }

            if ($asset->isDirty('company_id')) {
                $hasCustodyOrHistory = $asset->getOriginal('status') === AssetStatus::Held
                    || $asset->status === AssetStatus::Held
                    || AssetMovement::withoutGlobalScopes()
                        ->where('asset_id', $asset->id)
                        ->where('type', '!=', MovementType::Registered)
                        ->exists();

                if ($hasCustodyOrHistory) {
                    throw ValidationException::withMessages([
                        'company_id' => __('assets::models/asset.company.immutable'),
                    ]);
                }
            }

            if (filled($asset->company_id)) {
                CompanyConsistencyGuard::assert(
                    $asset->company_id,
                    [$asset->getAttributes()],
                    $asset->companyConsistentFields(),
                );
            }

            if ($asset->status === AssetStatus::Available) {
                if (blank($asset->warehouse_id) || ! AssetWarehouse::withoutGlobalScopes()->whereKey($asset->warehouse_id)->where('is_active', true)->whereNull('deleted_at')->exists()) {
                    throw ValidationException::withMessages([
                        'warehouse_id' => __('assets::services/asset-custody.warehouse-required'),
                    ]);
                }
            }

            if ($asset->isDirty('serial_no') && blank($asset->serial_no) && ! $asset->isQuantityTracked() && app(AssetSettings::class)->require_serial_by_default) {
                throw ValidationException::withMessages([
                    'serial_no' => __('assets::services/asset-custody.serial-required'),
                ]);
            }

            if ($asset->status === AssetStatus::Held) {
                app(AssetCustodyService::class)->validateHeldState($asset);

                if (app(AssetSettings::class)->require_expected_return_date && filled($asset->current_employee_id) && blank($asset->expected_return_at)) {
                    throw ValidationException::withMessages([
                        'expected_return_at' => __('assets::services/asset-custody.expected-return-required'),
                    ]);
                }
            }

            if ($asset->status !== AssetStatus::Available) {
                $asset->warehouse_id = null;
            }

            if ($asset->status !== AssetStatus::Held) {
                $asset->current_employee_id = null;
                $asset->current_site_id = null;
                $asset->issued_at = null;
                $asset->expected_return_at = null;
                $asset->custody_note = null;
            }
        });

        static::deleting(function (self $asset): void {
            if ($asset->status === AssetStatus::Held) {
                throw ValidationException::withMessages([
                    'asset' => __('assets::models/asset.deletion.held'),
                ]);
            }

            $hasHistory = AssetMovement::withoutGlobalScopes()
                ->where('asset_id', $asset->id)
                ->where('type', '!=', MovementType::Registered)
                ->exists();

            if ($asset->isForceDeleting()) {
                if ($hasHistory) {
                    throw ValidationException::withMessages([
                        'asset' => __('assets::models/asset.deletion.has-history'),
                    ]);
                }

                AssetMovement::withoutGlobalScopes()
                    ->where('asset_id', $asset->id)
                    ->where('type', MovementType::Registered)
                    ->delete();

                return;
            }

            if ($asset->status === AssetStatus::Available && $hasHistory) {
                throw ValidationException::withMessages([
                    'asset' => __('assets::models/asset.deletion.available-has-history'),
                ]);
            }
        });

        static::saved(function (self $asset): void {
            $asset->allowServiceCostAdjustment = false;
            $asset->cachedOperationalHistory = null;
        });
    }

    public static function formatQuantity(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $number = round((float) $value, 4);

        if (abs($number - round($number)) < 0.0001) {
            return (string) (int) round($number);
        }

        return rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
    }

    public function hasOperationalHistory(bool $fresh = false): bool
    {
        if (! $fresh && $this->cachedOperationalHistory !== null) {
            return $this->cachedOperationalHistory;
        }

        if (! $this->exists) {
            return $this->cachedOperationalHistory = false;
        }

        if (filled($this->parent_asset_id)) {
            return $this->cachedOperationalHistory = true;
        }

        $originalStatus = $this->getOriginal('status') ?? $this->status;
        $statusEnum = $originalStatus instanceof AssetStatus ? $originalStatus : AssetStatus::tryFrom((string) $originalStatus);

        if ($statusEnum !== null && $statusEnum !== AssetStatus::Available) {
            return $this->cachedOperationalHistory = true;
        }

        $hasHistory = AssetMovement::withoutGlobalScopes()
            ->where('asset_id', $this->id)
            ->where('type', '!=', MovementType::Registered)
            ->exists()
            || static::withoutGlobalScopes()
                ->withTrashed()
                ->where('parent_asset_id', $this->id)
                ->exists();

        return $this->cachedOperationalHistory = $hasHistory;
    }

    public function getNameAttribute(): ?string
    {
        return $this->product?->name;
    }

    public function getModelAttribute(): ?string
    {
        return $this->product?->model;
    }

    public function setInitialAttachmentsAttribute(mixed $value): void
    {
        $this->initialAttachments = is_array($value) ? array_values($value) : ($value ? (array) $value : null);
    }

    public function getCategoryAttribute(): ?string
    {
        return $this->product?->category?->full_name;
    }

    public function getCurrentHolderNameAttribute(): ?string
    {
        if ($this->status !== AssetStatus::Held) {
            return null;
        }

        $employee = $this->currentEmployee?->name;
        $site = $this->currentSite?->full_name ?? $this->currentSite?->name;

        if ($employee && $site) {
            return "{$employee} — {$site}";
        }

        return $employee ?: ($site ?: null);
    }

    public function getModelTitle(): string
    {
        return __('assets::models/asset.title');
    }

    public function companyConsistentFields(): array
    {
        return [
            'product_id'          => AssetProduct::class,
            'warehouse_id'        => AssetWarehouse::class,
            'current_employee_id' => Employee::class,
            'current_site_id'     => AssetSite::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(AssetProduct::class, 'product_id')->withTrashed();
    }

    public function parentAsset(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_asset_id')->withTrashed();
    }

    public function childAssets(): HasMany
    {
        return $this->hasMany(self::class, 'parent_asset_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(AssetWarehouse::class, 'warehouse_id')->withTrashed();
    }

    public function currentEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'current_employee_id')->withTrashed();
    }

    public function currentSite(): BelongsTo
    {
        return $this->belongsTo(AssetSite::class, 'current_site_id')->withTrashed();
    }

    public function movements(): HasMany
    {
        return $this->hasMany(AssetMovement::class, 'asset_id')->latest('occurred_at')->latest('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    protected static function newFactory(): AssetFactory
    {
        return AssetFactory::new();
    }
}
