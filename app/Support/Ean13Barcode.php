<?php

namespace App\Support;

class Ean13Barcode
{
    /** The 95 modules of a valid EAN-13, without quiet zones. */
    public function modules(?string $value): ?string
    {
        $value = trim((string) $value);
        if (! preg_match('/^[0-9]{13}$/D', $value)) {
            return null;
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $value[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        if ((10 - $sum % 10) % 10 !== (int) $value[12]) {
            return null;
        }

        $left = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
        $even = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];
        $right = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];
        $parities = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];
        $parity = $parities[(int) $value[0]];
        $modules = '101';
        for ($i = 1; $i <= 6; $i++) {
            $modules .= $parity[$i - 1] === 'L' ? $left[(int) $value[$i]] : $even[(int) $value[$i]];
        }
        $modules .= '01010';
        for ($i = 7; $i < 13; $i++) {
            $modules .= $right[(int) $value[$i]];
        }

        return $modules.'101';
    }
}
