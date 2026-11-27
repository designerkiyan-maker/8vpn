<?php
/**
 * P9.2 — Class_Volume quota math (pure, WP-free).
 * (Class_Volume is required by run-tests.php before test discovery.)
 */

final class VolumeMathTest extends P6UnitBase {

    public function testQuotaBytes() {
        $this->check('null gb → null quota (unlimited)', null === \Eligius_Sub\Core\Class_Volume::quota_bytes(null));
        $this->check('zero gb → null quota (unlimited)', null === \Eligius_Sub\Core\Class_Volume::quota_bytes(0));
        $this->check('negative gb → null quota', null === \Eligius_Sub\Core\Class_Volume::quota_bytes(-5));
        $this->check('1 GiB = 1073741824', 1073741824 === \Eligius_Sub\Core\Class_Volume::quota_bytes(1));
        $this->check('100 GiB', 107374182400 === \Eligius_Sub\Core\Class_Volume::quota_bytes(100));
    }

    public function testClampDelta() {
        $this->check('negative delta → 0 (monotonic)', 0 === \Eligius_Sub\Core\Class_Volume::clamp_delta(-100));
        $this->check('normal delta passes through', 4608 === \Eligius_Sub\Core\Class_Volume::clamp_delta(4608));
        $this->check('delta above 2^40 capped', 1099511627776 === \Eligius_Sub\Core\Class_Volume::clamp_delta(PHP_INT_MAX));
        $this->check('exactly 2^40 allowed', 1099511627776 === \Eligius_Sub\Core\Class_Volume::clamp_delta(1099511627776));
    }

    public function testPercent() {
        $this->check('null total → null percent', null === \Eligius_Sub\Core\Class_Volume::percent(500, null));
        $this->check('zero total → null percent', null === \Eligius_Sub\Core\Class_Volume::percent(500, 0));
        $this->check('50%', 50 === \Eligius_Sub\Core\Class_Volume::percent(512, 1024));
        $this->check('0%', 0 === \Eligius_Sub\Core\Class_Volume::percent(0, 1024));
        $this->check('over quota → capped at 100', 100 === \Eligius_Sub\Core\Class_Volume::percent(9999, 1024));
        $this->check('floor rounding', 33 === \Eligius_Sub\Core\Class_Volume::percent(1, 3));
    }

    public function testIsExhausted() {
        $this->check('null total → never exhausted', false === \Eligius_Sub\Core\Class_Volume::is_exhausted(999, null));
        $this->check('zero total → never exhausted', false === \Eligius_Sub\Core\Class_Volume::is_exhausted(999, 0));
        $this->check('used below quota', false === \Eligius_Sub\Core\Class_Volume::is_exhausted(1023, 1024));
        $this->check('used equals quota → exhausted', true === \Eligius_Sub\Core\Class_Volume::is_exhausted(1024, 1024));
        $this->check('used above quota → exhausted', true === \Eligius_Sub\Core\Class_Volume::is_exhausted(2048, 1024));
    }
}
