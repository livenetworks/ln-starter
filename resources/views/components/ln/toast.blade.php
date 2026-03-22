@props([
	'id'      => 'ln-toast-container',
	'timeout' => 6000,
	'max'     => 5,
	'ok'      => session('ok'),
])

@php
	$errors_all = collect($errors?->all() ?? [])->filter()->unique()->values();
@endphp

<div id="{{ $id }}"
	data-ln-toast
	@unless(is_null($timeout)) data-ln-toast-timeout="{{ (int) $timeout }}" @endunless
	@unless(is_null($max)) data-ln-toast-max="{{ (int) $max }}" @endunless
>
	{{-- Success — hydrated by ln-acme JS --}}
	@if ($ok)
		<div data-ln-toast-item data-type="success">{{ $ok }}</div>
	@endif

	{{-- Errors — hydrated by ln-acme JS --}}
	@foreach ($errors_all as $error)
		<div data-ln-toast-item data-type="error">{{ $error }}</div>
	@endforeach
</div>
