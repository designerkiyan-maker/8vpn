package ir.mrkiyan.eligius

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * P9.2: volume-gate mapping (pure math — no Android framework).
 */
class VolumeGateMappingTest {

    @Test
    fun `unlimited quota never crosses`() {
        assertFalse(VolumeGate.wouldCross(1_000_000L, null, 500L))
        assertFalse(VolumeGate.wouldCross(Long.MAX_VALUE, 0L, 1L))
        assertFalse(VolumeGate.wouldCross(0L, -5L, 0L))
    }

    @Test
    fun `offline state never crosses locally`() {
        assertFalse(VolumeGate.wouldCross(1000L, 1024L, 500L, offline = true))
    }

    @Test
    fun `used plus session crossing the quota`() {
        assertTrue(VolumeGate.wouldCross(1000L, 1024L, 24L))
        assertTrue(VolumeGate.wouldCross(1024L, 1024L, 0L))
        assertTrue(VolumeGate.wouldCross(2000L, 1024L, 0L))
    }

    @Test
    fun `used plus session below the quota`() {
        assertFalse(VolumeGate.wouldCross(1000L, 1024L, 23L))
        assertFalse(VolumeGate.wouldCross(0L, 1073741824L, 0L))
    }

    @Test
    fun `negative inputs are clamped`() {
        assertFalse(VolumeGate.wouldCross(-100L, 1024L, -50L))
        assertTrue(VolumeGate.wouldCross(-100L, 1024L, 2000L))
    }
}
