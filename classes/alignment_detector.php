<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Scan alignment detection for mod_paper.
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_paper;

defined('MOODLE_INTERNAL') || die();

/**
 * Measures how far a scanned page sits from the template it was printed from.
 *
 * Works on ink profiles rather than raw pixels: each image is reduced to "how much ink is
 * in this row" and "how much ink is in this column", and the profiles are slid against each
 * other to find the displacement that lines them up. That ignores the difference in tone
 * between a crisp template and a grey scan, and ignores the student's handwriting, which
 * is present in one image and not the other.
 *
 * The page is measured in bands rather than as a whole, so a displacement that grows
 * across the page - the scan being slightly stretched, not just shifted - is separated
 * into an offset and a scale instead of being averaged into a single wrong number.
 */
class alignment_detector {

    /** @var int How far to search, in pixels, either side of no displacement. */
    const SEARCH_PX = 60;

    /** @var int Bands per axis. Enough to fit a trend, few enough that each still has ink. */
    const BANDS = 4;

    /** @var float Ink threshold; below this a pixel counts as marked. */
    const INK_THRESHOLD = 200;

    /** @var object The paper instance record. */
    protected $paper;

    /** @var \context_module The module context. */
    protected $context;

    /**
     * @param object $paper The paper instance record.
     */
    public function __construct($paper) {
        $this->paper = $paper;
        $cm = get_coursemodule_from_instance('paper', $paper->id, 0, false, MUST_EXIST);
        $this->context = \context_module::instance($cm->id);
    }

    /**
     * Whether there is anything to measure against.
     *
     * @return bool True if both a retained scan page and a template are stored.
     */
    public function can_detect() {
        $fs = get_file_storage();
        return (bool) $fs->get_file($this->context->id, 'mod_paper', 'alignmentpage', 0, '/', 'page.jpg')
            && (bool) $fs->get_file($this->context->id, 'mod_paper', 'template', 0, '/', 'template.jpg');
    }

    /**
     * Measures the alignment of the retained scan page against the template.
     *
     * @return array|null Null if there is nothing to measure. Otherwise keys:
     *                    offsetx, offsety, scalex, scaley - the suggested values;
     *                    bandsx, bandsy - per-band displacements in pixels, for display;
     *                    reliablex, reliabley - whether each axis produced a consistent
     *                    trend, since a page can pin one direction and not the other.
     */
    public function detect() {
        $fs = get_file_storage();
        $scanfile = $fs->get_file($this->context->id, 'mod_paper', 'alignmentpage', 0, '/', 'page.jpg');
        $tplfile = $fs->get_file($this->context->id, 'mod_paper', 'template', 0, '/', 'template.jpg');
        if (!$scanfile || !$tplfile) {
            return null;
        }

        $scan = @imagecreatefromstring($scanfile->get_content());
        $tpl = @imagecreatefromstring($tplfile->get_content());
        if (!$scan || !$tpl) {
            if ($scan) {
                imagedestroy($scan);
            }
            if ($tpl) {
                imagedestroy($tpl);
            }
            return null;
        }

        try {
            // The two images are rendered from A4 at the same resolution, so they should
            // already share a pixel grid; scale the profiles if they somehow don't.
            $result = [];
            foreach (['y', 'x'] as $axis) {
                $sp = $this->profile($scan, $axis);
                $tp = $this->profile($tpl, $axis);
                if (count($sp) !== count($tp)) {
                    $sp = $this->resample($sp, count($tp));
                }
                $result[$axis] = $this->fit_axis($tp, $sp);
            }
        } finally {
            imagedestroy($scan);
            imagedestroy($tpl);
        }

        // A band's displacement d is where the scan sits relative to the template, so
        // fitting d against position gives exactly the offset/scale pair the renderer
        // wants: window = offset + (scale/100) * box.
        return [
            'offsetx' => round($result['x']['offset'], 4),
            'offsety' => round($result['y']['offset'], 4),
            'scalex' => round($result['x']['scale'], 4),
            'scaley' => round($result['y']['scale'], 4),
            'bandsx' => $result['x']['bands'],
            'bandsy' => $result['y']['bands'],
            'reliablex' => $result['x']['reliable'],
            'reliabley' => $result['y']['reliable'],
        ];
    }

    /**
     * Reduces an image to an ink count per row (axis 'y') or per column (axis 'x').
     *
     * @param \GdImage $im Source image.
     * @param string $axis 'y' for a row profile, 'x' for a column profile.
     * @return array Ink counts, indexed by row/column.
     */
    protected function profile($im, $axis) {
        $w = imagesx($im);
        $h = imagesy($im);
        $n = ($axis === 'y') ? $h : $w;
        $m = ($axis === 'y') ? $w : $h;

        $out = array_fill(0, $n, 0);
        for ($i = 0; $i < $n; $i++) {
            $count = 0;
            // Every second pixel across the profile: the ink counts stay proportional and
            // a full-page scan is a lot of pixels to walk twice.
            for ($j = 0; $j < $m; $j += 2) {
                $rgb = ($axis === 'y') ? imagecolorat($im, $j, $i) : imagecolorat($im, $i, $j);
                $lum = ((($rgb >> 16) & 0xFF) + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF)) / 3;
                if ($lum < self::INK_THRESHOLD) {
                    $count++;
                }
            }
            $out[$i] = $count;
        }

