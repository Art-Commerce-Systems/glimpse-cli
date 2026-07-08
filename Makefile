.PHONY: test build release check-version

# Usage:
#   make test                        run Pint, PHPStan, and the Pest suite
#   make build VERSION=vX.Y.Z        compile builds/glimpse stamped with VERSION
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

release: check-version
	@[ "$$(git branch --show-current)" = "main" ] || { echo "Releases are cut from main."; exit 1; }
	@[ -z "$$(git status --porcelain)" ] || { echo "Working tree is dirty; commit or stash first."; exit 1; }
	composer test
	php glimpse app:build glimpse --build-version=$(VERSION)
	git add builds/glimpse
	git commit -m "Build $(VERSION)"
	git push
	gh release create $(VERSION) builds/glimpse --title $(VERSION) --generate-notes

check-version:
	@[ -n "$(VERSION)" ] || { echo "VERSION is required, e.g. make release VERSION=v0.2.1"; exit 1; }
	@case "$(VERSION)" in v*) ;; *) echo "VERSION must be the tag name including the v prefix (see README)"; exit 1; ;; esac
