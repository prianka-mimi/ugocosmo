<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a currency conversion cannot be performed (unknown currency
 * or upstream rate source unavailable).
 */
class CurrencyConversionException extends RuntimeException {}
