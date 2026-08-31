<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureIsAdmin::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*',
            'payment/callback',
        ]);

        $middleware->redirectGuestsTo(fn () => route('auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'برای ادامه باید وارد حساب کاربری شوید.',
                ], 401);
            }

            return redirect()->guest(route('auth.login'));
        });

        $exceptions->render(function (\Illuminate\Http\Exceptions\PostTooLargeException $e, $request) {
            $message = 'حجم فایل ارسالی بیش از حد مجاز سرور است. لطفاً فایل کوچک‌تری انتخاب کنید یا با پشتیبانی تماس بگیرید.';

            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $message], 413);
            }

            return back()->withErrors(['image' => $message]);
        });

        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'مورد درخواستی پیدا نشد.',
                ], 404);
            }
        });

        $exceptions->render(function (Throwable $e, $request) {
            if (
                $request->expectsJson()
                && app()->isProduction()
                && !($e instanceof \Illuminate\Validation\ValidationException)
                && !($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface)
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'مشکلی پیش آمد. لطفاً دوباره تلاش کنید.',
                ], 500);
            }
        });
    })->create();
