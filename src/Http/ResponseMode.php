<?php

namespace LiveNetworks\LnStarter\Http;

use Illuminate\Http\Request;

/**
 * Single source of truth for which of the three response modes a request wants.
 *
 * Priority order:
 *   1. X-LN-Response: data header  → DATA
 *   2. X-Requested-With: XMLHttpRequest → AJAX
 *   3. wantsJson() (implies !ajax)  → DATA
 *   4. otherwise                    → FULL
 */
class ResponseMode
{
	public const HEADER = 'X-LN-Response';
	public const DATA_VALUE = 'data';

	public const DATA = 'data';
	public const AJAX = 'ajax';
	public const FULL = 'full';

	public static function for(Request $request): string
	{
		if (self::hasDataHeader($request)) {
			return self::DATA;
		}

		if ($request->ajax()) {
			return self::AJAX;
		}

		if ($request->wantsJson()) {
			return self::DATA;
		}

		return self::FULL;
	}

	public static function isData(Request $request): bool
	{
		return self::for($request) === self::DATA;
	}

	public static function isAjax(Request $request): bool
	{
		return self::for($request) === self::AJAX;
	}

	public static function isFull(Request $request): bool
	{
		return self::for($request) === self::FULL;
	}

	/** True when the client expects a JSON body (DATA or AJAX, i.e. not FULL). */
	public static function expectsJson(Request $request): bool
	{
		return self::for($request) !== self::FULL;
	}

	/** True when the request carries the data-mode header, regardless of route. */
	public static function hasDataHeader(Request $request): bool
	{
		return strtolower((string) $request->header(self::HEADER)) === self::DATA_VALUE;
	}
}
