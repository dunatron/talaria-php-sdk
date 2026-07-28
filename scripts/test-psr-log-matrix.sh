#!/usr/bin/env bash
# Local matrix: exercise Talaria\Logger against psr/log 1, 2, and 3.
# Requires composer + php on PATH.
set -euo pipefail
cd "$(dirname "$0")/.."

pairs=(
  "1.1.4|1.27.1"
  "2.0.0|3.5.0"
  "3.0.0|3.5.0"
)

for pair in "${pairs[@]}"; do
  IFS='|' read -r psr monolog <<<"$pair"
  echo "=== psr/log ${psr} + monolog ${monolog} ==="
  composer require --no-update "psr/log:${psr}" "monolog/monolog:${monolog}"
  composer update --prefer-dist --no-interaction --no-progress
  php -r 'require "vendor/autoload.php"; new ReflectionClass(Talaria\Logger::class); echo "Logger loads\n";'
  composer test
done

echo "All psr/log matrix combinations passed."
