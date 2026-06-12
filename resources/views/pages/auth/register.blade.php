<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Create Your Account')" :description="__('Choose a username and a simple password')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Name -->
            <flux:input
                name="name"
                :label="__('Your Name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                placeholder="e.g. Harold"
            />

            <!-- Username (stored as email field, no email format required) -->
            <flux:input
                name="email"
                :label="__('Username')"
                :value="old('email')"
                type="text"
                required
                autocomplete="username"
                placeholder="e.g. harold123"
            />
            <p class="-mt-4 text-sm text-gray-500">Can be anything — just needs to be unique. No @ required.</p>

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                placeholder="Any 4 or more characters"
                viewable
            />
            <p class="-mt-4 text-sm text-gray-500">Keep it simple — something easy to remember, like <strong>1234</strong> or <strong>story</strong>.</p>

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('Create My Account') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
