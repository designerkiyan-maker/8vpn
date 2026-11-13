package ir.mrkiyan.eligius

import android.app.Application
import ir.mrkiyan.eligius.api.RetrofitClient

class App : Application() {
    override fun onCreate() {
        super.onCreate()
        SettingsStore.init(this)
        AuthStore.init(this)
        Engine.init(this)

        // Phase 2/3 subscription components (docs 06 §6):
        // Retrofit auth/device interceptors, license state + CRL cache.
        RetrofitClient.init(this)
        LicenseStore.init(this)
    }
}
