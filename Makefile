.PHONY: test build release check-version check-public-token verify-phar

# Usage:
#   make test                        run Pint, PHPStan, and the Pest suite
#   make build VERSION=vX.Y.Z        compile builds/glimpse stamped with VERSION
#                                    and verify no excluded package got bundled
#   make release VERSION=vX.Y.Z      test, build, commit the PHAR, push, and
#                                    create the GitHub release with it attached
#
# VERSION must be the tag name verbatim, including the v prefix: self-update
# compares the embedded build version against the Packagist tag as plain
# strings (see README).

test:
	composer test

build: check-version
	php glimpse app:build glimpse --build-version=$(VERSION)
	$(MAKE) verify-phar

# Box config mistakes fail silently (a misspelled exclude key just bundles
# everything), so inspect the artifact itself: the built phar must not
# contain any package the vendor finder in box.json claims to exclude.
# BoxExcludeListTest guards box.json against composer.lock; this guards the
# phar against box.json.
verify-phar:
	@php scripts/verify-phar.php

# The workflow template no longer skips runs without a token, so the
# shipped CLI must carry a real public token: with an empty constant,
# fork pull requests fail on authentication instead of being checked.
check-public-token:
	@! grep -q "private const PUBLIC_TOKEN = '';" app/Glimpse/Config.php || { echo "Config::PUBLIC_TOKEN is empty. Bake the published analyze-only token before releasing."; exit 1; }

release: check-version check-public-token
	@[ "$$(git branch --show-current)" = "main" ] || { echo "Releases are cut from main."; exit 1; }
	@[ -z "$$(git status --porcelain)" ] || { echo "Working tree is dirty; commit or stash first."; exit 1; }
	# vendor/ is what actually gets compiled in, so sync it to the lock: a
	# package left behind by a branch switch would otherwise ship inside the
	# phar, unseen by both guards (they only know composer.lock and box.json).
	composer install --no-interaction
	composer test
	$(MAKE) build VERSION=$(VERSION)
	git add builds/glimpse
	git commit -m "Build $(VERSION)"
	git push
	gh release create $(VERSION) builds/glimpse --title $(VERSION) --generate-notes

check-version:
	@[ -n "$(VERSION)" ] || { echo "VERSION is required, e.g. make release VERSION=v0.2.1"; exit 1; }
	@case "$(VERSION)" in v*) ;; *) echo "VERSION must be the tag name including the v prefix (see README)"; exit 1; ;; esac
