<?php

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnsureIsAdmin;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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
            'admin' => EnsureIsAdmin::class,
        ]);

        // Runs first on every web and API request so that every log line and
        // every audit_events row produced while handling it shares one
        // correlation id. Prepended (not appended) so it is already bound
        // before session/auth middleware can emit anything auditable.
        $middleware->prepend(AssignCorrelationId::class);

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

        $exceptions->render(function (PostTooLargeException $e, $request) {
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
                && ! ($e instanceof ValidationException)
                && ! ($e instanceof HttpExceptionInterface)
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'مشکلی پیش آمد. لطفاً دوباره تلاش کنید.',
                ], 500);
            }
        });
    })->create();
