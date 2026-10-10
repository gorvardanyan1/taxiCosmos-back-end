<?php

namespace App\Exceptions;

use DomainException;

/**
 * An action the record's current state does not allow (HTTP 409). The admin UI shows the message
 * on the page it came from; other clients get 409 JSON (bootstrap/app.php).
 */
abstract class ConflictException extends DomainException {}
