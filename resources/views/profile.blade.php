<x-admin-layout title="My Profile">
    <div class="mx-auto max-w-5xl space-y-6">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="max-w-2xl">
                <livewire:profile.update-profile-information-form />
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="max-w-2xl">
                <livewire:profile.update-password-form />
            </div>
        </div>

        <div class="rounded-2xl border border-rose-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="max-w-2xl">
                <livewire:profile.delete-user-form />
            </div>
        </div>
    </div>
</x-admin-layout>
