<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = PlatformSetting::all()->keyBy('setting_key');
        return response()->json(['success' => true, 'data' => $settings]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'settings'              => 'required|array',
            'settings.*.key'        => 'required|string',
            'settings.*.value'      => 'required',
            'settings.*.type'       => 'required|in:string,integer,boolean,json',
        ]);

        foreach ($request->settings as $setting) {
            PlatformSetting::set($setting['key'], $setting['value'], $setting['type']);
        }

        return response()->json(['success' => true, 'message' => 'Settings updated.']);
    }
}
