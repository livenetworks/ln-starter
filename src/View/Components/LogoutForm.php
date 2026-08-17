<?php

namespace LiveNetworks\LnStarter\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class LogoutForm extends Component
{
    public function __construct(
        public string $buttonClass = '',
    ) {}

    public function render(): View
    {
        return view('ln-starter::components.ln.logout-form');
    }
}
