class ContactVerification {
    constructor(options = {}) {
        // Default options
        this.options = Object.assign({
            formSelector: '.contact-verification-form',
            contactNumberInput: 'input[name="contact_number"]',
            verifyButton: '.verify-btn',
            statusElement: '.verification-status',
            modalId: 'verificationModal',
            otpInput: 'input[name="otp_code"]',
            submitOtpBtn: '.submit-otp',
            resendOtpBtn: '.resend-otp',
            countdownElement: '.countdown',
            errorClass: 'text-red-600 text-sm mt-1',
            successClass: 'text-green-600 text-sm mt-1',
            loadingClass: 'opacity-50 cursor-not-allowed',
            userId: null,
            userRole: null
        }, options);

        this.init();
    }

    init() {
        this.form = document.querySelector(this.options.formSelector);
        if (!this.form) return;

        this.contactNumberInput = this.form.querySelector(this.options.contactNumberInput);
        this.verifyButton = this.form.querySelector(this.options.verifyButton);
        this.statusElement = this.form.querySelector(this.options.statusElement);
        
        // Initialize modal if it exists
        this.modal = document.getElementById(this.options.modalId);
        if (this.modal) {
            this.otpInput = this.modal.querySelector(this.options.otpInput);
            this.submitOtpBtn = this.modal.querySelector(this.options.submitOtpBtn);
            this.resendOtpBtn = this.modal.querySelector(this.options.resendOtpBtn);
            this.countdownElement = this.modal.querySelector(this.options.countdownElement);
            
            // Initialize modal events
            this.initModalEvents();
        }

        // Initialize form events
        this.initFormEvents();
        
        // Check initial verification status
        this.checkVerificationStatus();
    }

    initFormEvents() {
        // Live validation for contact number
        if (this.contactNumberInput) {
            let debounceTimer;
            this.contactNumberInput.addEventListener('input', (e) => {
                clearTimeout(debounceTimer);
                const value = e.target.value.trim();
                
                // Basic validation
                if (value && !this.isValidPhoneNumber(value)) {
                    this.showError('Please enter a valid phone number');
                    return;
                }
                
                // Debounce the API call
                debounceTimer = setTimeout(() => {
                    this.checkNumberAvailability(value);
                }, 500);
            });
        }

        // Verify button click
        if (this.verifyButton) {
            this.verifyButton.addEventListener('click', (e) => {
                e.preventDefault();
                this.initiateVerification();
            });
        }
    }

