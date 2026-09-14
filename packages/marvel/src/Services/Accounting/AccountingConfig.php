<?php

namespace Marvel\Services\Accounting;

use Marvel\Database\Models\Settings;

/**
 * settings.options.accounting — the admin/CA-editable accounting configuration:
 * master switch, strict-posting mode, account-role → code mapping, rounding tolerance,
 * adjustment approval threshold, cutover date, default recognition mode.
 * Env ACCOUNTING_ENABLED overrides the switch; an EMPTY env value is treated as unset
 * (the .railway/start.sh heredoc emits FOO= for unset vars — the trap that silently
 * forced the vendor ledger off).
 */
final class AccountingConfig
{
    public const ROLE_DEFAULTS = [
        'bank' => '1010', 'gateway_receivable' => '1020', 'customer_receivable' => '1030',
        'inventory' => '1040', 'other_current_assets' => '1050', 'input_gst' => '1060',
        'vendor_payables' => '2010', 'cgst_payable' => '2020', 'sgst_payable' => '2030', 'igst_payable' => '2040',
        'customer_refund_payable' => '2050', 'other_current_liabilities' => '2060',
        'customer_advances' => '2070', 'customer_wallet_liability' => '2080', 'dp_payables' => '2090',
        'capital' => '3010', 'retained_earnings' => '3020', 'opening_balance_equity' => '3030',
        'product_sales' => '4010', 'discounts_given' => '4015', 'platform_commission' => '4020',
        'delivery_revenue' => '4030', 'other_operating_revenue' => '4040',
        'cogs' => '5010', 'vendor_settlement_cost' => '5020', 'delivery_cost' => '5030',
        'packaging_cost' => '5040', 'gateway_charges' => '5050',
        'other_expenses' => '6060', 'rounding_differences' => '6070',
    ];

    private array $cfg;

    public function __construct(?array $options = null)
    {
        if ($options === null) {
            try {
                $options = (array) (Settings::getData()->options ?? []);
            } catch (\Throwable $e) {
                $options = [];
            }
        }
        $this->cfg = (array) ($options['accounting'] ?? []);
    }

    public function enabled(): bool
    {
        $env = env('ACCOUNTING_ENABLED');
        if ($env !== null && $env !== '') {
            return filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }
        return (bool) ($this->cfg['enabled'] ?? false);
    }

    /** Strict: a posting failure throws and rolls back the business change (spec §45). */
    public function strict(): bool
    {
        return (bool) ($this->cfg['strict'] ?? true);
    }

    public function accountCode(string $role): string
    {
        $map = (array) ($this->cfg['accounts'] ?? []);
        $code = $map[$role] ?? self::ROLE_DEFAULTS[$role] ?? null;
        if ($code === null) {
            throw new Exceptions\AccountingException("No account mapped for role '{$role}'.");
        }
        return (string) $code;
    }

    /** Max paise a per-order rounding residual may be before the entry is flagged (default ₹0.05). */
    public function roundingToleranceMinor(): int
    {
        return max(0, (int) ($this->cfg['rounding_tolerance_minor'] ?? 5));
    }

    public function adjustmentApprovalThreshold(): string
    {
        return (string) ($this->cfg['adjustment_approval_threshold'] ?? '5000.00');
    }

    /** Share of a shipment's courier/DP cost recovered from the vendor (spec §19): global %, per-shop override. */
    public function deliveryVendorSharePercent(?int $shopId = null): string
    {
        $d = (array) ($this->cfg['delivery'] ?? []);
        $v = $shopId !== null && isset($d['per_shop'][$shopId]) ? $d['per_shop'][$shopId] : ($d['vendor_share_percent'] ?? 0);
        return number_format(min(100, max(0, (float) $v)), 4, '.', '');
    }

    public function cutoverDate(): ?string
    {
        return $this->cfg['cutover_date'] ?? null;
    }

    /** principal | agent (spec D2). */
    public function defaultRecognitionMode(): string
    {
        return in_array($this->cfg['recognition_mode'] ?? 'principal', ['principal', 'agent'], true)
            ? $this->cfg['recognition_mode'] : 'principal';
    }
}
