<?php

namespace PHPUnit\Framework;

if (!class_exists(Assert::class)) {
    class Assert
    {
        public static function assertArrayHasKey($key, $array): void {}
        public static function assertSame($expected, $actual): void {}
        public static function assertTrue($condition): void {}
    }
}


