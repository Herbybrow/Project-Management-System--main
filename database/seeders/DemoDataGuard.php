<?php

namespace Database\Seeders;

use RuntimeException;

final class DemoDataGuard
{
    public static function check(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo seeders may only run in local or testing environments.');
        }
    }
}
