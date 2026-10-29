#!/bin/bash

set -euo pipefail

cd "$(dirname "$0")"
exec phpunit --configuration phpunit.xml --display-all-issues "$@"

