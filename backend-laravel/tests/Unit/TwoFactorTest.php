<?php

namespace Tests\Unit;

use App\Support\TwoFactor;
use PHPUnit\Framework\TestCase;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP + recovery-code primitives used by the admin two-factor flow.
 *
 * RFC 6238 against pragmarx/google2fa; recovery codes must generate, hash,
 * and verify exactly once. No database needed — these run anywhere.
 */
class TwoFactorTest extends TestCase
{
    private Google2FA $google;

    protected function setUp(): void
    {
        parent::setUp();
        $this->google = new Google2FA;
    }

    public function test_secret_generation_returns_a_usable_base32_secret(): void
    {
        $secret = TwoFactor::generateSecret();

        // Base32 alphabet only, and it round-trips through the library decoder.
        $this->assertIsString($secret);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertGreaterThan(0, strlen($this->google->base32Decode($secret)));
    }

    public function test_generated_code_verifies_and_wrong_code_does_not(): void
    {
        $secret = TwoFactor::generateSecret();
        $code = $this->google->getCurrentOtp($secret);

        $this->assertTrue(TwoFactor::verify($secret, $code));
        $this->assertFalse(TwoFactor::verify($secret, '000000'));
        $this->assertFalse(TwoFactor::verify($secret, ''));
    }

    public function test_otpauth_uri_embeds_secret_and_issuer(): void
    {
        $uri = TwoFactor::otpauthUri('admin@school.edu', 'ABC23456', 'OmniVote');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=ABC23456', $uri);
        $this->assertStringContainsString('issuer=OmniVote', $uri);
        $this->assertStringContainsString('OmniVote:admin%40school.edu', $uri);
    }

    public function test_recovery_codes_are_unique_and_well_formed(): void
    {
        $codes = TwoFactor::generateRecoveryCodes(10);

        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $code);
        }
    }

    /**
     * Regression: a recovery code must fit the length cap the login and
     * self-service 2FA endpoints validate against. The cap once sat at 10 while
     * generated codes are 11 characters, so a recovery code was rejected with
     * "The code field must not be greater than 10 characters" (422) before the
     * verifier ever ran — the documented lost-device path was unreachable.
     */
    public function test_recovery_codes_fit_the_accepted_code_input_length(): void
    {
        foreach (TwoFactor::generateRecoveryCodes(10) as $code) {
            $this->assertSame(TwoFactor::RECOVERY_CODE_LENGTH, strlen($code));
            $this->assertLessThanOrEqual(TwoFactor::MAX_CODE_INPUT_LENGTH, strlen($code));
        }
    }

    public function test_recovery_code_round_trips_and_matches_only_once(): void
    {
        $codes = TwoFactor::generateRecoveryCodes(3);
        $hashed = TwoFactor::hashRecoveryCodes($codes);

        // A stored hash never equals the plaintext.
        $this->assertNotContains($codes[0], $hashed);

        $this->assertSame(1, TwoFactor::findRecoveryIndex($hashed, $codes[1]));
        // Retyped or pasted in lowercase / with padding: still the same code.
        $this->assertSame(1, TwoFactor::findRecoveryIndex($hashed, strtolower($codes[1])));
        $this->assertSame(1, TwoFactor::findRecoveryIndex($hashed, ' '.$codes[1].' '));
        $this->assertSame(1, TwoFactor::findRecoveryIndex($hashed, strtolower('  '.$codes[1])));
        $this->assertNull(TwoFactor::findRecoveryIndex($hashed, 'NOPE1-NOPE2'));
        $this->assertNull(TwoFactor::findRecoveryIndex($hashed, ''));
        $this->assertNull(TwoFactor::findRecoveryIndex($hashed, '   '));
        $this->assertNull(TwoFactor::findRecoveryIndex(null, $codes[0]));
        $this->assertNull(TwoFactor::findRecoveryIndex([], $codes[0]));
    }

    public function test_recovery_index_is_stable_after_consumption(): void
    {
        $codes = TwoFactor::generateRecoveryCodes(4);
        $hashed = TwoFactor::hashRecoveryCodes($codes);

        $index = TwoFactor::findRecoveryIndex($hashed, $codes[2]);
        $this->assertSame(2, $index);

        unset($hashed[$index]);
        $this->assertNull(TwoFactor::findRecoveryIndex(array_values($hashed), $codes[2]));
        $this->assertSame(0, TwoFactor::findRecoveryIndex(array_values($hashed), $codes[0]));
    }
}
