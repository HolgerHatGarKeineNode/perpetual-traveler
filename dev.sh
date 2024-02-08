#!/bin/bash

# This script is used to start the development environment

# touch the database file
touch database/database.sqlite

# copy .env.example to .env
cp .env.example .env

# install the composer dependencies
docker run --rm \
             -u "$(id -u):$(id -g)" \
             -v $(pwd):/var/www/html \
             -w /var/www/html \
             laravelsail/php83-composer:latest \
             composer update --ignore-platform-reqs

# start the development environment
./vendor/bin/sail up -d

# install the npm dependencies
./vendor/bin/sail yarn

# migrate the database
./vendor/bin/sail artisan migrate

# compile the assets and start the dev server
./vendor/bin/sail yarn dev
