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

    public function export(
        Organization $organization,
        Carbon $startDate,
        Carbon $endDate,
        Collection $orders,
        string $scope = 'Outlet Aktif',
        string $dateBasis = 'paid_at',
        string $timezone = 'UTC',
        ?Collection $shifts = null
    ): array
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

        $this->writeSummarySheet($writer, $organization, $startDate, $endDate, $orders, $scope, $dateBasis, $timezone);
        $this->writeTransactionsSheet($writer, $orders, $timezone, $dateBasis);
        $this->writeProductsSheet($writer, $orders);
        $this->writeShiftsSheet($writer, $shifts ?? collect(), $timezone);

        $writer->close();

        return [
            'path' => $filePath,
            'filename' => $filename,
        ];
    }

    private function writeSummarySheet(
        Writer $writer,
        Organization $organization,
        Carbon $startDate,
        Carbon $endDate,
        Collection $orders,
        string $scope,
        string $dateBasis,
        string $timezone
    ): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Ringkasan');
        $sheet->setColumnWidth(28, 1);
        $sheet->setColumnWidth(22, 2, 3);

        $collected = fn ($order) => (int) ($order->final_amount ?: $order->total_amount);
        $netCollected = fn ($order) => $collected($order) - (int) $order->refund_amount;
        $totalRevenue = (int) $orders->sum($netCollected);
        $grossSales = (int) $orders->sum(fn ($order) => (int) ($order->subtotal_amount ?: $order->total_amount));
        $discounts = (int) $orders->sum(fn ($order) => (int) $order->discount_amount + (int) $order->points_discount_amount + (int) $order->manual_discount_amount);
        $refunds = (int) $orders->sum('refund_amount');
        $serviceTotal = (int) $orders->sum('service_amount');
        $taxTotal = (int) $orders->sum('tax_amount');
        $roundingTotal = (int) $orders->sum('rounding_amount');
        $totalOrders = $orders->where('payment_status', 'paid')->count();
        $paidOrders = $orders->where('payment_status', 'paid');
        $totalItems = (int) $paidOrders->flatMap->items->sum('qty');
        $averageOrder = $totalOrders > 0 ? (int) round($totalRevenue / $totalOrders) : 0;

        $paymentSummary = $orders
            ->groupBy(fn ($order) => $order->payment_method ?: 'Tidak diketahui')
            ->map(fn (Collection $group, string $paymentMethod) => [
                'payment_method' => Str::headline(str_replace('_', ' ', $paymentMethod)),
                'count' => $group->count(),
                'total' => (int) $group->sum($netCollected),
            ])
            ->sortByDesc('total')
            ->values();

        $serviceSummary = $orders
            ->groupBy(fn ($order) => $order->service_type ?: 'unknown')
            ->map(fn (Collection $group, string $serviceType) => [
                'service_type' => $serviceType === 'dine_in' ? 'Dine In' : 'Take Away',
                'count' => $group->count(),
                'total' => (int) $group->sum($netCollected),
            ])
            ->sortByDesc('total')
            ->values();

        $dailySummary = $orders
            ->groupBy(fn ($order) => ($dateBasis === 'created_at' ? $order->created_at : ($order->paid_at ?? $order->created_at))->copy()->setTimezone($timezone)->format('Y-m-d'))
            ->map(fn (Collection $group, string $date) => [
                'date' => Carbon::parse($date)->format('d/m/Y'),
                'orders' => $group->where('payment_status', 'paid')->count(),
                'items' => (int) $group->where('payment_status', 'paid')->flatMap->items->sum('qty'),
                'total' => (int) $group->sum($netCollected),
            ])
            ->sortBy('date')
            ->values();

        $writer->addRows([
            $this->row(['LAPORAN KEUANGAN POS'], $this->titleStyle()),
            $this->row(["Organisasi: {$organization->name}"], $this->subtitleStyle()),
            $this->row(['Periode', $startDate->format('d/m/Y') . ' - ' . $endDate->format('d/m/Y')], null, [0 => $this->labelStyle()]),
            $this->row(['Cakupan / dasar tanggal', $scope . ' / ' . ($dateBasis === 'paid_at' ? 'Tanggal pembayaran' : 'Tanggal order') . " ({$timezone})"], null, [0 => $this->labelStyle()]),
            $this->row(['Diekspor pada', now()->format('d/m/Y H:i')], null, [0 => $this->labelStyle()]),
            Row::fromValues([]),
            $this->row(['Ringkasan Utama'], $this->sectionStyle()),
            $this->row(['Metrik', 'Nilai'], $this->headerStyle(), [1 => $this->headerRightStyle()]),
            $this->row(['Penjualan kotor sebelum diskon', $grossSales], null, [0 => $this->labelStyle(), 1 => $this->currencyStyle()]),
            $this->row(['Diskon total', $discounts], null, [0 => $this->labelStyle(), 1 => $this->currencyStyle()]),
            $this->row(['Penjualan setelah diskon', max(0, $grossSales - $discounts)], null, [0 => $this->labelStyle(), 1 => $this->currencyStyle()]),
            $this->row(['Service / pajak / pembulatan', $serviceTotal + $taxTotal + $roundingTotal], null, [0 => $this->labelStyle(), 1 => $this->currencyStyle()]),
            $this->row(['Refund', $refunds], null, [0 => $this->labelStyle(), 1 => $this->currencyStyle()]),
            $this->row(['Pendapatan bersih diterima', $totalRevenue], null, [0 => $this->labelStyle(), 1 => $this->currencyStyle()]),
            $this->row(['Pesanan dibayar', $totalOrders], null, [0 => $this->labelStyle(), 1 => $this->numberStyle()]),
            $this->row(['Total Item Terjual', $totalItems], null, [0 => $this->labelStyle(), 1 => $this->numberStyle()]),
            $this->row(['Rata-rata per pesanan dibayar', $averageOrder], null, [0 => $this->labelStyle(), 1 => $this->currencyStyle()]),
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

    private function writeTransactionsSheet(Writer $writer, Collection $orders, string $timezone, string $dateBasis): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Transaksi');
        $sheet->setColumnWidth(18, 1);
        $sheet->setColumnWidth(14, 2, 3);
        $sheet->setColumnWidth(20, 4, 5, 6, 7);
        $sheet->setColumnWidth(16, 8);
        $sheet->setColumnWidth(52, 9);
        $sheet->setColumnWidth(16, 10, 11, 12);

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
            'Total Tagihan',
            'Refund',
            'Diterima Bersih',
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
                ($dateBasis === 'created_at' ? $order->created_at : ($order->paid_at ?? $order->created_at))->copy()->setTimezone($timezone)->format('d/m/Y'),
                ($dateBasis === 'created_at' ? $order->created_at : ($order->paid_at ?? $order->created_at))->copy()->setTimezone($timezone)->format('H:i'),
                $order->customer_name ?: 'Walk-in',
                $order->table_label ?: $order->table?->name ?: '-',
                $order->service_type === 'dine_in' ? 'Dine In' : 'Take Away',
                Str::headline(str_replace('_', ' ', $order->payment_method ?: 'Tidak diketahui')),
                (int) $order->items->sum('qty'),
                $itemSummary,
                (int) ($order->final_amount ?: $order->total_amount),
                (int) $order->refund_amount,
                (int) ($order->final_amount ?: $order->total_amount) - (int) $order->refund_amount,
            ], null, [
                7 => $this->numberStyle(),
                8 => $this->wrappedTextStyle(),
                9 => $this->currencyStyle(),
                10 => $this->currencyStyle(),
                11 => $this->currencyStyle(),
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
            ->where('payment_status', 'paid')
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
            'Penjualan Item Kotor',
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

    private function writeShiftsSheet(Writer $writer, Collection $shifts, string $timezone): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Tutup Shift');
        $sheet->setColumnWidth(24, 1, 2, 3);
        $sheet->setColumnWidth(18, 4, 5, 6, 7, 8, 9);
        $sheet->setColumnWidth(38, 10);

        $writer->addRow($this->row(['REKONSILIASI TUTUP SHIFT'], $this->titleStyle()));
        $writer->addRow($this->row([
            'Kasir',
            'Outlet',
            'Shift',
            'Status',
            'Kas Awal',
            'Penjualan Tunai',
            'Kas Seharusnya',
            'Kas Dihitung',
            'Selisih',
            'Mulai / Tutup / Catatan',
        ], $this->headerStyle()));

        foreach ($shifts as $shift) {
            $startedAt = $shift['started_at']?->copy()->setTimezone($timezone)->format('d/m/Y H:i') ?? '-';
            $closedAt = $shift['closed_at']?->copy()->setTimezone($timezone)->format('d/m/Y H:i') ?? 'Belum ditutup';
            $notes = trim((string) ($shift['notes'] ?? ''));
            $writer->addRow($this->row([
                $shift['staff_name'],
                $shift['outlet_name'],
                $shift['shift_title'],
                $shift['status'] === 'closed' ? 'Ditutup' : 'Terbuka',
                $shift['opening_cash'],
                $shift['cash_sales'],
                $shift['expected_cash'],
                $shift['closing_cash'],
                $shift['difference_cash'],
                "{$startedAt} / {$closedAt}" . ($notes !== '' ? " / {$notes}" : ''),
            ], null, [
                4 => $this->currencyStyle(),
                5 => $this->currencyStyle(),
                6 => $shift['expected_cash'] === null ? $this->numberStyle() : $this->currencyStyle(),
                7 => $shift['closing_cash'] === null ? $this->numberStyle() : $this->currencyStyle(),
                8 => $shift['difference_cash'] === null ? $this->numberStyle() : $this->currencyStyle(),
                9 => $this->wrappedTextStyle(),
            ]));
        }

        if ($shifts->isEmpty()) {
            $writer->addRow($this->row(['Tidak ada catatan shift pada periode ini.']));
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
