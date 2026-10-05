#!/bin/sh

echo "{\"app\": \"MONCYCLE.APP\", \"version\": \"$(git describe --tags)\", \"build\": \"$(date +%Y-%m-%d)\", \"commit\": \"$(git rev-parse --short HEAD)\"}" > www_data/api/version.json

# known advisories on the locked dependencies: informative, never blocks the build
composer audit --working-dir=www_data || echo "composer audit: advisories found, or composer is not installed"

docker build --platform linux/amd64 -t jeanio/moncycle.app .
