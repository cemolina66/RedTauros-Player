<?php
// C:\Users\desktop\Documents\Antigravity\RedTauros Player\api\id3.php

class AudioMetadataReader {
    public static function read($filePath) {
        $meta = [
            'title' => '',
            'artist' => '',
            'album' => '',
            'genre' => '',
            'duration' => 0.0
        ];

        if (!file_exists($filePath)) {
            return $meta;
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $filename = pathinfo($filePath, PATHINFO_FILENAME);

        // Standard fallback from filename
        if (strpos($filename, ' - ') !== false) {
            $parts = explode(' - ', $filename, 2);
            $meta['artist'] = trim($parts[0]);
            $meta['title'] = trim($parts[1]);
        } else {
            $meta['title'] = trim($filename);
            $meta['artist'] = 'Artista Desconocido';
        }
        $meta['album'] = 'Álbum Desconocido';
        $meta['genre'] = 'Desconocido';
        $meta['duration'] = 180.0; // 3 minutes default fallback

        try {
            switch ($ext) {
                case 'mp3':
                    self::parseMp3($filePath, $meta);
                    break;
                case 'm4a':
                    self::parseM4a($filePath, $meta);
                    break;
                case 'flac':
                    self::parseFlac($filePath, $meta);
                    break;
                case 'wav':
                    self::parseWav($filePath, $meta);
                    break;
            }
        } catch (Exception $e) {
            // Silence exceptions, use fallback metadata if parsing fails
        }

        // Standard cleanup and UTF-8 conversion
        if (empty($meta['title'])) {
            $meta['title'] = $filename;
        }
        if (empty($meta['artist'])) {
            $meta['artist'] = 'Artista Desconocido';
        }
        if (empty($meta['album'])) {
            $meta['album'] = 'Álbum Desconocido';
        }
        if (empty($meta['genre'])) {
            $meta['genre'] = 'Desconocido';
        }
        if ($meta['duration'] <= 0) {
            $meta['duration'] = 180.0;
        }

        // Convert metadata extracted from ANSI filenames to clean UTF-8 on Windows
        if (function_exists('winPathToUtf8')) {
            $meta['title'] = winPathToUtf8($meta['title']);
            $meta['artist'] = winPathToUtf8($meta['artist']);
            $meta['album'] = winPathToUtf8($meta['album']);
            $meta['genre'] = winPathToUtf8($meta['genre']);
        }

        return $meta;
    }

    private static function parseMp3($filePath, &$meta) {
        $fh = fopen($filePath, 'rb');
        if (!$fh) return;

        // 1. Read ID3v2 header
        $header = fread($fh, 10);
        $id3Size = 0;
        if (strlen($header) === 10 && substr($header, 0, 3) === 'ID3') {
            $majorVersion = ord($header[3]);
            $tagSize = ((ord($header[6]) & 0x7F) << 21) |
                       ((ord($header[7]) & 0x7F) << 14) |
                       ((ord($header[8]) & 0x7F) << 7) |
                       (ord($header[9]) & 0x7F);
            $id3Size = $tagSize + 10;

            // Read ID3v2 tags
            $tagData = fread($fh, $tagSize);
            self::parseId3v2Tags($tagData, $majorVersion, $meta);
        }

        // 2. Estimate MP3 duration
        fseek($fh, $id3Size);
        $fileSize = filesize($filePath);
        $audioSize = $fileSize - $id3Size;

        // Try to scan first audio frame header
        $syncFound = false;
        $frameHeader = '';
        for ($i = 0; $i < 4096; $i++) {
            $byte = fread($fh, 1);
            if ($byte === false || strlen($byte) === 0) break;
            if (ord($byte) === 0xFF) {
                $nextByte = fread($fh, 1);
                if ($nextByte === false || strlen($nextByte) === 0) break;
                if ((ord($nextByte) & 0xE0) === 0xE0) { // Sync found
                    $frameHeader = $byte . $nextByte . fread($fh, 2);
                    $syncFound = true;
                    break;
                }
                fseek($fh, -1, SEEK_CUR); // Backtrack 1 byte
            }
        }

        if ($syncFound && strlen($frameHeader) === 4) {
            // Parse MPEG frame header
            $versionId = (ord($frameHeader[1]) & 0x18) >> 3; // 3=MPEG v1, 2=MPEG v2
            $layerIdx = (ord($frameHeader[1]) & 0x06) >> 1; // 1=Layer III
            $bitrateIdx = (ord($frameHeader[2]) & 0xF0) >> 4;
            $sampleRateIdx = (ord($frameHeader[2]) & 0x0C) >> 2;
            $padding = (ord($frameHeader[2]) & 0x02) >> 1;

            $version = ($versionId === 3) ? 1 : 2;
            $layer = 4 - $layerIdx; // Layer indices: 3=Layer I, 2=Layer II, 1=Layer III

            // Bitrates table (kbps)
            $bitrates = [
                1 => [ // MPEG v1
                    1 => [32, 64, 96, 128, 160, 192, 224, 256, 288, 320, 352, 384, 416, 448], // Layer I
                    2 => [32, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384],    // Layer II
                    3 => [32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320]      // Layer III
                ],
                2 => [ // MPEG v2 / 2.5
                    1 => [32, 48, 56, 64, 80, 96, 112, 128, 144, 160, 176, 192, 224, 256],    // Layer I
                    2 => [8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160],         // Layer II & III
                    3 => [8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160]          // Layer III
                ]
            ];

            // Sample rates
            $samplerates = [
                1 => [44100, 48000, 32000], // MPEG v1
                2 => [22050, 24000, 16000]  // MPEG v2
            ];

            $bitrate = isset($bitrates[$version][$layer][$bitrateIdx - 1]) ? $bitrates[$version][$layer][$bitrateIdx - 1] : 128;
            $sampleRate = isset($samplerates[$version][$sampleRateIdx]) ? $samplerates[$version][$sampleRateIdx] : 44100;

            // Check for Xing/Info header in first frame to get exact duration
            // Offsets from the end of 4-byte header:
            $xingOffset = 0;
            if ($version === 1) {
                $xingOffset = ($layer === 3) ? 32 : 17;
            } else {
                $xingOffset = ($layer === 3) ? 17 : 9;
            }

            fseek($fh, ftell($fh) + $xingOffset);
            $xingHeader = fread($fh, 4);
            if ($xingHeader === 'Xing' || $xingHeader === 'Info') {
                $flags = fread($fh, 4);
                if (strlen($flags) === 4) {
                    $flagsInt = (ord($flags[0]) << 24) | (ord($flags[1]) << 16) | (ord($flags[2]) << 8) | ord($flags[3]);
                    if ($flagsInt & 1) { // Frames field exists
                        $framesBytes = fread($fh, 4);
                        if (strlen($framesBytes) === 4) {
                            $totalFrames = (ord($framesBytes[0]) << 24) | (ord($framesBytes[1]) << 16) | (ord($framesBytes[2]) << 8) | ord($framesBytes[3]);
                            $samplesPerFrame = ($layer === 1) ? 384 : 1152;
                            if ($version === 2 && $layer === 3) {
                                $samplesPerFrame = 576; // MPEG v2 Layer III has 576 samples
                            }
                            $meta['duration'] = ($totalFrames * $samplesPerFrame) / $sampleRate;
                            fclose($fh);
                            return;
                        }
                    }
                }
            }

            // Fallback: estimate based on constant bitrate
            if ($bitrate > 0) {
                $meta['duration'] = ($audioSize * 8) / ($bitrate * 1000);
            }
        }

        fclose($fh);
    }

    private static function parseId3v2Tags($tagData, $version, &$meta) {
        $offset = 0;
        $len = strlen($tagData);

        while ($offset < $len - 10) {
            $frameId = substr($tagData, $offset, 4);
            if ($frameId === "\x00\x00\x00\x00" || strlen(trim($frameId)) < 4) {
                break; // Padding
            }

            // Size format
            if ($version === 4) {
                // Synchsafe size
                $frameSize = ((ord($tagData[$offset + 4]) & 0x7F) << 21) |
                             ((ord($tagData[$offset + 5]) & 0x7F) << 14) |
                             ((ord($tagData[$offset + 6]) & 0x7F) << 7) |
                             (ord($tagData[$offset + 7]) & 0x7F);
            } else {
                // Normal 32-bit int
                $frameSize = (ord($tagData[$offset + 4]) << 24) |
                             (ord($tagData[$offset + 5]) << 16) |
                             (ord($tagData[$offset + 6]) << 8) |
                             ord($tagData[$offset + 7]);
            }

            if ($frameSize <= 0 || ($offset + 10 + $frameSize) > $len) {
                break;
            }

            $frameContent = substr($tagData, $offset + 10, $frameSize);
            $offset += 10 + $frameSize;

            switch ($frameId) {
                case 'TIT2':
                    $meta['title'] = self::decodeText($frameContent);
                    break;
                case 'TPE1':
                    $meta['artist'] = self::decodeText($frameContent);
                    break;
                case 'TALB':
                    $meta['album'] = self::decodeText($frameContent);
                    break;
                case 'TCON':
                    $meta['genre'] = self::decodeText($frameContent);
                    // Standard cleanup for ID3v1 genre numbers inside brackets, e.g. "(13)"
                    if (preg_match('/^\((\d+)\)$/', $meta['genre'], $matches)) {
                        $meta['genre'] = self::getGenreNameById3Id($matches[1]);
                    }
                    break;
            }
        }
    }

    private static function decodeText($data) {
        if (strlen($data) <= 1) return '';
        $encoding = ord($data[0]);
        $text = substr($data, 1);
        
        // Strip leading null bytes or BOMs if they slip in
        switch ($encoding) {
            case 0: // ISO-8859-1
                return trim(mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1'));
            case 1: // UTF-16 with BOM
                if (strlen($text) >= 2) {
                    $bom = substr($text, 0, 2);
                    if ($bom === "\xFF\xFE" || $bom === "\xFE\xFF") {
                        return trim(mb_convert_encoding($text, 'UTF-8', 'UTF-16'));
                    }
                }
                return trim(mb_convert_encoding($text, 'UTF-8', 'UTF-16'));
            case 2: // UTF-16BE without BOM
                return trim(mb_convert_encoding($text, 'UTF-8', 'UTF-16BE'));
            case 3: // UTF-8
                return trim($text);
            default:
                return trim(mb_convert_encoding($data, 'UTF-8', 'auto'));
        }
    }

    private static function parseM4a($filePath, &$meta) {
        $fh = fopen($filePath, 'rb');
        if (!$fh) return;

        $fileSize = filesize($filePath);
        self::parseM4aAtoms($fh, 0, $fileSize, $meta);
        fclose($fh);
    }

    private static function parseM4aAtoms($fh, $start, $end, &$meta) {
        fseek($fh, $start);
        while (ftell($fh) < $end) {
            $atomStart = ftell($fh);
            $atomSizeData = fread($fh, 4);
            if (strlen($atomSizeData) !== 4) break;
            $atomSize = (ord($atomSizeData[0]) << 24) |
                        (ord($atomSizeData[1]) << 16) |
                        (ord($atomSizeData[2]) << 8) |
                        ord($atomSizeData[3]);

            $atomType = fread($fh, 4);
            if (strlen($atomType) !== 4) break;

            if ($atomSize === 1) {
                // 64-bit size
                $largeSizeData = fread($fh, 8);
                // Simple representation (assuming fits in 32-bit for normal music files)
                $atomSize = (ord($largeSizeData[4]) << 24) |
                            (ord($largeSizeData[5]) << 16) |
                            (ord($largeSizeData[6]) << 8) |
                            ord($largeSizeData[7]);
            }

            if ($atomSize <= 0) break;

            $atomEnd = $atomStart + $atomSize;

            if (in_array($atomType, ['moov', 'udta', 'ilst'])) {
                self::parseM4aAtoms($fh, ftell($fh), $atomEnd, $meta);
            } else if ($atomType === 'meta') {
                // Meta atom has a 4-byte version/flags header before its sub-atoms
                self::parseM4aAtoms($fh, ftell($fh) + 4, $atomEnd, $meta);
            } else if ($atomType === 'mvhd') {
                // Get duration
                $version = ord(fread($fh, 1));
                fseek($fh, 3, SEEK_CUR); // flags
                if ($version === 1) {
                    fseek($fh, 16, SEEK_CUR); // creation and modification time (64-bit)
                    $timescaleData = fread($fh, 4);
                    $durationData = fread($fh, 8);
                    $timescale = (ord($timescaleData[0]) << 24) | (ord($timescaleData[1]) << 16) | (ord($timescaleData[2]) << 8) | ord($timescaleData[3]);
                    $duration = (ord($durationData[4]) << 24) | (ord($durationData[5]) << 16) | (ord($durationData[6]) << 8) | ord($durationData[7]);
                } else {
                    fseek($fh, 8, SEEK_CUR); // creation and modification time (32-bit)
                    $timescaleData = fread($fh, 4);
                    $durationData = fread($fh, 4);
                    $timescale = (ord($timescaleData[0]) << 24) | (ord($timescaleData[1]) << 16) | (ord($timescaleData[2]) << 8) | ord($timescaleData[3]);
                    $duration = (ord($durationData[0]) << 24) | (ord($durationData[1]) << 16) | (ord($durationData[2]) << 8) | ord($durationData[3]);
                }
                if ($timescale > 0) {
                    $meta['duration'] = $duration / $timescale;
                }
            } else if (in_array($atomType, ['©nam', '©ART', '©alb', '©gen'])) {
                // Read data sub-atom
                $dataStart = ftell($fh);
                // Loop through sub-atoms inside this metadata container by seeking
                while (ftell($fh) < $atomEnd - 8) {
                    $subSizeData = fread($fh, 4);
                    $subType = fread($fh, 4);
                    if (strlen($subSizeData) !== 4 || strlen($subType) !== 4) {
                        break;
                    }
                    
                    $subSize = (ord($subSizeData[0]) << 24) |
                               (ord($subSizeData[1]) << 16) |
                               (ord($subSizeData[2]) << 8) |
                               ord($subSizeData[3]);
                    
                    if ($subSize <= 0) {
                        break;
                    }
                    
                    $nextSubAtom = ftell($fh) - 8 + $subSize;
                    
                    if ($subType === 'data') {
                        fseek($fh, 8, SEEK_CUR); // skip version and flags
                        $textLength = $subSize - 16;
                        if ($textLength > 0 && $textLength < ($atomSize - 8)) {
                            $text = fread($fh, $textLength);
                            $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text)); // strip control chars
                            if ($atomType === '©nam') $meta['title'] = $text;
                            if ($atomType === '©ART') $meta['artist'] = $text;
                            if ($atomType === '©alb') $meta['album'] = $text;
                            if ($atomType === '©gen') $meta['genre'] = $text;
                        }
                        break;
                    }
                    
                    // Jump directly to the start of the next sub-atom
                    if ($nextSubAtom >= $atomEnd) {
                        break;
                    }
                    fseek($fh, $nextSubAtom);
                }
            }

            fseek($fh, $atomEnd);
        }
    }

