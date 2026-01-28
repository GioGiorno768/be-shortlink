<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;
use App\Traits\ApiResponseTrait;

abstract class Controller extends BaseController
{
    use ApiResponseTrait;
}
