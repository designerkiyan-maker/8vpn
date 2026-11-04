package ir.mrkiyan.eligius

import android.app.Application

class App : Application() {
    override fun onCreate() {
        super.onCreate()
        SettingsStore.init(this)
        AuthStore.init(this)
    }
}
