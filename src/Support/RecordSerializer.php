<?php

namespace LiveNetworks\LnStarter\Support;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use LiveNetworks\LnStarter\Contracts\ProvidesRecord;

/**
 * Resolves any record-like value to a flat associative array for data-mode
 * JSON. Prefers an explicit toRecord() contract, then falls back to
 * Arrayable / JsonSerializable / cast. Shared by LNController and the
 * central exception handler (409 conflict body).
 */
class RecordSerializer
{
	public static function toArray($record): array
	{
		if (is_array($record)) {
			return $record;
		}

		if ($record instanceof ProvidesRecord) {
			return $record->toRecord();
		}

		if (is_object($record) && method_exists($record, 'toRecord')) {
			return $record->toRecord();
		}

		if ($record instanceof Arrayable) {
			return $record->toArray();
		}

		if ($record instanceof JsonSerializable) {
			return (array) $record->jsonSerialize();
		}

		return (array) $record;
	}
}