    private static function parseFlac($filePath, &$meta) {
        $fh = fopen($filePath, 'rb');
        if (!$fh) return;

        // Check signature
        $sig = fread($fh, 4);
        if ($sig !== 'fLaC') {
            fclose($fh);
            return;
        }

        $lastBlock = false;
        while (!$lastBlock) {
            $header = fread($fh, 4);
            if (strlen($header) !== 4) break;

            $headerByte = ord($header[0]);
            $lastBlock = ($headerByte & 0x80) ? true : false;
            $blockType = $headerByte & 0x7F;

            $length = (ord($header[1]) << 16) | (ord($header[2]) << 8) | ord($header[3]);
            if ($length <= 0) break;

            $blockData = fread($fh, $length);
            if ($blockType === 0) { // STREAMINFO
                // Sample rate starts at bit 80 (byte 10 of content)
                // Bits 80-99 (20 bits): Sample Rate
                // Bits 108-143 (36 bits): Total Samples
                if (strlen($blockData) >= 18) {
                    $sampleRate = (ord($blockData[10]) << 12) | (ord($blockData[11]) << 4) | ((ord($blockData[12]) & 0xF0) >> 4);
                    $totalSamples = ((ord($blockData[13]) & 0x0F) << 32) |
                                    (ord($blockData[14]) << 24) |
                                    (ord($blockData[15]) << 16) |
                                    (ord($blockData[16]) << 8) |
                                    ord($blockData[17]);
                    if ($sampleRate > 0) {
                        $meta['duration'] = $totalSamples / $sampleRate;
                    }
                }
            } else if ($blockType === 4) { // VORBIS_COMMENT
                self::parseVorbisComments($blockData, $meta);
            }
        }

        fclose($fh);
    }

