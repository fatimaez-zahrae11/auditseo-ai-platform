<?php

namespace App\Support;

use Illuminate\Support\Str;

final class EmailAddress
{
    public static function canonicalize(string $email): string
    {
        return Str::lower(trim($email));
    }
}
