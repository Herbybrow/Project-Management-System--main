<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'manage.users' => \App\Http\Middleware\EnsureCanManageUsers::class,
            'can.mutate' => \App\Http\Middleware\EnsureCanMutate::class,
            'log.denied' => \App\Http\Middleware\LogFailedAuthorization::class,
            'account.active' => \App\Http\Middleware\EnsureActiveAccount::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
