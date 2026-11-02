#!/bin/bash
# A bounded, single-instance worker for the registered Herrera staging checkout.
set -Eeuo pipefail
umask 077

source_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
target_root=/home/herrera/herrera.herrera.hr
private_root=/home/herrera/.herrera-staging-backups
php_bin=/opt/cpanel/ea-php84/root/usr/bin/php
check_only=false
if [[ "${1:-}" == --check && $# == 1 ]]; then check_only=true; elif [[ $# != 0 ]]; then exit 1; fi

fail() { printf '%s\n' "EPREL worker stopped: $*" >&2; exit 1; }
skip() { if [[ "$check_only" == true ]]; then printf '%s\n' "EPREL worker skipped: $*"; fi; exit 0; }
[[ "$source_root" == /home/herrera/repositories/herrera-new ]] || fail 'Use the registered cPanel checkout.'
[[ "$(id -un)" == herrera ]] || fail 'Use the Herrera hosting account.'
[[ -x "$php_bin" && -d "$target_root" && ! -L "$target_root" ]] || fail 'The staging runtime is unavailable.'
[[ -f "$target_root/.env" && ! -L "$target_root/.env" && -f "$target_root/vendor/autoload.php" ]] || fail 'The staging environment is unavailable.'
[[ -d "$private_root" && ! -L "$private_root" ]] || fail 'The private deployment directory is unavailable.'
command -v flock >/dev/null || fail 'flock is unavailable.'

# The shared deploy lock prevents code replacement during an in-flight lookup.
# A long article may outlive max-time, so subsequent cron ticks must not overlap.
exec 8>"$private_root/eprel-worker.lock"
flock -n 8 || skip 'another EPREL worker is active.'
exec 9>"$private_root/deploy.lock"
flock -sn 9 || skip 'a deployment is active.'
[[ ! -f "$target_root/storage/framework/down" ]] || skip 'staging is in maintenance mode.'
[[ -f "$target_root/.herrera-deploy-revision" && ! -L "$target_root/.herrera-deploy-revision" ]] || skip 'the deployed revision is unavailable.'
[[ -z "$(git -C "$source_root" status --porcelain)" ]] || skip 'the cPanel checkout has uncommitted changes.'
[[ "$(cat "$target_root/.herrera-deploy-revision")" == "$(git -C "$source_root" rev-parse --verify HEAD)" ]] || skip 'the checkout has not been deployed.'

# Validate the private environment before booting Laravel. Never print keys.
if "$php_bin" -r '
require $argv[1]."/vendor/autoload.php";
$env=Dotenv\Dotenv::parse(file_get_contents($argv[1]."/.env"));
$bool=static fn($value)=>filter_var($value,FILTER_VALIDATE_BOOL);
if(($env["APP_ENV"]??"")!=="staging" || rtrim($env["APP_URL"]??"","/")!=="https://herrera.herrera.hr"
 || ($env["DB_DATABASE"]??"")!=="herrera_redesign" || !$bool($env["HERRERA_LOCAL_SAFE_MODE"]??false)
 || $bool($env["APP_DEBUG"]??false) || !$bool($env["STORE_B2B_ONLY"]??false)
 || PHP_VERSION_ID<80400 || PHP_VERSION_ID>=80500){fwrite(STDERR,"EPREL staging environment safety checks failed.\n");exit(1);}
chdir($argv[1]); $app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$settings=app(App\Services\Integrations\Eprel\EprelSettingsService::class);
exit($settings->enabled() && $settings->hasApiKey() ? 0 : 3);
' "$target_root"; then
    if [[ "$check_only" == true ]]; then printf '%s\n' 'EPREL worker ready.'; exit 0; fi
else
    status=$?
    [[ "$status" == 3 ]] && skip 'EPREL is disabled or its API key is missing.'
    fail 'runtime configuration could not be verified.'
fi

cd "$target_root"
exec "$php_bin" artisan queue:work eprel --queue=eprel --stop-when-empty --max-time=50 --timeout=600 --sleep=3 --tries=3 --no-interaction
