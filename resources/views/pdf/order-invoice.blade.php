<!DOCTYPE html>
<html>

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Order Invoice</title>
    <style type="text/css">
        body {
            font-family: Arial, sans-serif;
        }
    </style>
    @if ($language === 'bd')
        <style type="text/css">
            body {
                font-family: Arial, DejaVu Sans, sans-serif, 'bangla';
            }
        </style>
    @endif
    <style type="text/css">
        body {
            font-family: Arial, DejaVu Sans, sans-serif, 'bangla';
        }
    </style>
</head>

<body>
    @php
        $contactDetails = $settings->options['contactDetails'];
        $customer = $order->customer;
        $shippingAddress = $order->shipping_address;
        $products = $order->products;
        $settings = $settings->options;
        $authorDetails = $settings['contactDetails'];
        $authorLocation = $authorDetails['location'];
        $currency = isset($settings['currency']) ? $settings['currency'] : 'USD';
        $currencyOptions = isset($settings['currencyOptions']) ? $settings['currencyOptions'] : ['formation' => 'en-US', 'fractions' => 2];
        $locale = $currencyOptions['formation'] ?? 'en-US';

        // GST tax invoice: render the compliant layout only when the order carries a tax
        // snapshot (new orders). Legacy orders keep total_tax null and fall back to the
        // simple layout below.
        $taxConfig = $settings['tax'] ?? [];
        $hasGst = !is_null($order->total_tax);
        $isInter = (bool) ($order->is_inter_state ?? false);
        $sellerGstin = $order->seller_gstin ?? ($taxConfig['gstin'] ?? null);
        $sellerLegalName = $taxConfig['legal_name'] ?? ($settings['siteTitle'] ?? null);
        $sellerState = $order->seller_state ?? ($taxConfig['registration_state'] ?? null);
        $placeOfSupply = $order->place_of_supply ?? ($shippingAddress['state'] ?? null);
        $invoiceNumber = $order->invoice_number
            ?: (($hasGst && !empty($taxConfig['invoice_prefix'])) ? $taxConfig['invoice_prefix'] . '-' : '') . $order->tracking_number;
        // The date the invoice was RAISED, not today — reprinting last quarter's
        // invoice used to silently re-date it to the day it was reprinted.
        $invoiceDate = $order->invoice_date ?? $order->created_at;
        $gstItems = $hasGst ? $order->items : collect();

        $amountDue = $order->payment_status !== 'payment-success' ? $order->paid_total - intval($order?->wallet_point?->amount) : 0;

        if ($order->order_status === 'order-completed') {
            $amountDue = 0;
        }

        if ($order->parent_id) {
            $parentOrder = $order->parent_order;
        } else {
            $parentOrder = $order;
        }
        $cancelled_products = [];
        foreach ($parentOrder->children as $childOrder) {
            if ($childOrder->order_status == 'order-cancelled') {
                foreach ($childOrder->products as $product) {
                    $cancelled_products[] = $product->id;
                }
            }
        }
        
    @endphp

    <div style="display: block;">
        <div style="width: 50%; {{ $is_rtl ? ' direction: ltr;' : 'float: left;' }} float: left;">
            @if ($hasGst)
                <p style="font-size: 20px; font-weight: bold; margin: 0 0 8px 0;">TAX INVOICE</p>
            @endif
            @if (isset($translated_text['invoice_no']) || isset($order->tracking_number))
                {{-- A real invoice number once one has been minted; orders placed
                     before invoice numbering keep the old tracking-number form so
                     a reprint still matches what the customer was sent. --}}
                <p>{{ $translated_text['invoice_no'] }}:
                    {{ $invoiceNumber }}</p>
            @endif
            @if ($hasGst)
                <p style="margin: 2px 0;">Order ref: {{ $order->tracking_number }}</p>
            @endif
            @if (isset($translated_text['delivery_time']) || isset($order->delivery_time))
                <p>{{ isset($translated_text['payment_method']) ? $translated_text['payment_method'] : 'Payment Method' }}:
                    {{ $order->payment_gateway }}</p>
            @endif
            @if ($hasGst && $placeOfSupply)
                <p style="margin: 2px 0;">Place of supply: {{ $placeOfSupply }}{{ $order->place_of_supply_code ? ' (' . $order->place_of_supply_code . ')' : '' }}</p>
            @endif
        </div>
        <div
            style="width: 49%; {{ $is_rtl
                ? ' direction: rtl; float: left; margin-left: 10px;'
                : 'float: right; text-align: right; margin-right: 5px;' }}">
            @if (isset($translated_text['date']))
                <p>{{ $translated_text['date'] }}: {{ $invoiceDate ? \Illuminate\Support\Carbon::parse($invoiceDate)->format('jS F, Y') : date('jS F, Y') }}</p>
            @endif
        </div>
        <div style="clear: both;"></div>
    </div>

    <div style="height: 30px;"></div>

    <div style="display: block;">
        <ul
            style="width: 50%; {{ $is_rtl ? ' direction: rtl; float: right;' : 'float: left;' }} list-style: none;
            margin: 0; padding: 0;">
            @if (isset($customer['name']))
                <li style="display: block; font-size: 18px; font-weight:bold;">
                    <div style="margin-bottom: 10px">{{ $customer['name'] }}</div>
                </li>
            @endif

            @if (isset($customer['email']))
                <li style="display: block; color: #6f6f6f; font-size:14px;">
                    <div style="margin-bottom: 5x">{{ $customer['email'] }}</div>
                </li>
            @endif

            @if (isset($order->customer_contact))
                <li style="display: block; color: #6f6f6f; font-size:14px;">
                    <div style="margin-bottom: 5x">{{ $order->customer_contact }}</div>
                </li>
            @endif

            <li>
                <ul style="list-style: none; margin: 0; padding: 0;">
                    @if (isset($shippingAddress['street_address']))
                        <li style="display: block; color: #6f6f6f; font-size:14px;">
                            {{ $shippingAddress['street_address'] }}
                        </li>
                    @endif

                    @if (isset($shippingAddress['city']))
                        <li style="display: block; color: #6f6f6f; font-size:14px;">{{ $shippingAddress['city'] }}</li>
                    @endif

                    @if (isset($shippingAddress['state']))
                        <li style="display: block; color: #6f6f6f; font-size:14px;">{{ $shippingAddress['state'] }}
                        </li>
                    @endif

                    @if (isset($shippingAddress['zip']))
                        <li style="display: block; color: #6f6f6f; font-size:14px;">{{ $shippingAddress['zip'] }}</li>
                    @endif

                    @if (isset($shippingAddress['country']))
                        <li style="display: block; color: #6f6f6f; font-size:14px;">{{ $shippingAddress['country'] }}
                            </;>
                    @endif
                </ul>
            </li>
        </ul>

        <ul
            style="width: 49%; {{ $is_rtl
                ? ' direction: ltr; margin: 0; margin-left: 10px;'
                : 'text-align: right; margin: 0; margin-right: 5px;' }} list-style: none; padding: 0; float: right;">
            @if (isset($settings['siteTitle']))
                <li style="display: block; color: #000000; font-size:18px; font-weight:bold">
                    <div style="margin-bottom: {{ $hasGst && $sellerLegalName && $sellerLegalName !== ($settings['siteTitle'] ?? null) ? '2px' : '10px' }}">{{ $settings['siteTitle'] }}</div>
                </li>
            @endif

            {{-- The registered entity, which is who the invoice is actually FROM
                 and need not be the trading name. --}}
            @if ($hasGst && $sellerLegalName && $sellerLegalName !== ($settings['siteTitle'] ?? null))
                <li style="display: block; color: #6f6f6f; font-size:14px;">
                    <div style="margin-bottom: 10px">{{ $sellerLegalName }}</div>
                </li>
            @endif

            @if (isset($authorDetails['website']))
                <li style="display: block; color: #6f6f6f; font-size:14px;">
                    <div>{{ $authorDetails['website'] }}</div>
                </li>
            @endif

            @if (isset($authorDetails['contact']))
                <li style="display: block; color: #6f6f6f; font-size:14px;">
                    <div>{{ $authorDetails['contact'] }}</div>
                </li>
            @endif

            @if (isset($authorLocation['formattedAddress']))
                <li style="display: block; color: #6f6f6f; font-size:14px;">
                    <div>{{ $authorLocation['formattedAddress'] }}</div>
                </li>
            @endif

            @if ($hasGst && $sellerState)
                <li style="display: block; color: #6f6f6f; font-size:14px;">
                    <div>State: {{ $sellerState }}{{ $order->seller_state_code ? ' (' . $order->seller_state_code . ')' : '' }}</div>
                </li>
            @endif

            @if ($hasGst && $sellerGstin)
                <li style="display: block; color: #000000; font-size:14px; font-weight: bold;">
                    <div>GSTIN: {{ $sellerGstin }}</div>
                </li>
            @endif
        </ul>
        <div style="clear: both;"></div>
    </div>

    <div style="height: 30px;"></div>

    @if ($hasGst)
        <table style="width: 100%; border-collapse: collapse; font-size: 12px;" cellspacing="0" cellpadding="0">
            <thead>
                <tr style="background-color:#019376; color:#FFF;">
                    <th style="text-align: left; padding: 6px 8px;">{{ $translated_text['products'] ?? 'Item' }}</th>
                    <th style="text-align: center; padding: 6px 4px;">HSN</th>
                    <th style="text-align: center; padding: 6px 4px;">{{ $translated_text['quantity'] ?? 'Qty' }}</th>
                    <th style="text-align: right; padding: 6px 4px;">GST %</th>
                    <th style="text-align: right; padding: 6px 4px;">Taxable</th>
                    <th style="text-align: right; padding: 6px 4px;">Tax</th>
                    <th style="text-align: right; padding: 6px 8px;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($gstItems as $item)
                    @php
                        $lineTaxable = (float) ($item->taxable_value ?? 0);
                        $lineTax = (float) ($item->tax_amount ?? 0);
                        $lineAmount = $lineTaxable + $lineTax;
                        $rate = (float) ($item->tax_rate ?? 0);
                        $rateLabel = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
                    @endphp
                    <tr style="border-bottom: 1px solid #d4d4d4;">
                        <td style="text-align: left; padding: 6px 8px;">
                            {{ $item->product?->name ?? ('Item #' . $item->product_id) }}
                            @if (in_array($item->product_id, $cancelled_products))
                                <small style="color: red;">{{ $translated_text['cancelled'] ?? 'Cancelled' }}</small>
                            @endif
                        </td>
                        <td style="text-align: center; padding: 6px 4px;">{{ $item->hsn_code ?: '-' }}</td>
                        <td style="text-align: center; padding: 6px 4px;">{{ $item->order_quantity }}</td>
                        <td style="text-align: right; padding: 6px 4px;">{{ $rateLabel }}%</td>
                        <td style="text-align: right; padding: 6px 4px;">{{ formatCurrency($lineTaxable, $currency, $locale) }}</td>
                        <td style="text-align: right; padding: 6px 4px;">{{ formatCurrency($lineTax, $currency, $locale) }}</td>
                        <td style="text-align: right; padding: 6px 8px;">{{ formatCurrency($lineAmount, $currency, $locale) }}</td>
                    </tr>
                @endforeach
                @if (isset($order->delivery_fee) && $order->delivery_fee > 0)
                    <tr style="border-bottom: 1px solid #d4d4d4;">
                        <td style="text-align: left; padding: 6px 8px;">{{ $translated_text['delivery_fee'] ?? 'Delivery' }}</td>
                        <td style="text-align: center; padding: 6px 4px;">-</td>
                        <td style="text-align: center; padding: 6px 4px;">1</td>
                        <td style="text-align: right; padding: 6px 4px;">-</td>
                        <td style="text-align: right; padding: 6px 4px;">{{ formatCurrency((float) ($order->delivery_taxable ?? 0), $currency, $locale) }}</td>
                        <td style="text-align: right; padding: 6px 4px;">{{ formatCurrency((float) ($order->delivery_tax_amount ?? 0), $currency, $locale) }}</td>
                        <td style="text-align: right; padding: 6px 8px;">{{ formatCurrency((float) $order->delivery_fee, $currency, $locale) }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    @elseif (isset($translated_text['products']) || isset($translated_text['quantity']) || isset($translated_text['total']))
        <ul style="list-style: none; margin: 0; padding: 0;">
            @if (isset($translated_text['products']))
                <li
                    style="{{ $is_rtl ? ' direction: rtl; float: right;' : 'float: left; border-right: 1px solid #02705a;' }}
            width: 39%; display: inline-block;">
                    <div style="background-color:#019376; color: #FFF; font : normal 14px; padding:5px 8px;">
                        <span style="display: block">{{ $translated_text['products'] }}</span>
                    </div>
                </li>
            @endif

            @if (isset($translated_text['quantity']))
                <li
                    style="{{ $is_rtl
                        ? ' direction: rtl; float: right; border-left: 1px solid #02705a;'
                        : 'float: left; border-right: 1px solid #02705a;' }} text-align: center; width: 30%; display:
            inline-block;">
                    <div style="background-color:#019376; color: #FFF; font : normal 14px; padding:5px 8px;">
                        <span style="display: block">{{ $translated_text['quantity'] }}</span>
                    </div>
                </li>
            @endif

            @if (isset($translated_text['total']))
                <li
                    style="{{ $is_rtl ? ' direction: rtl; float: right; text-align: left;' : 'float: left; text-align: right;' }} width: 30%; display: inline-block;">
                    <div style="background-color:#019376; color: #FFF; font : normal 14px; padding:5px 8px;">
                        <span style="display: block">{{ $translated_text['total'] }}</span>
                    </div>
                </li>
            @endif
            <li style="clear: both;"></li>
        </ul>
    @endif

    @if (!$hasGst && !empty($products))
        @foreach ($products as $product)
            <ul style="list-style: none; margin: 0; padding: 0;">
                <li
                    style="{{ $is_rtl
                        ? ' direction: rtl; float: right; border-left: solid 1px #d4d4d4;'
                        : 'float: left; border-right: solid 1px #d4d4d4;' }} width: 39%; display: inline-block; border-bottom: solid
            1px #d4d4d4;">
                    <div style="display: block; padding: 7px; box-sizing: broder-box;">
                        {{ $product['name'] }}
                        @if (in_array($product->id, $cancelled_products))
                            <small style="color: red;">{{ $translated_text['cancelled'] ?? 'Cancelled' }}</small>
                        @endif
                    </div>
                </li>
                <li
                    style="{{ $is_rtl
                        ? ' direction: rtl; float: right; border-left: solid 1px #d4d4d4;'
                        : 'float: left; border-right: solid 1px #d4d4d4;' }} text-align: center; width: 30%; display: inline-block;
            border-bottom: solid 1px #d4d4d4;">
                    <div style="display: block; padding: 7px; box-sizing: broder-box;">
                        {{ $product->pivot['order_quantity'] }}
                    </div>
                </li>
                <li
                    style="{{ $is_rtl ? ' direction: rtl; float: right; text-align: left;' : 'float: left; text-align: right;' }} width: 30%; display: inline-block; border-bottom: solid 1px #d4d4d4;">
                    <div style="display: block; padding: 7px; box-sizing: broder-box;">
                        {{ formatCurrency($product->pivot['unit_price'], $currency, $locale) }}
                    </div>
                </li>
                <li style="clear: both;"></li>
            </ul>
        @endforeach
    @endif

    <div style="height: 20px;"></div>

    <div style="display: block;">
        <div style="width: 65%; height: 10px; {{ $is_rtl ? ' direction: rtl; float: right;' : 'float: left;' }}"></div>
        <div
            style="width: 33%; {{ $is_rtl ? ' direction: rtl; float: left; margin-left: 10px;' : 'float: right; margin-right: 10px;' }}">
            @if (isset($order->amount))
                <div style="padding: 3px 0px; box-sizing: border-box;">
                    <div
                        style="display: block; width: 48%; {{ $is_rtl ? ' direction: rtl; float: right; text-align: right;' : 'float: left;' }} color: #6b7280; font-size:14px;">
                        {{ $translated_text['subtotal'] }} : </div>
                    <div
                        style="display: block; width: 50%; {{ $is_rtl ? ' direction: rtl; float: left; text-align: left;' : 'float: right; text-align: right;' }} color: #6b7280; font-size:14px;">
                        {{ formatCurrency($order->amount, $currency, $locale) }}
                    </div>
                    <div style="clear: both;"></div>
                </div>
                <br>
            @endif
            @if (isset($order->discount))
                <div style="padding: 3px 0px; box-sizing: border-box;">
                    <div
                        style="display: block; width: 48%; {{ $is_rtl ? ' direction: rtl; float: right; text-align: right;' : 'float: left;' }} color: #6b7280; font-size:14px;">
                        {{ $translated_text['discount'] }} : </div>

                    <div
                        style="display: block; width: 50%; {{ $is_rtl ? ' direction: rtl; float: left; text-align: left;' : 'float: right; text-align: right;' }} color: #6b7280; font-size:14px;">
                        {{ formatCurrency($order->discount, $currency, $locale) }}
                    </div>
                    <div style="clear: both;"></div>
                </div>
                <br>
            @endif
            @if ($hasGst)
                @php
                    $pricesIncludeTax = $taxConfig['prices_include_tax'] ?? true;
                    $cgst = (float) ($order->cgst_amount ?? 0);
                    $sgst = (float) ($order->sgst_amount ?? 0);
                    $igst = (float) ($order->igst_amount ?? 0);
                @endphp
                @if ($cgst > 0 || $sgst > 0 || $igst > 0)
                    <div style="padding: 3px 0px; box-sizing: border-box;">
                        <div
                            style="display: block; width: 100%; {{ $is_rtl ? ' direction: rtl; float: right; text-align: right;' : 'float: left;' }} color: #6b7280; font-size:12px; font-style: italic;">
                            {{ $pricesIncludeTax ? 'GST included in the above' : 'Add: GST' }} :</div>
                        <div style="clear: both;"></div>
                    </div>
                @endif
                @if ($isInter)
                    @if ($igst > 0)
                        <div style="padding: 3px 0px; box-sizing: border-box;">
                            <div
                                style="display: block; width: 48%; {{ $is_rtl ? ' direction: rtl; float: right; text-align: right;' : 'float: left;' }} color: #6b7280; font-size:14px;">
                                IGST : </div>
                            <div
                                style="display: block; width: 50%; {{ $is_rtl ? ' direction: rtl; float: left; text-align: left;' : 'float: right; text-align: right;' }} color: #6b7280; font-size:14px;">
                                {{ formatCurrency($igst, $currency, $locale) }}</div>
                            <div style="clear: both;"></div>
                        </div>
                        <br>
                    @endif
                @else
                    @if ($cgst > 0)
                        <div style="padding: 3px 0px; box-sizing: border-box;">
                            <div
                                style="display: block; width: 48%; {{ $is_rtl ? ' direction: rtl; float: right; text-align: right;' : 'float: left;' }} color: #6b7280; font-size:14px;">
                                CGST : </div>
                            <div
                                style="display: block; width: 50%; {{ $is_rtl ? ' direction: rtl; float: left; text-align: left;' : 'float: right; text-align: right;' }} color: #6b7280; font-size:14px;">
                                {{ formatCurrency($cgst, $currency, $locale) }}</div>
                            <div style="clear: both;"></div>
                        </div>
                        <br>
                    @endif
                    @if ($sgst > 0)
                        <div style="padding: 3px 0px; box-sizing: border-box;">
                            <div
                                style="display: block; width: 48%; {{ $is_rtl ? ' direction: rtl; float: right; text-align: right;' : 'float: left;' }} color: #6b7280; font-size:14px;">
                                SGST : </div>
                            <div
                                style="display: block; width: 50%; {{ $is_rtl ? ' direction: rtl; float: left; text-align: left;' : 'float: right; text-align: right;' }} color: #6b7280; font-size:14px;">
                                {{ formatCurrency($sgst, $currency, $locale) }}</div>
                            <div style="clear: both;"></div>
                        </div>
                        <br>
                    @endif
                @endif
            @elseif (isset($order->sales_tax))
                <div style="padding: 3px 0px; box-sizing: border-box;">
                    <div
                        style="display: block; width: 48%; {{ $is_rtl ? ' direction: rtl; float: right; text-align: right;' : 'float: left;' }} color: #6b7280; font-size:14px;">
                        {{ $translated_text['tax'] }} : </div>
                    <div
                        style="display: block; width: 50%; {{ $is_rtl ? ' direction: rtl; float: left; text-align: left;' : 'float: right; text-align: right;' }} color: #6b7280; font-size:14px;">
                        {{ formatCurrency($order->sales_tax + $order->cancelled_tax, $currency, $locale) }}</div>
                    <div style="clear: both;"></div>
                </div>
                <br>
            @endif
            @if (isset($order->delivery_fee))
                <div style="padding: 3px 0px; box-sizing: border-box;">
                    <div
                        style="display: block; width: 48%; {{ $is_rtl ? ' direction: rtl; float: right; text-align: right;' : 'float: left;' }} color: #6b7280; font-size:14px;">
                        {{ $translated_text['delivery_fee'] }} : </div>

                    <div
                        style="display: block; width: 50%; {{ $is_rtl ? ' direction: rtl; float: left; text-align: left;' : 'float: right; text-align: right;' }} color: #6b7280; font-size:14px;">
                        {{ formatCurrency($order->delivery_fee, $currency, $locale) }}
                    </div>

                    <div style="clear: both;"></div>
                </div>
                <br>
            @endif
            @if (isset($order->cancelled_amount) && $order->cancelled_amount > 0 && $order->cancelled_tax > 0)
                <div style="padding: 3px 0px; box-sizing: border-box;">
                    <div
                        style="display: block; width: 68%; {{ $is_rtl ? ' direction: rtl; float: right; text-align: right;' : 'float: left;' }}  color: #6b7280; font-size:14px;">
                        {{ isset($translated_text['cancelled_tax']) ? $translated_text['cancelled_tax'] : 'Tax reduced' }}
                        :
                    </div>

                    <div
                        style="display: block; width: 30%; {{ $is_rtl ? ' direction: rtl; float: left; text-align: left;' : 'float: right; text-align: right;' }}  color: #6b7280; font-size:14px;">
                        - {{ formatCurrency($order->cancelled_tax, $currency, $locale) }}
                    </div>

                    <div style="clear: both;"></div>
                </div>
                <br>
            @endif
            @if (isset($order->cancelled_amount) && $order->cancelled_amount > 0 && $order->cancelled_delivery_fee > 0)
                <div style="padding: 3px 0px; box-sizing: border-box;">
                    <div
                        style="display: block; width: 73%; {{ $is_rtl ? ' direction: rtl; float: right; text-align: right;' : 'float: left;' }}  color: #6b7280; font-size:14px;">
                        {{ isset($translated_text['cancelled_delivery_fee']) ? $translated_text['cancelled_delivery_fee'] : 'Delivery fee reduced' }}
                        :
                    </div>

                    <div
                        style="display: block; width: 23%; {{ $is_rtl ? ' direction: rtl; float: left; text-align: left;' : 'float: right; text-align: right;' }}  color: #6b7280; font-size:14px;">
                        - {{ formatCurrency($order->cancelled_delivery_fee, $currency, $locale) }}
                    </div>

                    <div style="clear: both;"></div>
                </div>
                <br>
            @endif
            @if (isset($order->cancelled_amount) && $order->cancelled_amount > 0)
                <div style="padding: 3px 0px; box-sizing: border-box;">
                    <div
                        style="display: block; width: 68%; {{ $is_rtl ? ' direction: rtl; float: right; text-align: right;' : 'float: left;' }}  color: #6b7280; font-size:14px;">
                        {{ isset($translated_text['cancelled_subtotal']) ? $translated_text['cancelled_subtotal'] : ' Cancelled subtotal ' }}:
                    </div>

                    <div
                        style="display: block; width: 30%; {{ $is_rtl ? ' direction: rtl; float: left; text-align: left;' : 'float: right; text-align: right;' }}  color: #6b7280; font-size:14px;">
                        -{{ formatCurrency($order->cancelled_amount - $order->cancelled_tax - $order->cancelled_delivery_fee, $currency, $locale) }}
                    </div>

                    <div style="clear: both;"></div>
                </div>
                <br>
            @endif
            @if (isset($order->total))
                <div style="padding: 3px 0px; box-sizing: border-box;">
                    <div
                        style="display: block; width: 48%; {{ $is_rtl ? ' direction: rtl; float: right; text-align: right;' : 'float: left;' }} font-weight: bold; color: #000000; font-size:14px;">
                        {{ $translated_text['total'] }} : </div>

                    <div
                        style="display: block; width: 50%; {{ $is_rtl ? ' direction: rtl; float: left; text-align: left;' : 'float: right; text-align: right;' }} font-weight: bold; color: #000000; font-size:14px;">
                        {{ formatCurrency($order->total, $currency, $locale) }}
                    </div>

                    <div style="clear: both;"></div>
                </div>
                <br>
            @endif
            @if (isset($order?->wallet_point?->amount) && !$order->shop_id)
                <div style="padding: 3px 0px; box-sizing: border-box;">
                    <div
                        style="display: block; width: 48%; {{ $is_rtl ? ' direction: rtl; float: right; text-align: right;' : 'float: left;' }} color: #6b7280; font-size:14px;">
                        {{ $translated_text['paid_from_wallet'] }} : </div>

                    <div
                        style="display: block; width: 50%; {{ $is_rtl ? ' direction: rtl; float: left; text-align: left;' : 'float: right; text-align: right;' }} color: #6b7280; font-size:14px;">
                        {{ formatCurrency($order?->wallet_point?->amount, $currency, $locale) }}
                    </div>

                    <div style="clear: both;"></div>
                </div>
                <br>
            @endif
            @if (!$order->shop_id)
                <div style="padding: 3px 0px; box-sizing: border-box;">
                    <div
                        style="display: block; width: 48%; {{ $is_rtl ? ' direction: rtl; float: right; text-align: right;' : 'float: left;' }} color: #6b7280; font-size:14px;">
                        {{ $translated_text['amount_due'] }} : </div>

                    <div
                        style="display: block; width: 50%; {{ $is_rtl ? ' direction: rtl; float: left; text-align: left;' : 'float: right; text-align: right;' }} color: #6b7280; font-size:14px;">
                        {{ formatCurrency($amountDue, $currency, $locale) }}
                    </div>

                    <div style="clear: both;"></div>
                </div>
            @endif
        </div>
        <div style="clear: both;"></div>
    </div>

    @if ($hasGst)
        <div style="clear: both; margin-top: 30px;">
            {{-- Every taxable supply has to say which way the liability runs.
                 PlantAtHome sells as principal on its own GSTIN, so forward
                 charge always: the customer is not liable to pay this GST to
                 the department themselves. --}}
            <p style="color: #6b7280; font-size: 12px; margin: 0 0 4px 0;">Tax payable on reverse charge: No</p>
            <p style="color: #6b7280; font-size: 12px; margin: 0;">
                This is a computer-generated invoice.
            </p>

            <div style="margin-top: 26px; width: 40%; float: right; text-align: center;">
                <div style="border-top: 1px solid #9ca3af; padding-top: 6px; color: #374151; font-size: 12px;">
                    For {{ $sellerLegalName ?: ($settings['siteTitle'] ?? '') }}<br>
                    <span style="color: #6b7280;">Authorised signatory</span>
                </div>
            </div>
            <div style="clear: both;"></div>
        </div>
    @endif
</body>

</html>
