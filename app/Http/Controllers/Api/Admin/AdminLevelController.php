<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Level;
use Illuminate\Http\Request;

class AdminLevelController extends Controller
{
    public function index()
    {
        return $this->successResponse(Level::orderBy('min_total_earnings', 'asc')->get(), 'Levels retrieved');
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'min_total_earnings' => 'required|numeric',
            'bonus_percentage' => 'required|numeric',
        ]);

        $level = Level::findOrFail($id);
        $level->update($request->only(['min_total_earnings', 'bonus_percentage']));

        return $this->successResponse($level, 'Level updated successfully');
    }
    
    // Tambahkan method store/destroy jika ingin admin bisa menambah/hapus level
}