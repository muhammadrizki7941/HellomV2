<?php

namespace App\Services\Pos;

use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class PosReportExcelExporter
{
    private const CURRENCY_FORMAT = '[$Rp-421] #,##0';

    public function export(Organization $organization, Carbon $startDate, Carbon $endDate, Collection $orders): array
    {
        $directory = storage_path('app/exports');
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = sprintf(
            'Laporan-Keuangan-%s-%s-sampai-%s.xlsx',
            Str::slug($organization->name),
            $startDate->format('d-m-Y'),
            $endDate->format('d-m-Y')
        );

        $filePath = $directory . DIRECTORY_SEPARATOR . now()->format('YmdHis') . '-' . $filename;

        $writer = new Writer();
        $writer->openToFile($filePath);

        $this->writeSummarySheet($writer, $organization, $startDate, $endDate, $orders);
        $this->writeTransactionsSheet($writer, $orders);
        $this->writeProductsSheet($writer, $orders);

        $writer->close();

        return [
            'path' => $filePath,
            'filename' => $filename,
        ];
    }

    private function writeSummarySheet(Writer $writer, Organization $organization, Carbon $startDate, Carbon $endDate, Collection $orders): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Ringkasan');
        $sheet->setColumnWidth(28, 1);
        $sheet->setColumnWidth(22, 2, 3);

        $totalRevenue = (int) $orders->sum('total_amount');
        $totalOrders = $orders->count();
        $totalItems = (int) $orders->flatMap->items->sum('qty');
        $averageOrder = $totalOrders > 0 ? (int) round($totalRevenue / $totalOrders) : 0;

        $paymentSummary = $orders
            ->groupBy(fn ($order) => $order->payment_method ?: 'Tidak diketahui')
            ->map(fn (Collection $group, string $paymentMethod) => [
                'payment_method' => Str::headline(str_replace('_', ' ', $paymentMethod)),
                'count' => $group->count(),
                'total' => (int) $group->sum('total_amount'),
            ])
            ->sortByDesc('total')
            ->values();

        $serviceSummary = $orders
            ->groupBy(fn ($order) => $order->service_type ?: 'unknown')
            ->map(fn (Collection $group, string $serviceType) => [
                'service_type' => $serviceType === 'dine_in' ? 'Dine In' : 'Take Away',
                'count' => $group->count(),
                'total' => (int) $group->sum('total_amount'),
            ])
            ->sortByDesc('total')
            ->values();

        $dailySummary = $orders
            ->groupBy(fn ($order) => $order->created_at->format('Y-m-d'))
            ->map(fn (Collection $group, string $date) => [
                'date' => Carbon::parse($date)->format('d/m/Y'),
                'orders' => $group->count(),
                'items' => (int) $group->flatMap->items->sum('qty'),
                'total' => (int) $group->sum('total_amount'),
            ])
            ->sortBy('date')
            ->values();

        $writer->addRows([
            $this->row(['LAPORAN KEUANGAN POS'], $this->titleStyle()),
            $this->row(["Organisasi: {$organization->name}"], $this->subtitleStyle()),
            $this->row(['Periode', $startDate->format('d/m/Y') . ' - ' . $endDate->format('d/m/Y')], null, [0 => $this->labelStyle()]),
            $this->row(['Diekspor pada', now()->format('d/m/Y H:i')], null, [0 => $this->labelStyle()]),
            Row::fromValues([]),
            $this->row(['Ringkasan Utama'], $this->sectionStyle()),
            $this->row(['Metrik', 'Nilai'], $this->headerStyle(), [1 => $this->headerRightStyle()]),
            $this->row(['Total Pendapatan', $totalRevenue], null, [0 => $this->labelStyle(), 1 => $this->currencyStyle()]),
            $this->row(['Total Completed Orders', $totalOrders], null, [0 => $this->labelStyle(), 1 => $this->numberStyle()]),
            $this->row(['Total Item Terjual', $totalItems], null, [0 => $this->labelStyle(), 1 => $this->numberStyle()]),
            $this->row(['Average per Order', $averageOrder], null, [0 => $this->labelStyle(), 1 => $this->currencyStyle()]),
            Row::fromValues([]),
            $this->row(['Ringkasan Metode Pembayaran'], $this->sectionStyle()),
            $this->row(['Metode', 'Jumlah Transaksi', 'Total Pendapatan'], $this->headerStyle(), [1 => $this->headerRightStyle(), 2 => $this->headerRightStyle()]),
        ]);

        foreach ($paymentSummary as $row) {
            $writer->addRow($this->row(
                [$row['payment_method'], $row['count'], $row['total']],
                null,
                [1 => $this->numberStyle(), 2 => $this->currencyStyle()]
            ));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow($this->row(['Ringkasan Tipe Layanan'], $this->sectionStyle()));
        $writer->addRow($this->row(['Tipe Layanan', 'Jumlah Transaksi', 'Total Pendapatan'], $this->headerStyle(), [1 => $this->headerRightStyle(), 2 => $this->headerRightStyle()]));

        foreach ($serviceSummary as $row) {
            $writer->addRow($this->row(
                [$row['service_type'], $row['count'], $row['total']],
                null,
                [1 => $this->numberStyle(), 2 => $this->currencyStyle()]
            ));
        }

        $writer->addRow(Row::fromValues([]));
        $writer->addRow($this->row(['Rekap Harian'], $this->sectionStyle()));
        $writer->addRow($this->row(['Date', 'Order Count', 'Items Sold', 'Revenue'], $this->headerStyle(), [1 => $this->headerRightStyle(), 2 => $this->headerRightStyle(), 3 => $this->headerRightStyle()]));

        foreach ($dailySummary as $row) {
            $writer->addRow($this->row(
                [$row['date'], $row['orders'], $row['items'], $row['total']],
                null,
                [1 => $this->numberStyle(), 2 => $this->numberStyle(), 3 => $this->currencyStyle()]
            ));
        }
    }

    private function writeTransactionsSheet(Writer $writer, Collection $orders): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Transaksi');
        $sheet->setColumnWidth(18, 1);
        $sheet->setColumnWidth(14, 2, 3);
        $sheet->setColumnWidth(20, 4, 5, 6, 7);
        $sheet->setColumnWidth(16, 8);
        $sheet->setColumnWidth(52, 9);
        $sheet->setColumnWidth(16, 10);

        $writer->addRow($this->row(['DETAIL TRANSAKSI'], $this->titleStyle()));
        $writer->addRow($this->row([
            'Order No.',
            'Tanggal',
            'Waktu',
            'Pelanggan',
            'Meja',
            'Tipe Layanan',
            'Metode Bayar',
            'Jumlah Item',
            'Rincian Item',
            'Total',
        ], $this->headerStyle(), [
            7 => $this->headerRightStyle(),
            9 => $this->headerRightStyle(),
        ]));

        foreach ($orders as $order) {
            $itemSummary = $order->items
                ->map(fn ($item) => $item->product_name . ' x' . $item->qty)
                ->implode(', ');

            $writer->addRow($this->row([
                $order->order_number,
                $order->created_at->format('d/m/Y'),
                $order->created_at->format('H:i'),
                $order->customer_name ?: 'Walk-in',
                $order->table_label ?: $order->table?->name ?: '-',
                $order->service_type === 'dine_in' ? 'Dine In' : 'Take Away',
                Str::headline(str_replace('_', ' ', $order->payment_method ?: 'Tidak diketahui')),
                (int) $order->items->sum('qty'),
                $itemSummary,
                (int) $order->total_amount,
            ], null, [
                7 => $this->numberStyle(),
                8 => $this->wrappedTextStyle(),
                9 => $this->currencyStyle(),
            ]));
        }
    }

    private function writeProductsSheet(Writer $writer, Collection $orders): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Penjualan Produk');
        $sheet->setColumnWidth(32, 1);
        $sheet->setColumnWidth(16, 2, 3, 4);
        $sheet->setColumnWidth(18, 5);

        $productSummary = $orders
            ->flatMap->items
            ->groupBy('product_name')
            ->map(function (Collection $items, string $productName) {
                $quantity = (int) $items->sum('qty');
                $revenue = (int) $items->sum('line_total');
                $averageUnitPrice = $quantity > 0 ? (int) round($revenue / $quantity) : 0;

                return [
                    'product_name' => $productName,
                    'qty' => $quantity,
                    'average_unit_price' => $averageUnitPrice,
                    'revenue' => $revenue,
                    'order_count' => $items->pluck('order_id')->unique()->count(),
                ];
            })
            ->sortByDesc('revenue')
            ->values();

        $writer->addRow($this->row(['REKAP PENJUALAN PRODUK'], $this->titleStyle()));
        $writer->addRow($this->row([
            'Nama Produk',
            'Qty Terjual',
            'Rata-rata Harga',
            'Total Pendapatan',
            'Jumlah Order',
        ], $this->headerStyle(), [
            1 => $this->headerRightStyle(),
            2 => $this->headerRightStyle(),
            3 => $this->headerRightStyle(),
            4 => $this->headerRightStyle(),
        ]));

        foreach ($productSummary as $row) {
            $writer->addRow($this->row([
                $row['product_name'],
                $row['qty'],
                $row['average_unit_price'],
                $row['revenue'],
                $row['order_count'],
            ], null, [
                1 => $this->numberStyle(),
                2 => $this->currencyStyle(),
                3 => $this->currencyStyle(),
                4 => $this->numberStyle(),
            ]));
        }
    }

    private function row(array $values, ?Style $rowStyle = null, array $columnStyles = []): Row
    {
        return Row::fromValuesWithStyles($values, $rowStyle, $columnStyles);
    }

    private function titleStyle(): Style
    {
        return (new Style())
            ->setFontBold()
            ->setFontSize(14)
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor(Color::DARK_BLUE)
            ->setCellAlignment(CellAlignment::LEFT)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER);
    }

    private function subtitleStyle(): Style
    {
        return (new Style())
            ->setFontBold()
            ->setFontColor(Color::DARK_BLUE)
            ->setFontSize(12);
    }

    private function sectionStyle(): Style
    {
        return (new Style())
            ->setFontBold()
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor(Color::BLUE);
    }

    private function headerStyle(): Style
    {
        return (new Style())
            ->setFontBold()
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor(Color::rgb(31, 78, 121))
            ->setBorder($this->tableBorder())
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER);
    }

    private function headerRightStyle(): Style
    {
        return (new Style())
            ->setFontBold()
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor(Color::rgb(31, 78, 121))
            ->setBorder($this->tableBorder())
            ->setCellAlignment(CellAlignment::RIGHT)
            ->setCellVerticalAlignment(CellVerticalAlignment::CENTER);
    }

    private function labelStyle(): Style
    {
        return (new Style())
            ->setFontBold()
            ->setBorder($this->tableBorder());
    }

    private function numberStyle(): Style
    {
        return (new Style())
            ->setFormat('#,##0')
            ->setCellAlignment(CellAlignment::RIGHT)
            ->setBorder($this->tableBorder());
    }

    private function currencyStyle(): Style
    {
        return (new Style())
            ->setFormat(self::CURRENCY_FORMAT)
            ->setCellAlignment(CellAlignment::RIGHT)
            ->setBorder($this->tableBorder());
    }

    private function wrappedTextStyle(): Style
    {
        return (new Style())
            ->setShouldWrapText()
            ->setBorder($this->tableBorder())
            ->setCellVerticalAlignment(CellVerticalAlignment::TOP);
    }

    private function tableBorder(): Border
    {
        return new Border(
            new BorderPart(Border::BOTTOM, Color::rgb(217, 217, 217), Border::WIDTH_THIN),
            new BorderPart(Border::TOP, Color::rgb(217, 217, 217), Border::WIDTH_THIN),
            new BorderPart(Border::LEFT, Color::rgb(217, 217, 217), Border::WIDTH_THIN),
            new BorderPart(Border::RIGHT, Color::rgb(217, 217, 217), Border::WIDTH_THIN),
        );
    }
}
