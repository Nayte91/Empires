<?php

namespace App\Enumeration;

enum Category: string {
    private const array ART_INFO = [
        'name' => 'art',
        'color' => '4174ae'
    ];

    private const array CIVIC_INFO = [
        'name' => 'civic',
        'color' => 'd54740'
    ];

    private const array CRAFT_INFO = [
        'name' => 'craft',
        'color' => 'e39158'
    ];

    private const array RELIGION_INFO = [
        'name' => 'religion',
        'color' => 'eace66'
    ];

    private const array SCIENCE_INFO = [
        'name' => 'science',
        'color' => '71b66e'
    ];

    case ART = 'art';
    case CIVIC = 'civic';
    case CRAFT = 'craft';
    case RELIGION = 'religion';
    case SCIENCE = 'science';


}
