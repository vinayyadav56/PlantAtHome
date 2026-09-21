<?php

namespace Marvel\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\HsnCode;

/**
 * An HSN code must exist in the master.
 *
 * Not a plain `exists:` rule for two reasons: the table arrives with a migration
 * and deploys migrate AFTER the new code is serving, so a bare exists rule would
 * 500 every product save in that window; and an empty string has to pass, since
 * "no HSN yet" is a legitimate state the Missing Tax Config report exists to
 * surface rather than something to block a save on.
 */
class KnownHsnCode implements Rule
{
    public function passes($attribute, $value): bool
    {
        $code = strtoupper(trim((string) $value));
        if ($code === '') {
            return true;
        }

        try {
            if (!Schema::hasTable('hsn_codes')) {
                return true;
            }
        } catch (\Throwable $e) {
            return true;
        }

        return HsnCode::where('code', $code)->exists();
    }

    public function message(): string
    {
        return 'That HSN code is not in the master list. Add it under Finance → HSN Codes first.';
    }
}
