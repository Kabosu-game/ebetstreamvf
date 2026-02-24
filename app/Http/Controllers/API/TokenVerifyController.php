<?php
// app/Http/Controllers/API/TokenVerifyController.php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TokenVerifyController extends Controller
{
    public function verify(Request $request)
    {
        return response()->json(['id' => $request->user()->id]);
    }
}
