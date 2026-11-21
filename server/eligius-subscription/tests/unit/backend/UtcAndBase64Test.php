<?php
/**
 * P6 unit — UTC discipline (TD: explicit UTC everywhere) and base64url
 * encoding used by the entitlement blob.
 */
require_once __DIR__ . '/../../../includes/license/class-license-issuer.php';

final class UtcAndBase64Test extends P6UnitBase {

    public function testMysqlUtcIsGmtFormat() {
        $v = \Eligius_Sub\Core\Class_UTC_Time::mysql_utc();
        $this->check('mysql_utc matches Y-m-d H:i:s shape', 1 === preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v));
        $this->check('mysql_utc equals gmdate (server TZ-independent)', $v === gmdate('Y-m-d H:i:s'));
    }

    public function testIsoUtcFromTimestamp() {
        $iso = \Eligius_Sub\Core\Class_UTC_Time::iso_utc_from_timestamp(1794800000);
        $this->check('ISO conversion keeps the exact instant', 1794800000 === (int) strtotime($iso));
    }

    public function testAddSecondsAcrossDayBoundary() {
        $out = \Eligius_Sub\Core\Class_UTC_Time::add_seconds('2026-11-16 23:59:30', 120);
        $this->check('add_seconds rolls across midnight UTC', '2026-11-17 00:01:30' === $out);
    }

    public function testIsFutureWithBuffer() {
        $future = \Eligius_Sub\Core\Class_UTC_Time::add_seconds(\Eligius_Sub\Core\Class_UTC_Time::mysql_utc(), 7200);
        $this->check('2h in the future is future even with a 1h buffer', \Eligius_Sub\Core\Class_UTC_Time::is_future($future, 3600));
        $this->check('30 min ahead is NOT future with a 1h buffer',
            !\Eligius_Sub\Core\Class_UTC_Time::is_future(
                \Eligius_Sub\Core\Class_UTC_Time::add_seconds(\Eligius_Sub\Core\Class_UTC_Time::mysql_utc(), 1800), 3600));
    }

    public function testBase64UrlRoundTrip() {
        foreach (['', 'a', 'hello world', str_repeat('Ø§Ù„ÛŒÚ¯ÛŒÙˆØ³ ', 40), random_bytes(64)] as $i => $raw) {
            $enc = \Eligius_Sub\License\Class_License_Issuer::base64url_encode($raw);
            $this->check("base64url round-trip #$i (no +/ padding)", false === strpos($enc, '+') && false === strpos($enc, '/') && false === strpos($enc, '='));
            $dec = \Eligius_Sub\License\Class_License_Issuer::base64url_decode($enc);
            $this->check("base64url decode restores byte-exact input #$i", $dec === $raw);
        }
    }

    public function testBase64UrlDecodeRejectsGarbage() {
        // strict base64 decode → non-base64 input yields false, not data.
        $dec = \Eligius_Sub\License\Class_License_Issuer::base64url_decode('!!!!not-base64!!!!');
        $this->check('strict decode returns false for garbage', false === $dec);
    }
}
