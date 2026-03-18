@props([
	'id'      => 'ln-toast-container',
	'class'   => 'ln-toast ln-toast--top-right',
	'timeout' => 6000,
	'max'     => 5,
	'ok'      => session('ok'),
])

@php
	// Collect all error messages and deduplicate by text
	$errors_all = collect($errors?->all() ?? [])->filter()->unique()->values();

	// Determine if we have any messages to display
	$has_messages = !empty($ok) || $errors_all->isNotEmpty();
@endphp

<div id="{{ $id }}"
	class="{{ $class }}"
	role="region"
	aria-live="polite"
	aria-atomic="true"
	data-ln-toast
	@unless(is_null($timeout)) data-ln-toast-timeout="{{ (int) $timeout }}" @endunless
	@unless(is_null($max)) data-ln-toast-max="{{ (int) $max }}" @endunless
>
	{{-- Success Message --}}
	@if ($ok)
		<div class="ln-toast__item ln-toast__item--success" data-ln-toast-item data-type="success" x-data>
			<div class="ln-toast__content">
				<span class="ln-icon-check-circle ln-toast__icon" aria-hidden="true"></span>
				<span class="ln-toast__message">{{ $ok }}</span>
			</div>
			<button class="ln-toast__close ln-icon-close" type="button" @click="$el.closest('.ln-toast__item').remove()" aria-label="{{ __('Close message') }}">
				<span class="sr-only">{{ __('Close') }}</span>
			</button>
		</div>
	@endif

	{{-- Error Messages --}}
	@foreach ($errors_all as $error)
		<div class="ln-toast__item ln-toast__item--error" data-ln-toast-item data-type="error" x-data>
			<div class="ln-toast__content">
				<span class="ln-icon-error-circle ln-toast__icon" aria-hidden="true"></span>
				<span class="ln-toast__message">{{ $error }}</span>
			</div>
			<button class="ln-toast__close ln-icon-close" type="button" @click="$el.closest('.ln-toast__item').remove()" aria-label="{{ __('Close message') }}">
				<span class="sr-only">{{ __('Close') }}</span>
			</button>
		</div>
	@endforeach
</div>
