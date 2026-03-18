<?php

namespace LiveNetworks\LnStarter\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class Modal extends Component
{
    public function __construct(
        public string  $id         = '',
        public string  $title      = '',
        public string  $submitText = 'Submit',
        public ?string $action     = null,
        public string  $method     = 'POST',
    ) {}

    public function render(): View
    {
        return view('ln-starter::components.ln.modal');
    }
}
