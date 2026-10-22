<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/** Base for all API resources: floats keep their decimal part (0.0, 12.0) so typed clients always get doubles. */
abstract class Resource extends JsonResource
{
    public function jsonOptions()
    {
        return JSON_PRESERVE_ZERO_FRACTION;
    }
}
