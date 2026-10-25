<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_multicheckin;

defined('MOODLE_INTERNAL') || die();

/**
 * Minimal, dependency-free QR Code (Model 2) encoder.
 *
 * Supports byte mode with error-correction level M across versions 1-10, which
 * is more than enough for check-in URLs. Produces a PNG via Moodle's GD image
 * helpers when available, falling back to a raw PNG writer.
 *
 * Implementation follows ISO/IEC 18004 for the encoding pipeline: data encoding,
 * Reed-Solomon error correction, interleaving, module placement, mask selection.
 *
 * @package    mod_multicheckin
 * @copyright  2026 riiaiaw
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qrcode_encoder {

    /** @var array Per-version ECC block structure for level M: [ecc_per_block, [[num_blocks, data_codewords], ...]] */
    private const ECC_M = [
        1  => [10, [[1, 16]]],
        2  => [16, [[1, 28]]],
        3  => [26, [[1, 44]]],
        4  => [18, [[2, 32]]],
        5  => [24, [[2, 43]]],
        6  => [16, [[4, 27]]],
        7  => [18, [[4, 31]]],
        8  => [22, [[2, 38], [2, 39]]],
        9  => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];

    /** @var array Alignment pattern centre coordinates per version. */
    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /** @var int[] Total codewords per version (data + ecc). */
    private const TOTAL_CODEWORDS = [
        1 => 26, 2 => 44, 3 => 70, 4 => 100, 5 => 134,
        6 => 172, 7 => 196, 8 => 242, 9 => 292, 10 => 346,
    ];

    /**
     * Encode a string into a QR matrix.
     *
     * @param string $text Payload.
     * @return array Matrix of booleans (true = dark).
     */
    public static function encode(string $text): array {
        $data = array_values(unpack('C*', $text));
        $version = self::pick_version(count($data));
        $size = 17 + ($version * 4);

        // 1. Bit stream: mode (0100) + length + data + terminator + padding.
        $bits = self::byte_stream($data, $version);

        // 2. Split into data codewords and add Reed-Solomon ECC.
        [$dataCodewords, $eccCodewords] = self::split_codewords($version, $bits);

        // 3. Interleave.
        $final = self::interleave($dataCodewords, $eccCodewords);

        // 4. Build the matrix and place everything.
        $matrix = self::new_matrix($size);
        $reserved = self::new_matrix($size);
        self::place_function_patterns($matrix, $reserved, $version, $size);
        // Version information must be written for version 7 and above; the
        // cells were reserved above and are never masked, so filling them here
        // is enough.
        self::write_version_info($matrix, $size, $version);
        self::place_data($matrix, $reserved, $final, $size);

        // 5. Apply the best mask.
        $best = null;
        $bestpenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = self::apply_mask($matrix, $reserved, $mask, $size);
            $penalty = self::penalty($candidate, $size);
            if ($penalty < $bestpenalty) {
                $bestpenalty = $penalty;
                $best = $candidate;
            }
        }
        return $best ?? $matrix;
    }

    /**
     * Choose the smallest version that fits the payload at level M.
     *
     * @param int $length Byte length.
     * @return int
     */
    private static function pick_version(int $length): int {
        for ($v = 1; $v <= 10; $v++) {
            $bits = 4 + self::length_bits($v) + ($length * 8);
            $capacity = self::data_codewords($v) * 8;
            if ($bits <= $capacity) {
                return $v;
            }
        }
        return 10;
    }

    /**
     * Character count indicator length for byte mode.
     *
     * @param int $version Version.
     * @return int
     */
    private static function length_bits(int $version): int {
        return $version < 10 ? 8 : 16;
    }

    /**
     * Total data codewords for a version at level M.
     *
     * @param int $version Version.
     * @return int
     */
    private static function data_codewords(int $version): int {
        $sum = 0;
        foreach (self::ECC_M[$version][1] as [$blocks, $dcw]) {
            $sum += $blocks * $dcw;
        }
        return $sum;
    }

    /**
     * Build the raw bit stream (mode + count + data + terminator + padding).
     *
     * @param array $data Byte array.
     * @param int $version Version.
     * @return array Bit array.
     */
    private static function byte_stream(array $data, int $version): array {
        $bits = [];
        self::push($bits, 0b0100, 4);
        self::push($bits, count($data), self::length_bits($version));
        foreach ($data as $byte) {
            self::push($bits, $byte, 8);
        }

        $capacity = self::data_codewords($version) * 8;
        // Terminator (up to 4 zero bits).
        for ($i = 0; $i < 4 && count($bits) < $capacity; $i++) {
            $bits[] = 0;
        }
        // Pad to a byte boundary.
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }
        // Pad codewords 0xEC / 0x11 alternately.
        $pad = [0xEC, 0x11];
        $i = 0;
        while (count($bits) < $capacity) {
            self::push($bits, $pad[$i % 2], 8);
            $i++;
        }
        return $bits;
    }

    /**
     * Append $len bits of $value to the bit array.
     *
     * @param array $bits Bit array (by reference).
     * @param int $value Value.
     * @param int $len Bit count.
     * @return void
     */
    private static function push(array &$bits, int $value, int $len): void {
        for ($i = $len - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    /**
     * Convert bits to codewords and compute the ECC blocks.
     *
     * @param int $version Version.
     * @param array $bits Bit array.
     * @return array [dataCodewordBlocks, eccCodewordBlocks]
     */
    private static function split_codewords(int $version, array $bits): array {
        $codewords = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | ($bits[$i + $j] ?? 0);
            }
            $codewords[] = $byte;
        }

        [$eccperblock, $spec] = self::ECC_M[$version];

        $dataBlocks = [];
        $eccBlocks = [];
        $offset = 0;
        foreach ($spec as [$numblocks, $dcw]) {
            for ($b = 0; $b < $numblocks; $b++) {
                $block = array_slice($codewords, $offset, $dcw);
                $offset += $dcw;
                $dataBlocks[] = $block;
                $eccBlocks[] = self::reed_solomon($block, $eccperblock);
            }
        }
        return [$dataBlocks, $eccBlocks];
    }

    /**
     * Reed-Solomon error correction over GF(256).
     *
     * @param array $data Data codewords.
     * @param int $ecccount Number of ECC codewords to generate.
     * @return array
     */
    private static function reed_solomon(array $data, int $ecccount): array {
        $gen = self::generator_poly($ecccount);
        $res = array_fill(0, $ecccount, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $res[0];
            array_shift($res);
            $res[] = 0;
            for ($i = 0; $i < $ecccount; $i++) {
                $res[$i] ^= self::gf_mul($gen[$i], $factor);
            }
        }
        return $res;
    }

    /**
     * Build the generator polynomial for $degree ECC codewords.
     *
     * @param int $degree Degree.
     * @return array
     */
    private static function generator_poly(int $degree): array {
        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $j => $coef) {
                $next[$j] ^= $coef;
                $next[$j + 1] ^= self::gf_mul($coef, self::gf_pow(2, $i));
            }
            $poly = $next;
        }
        // Drop the leading 1.
        array_shift($poly);
        return $poly;
    }

    /**
     * Multiply two GF(256) elements (modulo 0x11D).
     *
     * @param int $a Operand.
     * @param int $b Operand.
     * @return int
     */
    private static function gf_mul(int $a, int $b): int {
        $p = 0;
        for ($i = 0; $i < 8; $i++) {
            if ($b & 1) {
                $p ^= $a;
            }
            $hi = $a & 0x80;
            $a = ($a << 1) & 0xFF;
            if ($hi) {
                $a ^= 0x1D;
            }
            $b >>= 1;
        }
        return $p;
    }

    /**
     * Raise 2 to the power $n in GF(256).
     *
     * @param int $base Base (always 2 here).
     * @param int $n Exponent.
     * @return int
     */
    private static function gf_pow(int $base, int $n): int {
        $r = 1;
        for ($i = 0; $i < $n; $i++) {
            $r = self::gf_mul($r, $base);
        }
        return $r;
    }

    /**
     * Interleave data and ECC codewords per the spec.
     *
     * @param array $dataBlocks Data blocks.
     * @param array $eccBlocks ECC blocks.
     * @return array Codeword sequence.
     */
    private static function interleave(array $dataBlocks, array $eccBlocks): array {
        $out = [];
        $maxdata = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxdata; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }
        $maxecc = max(array_map('count', $eccBlocks));
        for ($i = 0; $i < $maxecc; $i++) {
            foreach ($eccBlocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }
        return $out;
    }

    /**
     * Allocate a size x size matrix of false.
     *
     * @param int $size Size.
     * @return array
     */
    private static function new_matrix(int $size): array {
        return array_fill(0, $size, array_fill(0, $size, false));
    }

    /**
     * Place finder, separator, timing and alignment patterns; mark reserved cells.
     *
     * @param array $m Matrix (by reference).
     * @param array $r Reserved mask (by reference).
     * @param int $version Version.
     * @param int $size Size.
     * @return void
     */
    private static function place_function_patterns(array &$m, array &$r, int $version, int $size): void {
        // Finder patterns at three corners.
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$fx, $fy]) {
            for ($y = -1; $y <= 7; $y++) {
                for ($x = -1; $x <= 7; $x++) {
                    $px = $fx + $x;
                    $py = $fy + $y;
                    if ($px < 0 || $py < 0 || $px >= $size || $py >= $size) {
                        continue;
                    }
                    $dark = ($x >= 0 && $x <= 6 && $y >= 0 && $y <= 6)
                        && (($x === 0 || $x === 6 || $y === 0 || $y === 6)
                            || ($x >= 2 && $x <= 4 && $y >= 2 && $y <= 4));
                    $m[$py][$px] = $dark;
                    $r[$py][$px] = true;
                }
            }
        }

        // Timing patterns.
        for ($i = 8; $i < $size - 8; $i++) {
            $v = ($i % 2 === 0);
            if (!$r[6][$i]) {
                $m[6][$i] = $v;
                $r[6][$i] = true;
            }
            if (!$r[$i][6]) {
                $m[$i][6] = $v;
                $r[$i][6] = true;
            }
        }

        // Alignment patterns.
        $centres = self::ALIGN[$version];
        foreach ($centres as $cy) {
            foreach ($centres as $cx) {
                // Skip the three corners occupied by finder patterns.
                if (($cx === 6 && $cy === 6) || ($cx === 6 && $cy === $size - 7) || ($cx === $size - 7 && $cy === 6)) {
                    continue;
                }
                for ($y = -2; $y <= 2; $y++) {
                    for ($x = -2; $x <= 2; $x++) {
                        $dark = (max(abs($x), abs($y)) !== 1);
                        $m[$cy + $y][$cx + $x] = $dark;
                        $r[$cy + $y][$cx + $x] = true;
                    }
                }
            }
        }

        // Dark module and format reservation.
        $m[$size - 8][8] = true;
        $r[$size - 8][8] = true;
        for ($i = 0; $i < 9; $i++) {
            if (!$r[8][$i]) {
                $r[8][$i] = true;
            }
            if (!$r[$i][8]) {
                $r[$i][8] = true;
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $r[8][$size - 1 - $i] = true;
            $r[$size - 1 - $i][8] = true;
        }
        // Version information reservation (version >= 7).
        if ($version >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $r[$size - 11 + $j][$i] = true;
                    $r[$i][$size - 11 + $j] = true;
                }
            }
        }
    }

    /**
     * Place the data codewords in the zig-zag pattern.
     *
     * @param array $m Matrix (by reference).
     * @param array $r Reserved mask (by reference).
     * @param array $codewords Codewords.
     * @param int $size Size.
     * @return void
     */
    private static function place_data(array &$m, array &$r, array $codewords, int $size): void {
        $bits = [];
        foreach ($codewords as $cw) {
            self::push($bits, $cw, 8);
        }

        $idx = 0;
        $up = true;
        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                // Step over the vertical timing column exactly once, when the
                // sweep reaches it. Testing for "<= 6" instead also fired at
                // column 3, which turned the last two column pairs into (2,1)
                // and left columns 3 and 0 without any data at all: the symbol
                // still looked like a QR code but carried the mask pattern in
                // two whole columns, which is what made phone scanners reject
                // it while lenient desktop libraries still recovered the text.
                $col--;
            }
            for ($i = 0; $i < $size; $i++) {
                $row = $up ? ($size - 1 - $i) : $i;
                foreach ([$col, $col - 1] as $c) {
                    if ($r[$row][$c]) {
                        continue;
                    }
                    $m[$row][$c] = (bool) ($bits[$idx] ?? 0);
                    $idx++;
                }
            }
            $up = !$up;
        }
    }

    /**
     * Apply mask pattern $mask to data cells and write format information.
     *
     * @param array $m Base matrix.
     * @param array $r Reserved mask.
     * @param int $mask Mask id 0-7.
     * @param int $size Size.
     * @return array New matrix.
     */
    private static function apply_mask(array $m, array $r, int $mask, int $size): array {
        $out = $m;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($r[$y][$x]) {
                    continue;
                }
                if (self::mask_bit($mask, $x, $y)) {
                    $out[$y][$x] = !$out[$y][$x];
                }
            }
        }
        self::write_format($out, $mask, $size);
        return $out;
    }

    /**
     * Mask predicate.
     *
     * @param int $mask Mask id.
     * @param int $x X.
     * @param int $y Y.
     * @return bool
     */
    private static function mask_bit(int $mask, int $x, int $y): bool {
        switch ($mask) {
            case 0: return ($x + $y) % 2 === 0;
            case 1: return $y % 2 === 0;
            case 2: return $x % 3 === 0;
            case 3: return ($x + $y) % 3 === 0;
            case 4: return (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0;
            case 5: return (($x * $y) % 2) + (($x * $y) % 3) === 0;
            case 6: return ((($x * $y) % 2) + (($x * $y) % 3)) % 2 === 0;
            case 7: return ((($x + $y) % 2) + (($x * $y) % 3)) % 2 === 0;
        }
        return false;
    }

    /**
     * Write the 18-bit version information block (version 7 and above).
     *
     * Versions 7-40 carry a BCH(18,6) encoded copy of their own number in two
     * fixed blocks, so a scanner can identify the symbol before it has decoded
     * the format information. Leaving those cells blank is not cosmetic:
     * decoders that refuse to guess the version from the module count simply
     * give up, which is what made the projected code unreadable for some phone
     * scanners while desktop libraries still coped.
     *
     * @param array $m Matrix (by reference).
     * @param int $size Size.
     * @param int $version Version.
     * @return void
     */
    private static function write_version_info(array &$m, int $size, int $version): void {
        if ($version < 7) {
            return;
        }

        // Six version bits followed by a twelve-bit BCH remainder, generator
        // polynomial 0x1F25. Version 7 must come out as 0x07C94.
        $bits = $version << 12;
        for ($i = 17; $i >= 12; $i--) {
            if (($bits >> $i) & 1) {
                $bits ^= 0x1F25 << ($i - 12);
            }
        }
        $info = ($version << 12) | ($bits & 0xFFF);

        for ($i = 0; $i < 18; $i++) {
            $bit = (bool) (($info >> $i) & 1);
            // Bottom-left block: three rows tall by six columns wide.
            $m[$size - 11 + ($i % 3)][intdiv($i, 3)] = $bit;
            // Top-right block: the same 6x3 shape, transposed.
            $m[intdiv($i, 3)][$size - 11 + ($i % 3)] = $bit;
        }
    }

    /**
     * Write the 15-bit format information (level M + mask).
     *
     * @param array $m Matrix (by reference).
     * @param int $mask Mask id.
     * @param int $size Size.
     * @return void
     */
    private static function write_format(array &$m, int $mask, int $size): void {
        // ECC level M = 00, then 3-bit mask, BCH(15,5) + XOR 0x5412.
        $data = (0b00 << 3) | $mask;
        $bch = $data << 10;
        $gen = 0b10100110111;
        for ($i = 14; $i >= 10; $i--) {
            if (($bch >> $i) & 1) {
                $bch ^= $gen << ($i - 10);
            }
        }
        $format = (($data << 10) | $bch) ^ 0b101010000010010;

        for ($i = 0; $i < 15; $i++) {
            $bit = (bool) (($format >> $i) & 1);

            // Vertical strip (column 8).
            if ($i < 6) {
                $m[$i][8] = $bit;
            } else if ($i < 8) {
                $m[$i + 1][8] = $bit;
            } else {
                $m[$size - 15 + $i][8] = $bit;
            }

            // Horizontal strip (row 8).
            if ($i < 8) {
                $m[8][$size - $i - 1] = $bit;
            } else if ($i < 9) {
                $m[8][15 - $i - 1 + 1] = $bit;
            } else {
                $m[8][15 - $i - 1] = $bit;
            }
        }
        $m[$size - 8][8] = true;
    }

    /**
     * Compute the penalty score for mask selection.
     *
     * Implements the four evaluation rules of ISO/IEC 18004 section 8.8.2 so the
     * mask we pick is the one every mainstream encoder picks. Getting the rules
     * wrong does not make the symbol invalid - all eight masks decode - but the
     * rules exist precisely to avoid module arrangements that confuse scanners,
     * so an under-counted rule actively works against the goal of scanning
     * reliably across a mixed population of phone cameras and browsers.
     *
     * @param array $m Matrix.
     * @param int $size Size.
     * @return int
     */
    private static function penalty(array $m, int $size): int {
        return self::penalty_runs($m, $size)
            + self::penalty_blocks($m, $size)
            + self::penalty_finder_like($m, $size)
            + self::penalty_balance($m, $size);
    }

    /**
     * Rule 1 (N1): runs of five or more same-coloured modules in a row or column
     * each cost 3 plus one for every module beyond the fifth.
     *
     * @param array $m Matrix.
     * @param int $size Size.
     * @return int
     */
    private static function penalty_runs(array $m, int $size): int {
        $score = 0;
        for ($y = 0; $y < $size; $y++) {
            $run = 1;
            for ($x = 1; $x < $size; $x++) {
                if ($m[$y][$x] === $m[$y][$x - 1]) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $score += $run - 2;
                    }
                    $run = 1;
                }
            }
            if ($run >= 5) {
                $score += $run - 2;
            }
        }
        for ($x = 0; $x < $size; $x++) {
            $run = 1;
            for ($y = 1; $y < $size; $y++) {
                if ($m[$y][$x] === $m[$y - 1][$x]) {
                    $run++;
                } else {
                    if ($run >= 5) {
                        $score += $run - 2;
                    }
                    $run = 1;
                }
            }
            if ($run >= 5) {
                $score += $run - 2;
            }
        }
        return $score;
    }

    /**
     * Rule 2 (N2): every 2x2 block of a single colour costs 3.
     *
     * @param array $m Matrix.
     * @param int $size Size.
     * @return int
     */
    private static function penalty_blocks(array $m, int $size): int {
        $score = 0;
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                if ($m[$y][$x] === $m[$y][$x + 1]
                        && $m[$y][$x] === $m[$y + 1][$x]
                        && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        return $score;
    }

    /**
     * Rule 3 (N3): the 1:1:3:1:1 finder-like ratio flanked by four light modules
     * costs 40 per occurrence, in rows and in columns.
     *
     * The eleven-module windows are "10111010000" and "00001011101". Matching
     * only the bare seven-module core, as an earlier version of this encoder
     * did, both misses genuine occurrences and fires on patterns that sit next
     * to dark modules where a scanner would not be confused at all.
     *
     * @param array $m Matrix.
     * @param int $size Size.
     * @return int
     */
    private static function penalty_finder_like(array $m, int $size): int {
        $score = 0;
        $last = $size - 11;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x <= $last; $x++) {
                if ($m[$y][$x + 1] || !$m[$y][$x + 4] || $m[$y][$x + 5] || !$m[$y][$x + 6] || $m[$y][$x + 9]) {
                    continue;
                }
                $pattern1 = $m[$y][$x] && $m[$y][$x + 2] && $m[$y][$x + 3]
                    && !$m[$y][$x + 7] && !$m[$y][$x + 8] && !$m[$y][$x + 10];
                $pattern2 = !$m[$y][$x] && !$m[$y][$x + 2] && !$m[$y][$x + 3]
                    && $m[$y][$x + 7] && $m[$y][$x + 8] && $m[$y][$x + 10];
                if ($pattern1 || $pattern2) {
                    $score += 40;
                }
            }
        }
        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y <= $last; $y++) {
                if ($m[$y + 1][$x] || !$m[$y + 4][$x] || $m[$y + 5][$x] || !$m[$y + 6][$x] || $m[$y + 9][$x]) {
                    continue;
                }
                $pattern1 = $m[$y][$x] && $m[$y + 2][$x] && $m[$y + 3][$x]
                    && !$m[$y + 7][$x] && !$m[$y + 8][$x] && !$m[$y + 10][$x];
                $pattern2 = !$m[$y][$x] && !$m[$y + 2][$x] && !$m[$y + 3][$x]
                    && $m[$y + 7][$x] && $m[$y + 8][$x] && $m[$y + 10][$x];
                if ($pattern1 || $pattern2) {
                    $score += 40;
                }
            }
        }
        return $score;
    }

    /**
     * Rule 4 (N4): every 5% that the proportion of dark modules departs from 50%
     * costs 10.
     *
     * @param array $m Matrix.
     * @param int $size Size.
     * @return int
     */
    private static function penalty_balance(array $m, int $size): int {
        $dark = 0;
        foreach ($m as $row) {
            foreach ($row as $cell) {
                if ($cell) {
                    $dark++;
                }
            }
        }
        $percent = $dark / ($size * $size);
        return (int) (abs($percent * 100 - 50) / 5) * 10;
    }

    /**
     * Render a QR matrix to a PNG binary string.
     *
     * @param string $text Payload.
     * @param int $scale Pixels per module.
     * @param int $quiet Quiet zone in modules; ISO/IEC 18004 requires at least 4.
     * @return string PNG bytes.
     */
    public static function png(string $text, int $scale = 8, int $quiet = 4): string {
        $matrix = self::encode($text);
        $size = count($matrix);
        $dim = ($size + $quiet * 2) * $scale;

        // Prefer GD when available.
        if (function_exists('imagecreatetruecolor')) {
            $img = imagecreatetruecolor($dim, $dim);
            $white = imagecolorallocate($img, 255, 255, 255);
            $black = imagecolorallocate($img, 0, 0, 0);
            imagefilledrectangle($img, 0, 0, $dim - 1, $dim - 1, $white);
            for ($y = 0; $y < $size; $y++) {
                for ($x = 0; $x < $size; $x++) {
                    if ($matrix[$y][$x]) {
                        $px = ($x + $quiet) * $scale;
                        $py = ($y + $quiet) * $scale;
                        imagefilledrectangle($img, $px, $py, $px + $scale - 1, $py + $scale - 1, $black);
                    }
                }
            }
            ob_start();
            imagepng($img);
            $png = ob_get_clean();
            imagedestroy($img);
            return $png;
        }

        // Fallback: raw PNG writer (grayscale, 1 pixel per module for compactness).
        return self::png_raw($matrix, $scale, $quiet);
    }

    /**
     * Raw PNG writer used when GD is unavailable.
     *
     * @param array $matrix Matrix.
     * @param int $scale Scale.
     * @param int $quiet Quiet zone in modules.
     * @return string
     */
    private static function png_raw(array $matrix, int $scale, int $quiet): string {
        $size = count($matrix);
        $dim = ($size + $quiet * 2) * $scale;

        $rows = '';
        for ($y = 0; $y < $dim; $y++) {
            $rows .= "\x00"; // Filter type 0.
            $my = intdiv($y, $scale) - $quiet;
            for ($x = 0; $x < $dim; $x++) {
                $mx = intdiv($x, $scale) - $quiet;
                $dark = ($mx >= 0 && $my >= 0 && $mx < $size && $my < $size) ? $matrix[$my][$mx] : false;
                $rows .= $dark ? "\x00" : "\xFF";
            }
        }

        $raw = gzcompress($rows, 9);
        $png = "\x89PNG\r\n\x1a\n";
        $png .= self::chunk('IHDR', pack('NNCCCCC', $dim, $dim, 8, 0, 0, 0, 0));
        $png .= self::chunk('IDAT', $raw);
        $png .= self::chunk('IEND', '');
        return $png;
    }

    /**
     * Build a PNG chunk with CRC.
     *
     * @param string $type Chunk type.
     * @param string $data Chunk data.
     * @return string
     */
    private static function chunk(string $type, string $data): string {
        $crc = crc32($type . $data);
        return pack('N', strlen($data)) . $type . $data . pack('N', $crc);
    }
}
