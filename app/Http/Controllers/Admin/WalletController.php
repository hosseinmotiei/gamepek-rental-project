<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class WalletController extends Controller
{
    public function index(): View
    {
        abort_if(! auth()->user()->can('view_payments'), 403);

        return view('admin.wallet.index');
    }
}
