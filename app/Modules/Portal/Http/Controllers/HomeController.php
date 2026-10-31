<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Services\PortalAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Where a login lands after signing in: staff area, teacher dashboard or a family portal. */
class HomeController extends Controller
{
    public function __invoke(Request $request, PortalAccess $access): RedirectResponse
    {
        return redirect()->to($access->homeUrl($request->user()));
    }
}
