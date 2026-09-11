<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Rental\RentalDashboardMetrics;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The rental operations dashboard: what is waiting for a human right now.
 *
 * Separate from Admin\DashboardController on purpose -- that one answers the
 * SHOP's questions (orders, revenue, catalogue) and this one answers the rental
 * business's. Neither is a general "statistics" page and neither replaces the
 * other.
 *
 * READ-ONLY. It derives nothing, advances nothing and repairs nothing: every
 * figure comes from RentalDashboardMetrics, which only reads. The actions
 * themselves stay on the screens that own them (applications, operations,
 * devices), each with its own permission check.
 */
class RentalDashboardController extends Controller
{
    public function index(Request $request, RentalDashboardMetrics $metrics): View
    {
        // The same permission the rental application list requires: seeing the
        // queue is seeing the rentals in it.
        abort_if(! $request->user()->can('view_rental_applications'), 403);

        return view('admin.rental-dashboard', [
            'metrics' => $metrics->snapshot(),
        ]);
    }
}
