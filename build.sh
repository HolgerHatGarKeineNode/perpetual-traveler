#!/bin/bash

# This script is used to build the project

# remove the old folder perpetual-traveler
rm -rf build

# create the folder build if it does not exist
mkdir -p build

# copy the folder perpetual-traveler to pt
git archive HEAD | tar -x -C build

cd build || exit

# copy .env.example to .env
cp .env.example .env

# Install the dependencies
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php83-composer:latest \
    composer update --ignore-platform-reqs

cd ..

docker build -t static-app -f static-build.Dockerfile .

# exit with success
exit 0
