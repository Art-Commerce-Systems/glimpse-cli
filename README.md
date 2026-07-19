<p align="center">
  <a href="https://glimpseimg.com"><img src="art/banner.avif" alt="glimpse: ship smaller images, skip the toolchain" width="100%"></a>
</p>

<p align="center">
  Ship smaller images. Skip the toolchain.<br>
  Convert, optimize, resize, thumbnail and analyze images from your terminal.<br>
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

Shipping images means wrangling ImageMagick, libvips, mozjpeg, cwebp and avifenc: compiled binaries that differ between your laptop, your teammate's laptop, and CI. **glimpse replaces all of them with one command**, with zero binary dependencies. The heavy lifting happens on the [Glimpse API](https://glimpseimg.com): stateless, nothing stored, your bytes never linger. You install a single CLI and go:

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

## Documentation

The full documentation lives at **[glimpseimg.com/docs/cli](https://glimpseimg.com/docs/cli)**:

- [Installation](https://glimpseimg.com/docs/cli/installation): Composer, the standalone PHAR, and `self-update`.
- [Authentication](https://glimpseimg.com/docs/cli/authentication): `glimpse auth` and the environment variables.
- [Commands](https://glimpseimg.com/docs/cli/commands): convert, optimize, resize, thumbnail, analyze, info, and usage.
- [Project files](https://glimpseimg.com/docs/cli/project-setup): `glimpse init`, `glimpse check`, `.glimpseignore`, and the baseline.
- [Continuous integration](https://glimpseimg.com/docs/cli/continuous-integration): the scaffolded GitHub Actions workflow.
- [Scripting](https://glimpseimg.com/docs/cli/scripting): stdin, stdout, `--json`, and clean pipes.

Adding glimpse to an existing project? Follow [Add Glimpse to Your Project](https://glimpseimg.com/docs/add-to-your-project); it goes from install to a CI gate in about ten minutes.

This repository runs the same gate on itself: see [`.github/workflows/images.yml`](.github/workflows/images.yml).

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
