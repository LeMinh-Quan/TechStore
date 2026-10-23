<?php

namespace Tests\Feature;

use Tests\TestCase;

class CsrfOriginTest extends TestCase
{
    public function test_state_changing_requests_reject_untrusted_origins(): void
    {
        $this->postJson('/api/v1/auth/login', [], [
            'Origin' => 'https://attacker.example',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Request origin is not allowed.');
    }

    public function test_state_changing_requests_accept_configured_frontend_origins(): void
    {
        $this->postJson('/api/v1/auth/login', [], [
            'Origin' => 'http://localhost:5173',
        ])->assertUnprocessable();
    }

    public function test_state_changing_requests_validate_referer_when_origin_is_absent(): void
    {
        $this->postJson('/api/v1/auth/login', [], [
            'Referer' => 'https://attacker.example/login',
        ])->assertForbidden();

        $this->postJson('/api/v1/auth/login', [], [
            'Referer' => 'http://localhost:5173/login',
        ])->assertUnprocessable();
    }

    public function test_requests_without_browser_origin_headers_remain_supported(): void
    {
        $this->postJson('/api/v1/auth/login', [])->assertUnprocessable();
    }
}
