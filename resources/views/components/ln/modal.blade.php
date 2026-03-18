@props([
	'id' => '',
	'title' => '',
	'submitText' => 'Submit',
	'action' => null,
	'method' => 'POST',
])

<div
	id="{{ $id }}"
	class="ln-modal"
	role="dialog"
	aria-modal="true">

	{{-- Modal Dialog --}}
	<form
		@if($action)
			action="{{ $action }}"
			method="{{ $method === 'POST' ? 'POST' : 'GET' }}"
		@endif
		class="ln-modal__content"
		data-ln-ajax>

		<header class="ln-modal__header">
			<h2 class="ln-modal__title">{{ $title }}</h2>
			<button data-ln-modal-close type="button" class="ln-modal__close" aria-label="{{ __('Close modal') }}">&times;</button>
		</header>

		<main class="ln-modal__body">
			{{ $slot }}
		</main>

		<footer class="ln-modal__footer">
			<button type="button" data-ln-modal-close>{{ __('Cancel') }}</button>
			<button type="submit">{{ $submitText }}</button>
		</footer>
	</form>
</div>
