<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Damages one member of a ZIP in place, for the tests of a member that no
 * longer matches its CRC-32 (#1238): one byte of its data flipped, the
 * archive's directories left intact, so it still opens.
 *
 * The data is found from the member's local header — its name and extra
 * field lengths — rather than at a fixed offset, which a libzip writing an
 * extra field would move into the header.
 */
final class ZipDamage
{
    public static function flipByteInMember(string $path, string $member): void
    {
        $bytes = (string) file_get_contents($path);
        $at = 0;
        while (($at = strpos($bytes, "PK\x03\x04", $at)) !== false) {
            $header = unpack('vnameLength/vextraLength', substr($bytes, $at + 26, 4));
            $compressed = unpack('Vsize', substr($bytes, $at + 18, 4));
            if (!is_array($header) || !is_array($compressed)) {
                break;
            }
            $name = substr($bytes, $at + 30, $header['nameLength']);
            if ($name === $member) {
                if ($compressed['size'] < 2) {
                    throw new \LogicException("Member {$member} has no data to damage.");
                }
                $data = $at + 30 + $header['nameLength'] + $header['extraLength'];
                $flip = $data + intdiv($compressed['size'], 2);
                $bytes[$flip] = chr(ord($bytes[$flip]) ^ 0xFF);
                file_put_contents($path, $bytes);

                return;
            }
            $at += 4;
        }

        throw new \LogicException("No member {$member} in the archive.");
    }
}