    private static function parseVorbisComments($data, &$meta) {
        $len = strlen($data);
        if ($len < 4) return;

        $offset = 0;
        // Vendor length
        $vendorLen = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4 + $vendorLen;

        if ($offset + 4 > $len) return;

        // User comment count
        $commentCount = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;

        for ($i = 0; $i < $commentCount; $i++) {
            if ($offset + 4 > $len) break;
            $commentLen = unpack('V', substr($data, $offset, 4))[1];
            $offset += 4;

            if ($offset + $commentLen > $len) break;
            $comment = substr($data, $offset, $commentLen);
            $offset += $commentLen;

            if (strpos($comment, '=') !== false) {
                list($key, $val) = explode('=', $comment, 2);
                $key = strtoupper(trim($key));
                $val = trim($val);

                if ($key === 'TITLE') $meta['title'] = $val;
                if ($key === 'ARTIST') $meta['artist'] = $val;
                if ($key === 'ALBUM') $meta['album'] = $val;
                if ($key === 'GENRE') $meta['genre'] = $val;
            }
        }
    }

    private static function parseWav($filePath, &$meta) {
        $fh = fopen($filePath, 'rb');
        if (!$fh) return;

        $riff = fread($fh, 12);
        if (strlen($riff) !== 12 || substr($riff, 0, 4) !== 'RIFF' || substr($riff, 8, 4) !== 'WAVE') {
            fclose($fh);
            return;
        }

        $fileSize = filesize($filePath);
        $byteRate = 0;
        $dataSize = 0;

        while (ftell($fh) < $fileSize - 8) {
            $chunkId = fread($fh, 4);
            $chunkSizeData = fread($fh, 4);
            if (strlen($chunkId) !== 4 || strlen($chunkSizeData) !== 4) break;

            $chunkSize = unpack('V', $chunkSizeData)[1];
            $chunkEnd = ftell($fh) + $chunkSize;

            if ($chunkId === 'fmt ') {
                fseek($fh, 8, SEEK_CUR); // Skip audio format (2) and channels (2) and sample rate (4)
                $byteRateData = fread($fh, 4);
                if (strlen($byteRateData) === 4) {
                    $byteRate = unpack('V', $byteRateData)[1];
                }
            } else if ($chunkId === 'data') {
                $dataSize = $chunkSize;
            } else if ($chunkId === 'LIST') {
                $listType = fread($fh, 4);
                if ($listType === 'INFO') {
                    while (ftell($fh) < $chunkEnd - 8) {
                        $infoId = fread($fh, 4);
                        $infoSizeData = fread($fh, 4);
                        if (strlen($infoId) !== 4 || strlen($infoSizeData) !== 4) break;
                        $infoSize = unpack('V', $infoSizeData)[1];
                        if ($infoSize > 0) {
                            $infoVal = rtrim(fread($fh, $infoSize), "\x00");
                            if ($infoId === 'INAM') $meta['title'] = $infoVal;
                            if ($infoId === 'IART') $meta['artist'] = $infoVal;
                            if ($infoId === 'IPRD') $meta['album'] = $infoVal;
                            if ($infoId === 'IGNR') $meta['genre'] = $infoVal;
                        }
                        if ($infoSize % 2 !== 0) fseek($fh, 1, SEEK_CUR); // Pad byte
                    }
                }
            }

            fseek($fh, $chunkEnd);
            if ($chunkSize % 2 !== 0) fseek($fh, 1, SEEK_CUR); // Pad byte
        }

        if ($byteRate > 0 && $dataSize > 0) {
            $meta['duration'] = $dataSize / $byteRate;
        }

        fclose($fh);
    }

