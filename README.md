<p align="center">
  <a href="https://glimpseimg.com"><img src="art/banner.avif" alt="glimpse: ship smaller images, skip the toolchain" width="100%"></a>
</p>

<p align="center">
  Ship smaller images. Skip the toolchain.<br>
  Convert, optimize, resize, thumbnail, analyze and inspect images from your terminal.<br>
  The first-party CLI for <a href="https://glimpseimg.com"><strong>glimpseimg.com</strong></a>, the image API for developers.
</p>

<p align="center">
  <a href="https://packagist.org/packages/mathiasgrimm/glimpse-cli"><img src="https://img.shields.io/packagist/v/mathiasgrimm/glimpse-cli?style=flat-square&label=packagist" alt="Latest Version on Packagist"></a>
  <a href="https://github.com/mathiasgrimm/glimpse-cli/actions/workflows/ci.yml"><img src="https://img.shields.io/github/actions/workflow/status/mathiasgrimm/glimpse-cli/ci.yml?branch=main&label=tests&style=flat-square" alt="Tests"></a>
  <a href="https://packagist.org/packages/mathiasgrimm/glimpse-cli"><img src="https://img.shields.io/packagist/dt/mathiasgrimm/glimpse-cli?style=flat-square" alt="Total Downloads"></a>
  <a href="https://packagist.org/packages/mathiasgrimm/glimpse-cli"><img src="https://img.shields.io/packagist/dependency-v/mathiasgrimm/glimpse-cli/php?style=flat-square&label=php" alt="PHP Version"></a>
  <a href="LICENSE"><img src="https://img.shields.io/packagist/l/mathiasgrimm/glimpse-cli?style=flat-square" alt="License"></a>
</p>

---

Shipping images means wrangling ImageMagick, libvips, mozjpeg, cwebp and avifenc: compiled binaries that differ between your laptop, your teammate's laptop, and CI. **glimpse replaces all of them with one command.** The heavy lifting happens on the [Glimpse API](https://glimpseimg.com): stateless, nothing stored, your bytes never linger. You install a single CLI and go:

```bash
composer global require mathiasgrimm/glimpse-cli
glimpse auth
glimpse convert banner.png --format=avif
```

```
Wrote banner.avif (image/avif, 24.2 KB, 3200x840)
```

That's a real session: `banner.png` is a frame of the banner at the top of this page, re-encoded from 360.3 KB down to 24.2 KB. The banner you are actually looking at goes one step further; it is a two-frame animated AVIF (watch the green dot blink) that glimpse converted from a GIF, 40.1 KB in total. Want to know what a conversion will buy you *before* you convert? `glimpse analyze` predicts the output size for every format **without uploading your image**:

<p align="center">
  <img src="art/terminal.avif" alt="glimpse analyze and convert running in a terminal" width="100%">
</p>

## Why glimpse

- **Zero image binaries.** No ImageMagick, no libvips, no format-specific encoders to install, pin, or debug across machines. If it runs PHP 8.2, it runs glimpse.
- **One command per job.** `convert`, `optimize`, `resize`, `thumbnail`, `analyze` and `info` are small, predictable commands that do one thing well.
- **5 output formats.** JPG, PNG, WebP, GIF and AVIF. Input types are verified from the actual bytes, never a filename.
- **Know before you convert.** `glimpse analyze` predicts per-format savings from metadata and a local sample probe, for a single image or a whole directory tree. The image itself is never uploaded.
- **Built for pipelines.** Reads stdin, writes stdout, `--json` on every command, and human summaries go to STDERR so your pipes stay clean.
- **Safe by default.** Never overwrites an existing file without `--force`; the optimizer never returns a file larger than its input.
- **Stateless by design.** One request in, one image out. Nothing is stored server-side.
- **Self-updating binary.** The standalone PHAR upgrades itself with `glimpse self-update`.

## Installation

### Composer

```bash
composer global require mathiasgrimm/glimpse-cli
```

Make sure Composer's global bin directory (`composer global config bin-dir --absolute`) is on your `PATH`, then run `glimpse`.

### Standalone PHAR

`composer global` shares one dependency pool across all your global tools, so it can fail if another tool pins an incompatible `illuminate/*` version. The PHAR bundles its dependencies and sidesteps that entirely:

