<?php

namespace Webkul\Asset\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Asset\Enums\AssetProductType;
use Webkul\Asset\Enums\AssetTrackingMode;
use Webkul\Asset\Models\AssetCategory;
use Webkul\Asset\Models\AssetProduct;
use Webkul\Security\Models\User;
use Webkul\Support\Database\Factories\Concerns\HasCompanyDefault;

/**
 * @extends Factory<AssetProduct>
 */
class AssetProductFactory extends Factory
{
    use HasCompanyDefault;

    protected $model = AssetProduct::class;

    public function definition(): array
    {
        return [
            'category_id'   => fn () => AssetCategory::withoutGlobalScopes()->whereNull('company_id')->value('id')
                ?? AssetCategory::factory()->create(['company_id' => null])->id,
            'type'          => AssetProductType::Asset,
            'tracking_mode' => AssetTrackingMode::Unit,
            'name'          => fake()->unique()->words(3, true),
            'model'         => strtoupper(fake()->bothify('MDL-####')),
            'cost'          => fake()->randomFloat(2, 100, 5000),
            'is_active'     => true,
            'note'          => null,
            'creator_id'    => User::query()->value('id') ?? User::factory(),
        ];
    }

    public function consumable(): static
    {
        return $this->state(fn () => [
            'type'          => AssetProductType::Consumable,
            'tracking_mode' => AssetTrackingMode::Quantity,
        ]);
    }

    public function quantityTracked(): static
    {
        return $this->state(fn () => [
            'type'          => AssetProductType::Asset,
            'tracking_mode' => AssetTrackingMode::Quantity,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
