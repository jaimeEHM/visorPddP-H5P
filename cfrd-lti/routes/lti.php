<?php

use Cfrd\Lti\Http\Controllers\JwksController;
use Cfrd\Lti\Http\Controllers\LaunchController;
use Cfrd\Lti\Http\Controllers\OidcLoginInitiationController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::get('/lti/jwks.json', [JwksController::class, 'show'])->name('cfrd.lti.jwks');
    Route::match(['get', 'post'], '/lti/oauth/login-initiation', [OidcLoginInitiationController::class, 'show'])->name('cfrd.lti.oidc.login_initiation');
    Route::post('/lti/launch', [LaunchController::class, 'handle'])->name('cfrd.lti.launch');
});
