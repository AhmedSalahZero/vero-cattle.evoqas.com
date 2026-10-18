<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role'                => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'          => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission'  => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'subscription.active' => \App\Http\Middleware\CheckSubscriptionActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // QA-004: when a save/confirm button is refused with a plain "422 + message"
        // (abort(422, '...')), show the message in the normal red banner at the top of the
        // SAME page, keeping everything the user typed — instead of a raw error pop-up.
        // Only for page-app (Inertia) requests that change data; API/tests are untouched.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            if ($e->getStatusCode() === 422
                && $request->header('X-Inertia')
                && ! $request->isMethod('GET')) {
                $msg = $e->getMessage() !== '' ? $e->getMessage() : 'The request could not be completed.';
                // withErrors() makes the page treat this as a FAILED save (a modal stays open and keeps what was
                // typed); the message itself is shown by the red toast in the layout.
                return back()->withErrors(['_error' => $msg])->with('error', $msg);
            }
        });
    })->create();