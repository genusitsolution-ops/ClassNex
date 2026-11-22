package `in`.classnex.app.di

import dagger.hilt.EntryPoint
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import `in`.classnex.app.data.SessionStore

/** Lets code outside Hilt-injected classes (e.g. the emulator UI tests) reach the session store. */
@EntryPoint
@InstallIn(SingletonComponent::class)
interface SessionEntryPoint {
    fun store(): SessionStore
}
