<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    use HasFactory;

    protected $fillable = [
        'day_plan_id',
        'time_of_day',
        'description',
        'location',
        'latitude',
        'longitude',
        'booking_url',
        'activity_price',
        'activity_rating',
        'activity_image_url',
    ];

    public function dayPlan()
    {
        return $this->belongsTo(DayPlan::class);
    }
}
