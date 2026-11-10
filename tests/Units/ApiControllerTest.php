<?php

namespace GlpiPlugin\Apisession\Tests\Units;

use Computer;
use Document;
use Glpi\Api\HL\Controller\AbstractController;
use Glpi\Api\HL\Router;
use Item_OperatingSystem;
use OperatingSystem;
use Glpi\Tests\DbTestCase;
use GlpiPlugin\Apisession\Controller\ApiController;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
    private function get(string $path, array $query = [], array $headers = [], bool $superglobals = false): Response
    {
        $request = Request::create('/plugins/apisession/v2' . $path, 'GET', $query);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        if (!$superglobals) {
            return (new ApiController())->proxy($request, $path);
        }

        $get = $_GET;
        $request_parameters = $_REQUEST;
        $_GET = $query;
        $_REQUEST = $query;
        try {
            return (new ApiController())->proxy($request, $path);
        } finally {
            $_GET = $get;
            $_REQUEST = $request_parameters;
        }
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

    public static function superglobalsProvider(): iterable
    {
        yield 'Symfony request only' => [false];
        yield 'query also in the superglobals' => [true];
    }

    #[DataProvider('superglobalsProvider')]
    public function testMandatoryFilterIsNotForwarded(bool $superglobals): void
    {
        $this->login();
        $computers = $this->createComputers();
        $operating_system = $this->createItem(OperatingSystem::class, ['name' => 'apisession-' . $this->getUniqueString()]);
        $installations = [];
        foreach ($computers as $key => $computer) {
            $installations[$key] = $this->createItem(Item_OperatingSystem::class, [
                'itemtype'            => Computer::class,
                'items_id'            => $computer->getID(),
                'operatingsystems_id' => $operating_system->getID(),
            ]);
        }
        $path = '/Assets/Computer/' . $computers['child1']->getID() . '/OSInstallation';

        $response = $this->get($path, ['_mandatory_filter' => 'id=gt=0,id=gt=0'], [], $superglobals);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([$installations['child1']->getID()], array_column($this->decode($response), 'id'));
    }

    public static function reservedParameterProvider(): iterable
    {
        yield 'mandatory filter' => ['_mandatory_filter', true];
        yield 'any underscore prefix' => ['_x', true];
        yield 'filter' => ['filter', false];
        yield 'start' => ['start', false];
        yield 'limit' => ['limit', false];
    }

    #[DataProvider('reservedParameterProvider')]
    public function testReservedParametersAreRecognised(string $name, bool $reserved): void
    {
        $this->assertSame($reserved, ApiController::isReservedParameter($name));
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

    public function testDocumentDownloadHasContent(): void
    {
        $this->login();
        $content = 'apisession-' . $this->getUniqueString();
        $prefix = uniqid('', true);
        $filename = 'apisession.txt';
        file_put_contents(GLPI_TMP_DIR . '/' . $prefix . $filename, $content);
        $document = $this->createItem(Document::class, [
            'name'             => 'apisession-' . $this->getUniqueString(),
            '_filename'        => [$prefix . $filename],
            '_prefix_filename' => [$prefix],
        ]);
        $this->assertFileExists(GLPI_DOC_DIR . '/' . $document->fields['filepath']);

        $response = $this->get('/Management/Document/' . $document->getID() . '/Download');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(StreamedResponse::class, $response);
        ob_start();
        try {
            $response->sendContent();
        } finally {
            $body = ob_get_clean();
        }
        $this->assertSame($content, $body);
        $this->assertNotCacheable($response);
    }

    public function testDocumentWithoutFileIsA404Json(): void
    {
        $this->login();
        $prefix = uniqid('', true);
        $filename = 'apisession.txt';
        file_put_contents(GLPI_TMP_DIR . '/' . $prefix . $filename, 'apisession-' . $this->getUniqueString());
        $document = $this->createItem(Document::class, [
            'name'             => 'apisession-' . $this->getUniqueString(),
            '_filename'        => [$prefix . $filename],
            '_prefix_filename' => [$prefix],
        ]);
        unlink(GLPI_DOC_DIR . '/' . $document->fields['filepath']);

        $response = $this->get('/Management/Document/' . $document->getID() . '/Download');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertSame(AbstractController::ERROR_ITEM_NOT_FOUND, $this->decode($response)['status']);
        $this->assertNotCacheable($response);
    }

    public static function httpExceptionProvider(): iterable
    {
        yield 'access denied' => [new AccessDeniedHttpException(), 403, AbstractController::ERROR_RIGHT_MISSING];
        yield 'not found' => [new NotFoundHttpException(), 404, AbstractController::ERROR_ITEM_NOT_FOUND];
        yield 'bad request' => [new BadRequestHttpException(), 400, AbstractController::ERROR_GENERIC];
    }

    #[DataProvider('httpExceptionProvider')]
    public function testHttpExceptionsKeepTheirStatus(HttpExceptionInterface $exception, int $status, string $error): void
    {
        $level = ob_get_level();
        $response = ApiController::dispatch(static function () use ($exception): never {
            ob_start();
            throw $exception;
        });

        $this->assertSame($level, ob_get_level());
        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame($error, $this->decode($response)['status']);
        $this->assertNotCacheable($response);
    }

    public function testHttpServerErrorsAreLogged(): void
    {
        $response = ApiController::dispatch(
            static fn() => throw new HttpException(503, 'apisession-unavailable')
        );

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringNotContainsString('apisession-unavailable', (string) $response->getContent());
        $this->assertNotCacheable($response);
        $this->hasPhpLogRecordThatContains('apisession-unavailable', 'error');
    }

    public function testInvalidArgumentIsA400Json(): void
    {
        $response = ApiController::dispatch(static fn() => throw new InvalidArgumentException('Invalid value'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(AbstractController::ERROR_INVALID_PARAMETER, $this->decode($response)['status']);
        $this->assertNotCacheable($response);
    }

    public function testOtherExceptionsAreA500Json(): void
    {
        $level = ob_get_level();
        $response = ApiController::dispatch(static function (): never {
            ob_start();
            echo 'apisession-output';
            throw new RuntimeException('apisession-failure');
        });

        $this->assertSame($level, ob_get_level());
        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertStringNotContainsString('apisession-failure', (string) $response->getContent());
        $this->assertNotCacheable($response);
        $this->hasPhpLogRecordThatContains('apisession-failure', 'error');
    }

    public function testApiVersionIsTheLatest2x(): void
    {
        $version = Router::normalizeAPIVersion('2');
        $this->assertStringStartsWith('2.', $version);
        foreach (array_column(Router::getAPIVersions(), 'version') as $available) {
            if (str_starts_with($available, '2.')) {
                $this->assertTrue(version_compare($version, $available, '>='));
            }
        }
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
