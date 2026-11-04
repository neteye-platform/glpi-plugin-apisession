<?php

namespace GlpiPlugin\Apisession\Tests\Units;

use Glpi\Tests\DbTestCase;
use GlpiPlugin\Apisession\Controller\SessionController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests for the landing page of the GLPI login: GET /plugins/apisession/session.
 */
final class SessionControllerTest extends DbTestCase
{
    public function testWithoutSessionIsUnauthenticated(): void
    {
        $this->logOut();

        $response = (new SessionController())(Request::create('/plugins/apisession/session'));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testOnlyGetIsAccepted(): void
    {
        $this->login();

        $response = (new SessionController())(Request::create('/plugins/apisession/session', 'POST'));

        $this->assertSame(405, $response->getStatusCode());
    }

    public function testWithSessionIsAnHtmlPage(): void
    {
        $this->login();

        $response = (new SessionController())(Request::create('/plugins/apisession/session'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testExpiredPasswordIsRefused(): void
    {
        $this->login();
        $_SESSION['glpi_password_expired'] = 1;

        $response = (new SessionController())(Request::create('/plugins/apisession/session'));

        $this->assertSame(403, $response->getStatusCode());
    }
}
