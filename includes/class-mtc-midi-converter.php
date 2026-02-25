<?php
defined('ABSPATH') || exit;

/**
 * Pure PHP MIDI converter: Type 1 → Type 0
 * - Parses header + tracks
 * - Converts all tracks into a single merged track (Type 0)
 * - Preserves timing, meta events, sysex, channel events
 */
final class MTC_MidiConverter {

    public static function convert_file(string $src, string $dest): void {
        $data = file_get_contents($src);
        if ($data === false) {
            throw new RuntimeException('Unable to read MIDI file.');
        }

        $out = self::convert_bytes($data);

        if (file_put_contents($dest, $out) === false) {
            throw new RuntimeException('Unable to write converted MIDI.');
        }
    }

    public static function convert_bytes(string $data): string {
        $offset = 0;
        $need = function(int $n) use (&$data, &$offset): void {
            if ($offset + $n > strlen($data)) {
                throw new RuntimeException('Truncated MIDI data.');
            }
        };

        $need(14);

        $chunk = substr($data, $offset, 4); $offset += 4;
        if ($chunk !== 'MThd') {
            throw new RuntimeException('Invalid MIDI: missing MThd.');
        }

        $hdrLen = self::readU32($data, $offset);
        if ($hdrLen < 6) {
            throw new RuntimeException('Invalid MIDI header length.');
        }

        $format = self::readU16($data, $offset);
        $ntrks  = self::readU16($data, $offset);
        $divisionBytes = substr($data, $offset, 2); $offset += 2;

        // Skip extra header data if present (non-standard but seen in the wild).
        $extra = $hdrLen - 6;
        if ($extra > 0) {
            $need($extra);
            $offset += $extra;
        }

        // Read tracks
        $tracks = [];
        for ($i = 0; $i < $ntrks; $i++) {
            $need(8);
            $tchunk = substr($data, $offset, 4); $offset += 4;
            if ($tchunk !== 'MTrk') {
                throw new RuntimeException('Invalid MIDI: missing MTrk chunk.');
            }
            $tlen = self::readU32($data, $offset);
            $need($tlen);
            $tdata = substr($data, $offset, $tlen);
            $offset += $tlen;

            $tracks[] = self::parse_track_events($tdata, $i);
        }

        // If already Type 0 with a single track, return original bytes unchanged.
        // If format=0 but multiple tracks exist, we still merge them into Type 0.
        if ($format === 0 && $ntrks === 1) {
            return $data;
        }

        if ($format !== 1 && !($format === 0 && $ntrks > 1)) {
            throw new RuntimeException('Unsupported MIDI format: ' . $format);
        }

        // Merge events
        $all = [];
        foreach ($tracks as $trackEvents) {
            foreach ($trackEvents as $ev) {
                // Drop per-track End-of-Track. We'll add one at the end.
                if ($ev['kind'] === 'meta' && $ev['type'] === 0x2F) {
                    continue;
                }
                $all[] = $ev;
            }
        }

        usort($all, function(array $a, array $b): int {
            if ($a['time'] !== $b['time']) return ($a['time'] < $b['time']) ? -1 : 1;

            $pa = self::kind_priority($a);
            $pb = self::kind_priority($b);
            if ($pa !== $pb) return ($pa < $pb) ? -1 : 1;

            // For channel events, try to keep note-offs before note-ons at same tick.
            if ($a['kind'] === 'channel' && $b['kind'] === 'channel') {
                $ca = self::channel_priority($a);
                $cb = self::channel_priority($b);
                if ($ca !== $cb) return ($ca < $cb) ? -1 : 1;
            }

            // Deterministic tie-breakers
            if ($a['track'] !== $b['track']) return ($a['track'] < $b['track']) ? -1 : 1;
            if ($a['order'] !== $b['order']) return ($a['order'] < $b['order']) ? -1 : 1;

            return 0;
        });

        // Write merged track
        $trackOut = '';
        $lastTime = 0;

        foreach ($all as $ev) {
            $dt = $ev['time'] - $lastTime;
            if ($dt < 0) $dt = 0;
            $lastTime = $ev['time'];

            $trackOut .= self::writeVLQ($dt);
            $trackOut .= self::encode_event($ev);
        }

        // End of Track
        $trackOut .= self::writeVLQ(0) . chr(0xFF) . chr(0x2F) . chr(0x00);

        // New Type 0 header + single track
        $out  = 'MThd' . pack('N', 6) . pack('n', 0) . pack('n', 1) . $divisionBytes;
        $out .= 'MTrk' . pack('N', strlen($trackOut)) . $trackOut;

        return $out;
    }

    private static function kind_priority(array $ev): int {
        // Lower sorts first.
        return match ($ev['kind']) {
            'meta'   => 0,
            'sysex'  => 1,
            'sys'    => 1,
            'channel'=> 2,
            default  => 9,
        };
    }

    private static function channel_priority(array $ev): int {
        $status = $ev['status'] & 0xF0;
        $d = $ev['data'];
        $vel = $d[1] ?? 0;

        // Note off first, then note on, then everything else.
        if ($status === 0x80) return 0;
        if ($status === 0x90 && $vel === 0) return 0; // note-on velocity 0 treated as note-off
        if ($status === 0x90) return 1;
        return 2;
    }

