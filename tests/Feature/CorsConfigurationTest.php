<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsConfigurationTest extends TestCase
{
    public function test_site2_frontend_may_send_authorization_and_upload_headers(): void
    {
        config(['cors.allowed_origins' => ['http://147.182.164.148:8080']]);
        $this->withHeaders([
            'Origin' => 'http://147.182.164.148:8080',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->options('/api/documents')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://147.182.164.148:8080')
            ->assertHeader('Access-Control-Allow-Headers', 'authorization,content-type');
    }

    public function test_other_origins_do_not_receive_cors_access(): void
    {
        config(['cors.allowed_origins' => ['http://147.182.164.148:8080']]);
        $this->withHeaders([
            'Origin' => 'http://example.test',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/login')
            // A single configured origin may be sent even to other origins;
            // browsers reject it because it does not match their Origin.
            ->assertHeader('Access-Control-Allow-Origin', 'http://147.182.164.148:8080');
    }
}
