package ir.mrkiyan.eligius

import androidx.compose.material3.MaterialTheme
import androidx.compose.ui.test.hasTestTag
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Phase 5b acceptance — TrialGrantBanner UI states (owner list):
 *  • an ELIGIBLE state renders the trial copy and the start button;
 *  • a failed grant surfaces the error inline (test-tagged) and keeps the
 *    banner available for a retry;
 *  • visibility itself is driven by the SERVER-reported eligibility in
 *    MainActivity (gate error NO_ACTIVE_LICENSE + trial_eligible=true) —
 *    never by local cache emptiness; that contract is proven server-side by
 *    trial-eligibility-test.php and on-device by LicenseStoreTest.t6.
 */
@RunWith(AndroidJUnit4::class)
class TrialGrantBannerTest {

    @get:Rule
    val compose = createComposeRule()

    @Test
    fun test1_eligibleStateShowsCopyAndButton() {
        compose.setContent { MaterialTheme { TrialGrantBanner(onTrialGranted = {}) } }
        compose.onNodeWithText("دوره رایگان ۳۰ روزه").assertExists()
        compose.onNodeWithText("۳۰ روز رایگان با تمام امکانات — فقط یک مرتبه برای هر حساب").assertExists()
        compose.onNodeWithText("شروع دوره رایگان").assertExists()
    }

    @Test
    fun test2_failedGrantShowsErrorInlineAndKeepsRetryAvailable() {
        compose.setContent { MaterialTheme { TrialGrantBanner(onTrialGranted = {}) } }
        compose.onNodeWithText("شروع دوره رایگان").performClick()
        // The grant call fails in this test env (no live session / refused by
        // the backend) — the banner must surface the error INLINE (tagged
        // node) and keep the start button available for a retry.
        compose.waitUntil(15_000) {
            compose.onAllNodes(hasTestTag("trial_banner_error")).fetchSemanticsNodes().isNotEmpty()
        }
        compose.onNodeWithText("شروع دوره رایگان").assertExists()
        compose.onAllNodes(hasText("دوره رایگان ۳۰ روزه")).fetchSemanticsNodes().isNotEmpty()
    }
}
