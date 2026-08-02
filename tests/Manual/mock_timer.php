<?php

/**
 * Mock Workerman\Timer для тестов без event loop.
 * Подключается ДО autoload чтобы namespace Workerman\Timer
 * был объявлен первым и не конфликтовал с реальным классом.
 *
 * EPIC-11.2: supports fire() for accelerated timer audit tests.
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
        public  static int   $fireCount = 0;

        public static function add(float $interval, callable $cb, bool $persistent = true): int
        {
            $id = self::$nextId++;
            self::$active[$id] = [
                'interval'   => $interval,
                'cb'         => $cb,
                'persistent' => $persistent,
            ];
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

        public static function fire(int $id): bool
        {
            if (!isset(self::$active[$id])) {
                return false;
            }

            $entry = self::$active[$id];
            ($entry['cb'])();
            self::$fireCount++;

            if (!$entry['persistent']) {
                unset(self::$active[$id]);
            }

            return true;
        }

        public static function fireAll(): void
        {
            foreach (array_keys(self::$active) as $id) {
                self::fire((int) $id);
            }
        }

        public static function reset(): void
        {
            self::$nextId    = 1;
            self::$active    = [];
            self::$addCount  = 0;
            self::$delCount  = 0;
            self::$fireCount = 0;
        }
    }
}

// ─── Пространство имен Workerman ─────────────────────────────────────────────
namespace Workerman {

    class Timer
    {
        public static function add(float $interval, callable $cb, array $args = [], bool $persistent = true): int
        {
            return \MockTimer::add($interval, $cb, $persistent);
        }

        public static function del(int $id): bool
        {
            return \MockTimer::del($id);
        }
    }
}
