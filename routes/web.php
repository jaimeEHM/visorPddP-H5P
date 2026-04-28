<?php

use App\Http\Controllers\H5PPreviewController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Lti\OidcAuthorizeController;
use App\Http\Controllers\Lti\PlatformAccessController;
use App\Http\Controllers\LtiPlatformController;
use App\Http\Controllers\XapiStatementForwardController;
use App\Http\Middleware\EnsureLtiPlatformsAccess;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::post('/lti/login', [PlatformAccessController::class, 'store'])->name('lti.login.store');
Route::post('/lti/logout', [PlatformAccessController::class, 'destroy'])->name('lti.logout');
Route::get('/lti/plataformas', [LtiPlatformController::class, 'index'])->name('lti.platforms');
Route::middleware(EnsureLtiPlatformsAccess::class)->group(function (): void {
    Route::post('/lti/plataformas', [LtiPlatformController::class, 'store'])->name('lti.platforms.store');
    Route::put('/lti/plataformas/{platform}', [LtiPlatformController::class, 'update'])->name('lti.platforms.update');
    Route::delete('/lti/plataformas/{platform}', [LtiPlatformController::class, 'destroy'])->name('lti.platforms.destroy');
    Route::post('/lti/plataformas/{platform}/sync-jwks', [LtiPlatformController::class, 'syncJwks'])->name('lti.platforms.sync-jwks');
    Route::post('/lti/lrs/connections', [LtiPlatformController::class, 'storeLrs'])->name('lti.lrs.store');
    Route::delete('/lti/lrs/connections/{connection}', [LtiPlatformController::class, 'destroyLrs'])->name('lti.lrs.destroy');
    Route::post('/lti/lrs/connections/{connection}/test', [LtiPlatformController::class, 'testLrs'])->name('lti.lrs.test');
});
Route::get('/lti/oauth/authorize', [OidcAuthorizeController::class, 'redirectToPlatform'])->name('lti.oauth.authorize');
Route::post('/xapi/statements/forward', [XapiStatementForwardController::class, 'store'])->name('xapi.statements.forward');

Route::middleware('web')->group(function (): void {
    Route::post('/h5p-preview/upload', [H5PPreviewController::class, 'upload'])->name('h5p.preview.upload');
    Route::delete('/h5p-preview/current', [H5PPreviewController::class, 'destroy'])->name('h5p.preview.destroy');
    Route::get('/h5p-preview-token/{preview}/{token}/{path?}', [H5PPreviewController::class, 'assetWithToken'])
        ->where('path', '.*')
        ->name('h5p.preview.asset-token');
    Route::get('/h5p-preview/{preview}/{path?}', [H5PPreviewController::class, 'asset'])
        ->where('path', '.*')
        ->name('h5p.preview.asset');
});
