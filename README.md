# glimpse-cli

A command line client for the [Glimpse image API](https://glimpseimg.com). Convert, optimize, resize, thumbnail, and inspect images from your terminal; the CLI handles file reading, base64 encoding, authentication, and output files for you.

## Requirements

- PHP 8.2+
- Composer

## Installation

```bash
composer global require glimpseimg/cli
```

Make sure Composer's global bin directory (`composer global config bin-dir --absolute`) is on your `PATH`, then run `glimpse`.

The distributed `glimpse` binary is a compiled PHAR (`builds/glimpse`), so end users never see the framework's development commands, and standalone installs can update in place:

```bash
glimpse self-update
```

### Standalone PHAR

`composer global` shares one dependency pool across all your global tools, so it can fail if another tool pins an incompatible `illuminate/*` version. The PHAR bundles its dependencies and sidesteps that entirely:

```bash
curl -Lo /usr/local/bin/glimpse https://github.com/Art-Commerce-Systems/glimpse-cli/releases/latest/download/glimpse
chmod +x /usr/local/bin/glimpse
glimpse self-update   # upgrades in place from then on
```

### From source

```bash
git clone https://github.com/Art-Commerce-Systems/glimpse-cli.git
cd glimpse-cli
composer install
php glimpse --version
```

## Authentication

Create an API token in the Glimpse web app under **Settings > API Tokens**, then run:

```bash
glimpse auth
```

You will be prompted for the token; it is verified against the API and stored in `~/.config/glimpse/config.json` (or `$XDG_CONFIG_HOME/glimpse/config.json`).

```bash
glimpse auth:status   # show API URL, masked token, and who you are
glimpse auth:logout   # remove the stored token
```

Environment variables override the stored config, which is handy for CI and local development:

| Variable | Purpose |
| --- | --- |
| `GLIMPSE_TOKEN` | API token (beats the stored token) |
| `GLIMPSE_API_URL` | API base URL (default `https://glimpseimg.com/api`) |

## Usage

All commands accept a file path as input, or `-` to read from stdin. Output defaults to a file next to the input; pass `--output=-` (short form `-o-`) to write the raw image bytes to stdout. Use `--json` for machine-readable metadata and `--force` to overwrite an existing output file.

### Convert

```bash
glimpse convert photo.png --format=webp          # writes photo.webp
glimpse convert photo.png -o hero.avif           # format inferred from the extension
```

Supported formats: `jpg`, `png`, `webp`, `gif`, `avif`.

### Optimize

```bash
glimpse optimize photo.jpg                       # lossless optimizer chain, writes photo.optimized.jpg
glimpse optimize photo.jpg --quality=70          # lossy re-encode at quality 70
```

### Resize

Fits the image into a bounding box, preserving aspect ratio and never upscaling.

```bash
glimpse resize photo.jpg --width=800             # writes photo.resized.jpg
glimpse resize photo.jpg --width=800 --height=600
```

### Thumbnail

Resize plus lossy re-encode in one pass. API defaults: 300x300 box at quality 60.

```bash
glimpse thumbnail photo.jpg                      # writes photo.thumb.jpg
glimpse thumbnail photo.jpg --width=150 --quality=50
```

### Info

```bash
glimpse info photo.jpg                           # pretty metadata table
glimpse info photo.jpg --json | jq .format       # raw JSON
```

### Piping

```bash
glimpse thumbnail photo.jpg --output=- | imgcat
cat photo.png | glimpse convert - --format=webp -o photo.webp
```

## Limits

- Input images are capped at 15 MiB.
- Dimensions are capped at 10000 px.

## Development

```bash
composer test        # Pint (check), PHPStan, and the Pest suite
```

### Releasing

```bash
php glimpse app:build glimpse --build-version=vX.Y.Z
git add builds/glimpse && git commit -m "Build vX.Y.Z" && git push
gh release create vX.Y.Z builds/glimpse --title vX.Y.Z --generate-notes
```

`self-update` discovers versions through Packagist and downloads the `glimpse` PHAR attached to the GitHub release of the matching tag, so the release must carry the binary as an asset under exactly that name. Committing the fresh build keeps `composer global require` working (composer's `bin` points at `builds/glimpse`) and lets pre-0.1.1 installs, which download `builds/glimpse` from the tag itself, still update.

The `--build-version` must be the tag name verbatim, including the `v` prefix. The updater compares the embedded build version against the Packagist tag as plain strings, so a build stamped `0.1.0` never equals the tag `v0.1.0` and `self-update` would keep re-downloading the release it is already running.

## License

MIT
