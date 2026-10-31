<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** One-time page that posts the payer to the provider's checkout. */
class CheckoutController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $checkout = $request->session()->pull('portal.checkout');
        abort_unless(is_array($checkout) && isset($checkout['url']) && str_starts_with((string) $checkout['url'], 'https://'), 404);

        return response()->view('portal.checkout', ['url' => $checkout['url'], 'fields' => $checkout['fields'] ?? []])
            ->header('Cache-Control', 'no-store');
    }
}
