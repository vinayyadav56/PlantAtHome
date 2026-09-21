<?php

namespace Marvel\Integrations\Store;

use RuntimeException;

/**
 * A credential WRITE was refused because the bound store must not hold credentials in this
 * environment. Reads keep working; only storing something new is off the table.
 */
class CredentialStoreUnavailable extends RuntimeException
{
}