```bash
curl -Lo /usr/local/bin/glimpse https://github.com/mathiasgrimm/glimpse-cli/releases/latest/download/glimpse
chmod +x /usr/local/bin/glimpse
glimpse self-update   # upgrades in place from then on
```

### From source

```bash
git clone https://github.com/mathiasgrimm/glimpse-cli.git
cd glimpse-cli
composer install
php glimpse --version
```

## Authentication

Grab a free API key at [glimpseimg.com](https://glimpseimg.com) (**Settings → API Tokens**). glimpse is free while we finish billing, and there will always be a free tier. Then:

```bash
glimpse auth
```

You will be prompted for the token; it is verified against the API and stored in `~/.config/glimpse/config.json` (or `$XDG_CONFIG_HOME/glimpse/config.json`).

```bash
glimpse auth:status   # show API URL, masked token, and who you are
glimpse auth:logout   # remove the stored token
```

Environment variables override the stored config, which is handy for CI:

| Variable | Purpose |
| --- | --- |
| `GLIMPSE_TOKEN` | API token (beats the stored token) |
| `GLIMPSE_API_URL` | API base URL (default `https://glimpseimg.com/api`) |

## Usage

Run `glimpse` with no arguments to see everything it can do:

<p align="center">
  <img src="art/cli-banner.avif" alt="the glimpse banner and command list printed when running glimpse without arguments" width="100%">
</p>

All commands accept a file path as input, or `-` to read from stdin. Output defaults to a file next to the input; pass `--output=-` (short form `-o-`) to write the raw image bytes to stdout. Use `--json` for machine-readable metadata and `--force` to overwrite an existing output file.

Pass `--in-place` (short form `-i`) to write the result over the input file instead of creating a suffixed sibling. When `convert` changes the format, the input is replaced by a file with the new extension (`a.jpg` → `a.avif`); overwriting the input itself never needs `--force`, but overwriting a *different* existing file (an already-present `a.avif`, say) still does.

### Init

`glimpse init` sets up a project in one step. Run it from the project root: it writes a starter [`.glimpseignore`](#glimpseignore), creates an empty [`.glimpse-baseline.json`](#glimpse-baselinejson), offers to record the current images into the baseline (pass `--update-baseline` to do that without the prompt), and prints the next steps. Inside a git repository it also offers to add a GitHub Actions workflow that runs `glimpse check`. Pass `--workflow` to write that file without the prompt (see [Continuous Integration](#continuous-integration)). A plain run needs no token and makes no API calls.

```
$ glimpse init
Created .glimpseignore.

 Scan the current directory and record every image into the baseline now (runs analyze . --update-baseline)? (yes/no) [no]:
 > no

Created .glimpse-baseline.json (empty).

Next steps:
  1. Review .glimpseignore and tune the patterns for your project.
  2. Accept the current images as already handled: glimpse analyze . --update-baseline
  3. Commit .glimpseignore and .glimpse-baseline.json.
  4. Gate new images in CI: glimpse check .  (see the Continuous Integration section of the README)
```

Init never overwrites what you have: existing files are kept with a note, and the run still exits 0. `--force` recreates the `.glimpseignore` template. Combined with `--workflow` it also recreates the workflow file. On its own it never touches an existing workflow, and it never touches a baseline. To re-seed an existing baseline, run `glimpse init --update-baseline` (or `glimpse analyze . --update-baseline` directly).

### Convert

```bash
glimpse convert photo.png --format=webp                          # writes photo.webp
glimpse convert photo.png -o hero.avif                           # format inferred from the extension
glimpse convert photo.png --format=avif -i                       # replaces photo.png with photo.avif
glimpse convert photo.png --format=webp --optimize               # optimizes the converted image
glimpse convert photo.png --format=webp --optimize --quality=70  # lossy re-encode at quality 70
```

Supported formats: `jpg`, `png`, `webp`, `gif`, `avif`.

`--optimize` runs the converted image through the optimizer chain (the result is never larger than without it). `--quality` (1-100, default 85) requires `--optimize`.

### Optimize

```bash
glimpse optimize photo.jpg                       # lossless optimizer chain, writes photo.optimized.jpg
glimpse optimize photo.jpg --quality=70          # lossy re-encode at quality 70
glimpse optimize photo.jpg --in-place            # optimizes photo.jpg itself
```

### Resize

Fits the image into a bounding box, preserving aspect ratio and never upscaling.

```bash
glimpse resize photo.jpg --width=800                           # writes photo.resized.jpg
glimpse resize photo.jpg --width=800 --height=600
glimpse resize photo.jpg --width=800 -i                        # shrinks photo.jpg itself
glimpse resize photo.jpg --width=800 --optimize --quality=70   # resize, then lossy re-encode
```

`--optimize` and `--quality` work the same as on `convert`.

### Thumbnail

Resize plus lossy re-encode in one pass. API defaults: 300x300 box at quality 60.

```bash
glimpse thumbnail photo.jpg                      # writes photo.thumb.jpg
glimpse thumbnail photo.jpg --width=150 --quality=50
glimpse thumbnail photo.jpg -i                   # turns photo.jpg itself into the thumbnail
```

### Analyze

Know before you convert: `analyze` predicts the converted size for every format so you can pick a target *before* spending a conversion. Your image is never uploaded. Only its metadata (format, size, dimensions) plus an optional locally computed sample probe are sent.

```bash
glimpse analyze banner.png
```

```
Source: PNG, 360.3 KB, 3200x840, sampled

+--------+----------------+----------+---------+---------+
| Format | Estimated size | Saved    | Saved % | Quality |
+--------+----------------+----------+---------+---------+
| JPG    | ~145.9 KB      | 214.4 KB | 59.5%   | 85      |
| PNG    | ~162.1 KB      | 198.2 KB | 55%     | -       |
| WEBP   | ~81.1 KB       | 279.2 KB | 77.5%   | 85      |
| AVIF   | ~40.5 KB       | 319.8 KB | 88.7%   | 85      |
+--------+----------------+----------+---------+---------+
Estimates are heuristics for picking a target format, not guarantees.
```

```bash
glimpse analyze banner.png --optimize --quality=70    # assume a lossier re-encode
glimpse analyze banner.png --json                     # machine-readable estimates
```

Point it at a directory and glimpse scans every image in it, recursively, behind a progress bar. Pass `--format` to see one outcome per file, or omit it to see the format that saves the most for each image. The summary is sorted by bytes saved, color-classified (green saves at least a quarter, yellow saves less than that, red would grow the file), and ends with a totalizer:

```bash
glimpse analyze assets/ --format=avif            # what would AVIF buy, file by file
glimpse analyze assets/                          # best format per image
glimpse analyze assets/ --update-baseline        # record the results as the baseline
```

<p align="center">
  <img src="art/analyze.avif" alt="glimpse analyze scanning a directory: a sorted summary with green, yellow, and red rows and a totalizer" width="100%">
</p>

This is a real session against a folder of sample assets: huge wins for the camera photos, a marginal one for an already-lean background, and one file the estimator warns would get bigger as AVIF. Files that fail (unreadable or unrecognized) are skipped and reported; the run keeps going.

Installing `ext-imagick` (or `ext-gd`) sharpens the estimates considerably: the CLI trial-encodes a small sample of your image locally to measure its actual complexity (the `sampled` tag in the output). Without either extension it falls back to size-ratio heuristics.

### Check

Fail the build, not your users. `check` is the CI gate: it scans a directory (or a single file), estimates the best-format saving for every image, and lists only the ones that would benefit from optimization. It exits `1` when any image is over the threshold, so your pipeline fails until the images are optimized. Like `analyze`, nothing is uploaded.

```bash
glimpse check .                                  # fail if any image could save 10% or more
glimpse check assets/ --threshold=25             # only fail on big wins
glimpse check . --json                           # machine-readable report
```

```
+---------------------+---------+-----------+--------+---------+
| File                | Current | Estimated | Format | Saved % |
+---------------------+---------+-----------+--------+---------+
| art/social-card.png | 1.2 MB  | ~180 KB   | AVIF   | 85.0%   |
| art/icon.png        | 96 KB   | ~34 KB    | WEBP   | 64.6%   |
+---------------------+---------+-----------+--------+---------+
2 of 14 images need optimization (threshold: 10%).
```

An image "needs optimization" when the format that saves the most (the same pick `analyze` makes) would save at least `--threshold` percent (default 10, decimals allowed). Files that cannot be checked (unreadable or unrecognized) are listed separately and also fail the run: a green check means every image was checked and passed. A directory with no images passes.

#### .glimpseignore

Drop a `.glimpseignore` file at the project root to exclude paths, using gitignore syntax:

```gitignore
# generated assets, not worth optimizing
vendor/
public/build/
*.gif
!logo.gif
```

Like the baseline below, the file is read from the directory you run glimpse from, and patterns are relative to it: with the rules above, `glimpse check .` and `glimpse check public/` both skip `public/build/`. Negated patterns (`!logo.gif`) re-include files, with the same caveat as git: a file inside an ignored directory cannot be re-included. `analyze <dir>` honors the same file, and the transform commands honor it too by never recording an ignored file into the baseline.

#### .glimpse-baseline.json

Adopting glimpse in an existing project should not require converting every image on day one. A `.glimpse-baseline.json` file at the project root records files you have already dealt with, so `check <dir>` and `analyze <dir>` skip them and only new or changed images get flagged. It is the same idea as phpstan's baseline: accept the current state, gate everything from here on.

Generate it by scanning the directory:

```bash
glimpse analyze assets/ --update-baseline
```

Or let [`glimpse init`](#init) set it up in one step, together with the `.glimpseignore` file.

The file lists each image with its size, content hash, and the operation that put it there, under a composer.lock-style `_readme` header that explains the format to anyone reading it in review:

```json
{
    "_readme": [
        "Images already handled by glimpse: check and analyze skip a file while its size and xxh128 content hash still match its entry.",
        "..."
    ],
    "files": {
        "photos/hero.png": {
            "size": 482133,
            "xxh128": "5f3d0a9c1b2e4d6f8a7c9e0b1d2f3a4c",
            "via": "analyze"
        },
        "photos/hero.webp": {
            "size": 98342,
            "xxh128": "9a1c3e5f7b2d4a6c8e0f1a3b5c7d9e0f",
            "via": "optimize"
        }
    }
}
```

An image is skipped only while its content still matches the recorded entry. Replace a baselined file with a new version and it re-enters the scan automatically. Where `.glimpseignore` says "never look at these paths", the baseline says "these exact file contents are already handled".

The `via` field separates accepted debt from finished work: `analyze` means the file was taken as-is by `--update-baseline`, a command name (`convert`, `resize`, `thumbnail`) means glimpse produced or processed it, and `optimize` means the optimizer chain ran, whether through the `optimize` command or a transform's `--optimize` flag. When you burn down a bulk-adopted baseline later, the `analyze` entries are the ones still waiting for real treatment.

Every command reads the baseline (and `.glimpseignore`) from the current working directory, the way composer and phpstan resolve their config: run glimpse from the project root and the baseline there governs the run, with entries keyed relative to it (scanning `assets/` matches keys like `assets/hero.png`). There is no upward search; run glimpse from anywhere else and the baseline simply does not apply. `--update-baseline` writes the baseline to the current directory and requires the scanned directory to be inside it.

The transform commands keep the baseline current automatically: `convert` and `optimize` record the written output and its source (an in-place conversion that replaced the source also cleans up its old entry), while `resize` and `thumbnail` record only the file they produced. Writing to stdout or to a path outside the working directory records nothing, and a baseline problem never fails a transform that already succeeded; it degrades to a warning on STDERR. The commands never create the file; that is `--update-baseline`'s job. Re-running `analyze <dir> --update-baseline` refreshes changed entries and prunes deleted files.

The baseline only applies to directory scans. Naming a file explicitly (`glimpse analyze photo.png`) always analyzes it. Commit the file so CI and your teammates share the same starting point.

Baseline writes never race each other: a run that will write takes an exclusive lock on the file before doing anything else, creating it is exclusive too, and the content lands in a temporary file swapped atomically into place. A second concurrent writer fails fast with a clear error (`analyze --update-baseline`) or degrades to a STDERR warning (the transforms) instead of silently overwriting entries, readers never observe a half-written file, and a failed write leaves the previous baseline untouched.

### Info

```bash
glimpse info photo.jpg                           # pretty metadata table
glimpse info photo.jpg --json | jq .format       # raw JSON
```

### Piping

Every command speaks stdin/stdout, so glimpse drops straight into shell pipelines. Summaries are printed to STDERR, keeping the pipe clean:

```bash
glimpse thumbnail photo.jpg --output=- | imgcat
cat photo.png | glimpse convert - --format=webp -o photo.webp
glimpse convert photo.png --format=avif --json | jq .size
```

## Continuous Integration

Add `glimpse check` to your GitHub workflow to keep unoptimized images out of your repo. The fastest way is to let init write the workflow for you, from the repo root:

```bash
glimpse init --workflow
```

This creates `.github/workflows/glimpse.yml`. A plain `glimpse init` also offers it when the directory is a git repository. Then store your API token as an [encrypted secret](https://docs.github.com/en/actions/security-guides/encrypted-secrets) named `GLIMPSE_TOKEN` (glimpse picks it up from the environment, see [Authentication](#authentication)):

```bash
gh secret set GLIMPSE_TOKEN
```

This is the generated workflow, if you prefer to copy it by hand:

```yaml
name: Glimpse

on:
  push:
    branches: [main]
  pull_request:

permissions:
  contents: read

jobs:
  check-images:
    runs-on: ubuntu-latest
    env:
      GLIMPSE_TOKEN: ${{ secrets.GLIMPSE_TOKEN }}
    steps:
      - uses: actions/checkout@v6
      - name: Install glimpse
        run: |
          curl -fsSL -o "$RUNNER_TEMP/glimpse" https://github.com/mathiasgrimm/glimpse-cli/releases/latest/download/glimpse
          chmod +x "$RUNNER_TEMP/glimpse"
          echo "$RUNNER_TEMP" >> "$GITHUB_PATH"
      - name: Check images
        if: env.GLIMPSE_TOKEN != ''
        run: glimpse check .
      - name: Skipped (no token)
        if: env.GLIMPSE_TOKEN == ''
        run: echo "::warning::GLIMPSE_TOKEN is not available (fork PR, or the secret is not set); image check skipped."
```

The check step only runs when `GLIMPSE_TOKEN` is available. Pull requests from forks never receive repository secrets, so those runs skip the check with a warning annotation instead of failing on an authentication error. The push run after the merge has the secret and still gates. The same guard keeps a repository that has not set the secret yet on a visible warning instead of a permanent failure. If your default branch is not `main`, edit the one `branches: [main]` line.

GitHub's `ubuntu-latest` runners ship PHP out of the box, so the PHAR runs as-is. Exclude paths you do not control (vendored packages, generated assets) with a [`.glimpseignore`](#glimpseignore) file at the repo root, and tune the failure bar with `--threshold`. Rolling glimpse out on a repo full of legacy images? Commit a [`.glimpse-baseline.json`](#glimpse-baselinejson) so the gate only fails on new or changed files (`glimpse init` scaffolds both). When the check fails, fix it by running the optimizer locally:

```bash
glimpse optimize path/to/offender.png --in-place
```

This repository runs the same gate on itself: see [`.github/workflows/images.yml`](.github/workflows/images.yml).

## Limits

- Input images are capped at 15 MiB.
- Dimensions are capped at 10000 px.

## Development

```bash
composer test        # Pint (check), PHPStan, and the Pest suite
```

### Releasing

```bash
make release VERSION=vX.Y.Z
```

This runs the test suite, compiles the PHAR, commits `builds/glimpse`, pushes, and creates the GitHub release with the binary attached, the equivalent of:

```bash
php glimpse app:build glimpse --build-version=vX.Y.Z
git add builds/glimpse && git commit -m "Build vX.Y.Z" && git push
gh release create vX.Y.Z builds/glimpse --title vX.Y.Z --generate-notes
```

`self-update` discovers versions through Packagist and downloads the `glimpse` PHAR attached to the GitHub release of the matching tag, so the release must carry the binary as an asset under exactly that name. Committing the fresh build keeps `composer global require` working (composer's `bin` points at `builds/glimpse`) and lets pre-0.1.1 installs, which download `builds/glimpse` from the tag itself, still update.

The `--build-version` must be the tag name verbatim, including the `v` prefix. The updater compares the embedded build version against the Packagist tag as plain strings, so a build stamped `0.1.0` never equals the tag `v0.1.0` and `self-update` would keep re-downloading the release it is already running.

## License

glimpse-cli is open-source software licensed under the [MIT license](LICENSE).

---

<p align="center">
  <sub>Every image on this page was converted by glimpse itself, including the animated banner (GIF in, animated AVIF out).<br>
  Grab a free API key at <a href="https://glimpseimg.com"><strong>glimpseimg.com</strong></a> (<strong>Settings → API Tokens</strong>).</sub>
</p>
