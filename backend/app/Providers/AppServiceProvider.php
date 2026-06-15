<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Response;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Response::macro('success', function (mixed $data = null, string $message = 'Success', int $status = 200) {
            $payload = ['success' => true, 'message' => $message];
            if ($data !== null) {
                $payload['data'] = $data;
            }
            return response()->json($payload, $status);
        });

        Response::macro('error', function (string $message = 'Error', int $status = 400, mixed $errors = null) {
            $payload = ['success' => false, 'message' => $message];
            if ($errors !== null) {
                $payload['errors'] = $errors;
            }
            return response()->json($payload, $status);
        });
    }
}
