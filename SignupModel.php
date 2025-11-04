<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SignupModel extends Model
{
    protected $table = 'sign_up';  // Use lowercase with underscores
    protected $fillable = ['mobileno', 'Full_Name','Email','Password']; // allow mass assignment
}
