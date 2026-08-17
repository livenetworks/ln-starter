<?php

namespace LiveNetworks\LnStarter\Tests\Auth;

use LiveNetworks\LnStarter\Support\MagicLoginProofs;
use LiveNetworks\LnStarter\Tests\TestCase;
use RuntimeException;

class MagicLoginProofsTest extends TestCase
{
    public function test_versioned_peppers_keep_old_attempt_hashes_verifiable_after_rotation(): void
    {
        config()->set('ln-starter.auth.peppers.current', 'v1');
        config()->set('ln-starter.auth.peppers.keys', [
            'v1' => str_repeat('a', 32),
            'v2' => str_repeat('b', 32),
        ]);

        $proofs = new MagicLoginProofs();
        $oldHash = $proofs->codeHash('attempt-1', '001234', 'v1');

        config()->set('ln-starter.auth.peppers.current', 'v2');

        $this->assertSame($oldHash, $proofs->codeHash('attempt-1', '001234', 'v1'));
        $this->assertNotSame($oldHash, $proofs->codeHash('attempt-1', '001234', 'v2'));
        $this->assertSame('001234', str_pad('1234', 6, '0', STR_PAD_LEFT));
    }

    public function test_missing_referenced_pepper_fails_closed(): void
    {
        config()->set('ln-starter.auth.peppers.current', 'missing');
        config()->set('ln-starter.auth.peppers.keys', []);

        $this->expectException(RuntimeException::class);

        (new MagicLoginProofs())->assertConfigured();
    }

    public function test_link_tokens_match_constrained_route_format(): void
    {
        config()->set('ln-starter.auth.peppers.current', 'v1');
        config()->set('ln-starter.auth.peppers.keys.v1', str_repeat('a', 32));

        $token = (new MagicLoginProofs())->generateLinkToken();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43,128}$/', $token);
    }
}
