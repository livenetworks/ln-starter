@extends(request()->header('X-Requested-With') === 'XMLHttpRequest' ? config('ln-starter.ajax_layout', 'ln-starter::layouts._ajax') : config('ln-starter.layout', 'layouts._app'))
