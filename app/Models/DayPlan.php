<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DayPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_id',
        'day_number',
        'theme',
    ];

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }

    public function activities()
    {
        return $this->hasMany(Activity::class);
    }
}
