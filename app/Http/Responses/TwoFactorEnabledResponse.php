<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorEnabledResponse as TwoFactorEnabledResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class TwoFactorEnabledResponse implements TwoFactorEnabledResponseContract
{
    public function toResponse($request)
    {
        if (Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm')) {
            $request->session()->put('two_factor_confirming_at', time());
            $request->session()->forget('two_factor_empty_at');
        }

        if ($request->wantsJson()) {
            return new JsonResponse(['status' => Fortify::TWO_FACTOR_AUTHENTICATION_ENABLED], 200);
        }

        return back()->with('status', Fortify::TWO_FACTOR_AUTHENTICATION_ENABLED);
    }
}
