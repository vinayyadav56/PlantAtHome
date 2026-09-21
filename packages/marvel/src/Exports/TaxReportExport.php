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
            // The number actually printed on the invoice. Falls back to the old
            // tracking-number form for orders raised before invoice numbering, so
            // the return still matches the document the customer holds.
            $invoiceNo = $order->invoice_number ?: (($prefix ? $prefix . '-' : '') . $order->tracking_number);
            $date = (new Carbon($order->invoice_date ?? $order->created_at))->format('Y-m-d');
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

        // Credit notes (refunds) as NEGATIVE rows — GSTR-1 needs them netted against the invoices.
        if (\Illuminate\Support\Facades\Schema::hasTable('credit_notes')) {
            $cn = \Illuminate\Support\Facades\DB::table('credit_notes as cn')->join('orders as o', 'o.id', '=', 'cn.order_id')->whereNotNull('cn.journal_entry_id');
            if (!empty($this->filters['shop_id'])) { $cn->where('o.shop_id', $this->filters['shop_id']); }
            if (!empty($this->filters['from'])) { $cn->whereDate('cn.issue_date', '>=', $this->filters['from']); }
            if (!empty($this->filters['to'])) { $cn->whereDate('cn.issue_date', '<=', $this->filters['to']); }
            if (!empty($this->filters['state'])) { $cn->where('o.place_of_supply', $this->filters['state']); }
            foreach ($cn->orderBy('cn.issue_date')->get(['cn.*', 'o.tracking_number', 'o.place_of_supply', 'o.place_of_supply_code', 'o.is_inter_state', 'o.customer_id']) as $note) {
                $lines = json_decode((string) $note->lines, true) ?: [];
                $neg = fn ($v) => (float) $v == 0.0 ? '0.00' : number_format(-1 * (float) $v, 2, '.', '');
                foreach ($lines as $l) {
                    $results[] = [
                        'invoice_no' => $note->number, 'date' => substr((string) $note->issue_date, 0, 10), 'customer' => 'Credit note against ' . ($prefix ? $prefix . '-' : '') . $note->tracking_number,
                        'place' => $note->place_of_supply ?? '', 'state_code' => $note->place_of_supply_code ?? '', 'supply_type' => $note->is_inter_state ? 'Inter-state' : 'Intra-state',
                        'item' => $l['label'] ?? ('Refund line #' . ($l['order_item_id'] ?? '')), 'hsn' => $l['hsn'] ?? '',
                        'gst_rate' => isset($l['rate']) ? rtrim(rtrim(number_format((float) $l['rate'], 2, '.', ''), '0'), '.') . '%' : '', 'qty' => -1 * (int) ($l['qty'] ?? 1),
                        'taxable' => $neg($l['taxable'] ?? 0), 'cgst' => $neg($l['cgst'] ?? 0), 'sgst' => $neg($l['sgst'] ?? 0), 'igst' => $neg($l['igst'] ?? 0),
                        'line_tax' => $neg((float) ($l['cgst'] ?? 0) + (float) ($l['sgst'] ?? 0) + (float) ($l['igst'] ?? 0)),
                    ];
                }
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
