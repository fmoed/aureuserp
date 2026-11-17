<?php

namespace Webkul\Asset\Filament\Resources\AssetResource\Actions;

use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Webkul\Asset\Models\Asset;
use Webkul\Support\SupportServiceProvider;

class PrintAssetLabelsAction
{
    public static function make(): Action
    {
        return Action::make('printLabels')
            ->label(__('assets::filament/resources/asset.actions.print-labels.label'))
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->authorize(fn (Asset $record): bool => (bool) auth()->user()?->can('view', $record))
            ->modalHeading(__('assets::filament/resources/asset.actions.print-labels.modal-heading'))
            ->modalSubmitActionLabel(__('assets::filament/resources/asset.actions.print-labels.submit'))
            ->schema([
                TextInput::make('copies')
                    ->label(__('assets::filament/resources/asset.actions.print-labels.fields.copies'))
                    ->helperText(__('assets::filament/resources/asset.actions.print-labels.fields.copies-help'))
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(200)
                    ->default(fn (Asset $record): int => $record->isQuantityTracked()
                        ? min(50, max(1, (int) $record->quantity))
                        : 1)
                    ->required(),
            ])
            ->action(function (Asset $record, array $data): StreamedResponse {
                $record->loadMissing(['product', 'company']);

                $copies = max(1, min(200, (int) ($data['copies'] ?? 1)));
                $labels = static::buildLabelsPayload(collect([$record]), $copies);
                $safeTag = str_replace(['/', '\\'], '-', (string) ($record->asset_tag ?: $record->id));

                return static::streamLabelsPdf($labels, "Asset-Labels-{$safeTag}.pdf");
            });
    }

    public static function makeBulk(): BulkAction
    {
        return BulkAction::make('printLabels')
            ->label(__('assets::filament/resources/asset.actions.print-labels.bulk-label'))
            ->icon('heroicon-o-printer')
            ->authorize(fn (): bool => (bool) auth()->user()?->can('viewAny', Asset::class))
            ->action(function (Collection $records): StreamedResponse {
                if ($records instanceof EloquentCollection) {
                    $records->loadMissing(['product', 'company']);
                }

                $labels = static::buildLabelsPayload($records, 1);

                return static::streamLabelsPdf($labels, 'Asset-Labels.pdf');
            });
    }

    /**
     * Expand asset records into flat label payloads ready for PDF rendering.
     *
     * @param  Collection<int, Asset>  $records
     * @return Collection<int, array<string, ?string>>
     */
    protected static function buildLabelsPayload(Collection $records, int $copies = 1): Collection
    {
        return $records->flatMap(function (Asset $record) use ($copies): array {
            $payload = [
                'company_name' => $record->company?->name,
                'product_name' => $record->product?->name ?? '',
                'model'        => $record->product?->model,
                'asset_tag'    => $record->asset_tag,
                'serial_no'    => $record->serial_no,
            ];

            return array_fill(0, $copies, $payload);
        })->values();
    }

    /**
     * Render and stream the A4 barcode labels PDF.
     *
     * @param  Collection<int, array<string, ?string>>  $labels
     */
    protected static function streamLabelsPdf(Collection $labels, string $filename): StreamedResponse
    {
        $html = view('assets::filament.resources.assets.actions.print-labels', [
            'labels' => $labels,
        ])->render();

        $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');

        if (SupportServiceProvider::isRtl()) {
            $html = prepare_rtl_html($html);
        }

        $pdf = Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('isHtml5ParserEnabled', true);

        return response()->streamDownload(function () use ($pdf): void {
            echo $pdf->output();
        }, $filename);
    }
}
