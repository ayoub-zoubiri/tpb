<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GeneratePlanRequest extends FormRequest
{
    public function authorize()
    {
        return true; 
    }

    public function rules()
    {
        return [
            'destination' => 'required|string',
            'duration' => 'required|integer|min:1|max:14',
            'budget' => 'required|string',
            'interests' => 'nullable|string',
        ];
    }
}
