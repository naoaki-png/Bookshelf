<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApiAuthRequest;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(ApiAuthRequest $request)
    {
        $user = User::where('email', $request->email)->first();
        if ($user && Hash::check($request->password, $user->password)) {
            return response()->json([
                'token' => $user->createToken('api-token')->plainTextToken,
                'token_type' => 'Bearer'
            ]);
        }
        throw \Illuminate\Validation\ValidationException::withMessages([
            'email' => ['メールアドレスまたはパスワードが間違っています。'],
        ]);
    }
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'ログアウトしました。'], );

    }


}
