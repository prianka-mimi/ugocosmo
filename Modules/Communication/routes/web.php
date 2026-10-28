<?php

use Illuminate\Support\Facades\Route;

Route::get('communication/broadcast-tester', function () {
    return view('communication::broadcast-tester');
})->name('communication.broadcast-tester');
