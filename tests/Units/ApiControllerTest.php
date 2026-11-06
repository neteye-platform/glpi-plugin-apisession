<?php

namespace GlpiPlugin\Apisession\Tests\Units;

use Computer;
use Glpi\Api\HL\Router;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Apisession\Controller\ApiController;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests for the read-only proxy of the GLPI API v2: GET /plugins/apisession/v2{path}.
 *
 * The proxy must answer with exactly what the user of the GLPI web session can see, whatever the request says about
 * another identity, profile or entity, and whether the API v2 is enabled or not.
 */
final class ApiControllerTest extends DbTestCase
{
    public function setUp(): void
    {
        global $CFG_GLPI;

        parent::setUp();
        $CFG_GLPI['enable_hlapi'] = 1;
        Router::resetInstance();
    }

    public function tearDown(): void
    {
        // The proxy registers its authentication middleware on the router singleton
        Router::resetInstance();
        parent::tearDown();
    }

    /**
     * Call the proxy like the GLPI kernel does for GET /plugins/apisession/v2{path}?{query}. The superglobals are left
     * empty on purpose: the query must reach the API v2 from the Symfony request only
     */
    private function get(string $path, array $query = [], array $headers = []): Response
    {
        $request = Request::create('/plugins/apisession/v2' . $path, 'GET', $query);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return (new ApiController())->proxy($request, $path);
    }

    private function decode(Response $response): mixed
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<int, string> the names of the assets of a response of the API v2
     */
    private function names(Response $response): array
    {
        return array_column($this->decode($response), 'name');
    }

    /**
     * Two computers with a unique name: one in _test_child_1, one in _test_child_2
     *
     * @return array{child1: Computer, child2: Computer}
     */
    private function createComputers(): array
    {
        return [
            'child1' => $this->createItem(Computer::class, [
                'name'        => 'apisession-' . $this->getUniqueString(),
                'entities_id' => getItemByTypeName('Entity', '_test_child_1', true),
            ]),
            'child2' => $this->createItem(Computer::class, [
                'name'        => 'apisession-' . $this->getUniqueString(),
                'entities_id' => getItemByTypeName('Entity', '_test_child_2', true),
            ]),
        ];
    }

    public function testWithoutSessionIsUnauthenticated(): void
    {
        $this->logOut();

        $response = $this->get('/Assets/Computer');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('ERROR_UNAUTHENTICATED', $this->decode($response)['status']);
    }

    public function testWithSessionReturnsJson(): void
    {
        $this->login();

        $response = $this->get('/Assets/Computer');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertIsArray($this->decode($response));
    }

