<?php

namespace App\Exceptions\AliExpress;

/**
 * Thrown when a product import step fails (fetch, mapping, persistence).
 */
class ImportException extends AliExpressApiException {}
