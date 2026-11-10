<?php

namespace GlpiPlugin\Apisession\Controller;

use Glpi\Api\HL\Controller\AbstractController as ApiAbstractController;
use Glpi\Api\HL\Middleware\InternalAuthMiddleware;
use Glpi\Api\HL\Router;
use Glpi\Api\HL\StreamedResponseWrapper;
use Glpi\Controller\AbstractController;
use Glpi\Error\ErrorHandler;
use Glpi\Http\Firewall;
use Glpi\Http\JSONResponse as ApiJsonResponse;
use Glpi\Http\Request;
use Glpi\Http\Response;
use Glpi\Security\Attribute\SecurityStrategy;
use InvalidArgumentException;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Read-only access to the GLPI API v2 (High-Level API) with the GLPI web session of the user, i.e. its cookie.
 *
 * The API v2 accepts the session cookie only on a few routes of its own and plugins cannot add an authentication
 * middleware to it: this controller runs the API v2 router inside a regular (stateful) GLPI route instead, where the
 * session is already started from the cookie, and authenticates the request with that session only.
 *
 * Only GET is accepted: the endpoint never writes, so it is not exposed to CSRF. The other methods are refused here
 * and not with the methods of the route, because GLPI turns a method not allowed by a plugin route into an error 500.
 */
final class ApiController extends AbstractController
{
    /**
     * Headers that could make the API v2 use another identity, profile or entity than the ones of the web session
     */
    private const IGNORED_HEADERS = [
        'authorization',
        'glpi-profile',
        'glpi-entity',
        'glpi-entity-recursive',
        'glpi-api-version',
    ];

    /**
     * First segments of the API v2 paths that are not proxied
     */
    private const FORBIDDEN_FIRST_SEGMENTS = ['authorize', 'token'];

    #[Route(
        '/v2{path}',
        name: 'api_v2',
        requirements: ['path' => '(/.*)?'],
        defaults: ['path' => ''],
    )]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function proxy(SymfonyRequest $request, string $path = ''): SymfonyResponse
    {
        if (!$request->isMethod('GET')) {
            return self::methodNotAllowedResponse();
        }
        if (Session::getLoginUserID() === false) {
            return self::unauthenticatedResponse();
        }
        // The user has to change the password before using GLPI: the web UI does not serve data until then
        if (Session::mustChangePassword()) {
            return self::forbiddenResponse('The password of the user has expired');
        }
        if (self::isForbiddenPath($path)) {
            return self::forbiddenResponse('This path is not available');
        }

        $headers = self::filterHeaders($request->headers->all());
        $headers['GLPI-API-Version'] = Router::normalizeAPIVersion('2');

        $query = self::filterParameters($request->query->all());
        $api_request = (new Request('GET', '/' . trim($path, '/'), $headers))->withQueryParams($query);
        // The router reads the parameters (e.g. the RSQL filter) from the request parameters, not from the query
        foreach ($query as $name => $value) {
            $api_request->setParameter((string) $name, $value);
        }

        $router = Router::getInstance();
        // Authenticates with the session that GLPI started from the cookie. It is also the only authentication that
        // the router keeps when the API v2 is disabled in the GLPI configuration.
        $router->registerAuthMiddleware(new InternalAuthMiddleware(), PHP_INT_MAX);

        return self::dispatch(static fn(): Response => $router->handleRequest($api_request));
    }

    public static function dispatch(callable $handle): SymfonyResponse
    {
        $output_buffer_level = ob_get_level();
        try {
            $response = $handle();
        } catch (HttpExceptionInterface $e) {
            self::cleanOutputBuffers($output_buffer_level);
            $response = self::httpExceptionResponse($e);
        } catch (InvalidArgumentException $e) {
            self::cleanOutputBuffers($output_buffer_level);
            $response = new ApiJsonResponse(
                ApiAbstractController::getErrorResponseBody(
                    ApiAbstractController::ERROR_INVALID_PARAMETER,
                    $e->getMessage()
                ),
                400
            );
        } catch (Throwable $e) {
            self::cleanOutputBuffers($output_buffer_level);
            ErrorHandler::logCaughtException($e);
            $response = new ApiJsonResponse(null, 500);
        }

        if ($response instanceof StreamedResponseWrapper) {
            return self::withPrivateCacheHeaders($response->getSymfonyResponse());
        }

        return self::withPrivateCacheHeaders(new SymfonyResponse(
            (string) $response->getBody(),
            $response->getStatusCode(),
            $response->getHeaders()
        ));
    }

    /**
     * @param array<string, mixed> $headers the headers of the request, with lowercase names (Symfony)
     *
     * @return array<string, mixed> the headers without the ones that select another identity, profile or entity
     */
    public static function filterHeaders(array $headers): array
    {
        return array_diff_key($headers, array_flip(self::IGNORED_HEADERS));
    }

    private static function httpExceptionResponse(HttpExceptionInterface $exception): Response
    {
        $status = $exception->getStatusCode();
        if ($status >= 500) {
            ErrorHandler::logCaughtException($exception);
        }

        return match ($status) {
            403 => ApiAbstractController::getAccessDeniedErrorResponse(),
            404 => ApiAbstractController::getNotFoundErrorResponse(),
            default => new ApiJsonResponse(
                $status >= 500
                    ? null
                    : ApiAbstractController::getErrorResponseBody(ApiAbstractController::ERROR_GENERIC, 'Invalid request'),
                $status
            ),
        };
    }

    private static function cleanOutputBuffers(int $level): void
    {
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
    }

    public static function filterParameters(array $parameters): array
    {
        return array_filter(
            $parameters,
            static fn(int|string $name): bool => !self::isReservedParameter((string) $name),
            ARRAY_FILTER_USE_KEY
        );
    }

    public static function isReservedParameter(string $name): bool
    {
        return str_starts_with($name, '_');
    }

    /**
     * `/authorize` of the API v2 grants an OAuth authorization to a client with a GET when the user is logged in
     * (`accept` parameter): it must not be reachable by a link of another site.
     */
    public static function isForbiddenPath(string $path): bool
    {
        $segments = array_values(array_filter(explode('/', $path), static fn(string $s): bool => $s !== ''));

        return in_array(strtolower($segments[0] ?? ''), self::FORBIDDEN_FIRST_SEGMENTS, true);
    }

    /**
     * The answers depend on the user of the session: no shared cache may store them
     */
    public static function withPrivateCacheHeaders(SymfonyResponse $response): SymfonyResponse
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Vary', 'Cookie');

        return $response;
    }

    public static function forbiddenResponse(string $detail): SymfonyResponse
    {
        return self::withPrivateCacheHeaders(new JsonResponse([
            'title'  => 'Forbidden',
            'detail' => $detail,
            'status' => 'ERROR_FORBIDDEN',
        ], 403));
    }

    public static function methodNotAllowedResponse(): SymfonyResponse
    {
        return self::withPrivateCacheHeaders(new JsonResponse([
            'title'  => 'Method not allowed',
            'detail' => 'Only GET is accepted',
            'status' => 'ERROR_METHOD_NOT_ALLOWED',
        ], 405, ['Allow' => 'GET']));
    }

    public static function unauthenticatedResponse(): SymfonyResponse
    {
        return self::withPrivateCacheHeaders(new JsonResponse([
            'title'  => 'You are not authenticated',
            'detail' => 'There is no GLPI session for this request',
            'status' => 'ERROR_UNAUTHENTICATED',
        ], 401));
    }
}
