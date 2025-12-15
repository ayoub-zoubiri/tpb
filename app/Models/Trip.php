<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Trip extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'destination',
        'duration',
        'budget',
        'interests',
        'trip_title',
        'summary',
    ];

    public function user()
    {
        return $this->belongsTo(User::class)->withDefault();
    }

    public function dayPlans()
    {
        return $this->hasMany(DayPlan::class);
    }
}
