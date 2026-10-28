<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Behind Nginx Proxy Manager (TLS ends there) every page must link its icon over https. The panels used to build
 * the favicon URL at boot, before the proxy headers were read, so browsers blocked it as mixed content.
 */
class FaviconTest extends TestCase
{
    public function test_portal_and_app_pages_link_the_favicon_over_https_behind_the_proxy(): void
    {
        $https = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'fleet.ridecoop.net', 'X-Forwarded-Port' => '443'];
        foreach (['/fleet/login', '/store/login', '/admin/login'] as $path) {
            $html = $this->withHeaders($https)->get($path)->assertOk()->getContent();
            preg_match('/<link[^>]+rel="icon"[^>]*>/', $html, $m);
            $this->assertNotEmpty($m, "$path has a favicon link");
            $this->assertStringContainsString('href="https://fleet.ridecoop.net/favicon.ico', $m[0], $path);
            $this->assertStringNotContainsString('http://', $m[0]);
        }
    }
}
