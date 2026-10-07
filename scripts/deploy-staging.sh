#!/bin/bash
# Deploy the cPanel Git checkout to the isolated Herrera staging installation.
set -Eeuo pipefail
umask 077

source_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
target_root=/home/herrera/herrera.herrera.hr
php_bin=/opt/cpanel/ea-php84/root/usr/bin/php
composer_bin=/bin/composer
backup_base=/home/herrera/.herrera-staging-backups

fail() { printf '%s\n' "Deployment stopped: $*" >&2; exit 1; }
fingerprint() {
    git -C "$source_root" ls-files -c -o --exclude-standard -z -- resources app/Livewire composer.lock package.json package-lock.json vite.config.js tailwind.config.js postcss.config.js |
        "$php_bin" -r '$files=array_unique(array_filter(explode("\0",stream_get_contents(STDIN)))); sort($files,SORT_STRING); $hash=hash_init("sha256"); foreach($files as $file){if(!is_file($argv[1]."/".$file)){exit(1);} hash_update($hash,$file."\0".hash_file("sha256",$argv[1]."/".$file)."\0");} echo hash_final($hash);' "$source_root"
}

if [[ "${1:-}" == --stamp-build ]]; then
    php_bin="${HERRERA_BUILD_PHP:-$(command -v php)}"
    [[ -f "$source_root/public/build/manifest.json" ]] || fail 'Run npm run build before stamping the bundle.'
    fingerprint > "$source_root/public/build/.source-sha256"
    printf '%s\n' 'Frontend source fingerprint saved. Commit public/build with the source changes.'
    exit 0
