<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Http\Controllers\Inertia\UserProfileController as JetstreamUserProfileController;

class UserProfileController extends JetstreamUserProfileController
{
    /**
     * Keep session flags in sync without disabling 2FA on every profile reload
     * (default Jetstream logic uses time() and breaks QR/secret fetch right after enable).
     */
    protected function validateTwoFactorAuthenticationState(Request $request): void
    {
        if (! Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm')) {
            return;
        }

        $user = $request->user();

        if (is_null($user->two_factor_secret)) {
            $request->session()->put('two_factor_empty_at', time());
            $request->session()->forget('two_factor_confirming_at');

            return;
        }

        if (is_null($user->two_factor_confirmed_at)) {
            $request->session()->put('two_factor_confirming_at', time());
        }
    }
}
