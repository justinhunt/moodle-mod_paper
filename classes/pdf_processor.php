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
 * PDF Processor for mod_paper
 *
 * @package    mod_paper
 * @copyright  2024 Justin Hunt <poodllsupport@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_paper;

defined('MOODLE_INTERNAL') || die();

class pdf_processor {

    /**
     * Converts a multi-page PDF into an array of JPG images using Ghostscript.
     *
     * @param string $pdffilepath Full path to the source PDF.
     * @param string $outputdir Directory to save the resulting JPGs.
     * @return array Array of output JPG file paths.
     */
    public function pdf_to_images($pdffilepath, $outputdir) {
        $gspath = get_config('mod_paper', 'ghostscriptpath') ?: '/usr/bin/gs';
        
        if (!is_executable($gspath)) {
            throw new \moodle_exception('Ghostscript executable not found at: ' . $gspath);
        }

        $outputpattern = $outputdir . '/page-%03d.jpg';
        
        $cmd = escapeshellarg($gspath) . 
               " -q -dSAFER -dBATCH -dNOPAUSE -sDEVICE=jpeg -r150 -dUseCropBox -dPDFFitPage -sPAPERSIZE=a4 -sOutputFile=" . 
               escapeshellarg($outputpattern) . " " . 
               escapeshellarg($pdffilepath);
               
        exec($cmd, $output, $returnvar);
        
        if ($returnvar != 0) {
            throw new \moodle_exception("Ghostscript execution failed with code $returnvar.");
        }
        
        $files = glob($outputdir . '/page-*.jpg');
        sort($files);
        return $files;
    }

    /**
     * Decodes an image file into a GD handle.
     *
     * Split out from crop_image_to_base64() so a caller cropping several regions from the
     * same page decodes it once instead of once per region - a worksheet with ten response
     * areas was decoding the same full-size page JPEG ten times.
     *
     * The caller owns the returned handle and must imagedestroy() it.
     *
     * @param string $filepath Source image file path (JPG or PNG).
     * @return array [GdImage $src, string $ext]
     */
    public function load_image($filepath) {
        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        if ($ext === 'jpg' || $ext === 'jpeg') {
            $src = @imagecreatefromjpeg($filepath);
        } else if ($ext === 'png') {
            $src = @imagecreatefrompng($filepath);
        } else {
            throw new \moodle_exception("Unsupported image format: " . $ext);
        }

        if (!$src) {
            throw new \moodle_exception("Failed to load image for cropping: " . $filepath);
        }

        return [$src, $ext];
    }

    /**
     * Crops a specific physical region out of an image file based on percentage coordinates and returns a Base64 string.
     *
     * @param string $filepath Source image file path (JPG or PNG).
     * @param object $box Database object containing box_x, box_y, box_w, box_h (0-100 values).
     * @return string Base64 encoded payload of the extracted image region.
     */
    public function crop_image_to_base64($filepath, $box) {
        list($src, $ext) = $this->load_image($filepath);

        try {
            return $this->crop_loaded_image($src, $box, $ext);
        } finally {
            imagedestroy($src);
        }
    }

    /**
     * Crops a region out of an already-decoded image, so several regions can be taken from
     * one page without re-reading it. Does not destroy $src - the caller owns it.
     *
     * @param \GdImage $src Source image handle, from load_image().
     * @param object $box Database object containing box_x, box_y, box_w, box_h (0-100 values).
     * @param string $ext Source format ('jpg', 'jpeg' or 'png'), governing the output encoding.
     * @return string Base64 encoded payload of the extracted image region.
     */
    public function crop_loaded_image($src, $box, $ext) {
        $width = imagesx($src);
        $height = imagesy($src);
        
        $crop_x = (int) max(0, min($width - 1, ($box->box_x / 100) * $width));
        $crop_y = (int) max(0, min($height - 1, ($box->box_y / 100) * $height));
        $crop_w = (int) max(1, min($width - $crop_x, ($box->box_w / 100) * $width));
        $crop_h = (int) max(1, min($height - $crop_y, ($box->box_h / 100) * $height));
        
        $dest = imagecreatetruecolor($crop_w, $crop_h);
        
        if ($ext === 'png') {
            imagealphablending($dest, false);
            imagesavealpha($dest, true);
            $transparent = imagecolorallocatealpha($dest, 255, 255, 255, 127);
            imagefilledrectangle($dest, 0, 0, $crop_w, $crop_h, $transparent);
        }
        
        imagecopyresampled($dest, $src, 0, 0, $crop_x, $crop_y, $crop_w, $crop_h, $crop_w, $crop_h);
        
        ob_start();
        if ($ext === 'png') {
            imagepng($dest);
        } else {
            imagejpeg($dest, null, 90);
        }
        $imagedata = ob_get_clean();

        imagedestroy($dest);

        return base64_encode($imagedata);
    }

