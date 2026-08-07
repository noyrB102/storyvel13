<?php

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component {
    use ProfileValidationRules;

    public string $name = '';
    public string $email = '';
    public ?int $age = null;
    public ?string $gender = null;
    public ?string $interests = null;
    public ?string $favorite_authors = null;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
        $this->age = Auth::user()->age;
        $this->gender = Auth::user()->gender;
        $this->interests = Auth::user()->interests;
        $this->favorite_authors = Auth::user()->favorite_authors;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate($this->profileRules($user->id));

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->dispatch('profile-updated', name: $user->name);
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Username')" type="text" required autocomplete="username" />

                @if ($this->hasUnverifiedEmail)
                    <div>
                        <flux:text class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">
                                {{ __('Click here to re-send the verification email.') }}
                            </flux:link>
                        </flux:text>

                        @if (session('status') === 'verification-link-sent')
                            <flux:text class="mt-2 font-medium !dark:text-green-400 !text-green-600">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </flux:text>
                        @endif
                    </div>
                @endif
            </div>

            <flux:separator />

            <div>
                <flux:heading class="mb-1">{{ __('About the author') }}</flux:heading>
                <flux:subheading>{{ __('Tell us a little so the AI can tailor stories to the writer.') }}</flux:subheading>
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="age" :label="__('Age')" type="number" min="1" max="120" />

                <div>
                    <label for="gender" class="block text-sm font-medium text-gray-900 dark:text-white">{{ __('Gender') }}</label>
                    <select wire:model="gender" id="gender" class="mt-1 block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white">
                        <option value="">{{ __('-') }}</option>
                        <option value="boy">{{ __('Boy') }}</option>
                        <option value="girl">{{ __('Girl') }}</option>
                    </select>
                </div>
            </div>

            <flux:input wire:model="interests" :label="__('Interests')" type="text" :placeholder="__('e.g. dinosaurs, trucks, swimming, superheroes')" />

            <flux:input wire:model="favorite_authors" :label="__('Favorite authors')" type="text" :placeholder="__('e.g. Mo Willems, Jon Klassen, Sandra Boynton')" />

            <flux:text class="text-sm text-zinc-500">
                {{ __('The AI will write in the spirit of these authors — never copying them, just matching tone and playfulness.') }}
            </flux:text>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                        {{ __('Save') }}
                    </flux:button>
                </div>

                <x-action-message class="me-3" on="profile-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>

        @if ($this->showDeleteUser)
            <livewire:pages::settings.delete-user-form />
        @endif
    </x-pages::settings.layout>
</section>
