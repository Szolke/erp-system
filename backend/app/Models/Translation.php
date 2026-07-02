<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['namespace', 'key', 'locale', 'value'])]
class Translation extends Model {}