        return $out;
    }

    /**
     * Stretches a profile to a different length, for the case where the two images were
     * not rendered at the same resolution.
     *
     * @param array $profile Ink counts.
     * @param int $len Target length.
     * @return array Resampled ink counts.
     */
    protected function resample(array $profile, $len) {
        $src = count($profile);
        $out = array_fill(0, $len, 0);
        for ($i = 0; $i < $len; $i++) {
            $out[$i] = $profile[(int) floor($i * $src / $len)];
        }
        return $out;
    }

    /**
     * Finds the displacement in each band, then fits a straight line through them.
     *
     * @param array $tplprofile Template ink profile.
     * @param array $scanprofile Scan ink profile.
     * @return array offset (percent of page), scale (percent), bands (per-band pixels),
     *               reliable (bool).
     */
    protected function fit_axis(array $tplprofile, array $scanprofile) {
        $len = count($tplprofile);
        $bandsize = (int) floor($len / self::BANDS);

        $centres = [];
        $shifts = [];
        $bands = [];

        for ($b = 0; $b < self::BANDS; $b++) {
            $from = $b * $bandsize;
            $to = ($b === self::BANDS - 1) ? $len : $from + $bandsize;

            // A band with almost no ink has nothing to line up and would contribute noise.
            $ink = 0;
            for ($i = $from; $i < $to; $i++) {
                $ink += $tplprofile[$i];
            }
            if ($ink < 100) {
                $bands[] = null;
                continue;
            }

            $shift = $this->best_shift($tplprofile, $scanprofile, $from, $to);
            $bands[] = $shift;
            $centres[] = ($from + $to) / 2;
            $shifts[] = $shift;
        }

        if (count($shifts) < 2) {
            // Not enough to fit a trend; fall back to a single displacement with no scale.
            $shift = $this->best_shift($tplprofile, $scanprofile, 0, $len);
            return [
                'offset' => $shift / $len * 100,
                'scale' => 100.0,
                'bands' => $bands,
                'reliable' => false,
            ];
        }

        // Least squares through the band displacements: d(pos) = slope * pos + intercept.
        $n = count($shifts);
        $meanx = array_sum($centres) / $n;
        $meany = array_sum($shifts) / $n;
        $num = 0;
        $den = 0;
        for ($i = 0; $i < $n; $i++) {
            $num += ($centres[$i] - $meanx) * ($shifts[$i] - $meany);
            $den += ($centres[$i] - $meanx) ** 2;
        }
        $slope = $den > 0 ? $num / $den : 0.0;
        $intercept = $meany - $slope * $meanx;

        // Residual spread tells us whether the bands actually agreed on a trend. A page
        // that pins one axis well can leave the other ambiguous, and the teacher should
        // know which number to trust.
        $maxresidual = 0;
        for ($i = 0; $i < $n; $i++) {
            $predicted = $slope * $centres[$i] + $intercept;
            $maxresidual = max($maxresidual, abs($shifts[$i] - $predicted));
        }

        return [
            // The intercept is a pixel displacement at position 0, which is the offset;
            // the slope is the fractional stretch, which is the scale.
            'offset' => $intercept / $len * 100,
            'scale' => (1 + $slope) * 100,
            'bands' => $bands,
            'reliable' => $maxresidual <= 4,
        ];
    }

    /**
     * Displacement that best lines up one band of the scan profile with the template's.
     *
     * Scores with min() of the two ink counts, which rewards ink appearing in the same
     * places without being dominated by the scan's extra ink from handwriting.
     *
     * @param array $tplprofile Template ink profile.
     * @param array $scanprofile Scan ink profile.
     * @param int $from First index of the band.
     * @param int $to One past the last index of the band.
     * @return int Displacement in pixels; positive means the scan sits further on.
     */
    protected function best_shift(array $tplprofile, array $scanprofile, $from, $to) {
        $len = count($scanprofile);
        $best = -1;
        $bestshift = 0;

        for ($d = -self::SEARCH_PX; $d <= self::SEARCH_PX; $d++) {
            $score = 0;
            for ($i = $from; $i < $to; $i++) {
                $j = $i + $d;
                if ($j < 0 || $j >= $len) {
                    continue;
                }
                $score += min($tplprofile[$i], $scanprofile[$j]);
            }
            if ($score > $best) {
                $best = $score;
                $bestshift = $d;
            }
        }

        return $bestshift;
    }
}
