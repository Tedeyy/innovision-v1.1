<section class="mt-10">
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __('Contact Information') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __("Update and verify your contact number for important notifications.") }}
        </p>
    </header>

    <div class="mt-6 space-y-6">
        <div>
            <x-input-label for="contact_number" :value="__('Phone Number')" />
            <div class="flex mt-1 rounded-md shadow-sm">
                <input type="text" 
                       id="contact_number" 
                       name="contact_number" 
                       class="flex-1 min-w-0 block w-full px-3 py-2 rounded-l-md border-gray-300 dark:border-gray-700 dark:bg-gray-700 dark:text-gray-300 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                       :value="old('contact_number', $user->contact_number ?? '')"
                       :disabled="$isVerified"
                       x-ref="contactInput"
                       @input="phone = $event.target.value">
                <button type="button"
                        x-show="!isVerified"
                        @click="showVerificationModal()"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-r-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                    {{ __('Verify') }}
                </button>
                <div x-show="isVerified" class="inline-flex items-center px-4 py-2 bg-green-100 dark:bg-green-900 border border-transparent rounded-r-md font-semibold text-xs text-green-800 dark:text-green-200 uppercase tracking-widest">
                    {{ __('Verified') }}
                </div>
            </div>
            <x-input-error class="mt-2" :messages="$errors->get('contact_number')" />
        </div>
    </div>

    <x-verification-modal :is-verified="$isVerified" />
</section>

@push('scripts')
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('contactVerification', () => ({
            phone: '{{ old("contact_number", $user->contact_number ?? "") }}',
            isVerified: {{ $isVerified ? 'true' : 'false' }},
            showVerificationModal() {
                if (!this.phone) {
                    alert('Please enter a phone number');
                    return;
                }
                
                // Send OTP
                fetch('{{ route("verification.send-otp") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        contact_number: this.phone
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Show verification modal
                        this.$dispatch('show-verification', { phone: this.phone });
                    } else {
                        alert(data.message || 'Failed to send OTP');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while sending OTP');
                });
            },
            verifyOtp() {
                // This will be handled by the verification modal
            }
        }));
    });
</script>
@endpush
