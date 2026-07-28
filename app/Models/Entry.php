<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'date', 'type', 'amount', 'mode', 'note'])]
class Entry extends Model
{
    //
}