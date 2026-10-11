<?php

namespace App\Enums;

enum SocialProvider: string
{
    case Google = 'google';
    case Apple = 'apple';
    case Facebook = 'facebook';
}
