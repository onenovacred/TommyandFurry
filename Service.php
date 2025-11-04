<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $table = 'services';  // lowercase plural
    protected $fillable = ['pettype', 'service', 'date', 'time']; // allow mass assignment
}
