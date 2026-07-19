.PHONY: test build release check-version verify-phar

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
	@php -r '$$box = json_decode(file_get_contents("box.json"), true); $$ex = []; foreach ($$box["finder"] as $$f) { if (in_array("vendor", (array) ($$f["in"] ?? []), true)) { $$ex = $$f["exclude"] ?? []; } } if ($$ex === []) { fwrite(STDERR, "no vendor excludes found in box.json\n"); exit(1); } copy("builds/glimpse", $$tmp = sys_get_temp_dir()."/glimpse-verify.phar"); $$bad = []; foreach (new RecursiveIteratorIterator(new Phar($$tmp)) as $$file) { foreach ($$ex as $$e) { if (str_contains($$file->getPathname(), "/vendor/".$$e."/")) { $$bad[$$e] = true; } } } unlink($$tmp); if ($$bad) { fwrite(STDERR, "builds/glimpse contains excluded packages: ".implode(", ", array_keys($$bad))."\n"); exit(1); } echo "builds/glimpse is clean: no excluded packages inside\n";'

release: check-version
	@[ "$$(git branch --show-current)" = "main" ] || { echo "Releases are cut from main."; exit 1; }
	@[ -z "$$(git status --porcelain)" ] || { echo "Working tree is dirty; commit or stash first."; exit 1; }
	composer test
	$(MAKE) build VERSION=$(VERSION)
	git add builds/glimpse
	git commit -m "Build $(VERSION)"
	git push
	gh release create $(VERSION) builds/glimpse --title $(VERSION) --generate-notes

check-version:
	@[ -n "$(VERSION)" ] || { echo "VERSION is required, e.g. make release VERSION=v0.2.1"; exit 1; }
	@case "$(VERSION)" in v*) ;; *) echo "VERSION must be the tag name including the v prefix (see README)"; exit 1; ;; esac
