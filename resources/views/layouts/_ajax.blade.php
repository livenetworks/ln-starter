{{-- JSON Response Layout for AJAX Requests --}}
@php
	$sections = $__env->getSections();
	$title = isset($sections['title']) && !empty(trim($sections['title'])) ? trim($sections['title']) : null;
	unset($sections['title']);

	// Normalize $message to Message DTO or null
	if (!($message ?? null) instanceof \LiveNetworks\LnStarter\DTOs\Message) {
		if (session('ok')) {
			$message = new \LiveNetworks\LnStarter\DTOs\Message('success', __('Success'), session('ok'));
		} elseif ($errors->any()) {
			$message = new \LiveNetworks\LnStarter\DTOs\Message('error', __('Error'), $errors->first(), ['errors' => $errors->toArray()]);
		} else {
			$message = null;
		}
	}
@endphp
{
  "title": {!! json_encode($title) !!},
  "message": {!! json_encode($message) !!},
  "content": {
    @foreach($sections as $sectionName => $sectionContent)
      "{{ $sectionName }}": {!! json_encode($sectionContent) !!}{{ !$loop->last ? ',' : '' }}
    @endforeach
  }
}