fi
[[ $# == 0 ]] || fail 'Supported local option: --stamp-build.'
[[ "$source_root" == /home/herrera/repositories/herrera-new ]] || fail 'Run this deployment from the registered private cPanel checkout.'
[[ -x "$php_bin" && -f "$composer_bin" ]] || fail 'PHP 8.4 or Composer is unavailable.'
command -v rsync >/dev/null || fail 'rsync is unavailable.'
command -v flock >/dev/null || fail 'flock is unavailable.'
[[ -d "$target_root" && ! -L "$target_root" && -f "$target_root/.env" && ! -L "$target_root/.env" ]] || fail 'The existing isolated staging installation was not found.'
[[ -f "$target_root/vendor/autoload.php" ]] || fail 'The existing staging dependencies were not found.'
[[ -z "$(git -C "$source_root" status --porcelain)" ]] || fail 'The cPanel checkout must be clean.'
revision="$(git -C "$source_root" rev-parse --verify HEAD)"

# Check the private environment without printing database credentials or loading cached configuration.
"$php_bin" -r '
require $argv[1]."/vendor/autoload.php";
$env=Dotenv\Dotenv::parse(file_get_contents($argv[1]."/.env"));
$bool=static fn($value)=>filter_var($value,FILTER_VALIDATE_BOOL);
if(($env["APP_ENV"]??"")!=="staging" || rtrim($env["APP_URL"]??"","/")!=="https://herrera.herrera.hr"
 || ($env["DB_DATABASE"]??"")!=="herrera_redesign" || !$bool($env["HERRERA_LOCAL_SAFE_MODE"]??false)
 || $bool($env["APP_DEBUG"]??false) || !$bool($env["STORE_B2B_ONLY"]??false)
 || !$bool($env["STOREFRONT_CACHE_ENABLED"]??false) || ($env["STOREFRONT_CACHE_STORE"]??"")!=="file"
 || (int)($env["STOREFRONT_CACHE_TTL"]??0)!==120){fwrite(STDERR,"Staging environment safety checks failed.\n");exit(1);}
if(PHP_VERSION_ID<80400 || PHP_VERSION_ID>=80500){fwrite(STDERR,"Deployment requires PHP 8.4.\n");exit(1);}
' "$target_root"

[[ -f "$source_root/public/build/.source-sha256" ]] || fail 'The committed frontend build is missing its source fingerprint.'
[[ "$(cat "$source_root/public/build/.source-sha256")" == "$(fingerprint)" ]] || fail 'Frontend sources changed: build, stamp and commit public/build locally first.'
"$php_bin" -r '
$root=$argv[1]; $manifest=json_decode(file_get_contents($root."/public/build/manifest.json"),true,512,JSON_THROW_ON_ERROR);
if(!$manifest){exit(1);} foreach($manifest as $entry){$files=array_merge([$entry["file"]??""],$entry["css"]??[],$entry["assets"]??[]);foreach($files as $file){if(!$file || str_starts_with($file,"/") || in_array("..",explode("/",$file),true) || !is_file($root."/public/build/".$file) || is_link($root."/public/build/".$file)){fwrite(STDERR,"Frontend manifest contains a missing or unsafe asset.\n");exit(1);}}}
' "$source_root"

mkdir -p "$backup_base"
exec 9>"$backup_base/deploy.lock"
flock -n 9 || fail 'Another staging deployment is running.'
backup_root="$(mktemp -d "$backup_base/$(date -u +%Y%m%dT%H%M%SZ)-${revision:0:12}-XXXXXX")"
target_root_mode="$(stat -c '%a' "$target_root")"
stage_root="$backup_root/new"
mkdir -p "$stage_root" "$backup_root/old"
cp -p "$target_root/.env" "$backup_root/old/.env"
: > "$backup_root/changed-files"
: > "$backup_root/new-files"

# Only tracked source files are copied; deployment data, uploads and symlinks are preserved.
while IFS= read -r -d '' file; do
    case "$file" in
        .git/*|.env|.env.*|auth.json|.cpanel.yml|vendor/*|node_modules/*|storage/*|tests/*|temp/*|tmp/*|public/storage|public/storage/*|public/image|public/image/*|public/hot|bootstrap/cache/*) continue ;;
    esac
    [[ -f "$source_root/$file" && ! -L "$source_root/$file" ]] || fail "Unsupported tracked file: $file"
    parent="$(dirname "$file")"
    check="$target_root"
    IFS=/ read -r -a components <<< "$file"
    for component in "${components[@]}"; do
        check="$check/$component"
        [[ ! -L "$check" ]] || fail "Refusing to overwrite a staging symlink: $file"
    done
    if [[ -f "$target_root/$file" && "$(stat -c '%a' "$target_root/$file")" == 644 ]] && cmp -s "$source_root/$file" "$target_root/$file"; then continue; fi
    mkdir -p "$stage_root/$parent"
    cp -p "$source_root/$file" "$stage_root/$file"
    if [[ -f "$target_root/$file" ]]; then
        mkdir -p "$backup_root/old/$parent"
        cp -p "$target_root/$file" "$backup_root/old/$file"
        # cPanel's PHP handler must survive a tracked .htaccess replacement.
        if [[ "$(basename "$file")" == .htaccess ]]; then
            awk '/# php -- BEGIN cPanel-generated handler/{capture=1} capture{print} /# php -- END cPanel-generated handler/{capture=0}' "$target_root/$file" >> "$stage_root/$file"
        fi
    else
        printf '%s\0' "$file" >> "$backup_root/new-files"
    fi
    printf '%s\0' "$file" >> "$backup_root/changed-files"
done < <(git -C "$source_root" ls-files -z)

# Keep runtime/autoload metadata for rollback; the large vendor tree is retained.
cp -a "$target_root/bootstrap/cache" "$backup_root/bootstrap-cache"
cp -a "$target_root/vendor/composer" "$backup_root/vendor-composer"
cp -p "$target_root/vendor/autoload.php" "$backup_root/vendor-autoload.php"
dependencies_changed=false
if ! cmp -s "$source_root/composer.lock" "$target_root/composer.lock"; then dependencies_changed=true; fi
applied=false
rollback() {
    trap - ERR
    set +e
    if [[ "$applied" == true ]]; then
        rsync -a --chmod=D755,F644 --exclude=.env "$backup_root/old/" "$target_root/"
        chmod "$target_root_mode" "$target_root"
        while IFS= read -r -d '' file; do
            case "$file" in public/*) continue ;; esac
            [[ ! -L "$target_root/$file" ]] && rm -f -- "$target_root/$file"
        done < "$backup_root/new-files"
        if [[ "$dependencies_changed" == true ]]; then
            (cd "$target_root" && "$php_bin" "$composer_bin" install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts)
        else
            rsync -a "$backup_root/vendor-composer/" "$target_root/vendor/composer/"
            cp -p "$backup_root/vendor-autoload.php" "$target_root/vendor/autoload.php"
        fi
        rsync -a "$backup_root/bootstrap-cache/" "$target_root/bootstrap/cache/"
        (cd "$target_root" && "$php_bin" artisan config:cache && "$php_bin" artisan route:cache && "$php_bin" artisan view:cache && "$php_bin" artisan up)
    fi
    printf '%s\n' "Deployment failed; rollback attempted. Private backup: $backup_root" >&2
    exit 1
}
trap rollback ERR

cd "$target_root"
[[ ! -f storage/framework/down ]] || fail 'Staging is already in maintenance mode; leave its existing state untouched.'
"$php_bin" artisan down --retry=10
applied=true
# Explicit parents avoid private umask defaults for newly introduced public paths.
while IFS= read -r -d '' file; do
    parent="$(dirname "$file")"
    if [[ "$parent" != . ]]; then
        install -d -m 755 "$target_root/$parent"
    fi
done < "$backup_root/changed-files"
rsync -a --no-implied-dirs --chmod=D755,F644 --from0 --files-from="$backup_root/changed-files" "$stage_root/" "$target_root/"
if [[ "$dependencies_changed" == true ]]; then
    "$php_bin" "$composer_bin" install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts
else
    "$php_bin" "$composer_bin" dump-autoload --no-dev --optimize --no-interaction --no-scripts
fi
"$php_bin" artisan package:discover --ansi
"$php_bin" artisan config:cache
"$php_bin" artisan route:cache
"$php_bin" artisan view:cache
"$php_bin" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); app(App\Services\Front\GuestStorefrontCache::class)->invalidate();'
"$php_bin" artisan up
printf '%s\n' "$revision" > "$target_root/.herrera-deploy-revision"
trap - ERR
printf '%s\n' "Staging deployed: $revision. Private rollback files: $backup_root"
