<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApiAuthRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * ログインする。
     *
     * @param  ApiAuthRequest  $request
     * @return JsonResponse
     *
     * @throws ValidationException メールアドレスまたはパスワードが間違っている場合に投げられる。
     */
    public function login(ApiAuthRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();
        if ($user && Hash::check($request->password, $user->password)) {
            return response()->json([
                'token' => $user->createToken('api-token')->plainTextToken,
                'token_type' => 'Bearer',
            ]);
        }
        throw ValidationException::withMessages([
            'email' => ['メールアドレスまたはパスワードが間違っています。'],
        ]);
    }

    /**
     * ログアウトする。
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'ログアウトしました。']);
    }
}
