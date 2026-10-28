<?php

namespace App\Http\Controllers;

use App\Manager\JsonResponseFormat;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;
    use JsonResponseFormat;
}
