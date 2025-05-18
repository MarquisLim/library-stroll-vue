<?php

namespace App\Models\Models\Complaint;

use Illuminate\Database\Eloquent\Model;

class ComplaintType extends Model
{
    protected $fillable = ['slug','name','description'];

    public function complaints()
    {
        return $this->hasMany(Complaint::class);
    }
}
