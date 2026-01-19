<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LoginHistoryController extends Controller
{
    public function index(Request $request)
    {
        $histories = $request->user()
            ->loginHistories()
            ->latest('login_at')
            ->take(10)
            ->get();

        return $this->successResponse($histories, 'Login history retrieved');
    }
}
