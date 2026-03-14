{{-- JSON Response Layout for AJAX Requests --}}
@php
	$sections = $__env->getSections();
	$title = isset($sections['title']) && !empty(trim($sections['title'])) ? trim($sections['title']) : null;
	unset($sections['title']);
@endphp
{
  "title": {!! json_encode($title) !!},
  "message": {!! json_encode($message ?? null) !!},
  "content": {
    @foreach($sections as $sectionName => $sectionContent)
      "{{ $sectionName }}": {!! json_encode($sectionContent) !!}{{ !$loop->last ? ',' : '' }}
    @endforeach
  }
}
