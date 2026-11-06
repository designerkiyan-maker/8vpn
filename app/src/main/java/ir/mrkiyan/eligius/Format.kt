package ir.mrkiyan.eligius

import java.util.Locale

/** Formats a byte count as KB/MB/GB for the usage cards. */
internal fun fmtBytes(b: Long): String = when {
    b < 0 -> "—"
    b >= 1_073_741_824 -> String.format(Locale.US, "%.2f GB", b / 1_073_741_824.0)
    b >= 1_048_576 -> String.format(Locale.US, "%.1f MB", b / 1_048_576.0)
    b >= 1024 -> String.format(Locale.US, "%.1f KB", b / 1024.0)
    else -> "$b B"
}

/** Formats a duration as compact d/h/m/s for the usage cards. */
internal fun formatDuration(millis: Long): String {
    if (millis <= 0) return "—"
    val seconds = (millis / 1000) % 60
    val minutes = (millis / (1000 * 60)) % 60
    val hours = (millis / (1000 * 60 * 60)) % 24
    val days = millis / (1000 * 60 * 60 * 24)
    return when {
        days > 0 -> "${days}d ${hours}h"
        hours > 0 -> "${hours}h ${minutes}m"
        minutes > 0 -> "${minutes}m ${seconds}s"
        else -> "${seconds}s"
    }
}
