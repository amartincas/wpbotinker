<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel speaks Spanish whatever APP_LOCALE says. Only the panel's
 * requests go through here: the webhook, the queue and the conversations with
 * customers keep the application's locale.
 */
class UsePanelLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale('es');

        return $next($request);
    }
}