    initModalEvents() {
        // Submit OTP
        if (this.submitOtpBtn) {
            this.submitOtpBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.verifyOtp();
            });
        }

        // Resend OTP
        if (this.resendOtpBtn) {
            this.resendOtpBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.initiateVerification(true);
            });
        }

        // Handle OTP input changes
        if (this.otpInput) {
            this.otpInput.addEventListener('input', (e) => {
                const value = e.target.value.replace(/\D/g, '').slice(0, 6);
                e.target.value = value;
                
                // Enable/disable submit button based on OTP length
                if (this.submitOtpBtn) {
                    this.submitOtpBtn.disabled = value.length !== 6;
                }
            });
        }
    }

    async checkNumberAvailability(phoneNumber) {
        if (!phoneNumber) return;
        
        try {
            const response = await fetch('/api/check-phone-availability', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    phone: phoneNumber,
                    user_id: this.options.userId
                })
            });
            
            const data = await response.json();
            
            if (data.available) {
                this.showSuccess('Phone number is available');
                if (this.verifyButton) {
                    this.verifyButton.disabled = false;
                }
            } else {
                this.showError('This phone number is already in use');
                if (this.verifyButton) {
                    this.verifyButton.disabled = true;
                }
            }
            
            return data.available;
        } catch (error) {
            console.error('Error checking phone availability:', error);
            return false;
        }
    }

    async sendOtp(contactNumber) {
        if (!this.isValidPhoneNumber(contactNumber)) {
            this.showError('Please enter a valid 11-digit phone number');
            return false;
        }

        try {
            this.setLoading(true);
            
            // Check if number is already verified
            const isAvailable = await this.checkContactAvailability(contactNumber);
            if (!isAvailable) {
                this.showError('This phone number is already in use by another account');
                return false;
            }

            // Show loading state
            this.showStatus('Sending verification code...', 'info');
            
            // Proceed with OTP
            const response = await fetch('includes/send_otp.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    contact: contactNumber,
                    user_id: this.options.userId,
                    user_role: this.options.userRole
                })
            });

            // Check if response is OK
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();
            
            if (data.success) {
                this.showSuccess('Verification code sent to your phone!');
                this.showVerificationModal(contactNumber);
                return true;
            } else {
                const errorMessage = data.message || 'Failed to send verification code. Please try again.';
                this.showError(errorMessage);
                return false;
            }
        } catch (error) {
            console.error('Error sending OTP:', error);
            let errorMessage = 'An error occurred while sending the verification code';
            
            // Provide more specific error messages based on the error type
            if (error.name === 'TypeError' && error.message.includes('Failed to fetch')) {
                errorMessage = 'Network error. Please check your internet connection and try again.';
            } else if (error.message && error.message.includes('NetworkError')) {
                errorMessage = 'Network error. Please check your internet connection.';
            } else if (error.response) {
                // Handle HTTP error responses
                errorMessage = `Server error: ${error.response.status}`;
            }
            
            this.showError(errorMessage);
            return false;
        } finally {
            this.setLoading(false);
        }
    }

    async initiateVerification(isResend = false) {
        const phoneNumber = this.contactNumberInput ? this.contactNumberInput.value.trim() : '';

        if (!phoneNumber || !this.isValidPhoneNumber(phoneNumber)) {
            this.showError('Please enter a valid phone number');
            return;
        }
        
        // Disable button and show loading state
        if (this.verifyButton) {
            this.verifyButton.disabled = true;
            this.verifyButton.classList.add(this.options.loadingClass);
            this.verifyButton.innerHTML = 'Sending...';
        }
        
        try {
            const response = await fetch('/api/send-verification', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    phone: phoneNumber,
                    user_id: this.options.userId,
                    user_role: this.options.userRole,
                    is_resend: isResend
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showSuccess(data.message);
                if (this.modal) {
                    this.showVerificationModal();
                    this.startCountdown(300); // 5 minutes countdown
                }
            } else {
                this.showError(data.message || 'Failed to send verification code');
            }
        } catch (error) {
            console.error('Error initiating verification:', error);
            this.showError('An error occurred. Please try again.');
        } finally {
            // Re-enable button
            if (this.verifyButton) {
                this.verifyButton.disabled = false;
                this.verifyButton.classList.remove(this.options.loadingClass);
                this.verifyButton.innerHTML = 'Verify';
            }
        }
    }

    async verifyOtp() {
        const otp = this.otpInput ? this.otpInput.value.trim() : '';
        
        if (!otp || otp.length !== 6) {
            this.showModalError('Please enter a valid 6-digit code');
            return;
        }
        
        // Disable button and show loading state
        if (this.submitOtpBtn) {
            this.submitOtpBtn.disabled = true;
            this.submitOtpBtn.classList.add(this.options.loadingClass);
            this.submitOtpBtn.innerHTML = 'Verifying...';
        }
        
        try {
            const response = await fetch('/api/verify-otp', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    user_id: this.options.userId,
                    user_role: this.options.userRole,
                    otp: otp
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showModalSuccess(data.message);
                
                // Update UI on success
                setTimeout(() => {
                    this.hideVerificationModal();
                    this.checkVerificationStatus();
                    
                    // Clear OTP input
                    if (this.otpInput) {
                        this.otpInput.value = '';
                    }
                }, 1500);
            } else {
                this.showModalError(data.message || 'Verification failed');
            }
        } catch (error) {
            console.error('Error verifying OTP:', error);
            this.showModalError('An error occurred. Please try again.');
        } finally {
            // Re-enable button
            if (this.submitOtpBtn) {
                this.submitOtpBtn.disabled = false;
                this.submitOtpBtn.classList.remove(this.options.loadingClass);
                this.submitOtpBtn.innerHTML = 'Verify';
            }
        }
    }

    async checkVerificationStatus() {
        if (!this.options.userId || !this.options.userRole) return;
        
        try {
            const response = await fetch(`/api/verification-status?user_id=${this.options.userId}&user_role=${this.options.userRole}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            const data = await response.json();
            
            if (data.is_verified) {
                this.updateVerifiedUI(data.contact_number);
            }
        } catch (error) {
            console.error('Error checking verification status:', error);
        }
    }

    updateVerifiedUI(phoneNumber) {
        // Update contact number input
        if (this.contactNumberInput) {
            this.contactNumberInput.value = phoneNumber;
            this.contactNumberInput.disabled = true;
        }
        
        // Update verify button
        if (this.verifyButton) {
            this.verifyButton.innerHTML = '<i class="fas fa-check-circle"></i> Verified';
            this.verifyButton.disabled = true;
            this.verifyButton.classList.add('bg-green-500', 'hover:bg-green-600');
            this.verifyButton.classList.remove('bg-blue-600', 'hover:bg-blue-700');
        }
        
        // Show success message
        this.showSuccess('Your contact number is verified');
    }

    showVerificationModal() {
        if (!this.modal) return;
        
        // Reset modal state
        if (this.otpInput) this.otpInput.value = '';
        if (this.submitOtpBtn) this.submitOtpBtn.disabled = true;
        
        // Show modal (using Bootstrap 5 modal)
        const modal = new bootstrap.Modal(this.modal);
        modal.show();
    }

    hideVerificationModal() {
        if (!this.modal) return;
        
        const modal = bootstrap.Modal.getInstance(this.modal);
        if (modal) modal.hide();
    }

    startCountdown(seconds) {
        if (!this.countdownElement) return;
        
        let remaining = seconds;
        
        const updateCountdown = () => {
            const mins = Math.floor(remaining / 60);
            const secs = remaining % 60;
            this.countdownElement.textContent = `${mins}:${secs < 10 ? '0' : ''}${secs}`;
            
            if (remaining <= 0) {
                if (this.resendOtpBtn) {
                    this.resendOtpBtn.disabled = false;
                }
                clearInterval(interval);
            } else {
                remaining--;
            }
        };
        
        // Initial update
        updateCountdown();
        
        // Update every second
        const interval = setInterval(updateCountdown, 1000);
    }

    showError(message) {
        try {
            if (this.statusElement) {
                this.statusElement.textContent = message;
                this.statusElement.className = this.options.errorClass;
                this.statusElement.style.display = 'block';
                
                // Auto-hide error after 5 seconds
                setTimeout(() => {
                    if (this.statusElement && this.statusElement.textContent === message) {
                        this.statusElement.style.display = 'none';
                    }
                }, 5000);
            } else {
                // Use a more user-friendly alert if status element is not available
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-danger alert-dismissible fade show';
                alertDiv.role = 'alert';
                alertDiv.innerHTML = `
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                `;
                
                // Insert at the beginning of the form or body
                const container = this.form || document.body;
                container.insertBefore(alertDiv, container.firstChild);
                
                // Auto-remove after 5 seconds
                setTimeout(() => {
                    if (alertDiv.parentNode) {
                        const bsAlert = new bootstrap.Alert(alertDiv);
                        bsAlert.close();
                    }
                }, 5000);
            }
        } catch (error) {
            console.error('Error showing error message:', error);
            // Fallback to basic alert if everything else fails
            alert(`Error: ${message}`);
        }
    }

    showSuccess(message) {
        if (!this.statusElement) return;
        
        this.statusElement.className = this.options.successClass;
        this.statusElement.textContent = message;
    }

    showModalError(message) {
        const errorElement = this.modal ? this.modal.querySelector('.modal-error') : null;
        if (!errorElement) return;
        
        errorElement.className = 'modal-error text-red-500 text-sm mt-2';
        errorElement.textContent = message;
    }

    showModalSuccess(message) {
        const successElement = this.modal ? this.modal.querySelector('.modal-success') : null;
        if (!successElement) return;
        
        successElement.className = 'modal-success text-green-500 text-sm mt-2';
        successElement.textContent = message;
    }

    isValidPhoneNumber(phone) {
        // Simple validation - adjust regex as needed
        const phoneRegex = /^[0-9]{10,15}$/;
        return phoneRegex.test(phone);
    }
}

// Auto-initialize if data-contact-verification attribute is present
document.addEventListener('DOMContentLoaded', () => {
    const verificationElement = document.querySelector('[data-contact-verification]');
    if (verificationElement) {
        const options = {
            userId: verificationElement.dataset.userId,
            userRole: verificationElement.dataset.userRole
        };
        
        new ContactVerification(options);
    }
});