    private static function getGenreNameById3Id($id) {
        // ID3v1 standard genres mapping
        $genres = [
            0 => 'Blues', 1 => 'Classic Rock', 2 => 'Country', 3 => 'Dance', 4 => 'Disco', 5 => 'Funk', 6 => 'Grunge',
            7 => 'Hip-Hop', 8 => 'Jazz', 9 => 'Metal', 10 => 'New Age', 11 => 'Oldies', 12 => 'Other', 13 => 'Pop',
            14 => 'R&B', 15 => 'Rap', 16 => 'Reggae', 17 => 'Rock', 18 => 'Techno', 19 => 'Industrial', 20 => 'Alternative',
            21 => 'Salsa', 22 => 'Allemande', 23 => 'Bossa Nova', 24 => 'Classical', 25 => 'Eurodance', 26 => 'Gothic',
            27 => 'Darkwave', 28 => 'Jungle', 29 => 'Instrumental', 30 => 'Acid', 31 => 'House', 32 => 'Game',
            33 => 'Sound Clip', 34 => 'Gospel', 35 => 'Noise', 36 => 'Alternative Rock', 37 => 'Bass', 38 => 'Soul',
            39 => 'Punk', 40 => 'Space', 41 => 'Meditative', 42 => 'Instrumental Pop', 43 => 'Instrumental Rock',
            44 => 'Ethnic', 45 => 'Gothic Rock', 46 => 'Drum & Bass', 47 => 'Club', 48 => 'Tribute', 49 => 'Ambient',
            50 => 'Lounge', 51 => 'Duet', 52 => 'Punk Rock', 53 => 'Acid Jazz', 54 => 'Club-House', 55 => 'Chanson',
            56 => 'Opera', 57 => 'Chamber Music', 58 => 'Sonata', 59 => 'Symphony', 60 => 'Bootleg', 61 => 'Satire',
            62 => 'Slow Rock', 63 => 'Club', 64 => 'Tango', 65 => 'Samba', 66 => 'Folklore', 67 => 'Ballad',
            68 => 'Power Ballad', 69 => 'Rhythmic Soul', 70 => 'Freestyle', 71 => 'Duet', 72 => 'Punk Rock',
            73 => 'Drum Solo', 74 => 'Acapella', 75 => 'Euro-House', 76 => 'Dance Hall'
        ];
        return isset($genres[(int)$id]) ? $genres[(int)$id] : 'Desconocido';
    }
}
