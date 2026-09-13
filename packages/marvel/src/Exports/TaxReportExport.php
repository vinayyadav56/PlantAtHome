<?php

namespace Marvel\Exports;

use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Settings;

/**
 * GST tax report — one row per taxed order line (plus a delivery row where the
 * delivery charge carried GST), built entirely from the immutable order + order_items
 * snapshot. This is the source a CA needs for GSTR-1: invoice no, date, place of
 * supply, HSN, taxable value, and the CGST/SGST or IGST split as it was charged.
 *
 * Never recomputes tax — historical figures are read verbatim from the snapshot.
 */
class TaxReportExport implements FromCollection, WithHeadings
{
    /** @var array{from?:string,to?:string,state?:string} */
    private array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function collection()
    {
        $results = [];

        $query = Order::query()
            ->whereNull('parent_id')
            ->whereNotNull('total_tax')
            ->with(['items', 'customer'])
            ->orderBy('created_at');

        // Shop scope is authoritative — set at mint time under the permission check
        // (see OrderController::exportTaxReportUrl). Null only for a super-admin, who
        // legitimately gets the company-wide GST report; every other caller is pinned
        // to the single shop they were authorized against, so this is not an IDOR.
        if (!empty($this->filters['shop_id'])) {
            $query->where('shop_id', $this->filters['shop_id']);
        }

        if (!empty($this->filters['from'])) {
            $query->whereDate('created_at', '>=', $this->filters['from']);
        }
        if (!empty($this->filters['to'])) {
            $query->whereDate('created_at', '<=', $this->filters['to']);
        }
        if (!empty($this->filters['state'])) {
            $query->where('place_of_supply', $this->filters['state']);
        }

        $orders = $query->get();
        if ($orders->isEmpty()) {
            return collect($results);
        }

        $settings = Settings::getData(request()['language'] ?? DEFAULT_LANGUAGE);
        $prefix = $settings->options['tax']['invoice_prefix'] ?? 'INV';

        foreach ($orders as $order) {
            $invoiceNo = ($prefix ? $prefix . '-' : '') . $order->tracking_number;
            $date = (new Carbon($order->created_at))->format('Y-m-d');
            $customer = $order?->customer?->name ?? $order->customer_name ?? 'Guest';
            $pos = $order->place_of_supply ?? '';
            $posCode = $order->place_of_supply_code ?? '';
            $interState = $order->is_inter_state ? 'Inter-state' : 'Intra-state';

            foreach ($order->items as $item) {
                if ((float) ($item->taxable_value ?? 0) <= 0 && (float) ($item->tax_amount ?? 0) <= 0) {
                    continue;
                }
                $results[] = [
                    'invoice_no'   => $invoiceNo,
                    'date'         => $date,
                    'customer'     => $customer,
                    'place'        => $pos,
                    'state_code'   => $posCode,
                    'supply_type'  => $interState,
                    'item'         => $item->product?->name ?? ('#' . $item->product_id),
                    'hsn'          => $item->hsn_code ?? '',
                    'gst_rate'     => rtrim(rtrim(number_format((float) ($item->tax_rate ?? 0), 2, '.', ''), '0'), '.') . '%',
                    'qty'          => $item->order_quantity,
                    'taxable'      => number_format((float) ($item->taxable_value ?? 0), 2, '.', ''),
                    'cgst'         => number_format((float) ($item->cgst_amount ?? 0), 2, '.', ''),
                    'sgst'         => number_format((float) ($item->sgst_amount ?? 0), 2, '.', ''),
                    'igst'         => number_format((float) ($item->igst_amount ?? 0), 2, '.', ''),
                    'line_tax'     => number_format((float) ($item->tax_amount ?? 0), 2, '.', ''),
                ];
            }

            // Delivery, when it carried GST (follow-principal / separate treatments).
            if ((float) ($order->delivery_tax_amount ?? 0) > 0) {
                $delTax = (float) $order->delivery_tax_amount;
                $delTaxable = (float) ($order->delivery_taxable ?? 0);
                $results[] = [
                    'invoice_no'   => $invoiceNo,
                    'date'         => $date,
                    'customer'     => $customer,
                    'place'        => $pos,
                    'state_code'   => $posCode,
                    'supply_type'  => $interState,
                    'item'         => 'Delivery charge',
                    'hsn'          => '',
                    'gst_rate'     => '',
                    'qty'          => 1,
                    'taxable'      => number_format($delTaxable, 2, '.', ''),
                    'cgst'         => number_format($order->is_inter_state ? 0 : $delTax / 2, 2, '.', ''),
                    'sgst'         => number_format($order->is_inter_state ? 0 : $delTax / 2, 2, '.', ''),
                    'igst'         => number_format($order->is_inter_state ? $delTax : 0, 2, '.', ''),
                    'line_tax'     => number_format($delTax, 2, '.', ''),
                ];
            }
        }

        return collect($results);
    }

    public function headings(): array
    {
        return [
            'Invoice No',
            'Date',
            'Customer',
            'Place of Supply',
            'State Code',
            'Supply Type',
            'Item',
            'HSN/SAC',
            'GST Rate',
            'Qty',
            'Taxable Value',
            'CGST',
            'SGST',
            'IGST',
            'Total Tax',
        ];
    }
}
