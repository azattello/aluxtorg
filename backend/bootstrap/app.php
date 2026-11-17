<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($exception instanceof ValidationException) {
                return response()->json([
                    'message' => 'Проверьте правильность заполнения формы.',
                    'errors' => $exception->errors(),
                ], 422);
            }

            $status = match (true) {
                $exception instanceof AuthenticationException => 401,
                $exception instanceof AuthorizationException => 403,
                $exception instanceof ModelNotFoundException => 404,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };

            $message = match ($status) {
                401 => 'Войдите в аккаунт, чтобы продолжить.',
                403 => 'У вас недостаточно прав для этого действия.',
                404 => 'Запрошенная страница или запись не найдена.',
                405 => 'Этот способ запроса не поддерживается.',
                419 => 'Сессия устарела. Обновите страницу и попробуйте снова.',
                429 => 'Слишком много попыток. Попробуйте позже.',
                default => 'Не удалось выполнить запрос. Попробуйте позже.',
            };

            if ($exception instanceof HttpExceptionInterface && preg_match('/\\p{Cyrillic}/u', $exception->getMessage())) {
                $message = $exception->getMessage();
            }

            return response()->json(['message' => $message], $status >= 400 && $status < 600 ? $status : 500);
        });
    })->create();
