<?php

namespace Marvel\Imports;

/** Thrown when a batch sheet has more valid rows than the configured cap. */
class RowLimitExceededException extends \RuntimeException
{
}
