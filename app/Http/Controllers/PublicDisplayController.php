<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public lobby display.
 *
 * No auth middleware: this screen is meant to be visible to anyone in the
 * waiting area. Only queue numbers and order are rendered.
 */
class PublicDisplayController extends Controller
{
    public function index(Request $request): View
    {
        return view('display.index', [
            'windowCode' => $request->query('w'),
            'queueDate' => $request->query('date'),
        ]);
    }
}
