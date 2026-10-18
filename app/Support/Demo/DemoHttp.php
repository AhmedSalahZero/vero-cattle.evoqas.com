<?php

namespace App\Support\Demo;

// ══════════════════════════════════════════════════════════════════
//  DemoHttp
//
//  Lets the demo builder (php artisan demo:seed) press the app's own
//  "buttons" — it sends real requests to the real routes, exactly as
//  the screens and the automated tests do. That way every journal
//  entry, stock movement and invoice is created by the SAME code a
//  user would trigger, so the books are always correct.
//
//  Nothing here is used while the app runs normally.
//
//  @layer Support (demo only)
// ══════════════════════════════════════════════════════════════════

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\Concerns\InteractsWithAuthentication;
use Illuminate\Foundation\Testing\Concerns\InteractsWithContainer;
use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;

class DemoHttp
{
    use InteractsWithAuthentication, InteractsWithContainer, MakesHttpRequests;

    /** @var Application */
    public $app;

    public function __construct(Application $app)
    {
        $this->app = $app;

        // The CSRF check protects browsers; this runs inside the server itself.
        $skip = new class {
            public function handle($request, $next)
            {
                return $next($request);
            }
        };
        foreach ([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
        ] as $class) {
            if (class_exists($class)) {
                $app->instance($class, $skip);
            }
        }
    }

    /**
     * Send one request and boil the answer down to something simple.
     *
     * @return array{ok:bool,status:int,error:?string,warning:?string}
     */
    public function send(string $method, string $uri, array $data = []): array
    {
        $session = $this->app->make('session.store');
        $session->flush();

        $response = $this->json($method, $uri, $data);
        $status   = $response->getStatusCode();

        $error = null;
        if ($status >= 400) {
            $body  = json_decode($response->getContent(), true);
            $error = is_array($body)
                ? ($this->firstError($body['errors'] ?? []) ?: ($body['message'] ?? null))
                : null;
            $error = $error ?: ('HTTP ' . $status);
        } else {
            // A refusal that sends the user "back" carries its message in the session.
            $bag = $session->get('errors');
            if ($bag && method_exists($bag, 'any') && $bag->any()) {
                $error = collect($bag->getBags())->flatMap(fn ($b) => $b->all())->first();
            }
        }

        $warning = $session->get('warning');

        return [
            'ok'      => $error === null,
            'status'  => $status,
            'error'   => $error,
            'warning' => is_string($warning) ? $warning : null,
        ];
    }

    private function firstError($errors): ?string
    {
        if (! is_array($errors)) {
            return null;
        }
        foreach ($errors as $field => $messages) {
            $first = is_array($messages) ? ($messages[0] ?? null) : $messages;
            if ($first) {
                return $field . ': ' . $first;
            }
        }
        return null;
    }
}