    public function testQueryStringReachesTheApi(): void
    {
        $this->login();
        $computers = $this->createComputers();
        $name = $computers['child1']->fields['name'];

        $response = $this->get('/Assets/Computer', ['filter' => 'name=="' . $name . '"']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([$name], $this->names($response));
    }

    public function testOnlyTheActiveEntitiesOfTheSessionAreVisible(): void
    {
        $this->login();
        $computers = $this->createComputers();
        $this->setEntity('_test_child_1', false);

        $names = $this->names($this->get('/Assets/Computer', ['filter' => 'name=like="apisession-*"']));

        $this->assertContains($computers['child1']->fields['name'], $names);
        $this->assertNotContains($computers['child2']->fields['name'], $names);
    }

    public function testItemOutsideTheActiveEntitiesIsNotReadable(): void
    {
        $this->login();
        $computers = $this->createComputers();
        $this->setEntity('_test_child_1', false);

        $visible = $this->get('/Assets/Computer/' . $computers['child1']->getID());
        $this->assertSame(200, $visible->getStatusCode());
        $this->assertSame($computers['child1']->fields['name'], $this->decode($visible)['name']);

        $hidden = $this->get('/Assets/Computer/' . $computers['child2']->getID());
        $this->assertSame(404, $hidden->getStatusCode());
        $this->assertSame('ERROR_ITEM_NOT_FOUND', $this->decode($hidden)['status']);
        $this->assertStringNotContainsString($computers['child2']->fields['name'], (string) $hidden->getContent());
    }

    public function testProfileWithoutRightsIsForbidden(): void
    {
        $this->login();
        $computers = $this->createComputers();
        $this->login('post-only');

        // The self-service profile can read neither the assets nor the users of GLPI
        $assets = $this->get('/Assets/Computer', ['filter' => 'name=like="apisession-*"']);
        $this->assertSame(403, $assets->getStatusCode());
        $this->assertSame('ERROR_RIGHT_MISSING', $this->decode($assets)['status']);
        $this->assertStringNotContainsString($computers['child1']->fields['name'], (string) $assets->getContent());
        $this->assertStringNotContainsString($computers['child2']->fields['name'], (string) $assets->getContent());

        $user = $this->get('/Administration/User/' . getItemByTypeName('User', TU_USER, true));
        $this->assertSame(403, $user->getStatusCode());
        $this->assertSame('ERROR_RIGHT_MISSING', $this->decode($user)['status']);
    }

    public function testAuthorizationHeaderIsIgnored(): void
    {
        $this->login();

        // Without the filter the API v2 would try to open a session from the token and answer 400 "Invalid OAuth token"
        $response = $this->get('/Session', [], ['Authorization' => 'Bearer not-a-token']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(getItemByTypeName('User', TU_USER, true), $this->decode($response)['user_id']);
    }

    public function testIgnoredHeadersAreRemoved(): void
    {
        $headers = ApiController::filterHeaders([
            'authorization'         => ['Bearer token'],
            'glpi-profile'          => ['4'],
            'glpi-entity'           => ['2'],
            'glpi-entity-recursive' => ['true'],
            'glpi-api-version'      => ['1'],
            'accept'                => ['application/json'],
        ]);

        // Nothing else can select another identity, profile or entity: the headers the router reads are gone
        $this->assertSame(['accept' => ['application/json']], $headers);
    }

    public function testWorksWithTheApiDisabled(): void
    {
        // With the API v2 disabled and no session, the router itself answers 403 "The High-Level API is disabled", not
        // 401: only the session check of the proxy gives the 401 here. With the API enabled the router answers 401 too.
        global $CFG_GLPI;
        $CFG_GLPI['enable_hlapi'] = 0;

        $this->login();
        $this->assertSame(200, $this->get('/Assets/Computer')->getStatusCode());

        Router::resetInstance();
        $this->logOut();
        $this->assertSame(401, $this->get('/Assets/Computer')->getStatusCode());
    }

    public function testUnknownPathIsNotFound(): void
    {
        $this->login();

        $this->assertSame(404, $this->get('/Not/A/Route')->getStatusCode());
    }

    public function testEmptyPathDoesNotFail(): void
    {
        $this->login();

        $this->assertSame(200, $this->get('')->getStatusCode());
    }

    public function testOtherInformationOfTheApiIsReadable(): void
    {
        $this->login();

        $session = $this->get('/Session');
        $this->assertSame(200, $session->getStatusCode());
        $this->assertSame(getItemByTypeName('User', TU_USER, true), $this->decode($session)['user_id']);

        $user = $this->get('/Administration/User/' . getItemByTypeName('User', TU_USER, true));
        $this->assertSame(200, $user->getStatusCode());
        $this->assertSame(TU_USER, $this->decode($user)['username']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forbiddenPathProvider(): iterable
    {
        yield 'authorize' => ['/authorize'];
        yield 'trailing slash' => ['/authorize/'];
        yield 'uppercase' => ['/Authorize'];
        yield 'double slash' => ['//authorize'];
        yield 'token' => ['/token'];
    }

    #[DataProvider('forbiddenPathProvider')]
    public function testOAuthPathsAreNotProxied(string $path): void
    {
        $this->login();

        // `accept` would make the API v2 grant the authorization to the client with a GET
        $response = $this->get($path, [
            'client_id'     => 'any',
            'response_type' => 'code',
            'redirect_uri'  => 'https://example.org/cb',
            'accept'        => '1',
        ]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('', (string) $response->headers->get('Location'));
    }

    public function testOnlyTheFirstSegmentDecidesWhichPathsAreForbidden(): void
    {
        $this->assertFalse(ApiController::isForbiddenPath('/Assets/Computer'));
        $this->assertFalse(ApiController::isForbiddenPath('/Administration/authorize'));
        $this->assertFalse(ApiController::isForbiddenPath(''));
    }

    public function testExpiredPasswordIsRefused(): void
    {
        $this->login();
        $_SESSION['glpi_password_expired'] = 1;

        $response = $this->get('/Assets/Computer');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('"name"', (string) $response->getContent());
    }

    public function testAnswersAreNotCacheable(): void
    {
        $this->login();
        $ok = $this->get('/Assets/Computer');
        $this->assertNotCacheable($ok);

        $this->logOut();
        $denied = $this->get('/Assets/Computer');
        $this->assertSame(401, $denied->getStatusCode());
        $this->assertNotCacheable($denied);
    }

    private function assertNotCacheable(Response $response): void
    {
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame('Cookie', $response->headers->get('Vary'));
    }

    /**
     * HEAD, OPTIONS and TRACE are never stopped by the CSRF check of GLPI, and the other methods reach the controller
     * with a valid CSRF token
     *
     * @return iterable<string, array{string}>
     */
    public static function refusedMethodProvider(): iterable
    {
        foreach (['HEAD', 'OPTIONS', 'TRACE', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('refusedMethodProvider')]
    public function testOnlyGetIsAccepted(string $method): void
    {
        $this->login();
        $path = '/Assets/Computer';

        $response = (new ApiController())->proxy(Request::create('/plugins/apisession/v2' . $path, $method), $path);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET', $response->headers->get('Allow'));
        $this->assertSame('ERROR_METHOD_NOT_ALLOWED', $this->decode($response)['status']);
    }
}