    /**
     * Trims the blank paper margin off a cropped snippet so it hugs the actual ink.
     *
     * Response areas are usually drawn taller/wider than the handwriting they capture
     * (to tolerate slight scan drift and give students room to write), so the raw crop
     * has a lot of blank space around the content. Trimming to just the ink also avoids
     * transplanting the printed guide marks (parentheses, dotted line) from the scan on
     * top of the same marks already on the clean template background, which would ghost
     * if the scan is even slightly rotated/shifted relative to the template.
     *
     * The caller needs to know *where* the trimmed patch sat within the original crop so
     * it can be drawn back at its true position (see snippetx/y/w/h on paper_eval_items),
     * so this returns that location alongside the trimmed image data rather than just the
     * bytes.
     *
     * @param string $imagedata Raw JPEG binary data (the untrimmed box crop).
     * @return array{data: string, x: float, y: float, w: float, h: float} 'data' is the
     *              trimmed JPEG bytes (or the original bytes unchanged if no ink was
     *              detected or the image could not be read); x/y/w/h are the trimmed
     *              region's position/size as percentages (0-100) of the original crop's
     *              own width/height ([0,0,100,100] when nothing was trimmed).
     */
    public function trim_whitespace_border($imagedata) {
        $src = @imagecreatefromstring($imagedata);
        if (!$src) {
            return ['data' => $imagedata, 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100];
        }

        $width = imagesx($src);
        $height = imagesy($src);
        $threshold = 235; // Pixels brighter than this (average of R/G/B) are treated as blank paper.

        $minx = $width;
        $miny = $height;
        $maxx = -1;
        $maxy = -1;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($src, $x, $y);
                $lum = ((($rgb >> 16) & 0xFF) + (($rgb >> 8) & 0xFF) + ($rgb & 0xFF)) / 3;
                if ($lum < $threshold) {
                    $minx = min($minx, $x);
                    $maxx = max($maxx, $x);
                    $miny = min($miny, $y);
                    $maxy = max($maxy, $y);
                }
            }
        }

        if ($maxx < 0) {
            // No ink detected (blank response) - nothing to trim.
            imagedestroy($src);
            return ['data' => $imagedata, 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100];
        }

        // Pad around the detected ink so strokes aren't clipped right at the edge.
        $padx = (int) max(2, ($maxx - $minx) * 0.15);
        $pady = (int) max(2, ($maxy - $miny) * 0.15);
        $minx = max(0, $minx - $padx);
        $miny = max(0, $miny - $pady);
        $maxx = min($width - 1, $maxx + $padx);
        $maxy = min($height - 1, $maxy + $pady);

        $neww = $maxx - $minx + 1;
        $newh = $maxy - $miny + 1;

        $dest = imagecreatetruecolor($neww, $newh);
        imagecopyresampled($dest, $src, 0, 0, $minx, $miny, $neww, $newh, $neww, $newh);

        ob_start();
        imagejpeg($dest, null, 90);
        $trimmed = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dest);

        return [
            'data' => $trimmed,
            'x' => ($minx / $width) * 100,
            'y' => ($miny / $height) * 100,
            'w' => ($neww / $width) * 100,
            'h' => ($newh / $height) * 100,
        ];
    }

    /**
     * Creates an evaluation summary PDF wrapping OCR text over the original template image.
     * Returns exactly the raw binary string so the pluginfile hook can intercept it.
     *
     * @param object $paper The paper instance.
     * @param array $evaluations Array of evaluation DB objects
     * @param object $context Moodle context object
     * @param bool $includesummary Prepend a summary page listing all students/grades
     *                              (used for the "all combined" download, not single-student ones).
     * @return string Raw PDF binary string
     */
    public function generate_evaluations_pdf($paper, $evaluations, $context, $includesummary = false) {
        global $CFG, $DB;
        require_once($CFG->libdir . '/pdflib.php');
        
        $fs = get_file_storage();
        $file = $fs->get_file($context->id, 'mod_paper', 'template', 0, '/', 'template.jpg');
        if (!$file) {
            return false;
        }
        
        $imagecontent = '@' . $file->get_content();
        
        // A4 page size in mm
        $page_w = \mod_paper\constants::M_PAGE_W_MM;
        $page_h = \mod_paper\constants::M_PAGE_H_MM;

        // How far the scanned pages sat from where the template says they should - applied
        // when cutting display-only snippets back out of their padded crops.
        $alignment = \mod_paper\utils::get_scan_alignment($paper);

        $pdf = new \pdf('P', 'mm', 'A4');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Load areas once
        $areas = $DB->get_records('paper_response_areas', ['paperid' => $paper->id]);
        $maxpossible = 0;
        $hasgradeareas = false;
        foreach ($areas as $area) {
            if (utils::is_graded_area($area)) {
                $maxpossible += $area->maxgrade;
                if (($area->gradingmode ?? 'none') !== 'none') {
                    $hasgradeareas = true;
                }
            }
        }
        $maxpossible = round($maxpossible, 2) + 0;

        if ($includesummary) {
            $this->add_summary_page($pdf, $paper, $evaluations, $maxpossible, $hasgradeareas);
        }

        $pdf->SetAutoPageBreak(false);

        foreach ($evaluations as $eval) {
            $pdf->AddPage();
            $pdf->SetTextColor(0, 0, 0); // Reset color to black for each student page
            
            // Draw background image full bleed
            // Image($file, $x, $y, $w, $h)
            $pdf->Image($imagecontent, 0, 0, $page_w, $page_h, '', '', '', false, 300, '', false, false, 0);
            
            $items = $DB->get_records('paper_eval_items', ['evalid' => $eval->id]);
            if (empty($items)) continue;
            
            // Group items by area
            $itemsbyarea = [];
            foreach ($items as $item) {
                $itemsbyarea[$item->responseareaid] = $item;
            }

            $bottommost_y = 0;
            foreach ($areas as $area) {
                $item = $itemsbyarea[$area->id] ?? null;
                $ocr = $item ? trim($item->ocrtext ?? '') : '';
                $corrected = $item ? trim($item->correctedtext ?? '') : '';
                $feedback = $item ? ($item->feedback ?? '') : '';
                $grade = $item ? $item->itemgrade : null;

                // Handle name field fallback
                if (utils::is_name_area($area) && !$item) {
                    $ocr = $eval->studentnametext ?? '';
                    $corrected = $ocr;
                }

                $bottom = $area->box_y + $area->box_h;
                if ($bottom > $bottommost_y) {
                    $bottommost_y = $bottom;
                }

                $x_mm = ($area->box_x / 100) * $page_w;
                $y_mm = ($area->box_y / 100) * $page_h;
                $w_mm = ($area->box_w / 100) * $page_w;
                $h_mm = ($area->box_h / 100) * $page_h;
                
                $displaytext = ($corrected !== '') ? $corrected : $ocr;

                if (utils::is_displayonly_area($area)) {
                    // Display Only: Show the original image snippet
                    if ($item) {
                        $snippetfile = $fs->get_file($context->id, 'mod_paper', 'responsesnippet', $item->id, '/', 'snippet.jpg');
                        if ($snippetfile) {
                            if ($item->snippetx !== null && $item->snippety !== null
                                    && $item->snippetw !== null && $item->snippeth !== null) {
                                // The stored patch is cropped wider than the response area, so cut
                                // the area's own region out of it - shifted by however far the scan
                                // drifted - and draw that at the area's coordinates. Doing the
                                // correction here rather than at crop time means the alignment can
                                // be retuned without re-processing the submission.
                                $snippetdata = \mod_paper\utils::window_snippet(
                                    $snippetfile->get_content(), $item, $area, $alignment);
                                $pdf->Image('@' . $snippetdata, $x_mm, $y_mm, $w_mm, $h_mm, 'JPG', '', '', false, 300, '', false, false, 0, false);
                            } else {
                                // Legacy row from before snippet position was recorded - fall back
                                // to the old fit-and-anchor-to-bottom-center approximation.
                                $pdf->Image('@' . $snippetfile->get_content(), $x_mm, $y_mm, $w_mm, $h_mm, 'JPG', '', '', false, 300, '', false, false, 0, 'CB');
                            }
                        }
                    }
                } else if ($displaytext !== '') {
                    if ($corrected !== '' && $area->grammarcorrections !== 'no' && utils::is_graded_area($area)) {
                        $html = \mod_paper\utils::build_combined_diff($ocr, $corrected, true);
                    } else {
                        $html = htmlspecialchars($displaytext);
                    }
                    
                    // Select font for student response (OCR/Corrected text).
                    $studentfont = $paper->targetlanguagefont ?? 'courier';
                    $pdf->SetFont($studentfont, '', 12);
                    
                    if (utils::is_name_area($area)) {
                        // Manual vertical alignment since TCPDF doesn't support it for HTML.
                        $pdf->startTransaction();
                        // Use 0,0 to avoid page break issues during measurement.
                        $pdf->MultiCell($w_mm, 0, trim($html), 0, 'L', false, 1, 0, 0, true, 0, true, true, 0, 'T', false);
                        $content_h = $pdf->GetY();
                        $pdf = $pdf->rollbackTransaction(true);
                        
                        $y_offset = max(0, $h_mm - $content_h);
                        $pdf->writeHTMLCell($w_mm, $h_mm, $x_mm, $y_mm + $y_offset, trim($html), 0, 0, false, true, 'L', true);
                    } else {
                        $pdf->writeHTMLCell($w_mm, $h_mm, $x_mm, $y_mm, trim($html), 0, 0, false, true, 'L', true);
                    }
                }

                // Render feedback in its dedicated feedback area (independent of display text).
                if (!empty($feedback) && utils::is_graded_area($area) && ($area->feedbackmode ?? 'none') !== 'none') {
                    $feedbackfont = $paper->feedbacklanguagefont ?? 'freesans';
                    $pdf->SetFont($feedbackfont, '', 11);
                    $pdf->SetTextColor(0, 0, 0);

                    $fb = \mod_paper\utils::get_effective_feedback_box($area);
                    $fbx_mm = ($fb['x'] / 100) * $page_w;
                    $fby_mm = ($fb['y'] / 100) * $page_h;
                    $fbw_mm = ($fb['w'] / 100) * $page_w;
                    $fbh_mm = ($fb['h'] / 100) * $page_h;

                    $pdf->writeHTMLCell($fbw_mm, $fbh_mm, $fbx_mm, $fby_mm,
                        htmlspecialchars(html_entity_decode($feedback, ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 0, false, true, 'L', true);
                    $pdf->SetTextColor(0, 0, 0);
                }

                // Add item grade to the top right of the area (shifted outside).
                if ($grade !== null && utils::is_graded_area($area) && ($area->gradingmode ?? 'none') !== 'none') {
                    $pdf->SetFont('freesans', 'B', 20);
                    $pdf->SetTextColor(0, 128, 0); // Green.
                    $grade_text = (round($grade, 2) + 0);
                    // Position 2mm right and 5mm above the top-right corner of the box.
                    $pdf->Text($x_mm + $w_mm + 2, $y_mm - 5, $grade_text);
                    // Reset font/color for next response.
                    $pdf->SetFont('freesans', '', 14);
                    $pdf->SetTextColor(0, 0, 0);
                }
            }

            // Total Score beneath the final response area
            if (($paper->showtotalscore ?? 1) && $eval->totalgrade !== null) {
                $pdf->SetFont('freesans', 'B', 16);
                $pdf->SetTextColor(217, 83, 79); // #d9534f equivalent
                $score_text = "Your score: " . (round($eval->totalgrade, 2) + 0) . ' / ' . $maxpossible;
                $y_score = ($bottommost_y / 100) * $page_h + 10;
                // Safety check for bottom of page
                if ($y_score > 280) {
                    $y_score = 280;
                }
                $pdf->Text(15, $y_score, $score_text);
            }
        }
        
        return $pdf->Output('evaluations.pdf', 'S');
    }

    /**
     * Prepends a summary page listing every student and their total grade,
     * mirroring the on-screen table on reports.php.
     *
     * @param \pdf $pdf The in-progress PDF document (no pages added yet).
     * @param object $paper The paper instance.
     * @param array $evaluations Array of evaluation DB objects, in table order.
     * @param float $maxpossible Maximum possible total grade for this paper.
     * @param bool $hasgradeareas Whether any response area is actually gradable.
     */
    protected function add_summary_page($pdf, $paper, $evaluations, $maxpossible, $hasgradeareas) {
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();
        $pdf->SetTextColor(0, 0, 0);

        $namefont = $paper->targetlanguagefont ?? 'freesans';

        $pdf->SetFont('freesans', 'B', 16);
        $pdf->MultiCell(0, 10, get_string('evaluationreportsfor', 'mod_paper', $paper->name), 0, 'L', false, 1);
        $pdf->Ln(4);

        $pdf->SetFont($namefont, '', 11);

        $html = '<table cellpadding="4" border="1">';
        $html .= '<tr style="background-color:#eeeeee;font-weight:bold;">';
        $html .= '<th width="15%">' . htmlspecialchars(get_string('evaluationid', 'mod_paper')) . '</th>';
        $html .= '<th width="' . ($hasgradeareas ? '55' : '85') . '%">' . htmlspecialchars(get_string('studentname', 'mod_paper')) . '</th>';
        if ($hasgradeareas) {
            $html .= '<th width="30%">' . htmlspecialchars(get_string('totalgrade', 'mod_paper')) . '</th>';
        }
        $html .= '</tr>';

        foreach ($evaluations as $eval) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($eval->id) . '</td>';
            $html .= '<td>' . htmlspecialchars($eval->studentnametext ?? '') . '</td>';
            if ($hasgradeareas) {
                $gradetext = $eval->totalgrade !== null ? (round($eval->totalgrade, 2) + 0) . ' / ' . $maxpossible : '';
                $html .= '<td>' . htmlspecialchars($gradetext) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';

        $pdf->writeHTML($html, true, false, false, false, '');
    }
}
