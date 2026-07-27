#!/bin/bash
# Prefer run_ALL_tests.php for cross-platform parity (Windows SQLite, skip list).
for f in tests/Manual/test_*.php;
    do echo "=== $f ==="
    php "$f" | tail -2
done
