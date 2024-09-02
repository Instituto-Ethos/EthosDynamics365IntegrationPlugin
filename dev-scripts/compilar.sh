#!/bin/bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
CDIR=$( pwd )
cd $DIR/../ethos-dynamics-365-integration
docker run -it -v `pwd`:/compilar --rm --user www-data:www-data composer bash -c "cd /compilar && composer install"
