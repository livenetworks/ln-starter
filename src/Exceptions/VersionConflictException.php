<?php

namespace LiveNetworks\LnStarter\Exceptions;

use RuntimeException;

/**
 * Thrown by LNController::guardVersion() on an optimistic-lock mismatch.
 * Carries the server's current record so the central handler can return it
 * as the 409 body for client-side conflict resolution.
 */
class VersionConflictException extends RuntimeException
{
	public function __construct(
		public mixed $record,
		public string $column = 'version',
		string $message = 'Version conflict.'
	) {
		parent::__construct($message, 409);
	}
}
