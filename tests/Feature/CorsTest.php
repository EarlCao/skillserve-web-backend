<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The API trusts only the admin web's own origins — never a whole hosting
 * platform, where anyone can deploy a site.
 */
class CorsTest extends TestCase
{
    public function test_the_admin_web_origin_is_allowed(): void
    {
        $this->preflight('https://skillserve-web-admin.vercel.app')
            ->assertHeader('Access-Control-Allow-Origin', 'https://skillserve-web-admin.vercel.app');
    }

    public function test_other_sites_on_the_same_hosts_are_not(): void
    {
        foreach (['https://attacker.vercel.app', 'https://phish.onrender.com'] as $origin) {
            $this->assertNull($this->preflight($origin)->headers->get('Access-Control-Allow-Origin'), $origin);
        }
    }

    public function test_frontend_url_and_frontend_urls_extend_the_list(): void
    {
        $origins = $this->originsWith([
            'FRONTEND_URL' => 'https://admin.skillserve.ph/',
            'FRONTEND_URLS' => 'https://staging.skillserve.ph, https://demo.skillserve.ph',
        ]);

        $this->assertContains('https://admin.skillserve.ph', $origins);
        $this->assertContains('https://staging.skillserve.ph', $origins);
        $this->assertContains('https://demo.skillserve.ph', $origins);
        $this->assertNotContains('', $origins);
    }

    public function test_the_admin_webs_retired_addresses_are_not_trusted(): void
    {
        // One now serves an unrelated app; the other's name is free for anyone
        // to claim. Checked with the deployment variables empty, so a stale
        // value in a local .env cannot hide them coming back into the code.
        $origins = $this->originsWith(['FRONTEND_URL' => '', 'FRONTEND_URLS' => '']);

        $this->assertContains('https://skillserve-web-admin.vercel.app', $origins);
        $this->assertNotContains('https://skillserve-frontend.onrender.com', $origins);
        $this->assertNotContains('https://skillserve-admin-side.vercel.app', $origins);
    }

    /**
     * The allowed origins as config/cors.php builds them with [$values] in
     * the environment, restoring the environment afterwards.
     *
     * @param  array<string, string>  $values
     * @return array<int, string>
     */
    private function originsWith(array $values): array
    {
        $saved = [];
        foreach ($values as $key => $value) {
            $saved[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        try {
            return (require config_path('cors.php'))['allowed_origins'];
        } finally {
            foreach ($saved as $key => [$env, $envArray, $server]) {
                $env === false ? putenv($key) : putenv("{$key}={$env}");
                if ($envArray === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $envArray;
                }
                if ($server === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $server;
                }
            }
        }
    }

    private function preflight(string $origin)
    {
        return $this->call('OPTIONS', '/api/health', server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
    }
}
