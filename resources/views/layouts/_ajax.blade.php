{{-- JSON Response Layout for AJAX Requests --}}
@php
	$sections = $__env->getSections();
	$title = isset($sections['title']) && !empty(trim($sections['title'])) ? trim($sections['title']) : null;
	unset($sections['title']);

	// Normalize $message to Message DTO or null
	if (!($message ?? null) instanceof \LiveNetworks\LnStarter\DTOs\Message) {
		if (session('message') instanceof \LiveNetworks\LnStarter\DTOs\Message) {
			$message = session('message');
		} elseif (is_array(session('message'))) {
			$sessMsg = session('message');
			$message = new \LiveNetworks\LnStarter\DTOs\Message(
				$sessMsg['type'] ?? 'info',
				$sessMsg['title'] ?? '',
				$sessMsg['body'] ?? $sessMsg['text'] ?? $sessMsg['message'] ?? ''
			);
		} elseif (is_string(session('message'))) {
			$message = new \LiveNetworks\LnStarter\DTOs\Message('info', __('Notice'), session('message'));
		} elseif (session('ok')) {
			$message = new \LiveNetworks\LnStarter\DTOs\Message('success', __('Success'), session('ok'));
		} elseif (session('error')) {
			$message = new \LiveNetworks\LnStarter\DTOs\Message('error', __('Error'), is_string(session('error')) ? session('error') : (session('error')->body ?? ''));
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
