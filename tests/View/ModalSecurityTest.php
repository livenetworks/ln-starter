<?php

namespace LiveNetworks\LnStarter\Tests\View;

use Illuminate\Support\Facades\Blade;
use LiveNetworks\LnStarter\Tests\TestCase;

class ModalSecurityTest extends TestCase
{
    public function test_post_modal_contains_csrf_token(): void
    {
        $html = Blade::render('<x-ln.modal action="/members" />');

        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('name="_token"', $html);
    }

    public function test_non_post_method_is_spoofed_and_csrf_protected(): void
    {
        $html = Blade::render('<x-ln.modal action="/members/1" method="DELETE" />');

        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('name="_method" value="DELETE"', $html);
    }
}
