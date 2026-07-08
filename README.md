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
php glimpse app:build glimpse --build-version=X.Y.Z
git add builds/glimpse && git commit -m "Build vX.Y.Z"
git tag vX.Y.Z && git push --tags
```

`self-update` discovers versions through Packagist and downloads `builds/glimpse` from the matching tag, so committing the fresh build before tagging is what ships it.

## License

MIT
