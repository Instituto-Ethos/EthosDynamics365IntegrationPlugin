#!/bin/bash

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
CDIR=$( pwd )
cd "$DIR/../"
rm -f ethos-dynamics-365-integration.zip
zip -r ethos-dynamics-365-integration.zip ethos-dynamics-365-integration
cd "$CDIR"
