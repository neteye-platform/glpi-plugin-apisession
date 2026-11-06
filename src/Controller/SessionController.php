<?php

namespace GlpiPlugin\Apisession\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use Session;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Landing page of a login to GLPI started by another page of the same origin, e.g. in a hidden iframe with
 * /glpi/?redirect=<this page>. The opener detects it from its pathname: reaching it means that the GLPI session exists.
 */
final class SessionController extends AbstractController
{
    #[Route('/session', name: 'session')]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function __invoke(Request $request): Response
    {
        // Not with the methods of the route: GLPI turns a method not allowed by a plugin route into an error 500
        if (!$request->isMethod('GET')) {
            return ApiController::methodNotAllowedResponse();
        }
        if (Session::getLoginUserID() === false) {
            return ApiController::unauthenticatedResponse();
        }
        if (Session::mustChangePassword()) {
            return ApiController::forbiddenResponse('The password of the user has expired');
        }

        return new Response(
            '<!DOCTYPE html><html><head><meta charset="utf-8"><title>GLPI</title></head><body></body></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']
        );
    }
}
