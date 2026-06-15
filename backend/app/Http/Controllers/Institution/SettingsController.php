<?php

namespace App\Http\Controllers\Institution;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $institution = $request->user()->institution;
        return response()->json(['success' => true, 'data' => $institution]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contact_person' => 'sometimes|string',
            'phone'          => 'sometimes|string|max:20',
            'address'        => 'nullable|string',
            'city'           => 'nullable|string',
            'state'          => 'nullable|string',
            'website'        => 'nullable|url',
            'settings'       => 'nullable|array',
        ]);

        $institution = $request->user()->institution;
        $institution->update($data);

        return response()->json(['success' => true, 'data' => $institution]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate(['logo' => 'required|image|mimes:jpg,jpeg,png|max:2048']);

        $path = $request->file('logo')->store("institutions/{$request->user()->institution_id}/logo", 'public');

        $request->user()->institution->update(['logo_path' => $path]);

        return response()->json(['success' => true, 'logo_path' => $path]);
    }
}
