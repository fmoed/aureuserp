<?php

namespace Webkul\Asset\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum AssetTrackingMode: string implements HasColor, HasDescription, HasIcon, HasLabel
{
    case Unit = 'unit';

    case Quantity = 'quantity';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unit     => __('assets::enums/asset-tracking-mode.unit'),
            self::Quantity => __('assets::enums/asset-tracking-mode.quantity'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Unit     => __('assets::enums/asset-tracking-mode.unit-description'),
            self::Quantity => __('assets::enums/asset-tracking-mode.quantity-description'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Unit     => 'primary',
            self::Quantity => 'info',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Unit     => 'heroicon-o-qr-code',
            self::Quantity => 'heroicon-o-squares-2x2',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Unit->value     => self::Unit->getLabel(),
            self::Quantity->value => self::Quantity->getLabel(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        return [
            self::Unit->value     => self::Unit->getDescription(),
            self::Quantity->value => self::Quantity->getDescription(),
        ];
    }
}
