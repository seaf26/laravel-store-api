<?php

namespace App\Services\Orders;

use RuntimeException;

/**
 * Internal signal raised inside the order transaction when a concurrent request
 * has already claimed the same idempotency key. It is caught within the service
 * to roll back and replay the original order — it is never rendered to a client.
 */
class DuplicateOrderException extends RuntimeException {}
