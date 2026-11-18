#!/usr/bin/env bash
# Atualização da loja em produção (Hostinger, via SSH), depois de extrair o pacote novo por cima:
#   cd ~/domains/lojaforfit.com.br/loja && bash deploy.sh
# Leva ~1 minuto; a loja mostra "voltamos já" nesse intervalo. Faça fora do horário de pico.
set -euo pipefail
cd "$(dirname "$0")"

PHP="${PHP:-php}"   # se o php do SSH for outra versão que a do site: PHP=/caminho/do/php bash deploy.sh

$PHP artisan down --retry=20 || true
trap '$PHP artisan up' EXIT   # se algo falhar, a loja volta ao ar mesmo assim

# Na Hostinger o proc_open é desligado: o composer instala, mas os scripts do fim dão erro — é esperado.
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist || true
$PHP artisan package:discover
$PHP artisan filament:upgrade
$PHP artisan migrate --force
$PHP artisan optimize:clear
$PHP artisan optimize
$PHP artisan filament:optimize
[ -e public/storage ] || ln -s ../storage/app/public public/storage   # o PHP da hospedagem não cria links
$PHP artisan store:cache-clear
$PHP artisan queue:restart

echo "Deploy concluído. Confira Admin → Configurações → Saúde da loja."
