<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'bentorm-api', 'docs' => '/api/v1/health']));
