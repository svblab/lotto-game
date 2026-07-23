<?php

/**
 * Mock Workerman\Timer для тестов без event loop.
 * Подключается ДО autoload чтобы namespace Workerman\Timer
 * был объявлен первым и не конфликтовал с реальным классом.
 */

declare(strict_types=1);

// ─── Глобальное пространство имен ────────────────────────────────────────────
namespace {

    class MockTimer
    {
        private static int   $nextId   = 1;
        public  static array $active   = [];
        public  static int   $addCount = 0;
        public  static int   $delCount = 0;

        public static function add(float $interval, callable $cb): int
        {
            $id = self::$nextId++;
            self::$active[$id] = ['interval' => $interval, 'cb' => $cb];
            self::$addCount++;
            return $id;
        }

        public static function del(int $id): bool
        {
            if (isset(self::$active[$id])) {
                unset(self::$active[$id]);
                self::$delCount++;
                return true;
            }
            return false;
        }

        public static function reset(): void
        {
            self::$nextId   = 1;
            self::$active   = [];
            self::$addCount = 0;
            self::$delCount = 0;
        }
    }
}

// ─── Пространство имен Workerman ─────────────────────────────────────────────
namespace Workerman {

    class Timer
    {
        public static function add(float $interval, callable $cb, array $args = [], bool $persistent = true): int
        {
            return \MockTimer::add($interval, $cb);
        }

        public static function del(int $id): bool
        {
            return \MockTimer::del($id);
        }
    }
}