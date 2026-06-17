<?php

namespace LiveNetworks\LnStarter\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class LangSwitcher extends Component
{
	public function render(): View
	{
		return view('ln-starter::components.ln.lang-switcher');
	}
}
