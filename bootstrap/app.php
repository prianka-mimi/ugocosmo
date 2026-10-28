<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\AttachJwtFromCookie;
use App\Http\Middleware\WebAdminMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Communication\Http\Middleware\TrackUserOnline;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            AttachJwtFromCookie::class,
        ]);
        $middleware->web(prepend: [
            AttachJwtFromCookie::class,
        ]);

        // Tracks authenticated users as online after each request (terminable – zero latency).
        $middleware->api(append: [
            TrackUserOnline::class,
        ]);
        $middleware->web(append: [
            TrackUserOnline::class,
        ]);

        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'web-admin' => WebAdminMiddleware::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            if ($request->is('api/*')) {
                return true;
            }

            return $request->expectsJson();
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'status' => false,
                    'status_code' => 401,
                    'status_message' => 'Unauthenticated. Please provide a valid Bearer token.',
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'status' => false,
                    'status_code' => 404,
                    'status_message' => 'Resource not found.',
                    'message' => 'Not found.',
                ], 404);
            }
        });
    })->create();
