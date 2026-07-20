<?php

namespace MathiasGrimm\GlimpseCli\Support;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * The brand banner printed by the bare `glimpse` invocation: the eye logo
 * next to a block-letter wordmark, then the tagline and version.
 *
 * The eye was rasterized from the logo SVG in art/banner.html (the outline
 * `M22 100 Q100 54 178 100 Q100 146 22 100`, the r=22 iris ring and the
 * green pupil) into half-block characters; the wordmark is ANSI-Regular
 * style glyphs. Colors follow the palette in art/banner.html: the logo is
 * monochrome except for the green pupil, exactly like the original.
 *
 * The art degrades with terminal width: full lockup, wordmark only, then a
 * compact two-line fallback. Formatter tags are stripped automatically for
 * non-TTY output and --no-ansi, so no plain-text variant is needed.
 */
final class Banner
{
    private const ACCENT = '#6088d5';

    private const GREEN = '#5be37d';

    /**
     * Visible width of the wordmark glyphs, before the cursor block.
     */
    private const WORDMARK_INNER = 54;

    /**
     * Visible width of one assembled banner line: 1 (indent) + 30 (eye)
     * + 3 (gap) + 54 (wordmark) + 4 (gap and cursor).
     */
    private const FULL_WIDTH = 92;

    /**
     * Visible width of a wordmark-only line: 2 (indent) + 54 (wordmark)
     * + 4 (gap and cursor).
     */
    private const WORDMARK_WIDTH = 60;

    /**
     * Each line is exactly 30 visible columns; the tags carry zero width.
     */
    private const EYE = [
        '        ▄▄▄▄▄▄▄▄▄▄▄▄▄▄        ',
        '  ▄▄▄██▀▀▀██▀▀  ▀▀██▀▀▀██▄▄▄  ',
        '████      ██  <fg=#5be37d>██</>  ██      ████',
        '  ▀▀▀██▄▄▄██▄▄  ▄▄██▄▄▄██▀▀▀  ',
        '        ▀▀▀▀▀▀▀▀▀▀▀▀▀▀        ',
    ];

    private const WORDMARK = [
        ' ██████  ██      ██ ███    ███ ██████  ███████ ███████',
        '██       ██      ██ ████  ████ ██   ██ ██      ██',
        '██   ███ ██      ██ ██ ████ ██ ██████  ███████ █████',
        '██    ██ ██      ██ ██  ██  ██ ██           ██ ██',
        ' ██████  ███████ ██ ██      ██ ██      ███████ ███████',
    ];

    public function render(string $version, OutputInterface $output): void
    {
        $width = (new Terminal)->getWidth();

        $output->write("\n");

        if ($width >= self::FULL_WIDTH + 2) {
            foreach (self::EYE as $row => $eye) {
                $output->write(' '.$eye.'   <options=bold>'.$this->wordmark($row)."</>\n");
            }
            $output->write("\n".$this->tagline($version, self::FULL_WIDTH)."\n".$this->attribution()."\n\n");

            return;
        }

        if ($width >= self::WORDMARK_WIDTH + 2) {
            foreach (array_keys(self::WORDMARK) as $row) {
                $output->write('  <options=bold>'.$this->wordmark($row)."</>\n");
            }
            $output->write("\n".$this->tagline($version, self::WORDMARK_WIDTH)."\n".$this->attribution()."\n\n");

            return;
        }

        $output->write(
            '  <fg='.self::GREEN.'>●</> <options=bold>glimpse</> <fg=green;options=bold>'.$version."</>\n".
            '  '.$this->taglineText()."\n".
            $this->attribution()."\n\n"
        );
    }

    /**
     * The green terminal cursor after the wordmark: half the letter height
     * and bottom-aligned like a real block cursor, so it cannot be mistaken
     * for a letter "I".
     */
    private const CURSOR = ['', '', '▄▄', '██', '██'];

    /**
     * A wordmark row padded to full width, ending in the cursor block.
     */
    private function wordmark(int $row): string
    {
        $line = self::WORDMARK[$row];

        if (self::CURSOR[$row] === '') {
            return $line;
        }

        return $line.str_repeat(' ', self::WORDMARK_INNER - mb_strlen($line))
            .'  <fg='.self::GREEN.'>'.self::CURSOR[$row].'</>';
    }

    private function attribution(): string
    {
        return '  <fg=gray>by Mathias Grimm · https://mathiasgrimm.com</>';
    }

    /**
     * The tagline with the version right-aligned to the banner width.
     */
    private function tagline(string $version, int $bannerWidth): string
    {
        $taglineWidth = 2 + mb_strlen('The image API for developers.');
        $padding = max(2, $bannerWidth - $taglineWidth - mb_strlen($version));

        return '  '.$this->taglineText().str_repeat(' ', $padding).'<fg=green;options=bold>'.$version.'</>';
    }

    private function taglineText(): string
    {
        return '<fg=gray>The image API for</> <fg='.self::ACCENT.'>developers</><fg=gray>.</>';
    }
}
