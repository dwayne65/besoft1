<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CustomerInfoController extends Controller
{
    public function show(Request $request)
    {
        $request->validate([
            'phone' => ['required','string'],
        ]);

        $token = $request->header('X-Mopay-Token', env('MOPAY_TOKEN'));
        if (!$token) {
            $auth = $request->header('Authorization');
            if (is_string($auth) && str_starts_with($auth, 'Bearer ')) {
                $token = substr($auth, 7);
            }
        }
        $base = env('MOPAY_BASE_URL', 'https://api.mopay.rw');
        if (!$token) {
            return response()->json(['error' => 'API key required'], 401);
        }

        $phone = $request->string('phone')->toString();
        $response = Http::withToken($token)
            ->retry(2, 200)
            ->get($base.'/customer-info', ['phone' => $phone]);

        if ($response->failed()) {
            return response()->json(['error' => 'Lookup failed', 'details' => $response->json()], $response->status());
        }

        $data = $response->json();

        $mapped = [
            'firstName' => $data['firstName'] ?? ($data['first_name'] ?? ''),
            'lastName' => $data['lastName'] ?? ($data['last_name'] ?? ''),
            'birthDate' => $data['birthDate'] ?? ($data['birth_date'] ?? null),
            'gender' => $data['gender'] ?? ($data['genderCode'] ?? null),
            'isActive' => (bool)($data['isActive'] ?? true),
        ];

        return response()->json($mapped);
    }
}