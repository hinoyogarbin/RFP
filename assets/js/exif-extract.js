// Client-side EXIF extraction for field photo uploads.
//
// Reads GPS coordinates, capture date/time, and camera make/model
// directly out of the JPEG's EXIF segment in the browser, before the
// file is ever sent to the server. This lets the upload form show the
// photographer what will be recorded, and lets the server prefer
// trustworthy pre-parsed values instead of re-parsing bytes itself.
//
// No external libraries — this is a small, purpose-built reader for
// the handful of EXIF tags this app cares about (it is not a general
// EXIF library). Falls back silently (calls back with null) for PNGs,
// photos with no EXIF block (e.g. stripped by Signal/WhatsApp), or if
// the browser can't read the file.
(function (global) {
    'use strict';

    var TAG = {
        MAKE: 0x010F,
        MODEL: 0x0110,
        DATETIME: 0x0132,
        EXIF_IFD_POINTER: 0x8769,
        GPS_IFD_POINTER: 0x8825,
        DATETIME_ORIGINAL: 0x9003,
        GPS_LAT_REF: 0x0001,
        GPS_LAT: 0x0002,
        GPS_LNG_REF: 0x0003,
        GPS_LNG: 0x0004
    };

    function readString(view, offset, length) {
        var chars = [];
        for (var i = 0; i < length; i++) {
            var code = view.getUint8(offset + i);
            if (code === 0) break;
            chars.push(String.fromCharCode(code));
        }
        return chars.join('').trim();
    }

    function readRational(view, offset, little) {
        var numerator = view.getUint32(offset, little);
        var denominator = view.getUint32(offset + 4, little);
        return denominator === 0 ? 0 : numerator / denominator;
    }

    function dmsToDecimal(dms, ref) {
        var decimal = dms[0] + dms[1] / 60 + dms[2] / 3600;
        if (ref === 'S' || ref === 'W') decimal *= -1;
        return Math.round(decimal * 1e7) / 1e7;
    }

    // Walks one IFD (Image File Directory) and returns {tags, nextOffset}.
    function readIfd(view, ifdOffset, tiffStart, little, wantedTags) {
        var tags = {};
        var entryCount = view.getUint16(ifdOffset, little);

        for (var i = 0; i < entryCount; i++) {
            var entryOffset = ifdOffset + 2 + i * 12;
            var tagId = view.getUint16(entryOffset, little);
            if (wantedTags.indexOf(tagId) === -1) continue;

            var type = view.getUint16(entryOffset + 2, little);
            var count = view.getUint32(entryOffset + 4, little);
            var valueOffset = entryOffset + 8;

            if (tagId === TAG.MAKE || tagId === TAG.MODEL || tagId === TAG.DATETIME_ORIGINAL || tagId === TAG.DATETIME) {
                // ASCII string. If it fits in 4 bytes it's inline, else it's a pointer.
                var strOffset = count > 4 ? tiffStart + view.getUint32(valueOffset, little) : valueOffset;
                tags[tagId] = readString(view, strOffset, count);
            } else if (tagId === TAG.EXIF_IFD_POINTER || tagId === TAG.GPS_IFD_POINTER) {
                tags[tagId] = tiffStart + view.getUint32(valueOffset, little);
            } else if (tagId === TAG.GPS_LAT_REF || tagId === TAG.GPS_LNG_REF) {
                tags[tagId] = String.fromCharCode(view.getUint8(valueOffset));
            } else if (tagId === TAG.GPS_LAT || tagId === TAG.GPS_LNG) {
                // 3 rationals (degrees, minutes, seconds), always a pointer (count=3, type=RATIONAL=5, 8 bytes each).
                var rationalsOffset = tiffStart + view.getUint32(valueOffset, little);
                tags[tagId] = [
                    readRational(view, rationalsOffset, little),
                    readRational(view, rationalsOffset + 8, little),
                    readRational(view, rationalsOffset + 16, little)
                ];
            }
        }

        return tags;
    }

    function parseExifBuffer(buffer) {
        var view = new DataView(buffer);
        var result = { latitude: null, longitude: null, datetime: null, make: null, model: null };

        if (view.byteLength < 4 || view.getUint16(0, false) !== 0xFFD8) {
            return result; // Not a JPEG.
        }

        var offset = 2;
        var length = view.byteLength;

        while (offset < length - 4) {
            var marker = view.getUint16(offset, false);
            offset += 2;

            if ((marker & 0xFF00) !== 0xFF00) break; // Not a valid marker, stop.
            if (marker === 0xFFD9 || marker === 0xFFDA) break; // EOI / start of scan: no more markers to scan.

            var segmentLength = view.getUint16(offset, false);

            if (marker === 0xFFE1) { // APP1 — where EXIF lives.
                var app1Start = offset + 2;
                if (view.getUint32(app1Start, false) === 0x45786966) { // "Exif"
                    var tiffStart = app1Start + 6;
                    var little = view.getUint16(tiffStart, false) === 0x4949;
                    var ifd0Offset = tiffStart + view.getUint32(tiffStart + 4, little);

                    var ifd0 = readIfd(view, ifd0Offset, tiffStart, little,
                        [TAG.MAKE, TAG.MODEL, TAG.DATETIME, TAG.EXIF_IFD_POINTER, TAG.GPS_IFD_POINTER]);

                    result.make = ifd0[TAG.MAKE] || null;
                    result.model = ifd0[TAG.MODEL] || null;

                    var rawDateTime = ifd0[TAG.DATETIME] || null;

                    if (ifd0[TAG.EXIF_IFD_POINTER]) {
                        var exifIfd = readIfd(view, ifd0[TAG.EXIF_IFD_POINTER], tiffStart, little, [TAG.DATETIME_ORIGINAL]);
                        if (exifIfd[TAG.DATETIME_ORIGINAL]) rawDateTime = exifIfd[TAG.DATETIME_ORIGINAL];
                    }
                    // EXIF datetime looks like "2026:09:10 14:32:07" — matches the
                    // format the PHP side already parses with Y:m:d H:i:s.
                    result.datetime = rawDateTime || null;

                    if (ifd0[TAG.GPS_IFD_POINTER]) {
                        var gpsIfd = readIfd(view, ifd0[TAG.GPS_IFD_POINTER], tiffStart, little,
                            [TAG.GPS_LAT_REF, TAG.GPS_LAT, TAG.GPS_LNG_REF, TAG.GPS_LNG]);
                        if (gpsIfd[TAG.GPS_LAT] && gpsIfd[TAG.GPS_LNG]) {
                            result.latitude = dmsToDecimal(gpsIfd[TAG.GPS_LAT], gpsIfd[TAG.GPS_LAT_REF] || 'N');
                            result.longitude = dmsToDecimal(gpsIfd[TAG.GPS_LNG], gpsIfd[TAG.GPS_LNG_REF] || 'E');
                        }
                    }
                }
                break; // Only one EXIF APP1 segment; done.
            }

            offset += segmentLength;
        }

        return result;
    }

    /**
     * Reads a File/Blob and calls back with the extracted EXIF fields.
     * callback(result) — result is always an object; unavailable fields are null.
     * Never throws: any parse failure just yields an all-null result.
     */
    function extractExif(file, callback) {
        if (!file || typeof FileReader === 'undefined') {
            callback({ latitude: null, longitude: null, datetime: null, make: null, model: null });
            return;
        }

        var reader = new FileReader();
        reader.onload = function (event) {
            var result;
            try {
                result = parseExifBuffer(event.target.result);
            } catch (err) {
                result = { latitude: null, longitude: null, datetime: null, make: null, model: null };
            }
            callback(result);
        };
        reader.onerror = function () {
            callback({ latitude: null, longitude: null, datetime: null, make: null, model: null });
        };
        // Only the first 256KB is needed — EXIF sits right after the JPEG's
        // SOI marker, long before any scan/image data.
        reader.readAsArrayBuffer(file.slice(0, 262144));
    }

    global.FieldPhotoExif = { extract: extractExif };
})(window);
