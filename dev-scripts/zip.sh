#!/bin/bash

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
CDIR=$( pwd )
cd $DIR/../

rm -f ethos-dynamics-365-integration.zip
# "-x *docs-locais*" garante que documentos sensíveis locais nunca entrem
# no pacote, mesmo se forem criados acidentalmente dentro do plugin.
zip -r ethos-dynamics-365-integration.zip ethos-dynamics-365-integration -x "*docs-locais*"

cd $CDIR
