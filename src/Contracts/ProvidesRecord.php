<?php

namespace LiveNetworks\LnStarter\Contracts;

interface ProvidesRecord
{
	/**
	 * Canonical JSON record shape for this entity, consumed by data-mode
	 * responses (ln-api-connector). Values must be JSON-safe scalars/arrays.
	 */
	public function toRecord(): array;
}
