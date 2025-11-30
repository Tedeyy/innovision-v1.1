<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactVerificationLog extends Model
{
    protected $primaryKey = 'contactverify_id';
    protected $fillable = ['user_id', 'userrole', 'contact'];
    
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