    private static function parse_track_events(string $tdata, int $trackIndex): array {
        $offset = 0;
        $len = strlen($tdata);
        $abs = 0;
        $running = null;
        $order = 0;
        $events = [];

        while ($offset < $len) {
            $delta = self::readVLQ($tdata, $offset);
            $abs += $delta;

            if ($offset >= $len) break;

            $b = ord($tdata[$offset]);

            // Running status if < 0x80
            if ($b < 0x80) {
                if ($running === null) {
                    throw new RuntimeException('Running status used without previous status.');
                }
                $status = $running;
            } else {
                $status = $b;
                $offset++;
                if ($status < 0xF0) {
                    $running = $status;
                } else {
                    $running = null;
                }
            }

            // Meta event
            if ($status === 0xFF) {
                if ($offset + 1 > $len) throw new RuntimeException('Truncated meta event.');
                $type = ord($tdata[$offset]); $offset++;
                $mlen = self::readVLQ($tdata, $offset);
                if ($offset + $mlen > $len) throw new RuntimeException('Truncated meta data.');
                $mdata = substr($tdata, $offset, $mlen);
                $offset += $mlen;

                $events[] = [
                    'time' => $abs,
                    'kind' => 'meta',
                    'type' => $type,
                    'data' => $mdata,
                    'track'=> $trackIndex,
                    'order'=> $order++,
                ];
                continue;
            }

            // Sysex
            if ($status === 0xF0 || $status === 0xF7) {
                $slen = self::readVLQ($tdata, $offset);
                if ($offset + $slen > $len) throw new RuntimeException('Truncated sysex data.');
                $sdata = substr($tdata, $offset, $slen);
                $offset += $slen;

                $events[] = [
                    'time' => $abs,
                    'kind' => 'sysex',
                    'status' => $status,
                    'data' => $sdata,
                    'track'=> $trackIndex,
                    'order'=> $order++,
                ];
                continue;
            }

            // Channel messages
            if ($status >= 0x80 && $status <= 0xEF) {
                $etype = $status & 0xF0;
                $dlen = ($etype === 0xC0 || $etype === 0xD0) ? 1 : 2;

                if ($offset + $dlen > $len) throw new RuntimeException('Truncated channel message.');
                $d1 = ord($tdata[$offset]); $offset++;
                $dataBytes = [$d1];

                if ($dlen === 2) {
                    $d2 = ord($tdata[$offset]); $offset++;
                    $dataBytes[] = $d2;
                }

                $events[] = [
                    'time' => $abs,
                    'kind' => 'channel',
                    'status' => $status,
                    'data' => $dataBytes,
                    'track'=> $trackIndex,
                    'order'=> $order++,
                ];
                continue;
            }

            // Some rare system common / realtime bytes (unusual in MIDI files, but we preserve if encountered)
            $sysLen = match ($status) {
                0xF1 => 1,
                0xF2 => 2,
                0xF3 => 1,
                0xF6 => 0,
                0xF8, 0xFA, 0xFB, 0xFC, 0xFE => 0,
                default => 0
            };

            if ($offset + $sysLen > $len) throw new RuntimeException('Truncated system message.');
            $raw = '';
            for ($i = 0; $i < $sysLen; $i++) {
                $raw .= chr(ord($tdata[$offset])); $offset++;
            }

            $events[] = [
                'time' => $abs,
                'kind' => 'sys',
                'status' => $status,
                'data' => $raw,
                'track'=> $trackIndex,
                'order'=> $order++,
            ];
        }

        return $events;
    }

    private static function encode_event(array $ev): string {
        switch ($ev['kind']) {
            case 'meta':
                $data = $ev['data'];
                return chr(0xFF) . chr($ev['type']) . self::writeVLQ(strlen($data)) . $data;

            case 'sysex':
                $data = $ev['data'];
                return chr($ev['status']) . self::writeVLQ(strlen($data)) . $data;

            case 'channel':
                $out = chr($ev['status']);
                $out .= chr($ev['data'][0]);
                if (isset($ev['data'][1])) $out .= chr($ev['data'][1]);
                return $out;

            case 'sys':
                return chr($ev['status']) . (string) $ev['data'];

            default:
                throw new RuntimeException('Unknown event kind.');
        }
    }

    private static function readU16(string $data, int &$offset): int {
        $v = unpack('n', substr($data, $offset, 2))[1];
        $offset += 2;
        return (int) $v;
    }

    private static function readU32(string $data, int &$offset): int {
        $v = unpack('N', substr($data, $offset, 4))[1];
        $offset += 4;
        return (int) $v;
    }

    private static function readVLQ(string $data, int &$offset): int {
        $value = 0;
        $len = strlen($data);
        for ($i = 0; $i < 4; $i++) {
            if ($offset >= $len) throw new RuntimeException('Truncated VLQ.');
            $b = ord($data[$offset]); $offset++;
            $value = ($value << 7) | ($b & 0x7F);
            if (($b & 0x80) === 0) break;
        }
        return $value;
    }

    private static function writeVLQ(int $value): string {
        $value = max(0, $value);
        $bytes = [ $value & 0x7F ];
        $value >>= 7;
        while ($value > 0) {
            array_unshift($bytes, 0x80 | ($value & 0x7F));
            $value >>= 7;
        }
        return implode('', array_map('chr', $bytes));
    }
}

