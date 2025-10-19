<?php

namespace App\Rules;

use App\Services\GridCalculatorService;

class GridValidationRules
{
    public static function getSpacingRule()
    {
        return [
            'required',
            'numeric',
            'min:' . GridCalculatorService::MIN_SPACING,
            'max:' . GridCalculatorService::MAX_SPACING,
        ];
    }
    
    public static function getLevelsRule()
    {
        return [
            'required',
            'integer',
            'min:' . GridCalculatorService::MIN_GRID_LEVELS,
            'max:' . GridCalculatorService::MAX_GRID_LEVELS,
            function ($attribute, $value, $fail) {
                if ($value % 2 !== 0) {
                    $fail('تعداد سطوح باید زوج باشد.');
                }
            },
        ];
    }
    
    public static function getCapitalRule()
    {
        return [
            'required',
            'numeric',
            'min:50000000', // حداقل 50 میلیون ریال
        ];
    }
    
    public static function getActivePercentRule()
    {
        return [
            'required',
            'numeric',
            'min:10',
            'max:80',
        ];
    }
}