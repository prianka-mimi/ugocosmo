<?php

namespace App\Exceptions\Csv;

use RuntimeException;

/**
 * Thrown when a CSV upload fails structural validation (empty file,
 * missing required headers, malformed rows, invalid encoding).
 */
class CsvImportException extends RuntimeException {}
