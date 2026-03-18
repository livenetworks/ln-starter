<?php

namespace LiveNetworks\LnStarter\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class Toast extends Component
{
    public function __construct(
        public string $id      = 'ln-toast-container',
        public string $class   = 'ln-toast ln-toast--top-right',
        public int    $timeout = 6000,
        public int    $max     = 5,
    ) {}

    public function render(): View
    {
        return view('ln-starter::components.ln.toast');
    }
}
