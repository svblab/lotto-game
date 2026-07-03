#!/bin/bash
for f in tests/Manual/test_*.php;
    do echo "=== $f ==="
    php "$f" | tail -2
done
