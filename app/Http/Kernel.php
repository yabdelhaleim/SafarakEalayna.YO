<?php

namespace App\Http;

use App\Http\Middleware\AuthenticateWithApiToken;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureIsActive;
use App\Http\Middleware\EnsureIsAdmin;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Middleware\TrimStrings;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class Kernel extends HttpKernel
{
    protected $middleware = [
        TrustProxies::class,
        SetCacheHeaders::class,
        TrimStrings::class,
        ValidatePostSize::class,
    ];

    protected $middlewareGroups = [
        'web' => [
            StartSession::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            SubstituteBindings::class,
            AuthenticateWithApiToken::class, // Added for SSO
        ],

        'api' => [
            ThrottleRequests::class,
            SubstituteBindings::class,
        ],
    ];

    protected $routeMiddleware = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'auth.api' => AuthenticateWithApiToken::class,
        'admin' => EnsureIsAdmin::class,
        'permission' => CheckPermission::class,
        'active' => EnsureIsActive::class,
    ];
}